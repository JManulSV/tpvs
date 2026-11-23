<?php

namespace App\Domain\AI;

use App\Domain\AI\DTO\ExtractionIssue;
use App\Domain\Shared\Money;
use App\Enums\RecordSource;
use App\Models\Company;
use App\Models\Record;

class RecordExtractionService
{
    public function __construct(private readonly ExtractionFinancialCalculator $financialCalculator) {}

    /**
     * Persist the normalized proposal onto the provisional Record and its ordered details.
     * Caller owns the surrounding transaction and state transition.
     *
     * @param  array<string, mixed>  $extraction
     * @param  list<array<string, mixed>>  $existingIssues
     * @return list<array{path: string, code: string, severity: string, message: string, value?: mixed}>
     */
    public function apply(Record $record, array $extraction, array $existingIssues = []): array
    {
        if ($record->source !== RecordSource::IMAGE) {
            throw new \InvalidArgumentException('AI extraction can only be applied to IMAGE records.');
        }

        $recordData = $extraction['record'];
        $companyName = $this->fieldValue($recordData, 'company_name');
        [$companyId, $companyIssue] = $this->resolveCompany($companyName);
        $issues = $existingIssues;
        if ($companyIssue !== null) {
            $issues[] = $companyIssue;
        }

        $weekStartDate = $this->fieldValue($recordData, 'week_start_date');
        $weekEndDate = $this->fieldValue($recordData, 'week_end_date');
        if ($weekStartDate !== null && $weekEndDate !== null && $weekStartDate > $weekEndDate) {
            $issues[] = ExtractionIssue::review('record.week_end_date', 'INVERTED_WEEK_DATE_RANGE', 'The period end date is earlier than its start date; both dates require review.')->withValue($weekEndDate)->toArray();
            $weekStartDate = null;
            $weekEndDate = null;
        }

        $record->forceFill([
            'company_id' => $companyId,
            'year' => $this->fieldValue($recordData, 'year'),
            'week' => $this->fieldValue($recordData, 'week'),
            'week_start_date' => $weekStartDate,
            'week_end_date' => $weekEndDate,
        ])->save();

        $record->details()->delete();
        foreach ($extraction['details'] as $index => $detail) {
            $financial = $this->financialCalculator->calculate($detail, $index);
            array_push($issues, ...$financial->issues);

            $affiliation = $this->safeText($this->fieldValue($detail, 'affiliation'), "details.{$index}.affiliation", $issues);
            $branch = $this->safeText($this->fieldValue($detail, 'branch'), "details.{$index}.branch", $issues);

            $record->details()->create([
                'affiliation' => $affiliation,
                'branch' => $branch,
                'income' => $financial->income,
                'commission_percentage' => $financial->commissionPercentage,
                'commission' => $financial->commission,
                'return_amount' => $financial->returnAmount,
                'order' => $index + 1,
            ]);
        }

        $record->unsetRelation('details');
        $record->load('details');
        array_push($issues, ...$this->compareSourceTotals($extraction, $record));

        return $issues;
    }

    /**
     * @param  list<array{path: string, code: string, severity: string, message: string, value?: mixed}>  $issues
     */
    private function safeText(mixed $value, string $path, array &$issues): ?string
    {
        if ($value !== null && mb_strlen($value, 'UTF-8') > 255) {
            $issues[] = ExtractionIssue::review($path, 'TEXT_VALUE_TOO_LONG', 'The extracted text exceeds the database field limit.')->withValue($value)->toArray();

            return null;
        }

        return $value;
    }

    /** @param array<string, mixed> $fields */
    private function fieldValue(array $fields, string $field): mixed
    {
        $value = $fields[$field] ?? null;

        return is_array($value) ? ($value['value'] ?? null) : null;
    }

    /**
     * @return array{0: int|null, 1: array{path: string, code: string, severity: string, message: string, value?: mixed}|null}
     */
    private function resolveCompany(mixed $companyName): array
    {
        if (! is_string($companyName) || trim($companyName) === '') {
            return [null, ExtractionIssue::review('record.company_name', 'COMPANY_NAME_MISSING', 'A company name is missing and requires review.')->toArray()];
        }

        $normalizedName = mb_strtolower(trim($companyName), 'UTF-8');
        $matches = Company::query()
            ->whereRaw('LOWER(BTRIM(name)) = ?', [$normalizedName])
            ->limit(2)
            ->get(['id']);

        if ($matches->count() === 1) {
            return [$matches->first()->id, null];
        }

        $issue = $matches->isEmpty()
            ? ExtractionIssue::review('record.company_name', 'COMPANY_NOT_FOUND', 'No company matches the extracted name exactly; manual review is required.')
            : ExtractionIssue::review('record.company_name', 'COMPANY_AMBIGUOUS', 'More than one company matches the extracted name; manual review is required.');

        return [null, $issue->withValue($companyName)->toArray()];
    }

    /**
     * @param  array<string, mixed>  $extraction
     * @return list<array{path: string, code: string, severity: string, message: string, value?: mixed}>
     */
    private function compareSourceTotals(array $extraction, Record $record): array
    {
        $issues = [];
        $totals = [
            'income' => '0.00',
            'commission' => '0.00',
            'return_amount' => '0.00',
        ];
        $complete = true;
        foreach ($record->details as $detail) {
            foreach (array_keys($totals) as $field) {
                if ($detail->{$field} === null) {
                    $complete = false;

                    continue;
                }
                $totals[$field] = Money::fromDecimal($totals[$field])->add(Money::fromDecimal($detail->{$field}))->toDecimal();
            }
        }

        if (! $complete) {
            return $issues;
        }

        foreach ($totals as $field => $calculatedTotal) {
            $sourceTotal = $this->fieldValue($extraction['source_totals'], $field);
            if ($sourceTotal !== null && $sourceTotal !== $calculatedTotal) {
                $issues[] = ExtractionIssue::review("source_totals.{$field}", 'SOURCE_TOTAL_MISMATCH', 'The printed source total differs from the total calculated from proposed movements.')->withValue($sourceTotal)->toArray();
            }
        }

        return $issues;
    }
}
