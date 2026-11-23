<?php

namespace App\Domain\Images\Actions;

use App\Domain\Images\Exceptions\RecordImageBlocked;
use App\Domain\Images\RecordImageStorage;
use App\Enums\RecordStatus;
use App\Models\Image;
use App\Models\Record;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Attach an image to a Record, or replace its current one while no AI processing
 * exists. The new file is stored first; if the rows cannot be written it is
 * deleted, and after a replacement the previous file is deleted.
 */
class ReplaceRecordImage
{
    public function __construct(private readonly RecordImageStorage $imageStorage) {}

    /**
     * @return array{image: Image, replaced: bool}
     */
    public function handle(Record $record, UploadedFile $file): array
    {
        // Checked before storing so a canceled Record never receives a file; re-checked under the lock.
        if ($record->status === RecordStatus::CANCELED) {
            throw RecordImageBlocked::uploadForCanceledRecord();
        }

        $stored = $this->imageStorage->store($file);
        $previousPath = null;
        $replaced = false;

        try {
            $image = DB::transaction(function () use ($record, $stored, &$previousPath, &$replaced): Image {
                $lockedRecord = Record::findLockedOrFail($record->getKey());

                if ($lockedRecord->status === RecordStatus::CANCELED) {
                    throw RecordImageBlocked::uploadForCanceledRecord();
                }

                $image = $lockedRecord->image()->lockForUpdate()->first();
                if ($image !== null && $image->aiProcessing()->exists()) {
                    throw RecordImageBlocked::replacementAfterAiProcessing();
                }

                $replaced = $image !== null;
                $previousPath = $image?->file_path;

                if ($image === null) {
                    $image = new Image;
                    $image->record()->associate($lockedRecord);
                }

                $image->fill($stored);
                $image->save();

                return $image;
            });
        } catch (Throwable $exception) {
            $this->imageStorage->deleteQuietly($stored['file_path']);

            throw $exception;
        }

        if ($previousPath !== null && $previousPath !== $stored['file_path']) {
            $this->imageStorage->deleteQuietly($previousPath);
        }

        return ['image' => $image, 'replaced' => $replaced];
    }
}
