<?php

namespace App\Domain\Records\Actions;

use App\Domain\Records\BusinessValidator;
use App\Domain\Records\Exceptions\RecordConfirmationBlocked;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Models\Record;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Only a human confirms, and only a NEEDS_REVIEW record whose business data revalidates. */
class ConfirmRecord
{
    public function __construct(private readonly BusinessValidator $businessValidator) {}

    public function handle(Record $record, int $userId): Record
    {
        return DB::transaction(function () use ($record, $userId): Record {
            $record = Record::findLockedOrFail($record->getKey());

            if ($record->status !== RecordStatus::NEEDS_REVIEW) {
                throw RecordConfirmationBlocked::invalidState();
            }

            if ($record->source === RecordSource::IMAGE && ! $this->hasCompleteImageAggregate($record)) {
                throw RecordConfirmationBlocked::imageBusinessValidationFailed();
            }

            $record->load('details');
            try {
                $this->businessValidator->validatePersistedManualRecord($record);
            } catch (ValidationException) {
                throw RecordConfirmationBlocked::businessValidationFailed();
            }

            $record->status = RecordStatus::CONFIRMED;
            $record->save();
            $record->histories()->create([
                'user_id' => $userId,
                'field_name' => 'status',
                'previous_value' => RecordStatus::NEEDS_REVIEW->value,
                'new_value' => RecordStatus::CONFIRMED->value,
                'reason' => $record->source === RecordSource::IMAGE
                    ? 'Registro de imagen confirmado'
                    : 'Registro manual confirmado',
            ]);

            return $record;
        });
    }

    private function hasCompleteImageAggregate(Record $record): bool
    {
        if ($record->company_id === null
            || $record->year === null
            || $record->week === null
            || $record->week_start_date === null
            || $record->week_end_date === null
            || $record->image()->doesntExist()) {
            return false;
        }

        $record->loadMissing('details');
        if ($record->details->isEmpty()) {
            return false;
        }

        foreach ($record->details as $detail) {
            if ($detail->affiliation === null
                || $detail->branch === null
                || $detail->income === null
                || $detail->commission === null
                || $detail->return_amount === null) {
                return false;
            }
        }

        return true;
    }
}
