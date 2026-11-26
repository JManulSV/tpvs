<?php

namespace App\Http\Resources;

use App\Domain\Deliveries\DeliveryReturnPolicy;
use App\Domain\Deliveries\DeliverySplit;
use App\Models\Delivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Delivery */
class DeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $returnPolicy = app(DeliveryReturnPolicy::class);
        $currentReturnAmount = $this->current_return_amount !== null
            ? (string) $this->current_return_amount
            : $returnPolicy->currentReturnAmount($this->record);
        $isStale = $this->is_stale !== null
            ? (bool) $this->is_stale
            : $returnPolicy->isStale($this->prepared_return_amount, $currentReturnAmount);
        // The transfer is read against the stored official return, so a stale delivery keeps its own split.
        $split = DeliverySplit::calculate(
            (string) $this->prepared_return_amount,
            (string) $this->requested_cash_amount,
            (string) $this->cash_commission_percentage,
        );

        return [
            'id' => $this->getKey(),
            'record_id' => $this->record_id,
            'delivery_date' => $this->delivery_date?->toDateString(),
            'delivered_by_name' => $this->delivered_by_name,
            'received_by_name' => $this->received_by_name,
            'prepared_return_amount' => $this->prepared_return_amount,
            'current_return_amount' => $currentReturnAmount,
            'is_stale' => $isStale,
            'requested_cash_amount' => $this->requested_cash_amount,
            'transfer_amount' => $split->transferAmount,
            'cash_commission_percentage' => $this->cash_commission_percentage,
            'cash_commission' => $this->cash_commission,
            'cash_amount' => $this->cash_amount,
            'denominations' => $this->cashDenominations
                ->sortByDesc(static fn ($denomination): float => (float) $denomination->value)
                ->values()
                ->map(static fn ($denomination): array => [
                    'value' => $denomination->value,
                    'quantity' => (int) $denomination->quantity,
                ])
                ->all(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
