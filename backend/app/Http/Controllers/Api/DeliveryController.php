<?php

namespace App\Http\Controllers\Api;

use App\Domain\Deliveries\DeliveryHistoryService;
use App\Domain\Deliveries\DeliveryValidator;
use App\Domain\Deliveries\Exceptions\DeliveryPreparationBlocked;
use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deliveries\UpsertDeliveryRequest;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\Record;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeliveryController extends Controller
{
    public function show(Record $record, DeliveryValidator $validator): JsonResource|JsonResponse
    {
        Gate::authorize('viewDelivery', $record);

        $delivery = Delivery::query()
            ->with('cashDenominations')
            ->where('record_id', $record->getKey())
            ->first();

        if ($delivery === null) {
            return response()->json(['data' => null]);
        }

        return new DeliveryResource($this->decorate($delivery, $record, $validator));
    }

    public function upsert(
        UpsertDeliveryRequest $request,
        Record $record,
        DeliveryValidator $validator,
        DeliveryHistoryService $history,
    ): JsonResponse {
        Gate::authorize('prepareDelivery', $record);

        $validated = $request->validated();
        $denominations = $validator->canonicalizeDenominations($validated['denominations']);
        $userId = $request->user()->id;

        $result = DB::transaction(function () use (
            $record,
            $validated,
            $denominations,
            $validator,
            $history,
            $userId,
        ): Delivery {
            $record = Record::query()
                ->whereKey($record->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($record->status !== RecordStatus::CONFIRMED) {
                throw DeliveryPreparationBlocked::invalidRecordState();
            }

            $delivery = Delivery::query()
                ->where('record_id', $record->getKey())
                ->lockForUpdate()
                ->first();

            $currentReturnAmount = $validator->currentReturnAmount($record);
            $mismatch = $validator->preparationCashShortfall($currentReturnAmount, $denominations);

            if ($mismatch !== null) {
                throw DeliveryPreparationBlocked::cashMismatch(
                    $mismatch['expected_amount'],
                    $mismatch['cash_amount'],
                    $mismatch['difference'],
                );
            }

            $attributes = [
                'delivery_date' => $validator->canonicalDate($validated['delivery_date']),
                'delivered_by_name' => $validator->canonicalName($validated['delivered_by_name']),
                'received_by_name' => $validator->canonicalName($validated['received_by_name'] ?? null),
                'prepared_return_amount' => $currentReturnAmount,
            ];

            if ($delivery === null) {
                $delivery = $record->delivery()->create($attributes);
                $delivery->cashDenominations()->createMany($denominations);

                $history->writeFieldChanges(
                    $delivery,
                    [],
                    $history->snapshot($delivery),
                    $userId,
                    'Preparación de entrega creada',
                );

                return $delivery;
            }

            $before = $history->snapshot($delivery);
            $after = $history->snapshotFromValues(
                $attributes['delivery_date'],
                $attributes['delivered_by_name'],
                $attributes['received_by_name'],
                $attributes['prepared_return_amount'],
                $denominations,
            );

            if ($history->diff($before, $after) === []) {
                return $delivery;
            }

            $delivery->update($attributes);
            $delivery->cashDenominations()->delete();
            $delivery->cashDenominations()->createMany($denominations);

            $history->writeFieldChanges(
                $delivery,
                $before,
                $history->snapshot($delivery),
                $userId,
                'Preparación de entrega actualizada',
            );

            return $delivery;
        });

        return (new DeliveryResource($this->decorate($result, $record, $validator)))
            ->response()
            ->setStatusCode(200);
    }

    private function decorate(Delivery $delivery, Record $record, DeliveryValidator $validator): Delivery
    {
        $currentReturnAmount = $validator->currentReturnAmount($record);

        $delivery->setAttribute('current_return_amount', $currentReturnAmount);
        $delivery->setAttribute(
            'is_stale',
            $validator->isStale($delivery->prepared_return_amount, $currentReturnAmount),
        );
        $delivery->load('cashDenominations');

        return $delivery;
    }
}
