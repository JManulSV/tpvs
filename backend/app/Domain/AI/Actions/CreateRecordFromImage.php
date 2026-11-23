<?php

namespace App\Domain\AI\Actions;

use App\Domain\AI\AiJobDispatcher;
use App\Domain\Images\RecordImageStorage;
use App\Domain\Records\RecordHistoryService;
use App\Enums\AiProcessingStatus;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Models\Image;
use App\Models\Record;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Create a PENDING image Record from an already stored file and queue its
 * extraction. If the rows cannot be created, the staged file is deleted.
 */
class CreateRecordFromImage
{
    public function __construct(
        private readonly RecordImageStorage $imageStorage,
        private readonly RecordHistoryService $history,
        private readonly AiJobDispatcher $dispatcher,
    ) {}

    /**
     * @param  array{file_path: string, original_file_name: string, mime_type: string, file_size: int}  $stored
     */
    public function handle(array $stored, int $userId): Record
    {
        try {
            [$record, $processing] = DB::transaction(function () use ($stored, $userId): array {
                $record = Record::query()->create([
                    'company_id' => null,
                    'source' => RecordSource::IMAGE,
                    'status' => RecordStatus::PENDING,
                    'year' => null,
                    'week' => null,
                    'week_start_date' => null,
                    'week_end_date' => null,
                ]);

                $image = new Image($stored);
                $image->record()->associate($record);
                $image->save();

                $processing = $image->aiProcessing()->create([
                    'provider' => config('ai.provider'),
                    'model' => config('ai.ollama.model'),
                    'status' => AiProcessingStatus::PENDING,
                ]);

                $this->history->writeRecordCreated(
                    $record,
                    $userId,
                    RecordStatus::PENDING,
                    'Registro creado desde imagen',
                );

                return [$record, $processing];
            });
        } catch (Throwable $exception) {
            $this->imageStorage->deleteQuietly($stored['file_path']);

            throw $exception;
        }

        $this->dispatcher->dispatch($record, $processing);

        return $record->refresh();
    }
}
