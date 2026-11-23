<?php

namespace App\Domain\Records\Actions;

use App\Domain\Records\BusinessValidator;
use App\Domain\Records\RecordHistoryService;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Models\Record;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** A manual record is born CONFIRMED: an EDITOR typed and validated every value. */
class CreateManualRecord
{
    public function __construct(
        private readonly BusinessValidator $businessValidator,
        private readonly RecordHistoryService $recordHistory,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(array $validated, int $userId): Record
    {
        $details = $this->businessValidator->validateAndCalculateManualDetails($validated);

        return DB::transaction(function () use ($validated, $details, $userId): Record {
            $record = Record::query()->create([
                'company_id' => $validated['company_id'],
                'source' => RecordSource::MANUAL,
                'status' => RecordStatus::CONFIRMED,
                'year' => $validated['year'],
                'week' => $validated['week'],
                'week_start_date' => CarbonImmutable::parse($validated['week_start_date'])->toDateString(),
                'week_end_date' => CarbonImmutable::parse($validated['week_end_date'])->toDateString(),
            ]);

            $record->details()->createMany($details);
            $this->recordHistory->writeRecordCreated(
                $record,
                $userId,
                RecordStatus::CONFIRMED,
                'Registro creado manualmente',
            );

            return $record;
        });
    }
}
