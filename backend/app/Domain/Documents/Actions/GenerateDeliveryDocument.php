<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Deliveries\DeliveryHistoryService;
use App\Domain\Documents\DeliveryDocumentBuilder;
use App\Domain\Documents\DeliveryDocumentEligibility;
use App\Domain\Shared\Exceptions\DomainRuleException;
use App\Models\Delivery;
use App\Models\Record;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Build the Word receipt while holding the Record and Delivery locks, and log it
 * in the delivery history. The caller owns the returned file and must delete it.
 */
class GenerateDeliveryDocument
{
    public function __construct(
        private readonly DeliveryDocumentEligibility $eligibility,
        private readonly DeliveryDocumentBuilder $builder,
        private readonly DeliveryHistoryService $history,
    ) {}

    /**
     * @return array{path: string, record: Record} the temporary .docx and the locked Record it was built from
     */
    public function handle(
        Delivery $delivery,
        Record $record,
        string $documentReturnAmount,
        bool $customerWillGiveChange,
        int $userId,
    ): array {
        $documentPath = null;

        try {
            DB::transaction(function () use ($delivery, $documentReturnAmount, $customerWillGiveChange, $userId, &$documentPath, &$record): void {
                $record = Record::findLockedOrFail($record->getKey());
                $locked = Delivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();

                $imagePath = $this->eligibility->check($locked, $record, $documentReturnAmount, $customerWillGiveChange);
                $documentPath = $this->builder->build($locked, $imagePath, $documentReturnAmount, $customerWillGiveChange);

                $this->history->writeDocumentGenerated($locked, $userId);
            });
        } catch (Throwable $exception) {
            if ($documentPath !== null && ! $exception instanceof DomainRuleException && ! $exception instanceof ValidationException) {
                @unlink($documentPath);
            }

            throw $exception;
        }

        return ['path' => $documentPath, 'record' => $record];
    }
}
