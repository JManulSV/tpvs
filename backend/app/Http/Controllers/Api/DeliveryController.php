<?php

namespace App\Http\Controllers\Api;

use App\Domain\Deliveries\DeliveryHistoryService;
use App\Domain\Deliveries\DeliveryValidator;
use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deliveries\UpsertDeliveryRequest;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\Record;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class DeliveryController extends Controller
{
    public function show(Record $record, DeliveryValidator $validator): JsonResource|JsonResponse
    {
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
    ): JsonResource|JsonResponse {
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
        ): Delivery|JsonResponse {
            $record = Record::query()
                ->whereKey($record->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($record->status !== RecordStatus::CONFIRMED) {
                return response()->json([
                    'message' => 'The delivery cannot be prepared in the current record state.',
                    'code' => 'DELIVERY_PREPARATION_BLOCKED',
                    'details' => ['reason' => 'INVALID_RECORD_STATE'],
                ], 409);
            }

            $delivery = Delivery::query()
                ->where('record_id', $record->getKey())
                ->lockForUpdate()
                ->first();

            $currentReturnAmount = $validator->currentReturnAmount($record);
            $mismatch = $validator->cashMismatch($currentReturnAmount, $denominations);

            if ($mismatch !== null) {
                return response()->json([
                    'message' => 'The cash breakdown does not match the current return amount.',
                    'code' => 'DELIVERY_PREPARATION_BLOCKED',
                    'details' => [
                        'reason' => 'CASH_MISMATCH',
                        'expected_amount' => $mismatch['expected_amount'],
                        'cash_amount' => $mismatch['cash_amount'],
                        'difference' => $mismatch['difference'],
                    ],
                ], 409);
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

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return new DeliveryResource($this->decorate($result, $record, $validator));
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
