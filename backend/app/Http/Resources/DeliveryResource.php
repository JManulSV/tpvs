<?php

namespace App\Http\Resources;

use App\Domain\Deliveries\DeliveryValidator;
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
        $validator = app(DeliveryValidator::class);
        $currentReturnAmount = $this->current_return_amount !== null
            ? (string) $this->current_return_amount
            : $validator->currentReturnAmount($this->record);
        $isStale = $this->is_stale !== null
            ? (bool) $this->is_stale
            : $validator->isStale($this->prepared_return_amount, $currentReturnAmount);

        return [
            'id' => $this->getKey(),
            'record_id' => $this->record_id,
            'delivery_date' => $this->delivery_date?->toDateString(),
            'delivered_by_name' => $this->delivered_by_name,
            'received_by_name' => $this->received_by_name,
            'prepared_return_amount' => $this->prepared_return_amount,
            'current_return_amount' => $currentReturnAmount,
            'is_stale' => $isStale,
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
