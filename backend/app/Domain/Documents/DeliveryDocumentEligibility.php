<?php

namespace App\Domain\Documents;

use App\Domain\Deliveries\DeliveryValidator;
use App\Domain\Documents\Exceptions\DocumentGenerationBlockedException as Blocked;
use App\Enums\RecordStatus;
use App\Models\Delivery;
use App\Models\Record;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DeliveryDocumentEligibility
{
    private const IMAGE_DISK = 'record_images';

    public function __construct(private readonly DeliveryValidator $validator) {}

    /**
     * Check that the Delivery can produce a Word document and return the
     * absolute path of the Record image to embed. The caller must hold the
     * Record and Delivery locks so the result cannot change before use.
     *
     * @throws Blocked
     */
    public function check(Delivery $delivery, Record $record): string
    {
        if ($record->status !== RecordStatus::CONFIRMED) {
            throw new Blocked(Blocked::INVALID_RECORD_STATE);
        }

        $image = $record->image()->first();

        if ($image === null) {
            throw new Blocked(Blocked::MISSING_IMAGE);
        }

        $currentAmount = $this->validator->currentReturnAmount($record);
        $preparedAmount = (string) $delivery->prepared_return_amount;

        if ($this->validator->isStale($preparedAmount, $currentAmount)) {
            throw new Blocked(Blocked::STALE_DELIVERY, [
                'prepared_amount' => $this->validator->canonicalAmount($preparedAmount) ?? '0.00',
                'current_amount' => $currentAmount,
            ]);
        }

        $denominations = $delivery->cashDenominations()
            ->get(['value', 'quantity'])
            ->map(fn ($row): array => ['value' => (string) $row->value, 'quantity' => (int) $row->quantity])
            ->all();

        $mismatch = $this->validator->cashMismatch($currentAmount, $denominations);

        if ($mismatch !== null) {
            throw new Blocked(Blocked::CASH_MISMATCH, $mismatch);
        }

        return $this->readableImagePath($image->getKey(), (string) $image->file_path);
    }

    private function readableImagePath(int $imageId, string $storageKey): string
    {
        $disk = Storage::disk(self::IMAGE_DISK);

        try {
            $path = $storageKey !== '' && $disk->exists($storageKey) ? $disk->path($storageKey) : null;
        } catch (\Throwable $exception) {
            report($exception);
            $path = null;
        }

        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            Log::warning('Record image file is unavailable for document generation.', ['image_id' => $imageId]);

            throw new Blocked(Blocked::IMAGE_UNAVAILABLE);
        }

        return $path;
    }
}
