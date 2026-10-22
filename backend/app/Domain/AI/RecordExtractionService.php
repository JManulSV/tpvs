<?php

namespace App\Domain\AI;

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
            $issues[] = $this->issue('record.week_end_date', 'INVERTED_WEEK_DATE_RANGE', 'The period end date is earlier than its start date; both dates require review.', $weekEndDate);
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
            $issues[] = $this->issue("details.{$index}.operation_number", 'OPERATION_NUMBER_MISSING', 'An operation number is not part of AI extraction and requires human review.');

            $affiliation = $this->safeText($this->fieldValue($detail, 'affiliation'), "details.{$index}.affiliation", $issues);
            $branch = $this->safeText($this->fieldValue($detail, 'branch'), "details.{$index}.branch", $issues);

            $record->details()->create([
                'operation_number' => null,
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
            $issues[] = $this->issue($path, 'TEXT_VALUE_TOO_LONG', 'The extracted text exceeds the database field limit.', $value);

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
            return [null, $this->issue('record.company_name', 'COMPANY_NAME_MISSING', 'A company name is missing and requires review.')];
        }

        $normalizedName = mb_strtolower(trim($companyName), 'UTF-8');
        $matches = Company::query()
            ->whereRaw('LOWER(BTRIM(name)) = ?', [$normalizedName])
            ->limit(2)
            ->get(['id']);

        if ($matches->count() === 1) {
            return [$matches->first()->id, null];
        }

        return [null, $this->issue(
            'record.company_name',
            $matches->isEmpty() ? 'COMPANY_NOT_FOUND' : 'COMPANY_AMBIGUOUS',
            $matches->isEmpty()
                ? 'No company matches the extracted name exactly; manual review is required.'
                : 'More than one company matches the extracted name; manual review is required.',
            $companyName,
        )];
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
                $totals[$field] = $this->addMoney($totals[$field], $detail->{$field});
            }
        }

        if (! $complete) {
            return $issues;
        }

        foreach ($totals as $field => $calculatedTotal) {
            $sourceTotal = $this->fieldValue($extraction['source_totals'], $field);
            if ($sourceTotal !== null && $sourceTotal !== $calculatedTotal) {
                $issues[] = $this->issue("source_totals.{$field}", 'SOURCE_TOTAL_MISMATCH', 'The printed source total differs from the total calculated from proposed movements.', $sourceTotal);
            }
        }

        return $issues;
    }

    private function addMoney(string $left, string $right): string
    {
        [$leftWhole, $leftFraction] = explode('.', $left, 2);
        [$rightWhole, $rightFraction] = explode('.', $right, 2);
        $cents = ((int) $leftWhole * 100) + (int) $leftFraction + ((int) $rightWhole * 100) + (int) $rightFraction;

        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @return array{path: string, code: string, severity: string, message: string, value?: mixed} */
    private function issue(string $path, string $code, string $message, mixed $value = null): array
    {
        $issue = ['path' => $path, 'code' => $code, 'severity' => 'review', 'message' => $message];
        if (func_num_args() >= 4) {
            $issue['value'] = $value;
        }

        return $issue;
    }
}
