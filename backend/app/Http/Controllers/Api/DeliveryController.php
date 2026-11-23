<?php

namespace App\Http\Controllers\Api;

use App\Domain\Deliveries\Actions\PrepareDelivery;
use App\Domain\Deliveries\DeliveryReturnPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deliveries\UpsertDeliveryRequest;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\Record;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class DeliveryController extends Controller
{
    public function show(Record $record, DeliveryReturnPolicy $returnPolicy): JsonResource|JsonResponse
    {
        Gate::authorize('viewDelivery', $record);

        $delivery = Delivery::query()
            ->with('cashDenominations')
            ->where('record_id', $record->getKey())
            ->first();

        if ($delivery === null) {
            return response()->json(['data' => null]);
        }

        return new DeliveryResource($this->decorate($delivery, $record, $returnPolicy));
    }

    public function upsert(
        UpsertDeliveryRequest $request,
        Record $record,
        PrepareDelivery $prepareDelivery,
        DeliveryReturnPolicy $returnPolicy,
    ): JsonResponse {
        Gate::authorize('prepareDelivery', $record);

        $delivery = $prepareDelivery->handle($record, $request->validated(), $request->user()->id);

        return (new DeliveryResource($this->decorate($delivery, $record, $returnPolicy)))
            ->response()
            ->setStatusCode(200);
    }

    private function decorate(Delivery $delivery, Record $record, DeliveryReturnPolicy $returnPolicy): Delivery
    {
        $currentReturnAmount = $returnPolicy->currentReturnAmount($record);

        $delivery->setAttribute('current_return_amount', $currentReturnAmount);
        $delivery->setAttribute(
            'is_stale',
            $returnPolicy->isStale($delivery->prepared_return_amount, $currentReturnAmount),
        );
        $delivery->load('cashDenominations');

        return $delivery;
    }
}
