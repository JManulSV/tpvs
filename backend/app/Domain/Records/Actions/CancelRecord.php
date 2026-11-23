<?php

namespace App\Domain\Records\Actions;

use App\Domain\Records\Exceptions\RecordCancellationBlocked;
use App\Enums\RecordStatus;
use App\Models\Record;
use Illuminate\Support\Facades\DB;

/** Cancel a record that never reached CONFIRMED: NEEDS_REVIEW or ERROR. */
class CancelRecord
{
    public function handle(Record $record, int $userId, string $reason): Record
    {
        return DB::transaction(function () use ($record, $userId, $reason): Record {
            $record = Record::findLockedOrFail($record->getKey());

            if (! in_array($record->status, [RecordStatus::NEEDS_REVIEW, RecordStatus::ERROR], true)) {
                throw RecordCancellationBlocked::invalidState();
            }

            $previousStatus = $record->status->value;
            $record->status = RecordStatus::CANCELED;
            $record->save();

            $record->histories()->create([
                'user_id' => $userId,
                'field_name' => 'status',
                'previous_value' => $previousStatus,
                'new_value' => RecordStatus::CANCELED->value,
                'reason' => $reason,
            ]);

            return $record;
        });
    }
}
