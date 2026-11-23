<?php

namespace App\Domain\Records\Actions;

use App\Domain\Records\BusinessValidator;
use App\Domain\Records\Exceptions\RecordUpdateBlocked;
use App\Domain\Records\RecordHistoryService;
use App\Enums\RecordStatus;
use App\Models\Company;
use App\Models\Record;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Replace a record's header and movements. Editing a CONFIRMED record sends it
 * back to NEEDS_REVIEW and requires at least one real change.
 */
class UpdateRecord
{
    public function __construct(
        private readonly BusinessValidator $businessValidator,
        private readonly RecordHistoryService $recordHistory,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(Record $record, array $validated, int $userId): Record
    {
        return DB::transaction(function () use ($record, $validated, $userId): Record {
            $record = Record::findLockedOrFail($record->getKey());

            if (! in_array($record->status, [RecordStatus::NEEDS_REVIEW, RecordStatus::CONFIRMED], true)) {
                throw RecordUpdateBlocked::invalidState();
            }

            $wasConfirmed = $record->status === RecordStatus::CONFIRMED;
            $before = $this->recordHistory->snapshot($record);

            $details = $this->businessValidator->validateAndCalculateManualDetails($validated);
            $companyName = Company::query()->whereKey($validated['company_id'])->value('name');
            $after = $this->recordHistory->snapshotFromValidated($validated, $details, $companyName);
            $changes = $this->recordHistory->diff($before, $after);

            if ($wasConfirmed && $changes === []) {
                throw RecordUpdateBlocked::noChanges();
            }

            $attributes = [
                'company_id' => $validated['company_id'],
                'year' => $validated['year'],
                'week' => $validated['week'],
                'week_start_date' => CarbonImmutable::parse($validated['week_start_date'])->toDateString(),
                'week_end_date' => CarbonImmutable::parse($validated['week_end_date'])->toDateString(),
            ];

            if ($wasConfirmed) {
                $attributes['status'] = RecordStatus::NEEDS_REVIEW;
            }

            $record->update($attributes);
            $record->details()->delete();
            $record->details()->createMany($details);

            if ($wasConfirmed) {
                $this->recordHistory->writeFieldChanges($record, $before, $after, $userId, $validated['reason']);
                $this->recordHistory->writeStatusChange(
                    $record,
                    RecordStatus::CONFIRMED,
                    RecordStatus::NEEDS_REVIEW,
                    $userId,
                    $validated['reason'],
                );
            } elseif ($changes === []) {
                $this->recordHistory->writeRecordUpdated($record, $userId, $validated['reason']);
            } else {
                $this->recordHistory->writeFieldChanges($record, $before, $after, $userId, $validated['reason']);
            }

            return $record;
        });
    }
}
