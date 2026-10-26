<?php

namespace App\Http\Controllers\Api;

use App\Domain\Records\BusinessValidator;
use App\Domain\Records\RecordHistoryService;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\CancelRecordRequest;
use App\Http\Requests\Records\ConfirmRecordRequest;
use App\Http\Requests\Records\IndexRecordHistoryRequest;
use App\Http\Requests\Records\IndexRecordsRequest;
use App\Http\Requests\Records\StoreRecordRequest;
use App\Http\Requests\Records\UpdateRecordRequest;
use App\Http\Resources\HistoryResource;
use App\Http\Resources\RecordResource;
use App\Models\Company;
use App\Models\History;
use App\Models\Record;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecordController extends Controller
{
    public function store(
        StoreRecordRequest $request,
        BusinessValidator $businessValidator,
        RecordHistoryService $recordHistory,
    ): JsonResponse {
        $validated = $request->validated();
        $details = $businessValidator->validateAndCalculateManualDetails($validated);
        $userId = $request->user()->id;

        $record = DB::transaction(function () use ($validated, $details, $userId, $recordHistory): Record {
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
            $recordHistory->writeRecordCreated(
                $record,
                $userId,
                RecordStatus::CONFIRMED,
                'Registro creado manualmente',
            );

            return $record;
        });

        $record->load(['company', 'details', 'image.aiProcessing']);
        $record->loadSum('details as income_total', 'income');
        $record->loadSum('details as commission_total', 'commission');
        $record->loadSum('details as return_total', 'return_amount');

        return (new RecordResource($record))
            ->response()
            ->setStatusCode(201);
    }

    public function index(IndexRecordsRequest $request)
    {
        $filters = $request->validated();
        $search = $filters['search'] ?? null;

        $records = Record::query()
            ->with(['company', 'details', 'image.aiProcessing'])
            ->withSum('details as income_total', 'income')
            ->withSum('details as commission_total', 'commission')
            ->withSum('details as return_total', 'return_amount')
            ->when(isset($filters['company_id']), static fn ($query) => $query
                ->where('company_id', $filters['company_id']))
            ->when(isset($filters['year']), static fn ($query) => $query
                ->where('year', $filters['year']))
            ->when(isset($filters['month']), static fn ($query) => $query
                ->whereMonth('week_end_date', $filters['month']))
            ->when(isset($filters['week']), static fn ($query) => $query
                ->where('week', $filters['week']))
            ->when(isset($filters['status']), static fn ($query) => $query
                ->where('status', $filters['status']))
            ->when(isset($filters['source']), static fn ($query) => $query
                ->where('source', $filters['source']))
            ->when($search !== null && $search !== '', static function ($query) use ($search): void {
                $term = '%'.mb_strtolower($search).'%';

                $query->where(static function ($query) use ($term): void {
                    $query->whereHas('company', static fn ($companyQuery) => $companyQuery
                        ->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('details', static fn ($detailsQuery) => $detailsQuery
                            ->whereRaw('LOWER(affiliation) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(branch) LIKE ?', [$term]));
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15)
            ->appends($filters);

        return RecordResource::collection($records);
    }

    public function update(
        UpdateRecordRequest $request,
        Record $record,
        BusinessValidator $businessValidator,
        RecordHistoryService $recordHistory,
    ): RecordResource|JsonResponse {
        $result = DB::transaction(function () use ($request, $record, $businessValidator, $recordHistory): Record|JsonResponse {
            $record = Record::query()
                ->whereKey($record->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($record->status, [RecordStatus::NEEDS_REVIEW, RecordStatus::CONFIRMED], true)) {
                return response()->json([
                    'message' => 'The record cannot be updated in its current state.',
                    'code' => 'RECORD_UPDATE_BLOCKED',
                    'details' => ['reason' => 'INVALID_STATE'],
                ], 409);
            }

            $wasConfirmed = $record->status === RecordStatus::CONFIRMED;
            $userId = $request->user()->id;
            $before = $recordHistory->snapshot($record);

            $validated = $request->validated();
            $details = $businessValidator->validateAndCalculateManualDetails($validated);
            $companyName = Company::query()->whereKey($validated['company_id'])->value('name');
            $after = $recordHistory->snapshotFromValidated($validated, $details, $companyName);
            $changes = $recordHistory->diff($before, $after);

            if ($wasConfirmed && $changes === []) {
                return response()->json([
                    'message' => 'The record cannot be updated without changes.',
                    'code' => 'RECORD_UPDATE_NO_CHANGES',
                    'details' => ['reason' => 'NO_CHANGES'],
                ], 422);
            }

            $attributes = [
                'company_id' => $validated['company_id'],
                'year' => $validated['year'],
                'week' => $validated['week'],
                'week_start_date' => CarbonImmutable::parse($validated['week_start_date'])->toDateString(),
                'week_end_date' => CarbonImmutable::parse($validated['week_end_date'])->toDateString(),
            ];

            if ($wasConfirmed) {
                $attributes['status'] = RecordStatus::NEEDS_REVIEW;
            }

            $record->update($attributes);
            $record->details()->delete();
            $record->details()->createMany($details);

            if ($wasConfirmed) {
                $recordHistory->writeFieldChanges($record, $before, $after, $userId, $validated['reason']);
                $recordHistory->writeStatusChange(
                    $record,
                    RecordStatus::CONFIRMED,
                    RecordStatus::NEEDS_REVIEW,
                    $userId,
                    $validated['reason'],
                );
            } elseif ($changes === []) {
                $recordHistory->writeRecordUpdated($record, $userId, $validated['reason']);
            } else {
                $recordHistory->writeFieldChanges($record, $before, $after, $userId, $validated['reason']);
            }

            return $record;
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $result->load(['company', 'details', 'image.aiProcessing']);
        $result->loadSum('details as income_total', 'income');
        $result->loadSum('details as commission_total', 'commission');
        $result->loadSum('details as return_total', 'return_amount');

        return new RecordResource($result);
    }

    public function history(IndexRecordHistoryRequest $request, Record $record): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $history = History::query()
            ->with('user')
            ->where('record_id', $record->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50)
            ->appends($filters);

        return HistoryResource::collection($history);
    }

    public function show(Record $record): RecordResource
    {
        Gate::authorize('view', $record);

        $record->load(['company', 'details', 'image.aiProcessing']);
        $record->loadSum('details as income_total', 'income');
        $record->loadSum('details as commission_total', 'commission');
        $record->loadSum('details as return_total', 'return_amount');

        return new RecordResource($record);
    }

    public function confirm(
        ConfirmRecordRequest $request,
        Record $record,
        BusinessValidator $businessValidator,
    ): RecordResource|JsonResponse {
        $result = DB::transaction(function () use ($request, $record, $businessValidator): Record|JsonResponse {
            $record = Record::query()
                ->whereKey($record->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($record->status !== RecordStatus::NEEDS_REVIEW) {
                return response()->json([
                    'message' => 'The record cannot be confirmed in its current state.',
                    'code' => 'RECORD_CONFIRMATION_BLOCKED',
                    'details' => ['reason' => 'INVALID_STATE'],
                ], 409);
            }

            if ($record->source === RecordSource::IMAGE && ! $this->hasCompleteImageAggregate($record)) {
                return response()->json([
                    'message' => 'The image record cannot be confirmed until its business data is valid.',
                    'code' => 'RECORD_CONFIRMATION_BLOCKED',
                    'details' => ['reason' => 'BUSINESS_VALIDATION_FAILED'],
                ], 409);
            }

            $record->load('details');
            try {
                $businessValidator->validatePersistedManualRecord($record);
            } catch (ValidationException) {
                return response()->json([
                    'message' => 'The record cannot be confirmed until its business data is valid.',
                    'code' => 'RECORD_CONFIRMATION_BLOCKED',
                    'details' => ['reason' => 'BUSINESS_VALIDATION_FAILED'],
                ], 409);
            }

            $record->status = RecordStatus::CONFIRMED;
            $record->save();
            $record->histories()->create([
                'user_id' => $request->user()->id,
                'field_name' => 'status',
                'previous_value' => RecordStatus::NEEDS_REVIEW->value,
                'new_value' => RecordStatus::CONFIRMED->value,
                'reason' => $record->source === RecordSource::IMAGE
                    ? 'Registro de imagen confirmado'
                    : 'Registro manual confirmado',
            ]);

            return $record;
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $result->load(['company', 'details', 'image.aiProcessing']);
        $result->loadSum('details as income_total', 'income');
        $result->loadSum('details as commission_total', 'commission');
        $result->loadSum('details as return_total', 'return_amount');

        return new RecordResource($result);
    }

    public function cancel(
        CancelRecordRequest $request,
        Record $record,
    ): RecordResource|JsonResponse {
        $result = DB::transaction(function () use ($request, $record): Record|JsonResponse {
            $record = Record::query()
                ->whereKey($record->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($record->status, [RecordStatus::NEEDS_REVIEW, RecordStatus::ERROR], true)) {
                return response()->json([
                    'message' => 'The record cannot be canceled in its current state.',
                    'code' => 'RECORD_CANCELLATION_BLOCKED',
                    'details' => ['reason' => 'INVALID_STATE'],
                ], 409);
            }

            $previousStatus = $record->status->value;
            $record->status = RecordStatus::CANCELED;
            $record->save();

            $record->histories()->create([
                'user_id' => $request->user()->id,
                'field_name' => 'status',
                'previous_value' => $previousStatus,
                'new_value' => RecordStatus::CANCELED->value,
                'reason' => $request->validated('reason'),
            ]);

            return $record;
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $result->load(['company', 'details', 'image.aiProcessing']);
        $result->loadSum('details as income_total', 'income');
        $result->loadSum('details as commission_total', 'commission');
        $result->loadSum('details as return_total', 'return_amount');

        return new RecordResource($result);
    }

    private function hasCompleteImageAggregate(Record $record): bool
    {
        if ($record->company_id === null
            || $record->year === null
            || $record->week === null
            || $record->week_start_date === null
            || $record->week_end_date === null
            || $record->image()->doesntExist()) {
            return false;
        }

        $record->loadMissing('details');
        if ($record->details->isEmpty()) {
            return false;
        }

        foreach ($record->details as $detail) {
            if ($detail->affiliation === null
                || $detail->branch === null
                || $detail->income === null
                || $detail->commission === null
                || $detail->return_amount === null) {
                return false;
            }
        }

        return true;
    }
}
