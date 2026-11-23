<?php

namespace App\Http\Controllers\Api;

use App\Domain\Records\BusinessValidator;
use App\Domain\Records\Exceptions\RecordCancellationBlocked;
use App\Domain\Records\Exceptions\RecordConfirmationBlocked;
use App\Domain\Records\Exceptions\RecordUpdateBlocked;
use App\Domain\Records\RecordFilters;
use App\Domain\Records\RecordHistoryService;
use App\Domain\Records\RecordQuery;
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

        $record->loadResourceAggregates();

        return (new RecordResource($record))
            ->response()
            ->setStatusCode(201);
    }

    public function index(IndexRecordsRequest $request, RecordQuery $records): AnonymousResourceCollection
    {
        $validated = $request->validated();

        $page = $records
            ->paginate(RecordFilters::fromValidated($validated), $validated['per_page'] ?? 15)
            ->appends($validated);

        return RecordResource::collection($page);
    }

    public function update(
        UpdateRecordRequest $request,
        Record $record,
        BusinessValidator $businessValidator,
        RecordHistoryService $recordHistory,
    ): RecordResource {
        $result = DB::transaction(function () use ($request, $record, $businessValidator, $recordHistory): Record {
            $record = Record::findLockedOrFail($record->getKey());

            if (! in_array($record->status, [RecordStatus::NEEDS_REVIEW, RecordStatus::CONFIRMED], true)) {
                throw RecordUpdateBlocked::invalidState();
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
                throw RecordUpdateBlocked::noChanges();
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

        return new RecordResource($result->loadResourceAggregates());
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

        return new RecordResource($record->loadResourceAggregates());
    }

    public function confirm(
        ConfirmRecordRequest $request,
        Record $record,
        BusinessValidator $businessValidator,
    ): RecordResource {
        $result = DB::transaction(function () use ($request, $record, $businessValidator): Record {
            $record = Record::findLockedOrFail($record->getKey());

            if ($record->status !== RecordStatus::NEEDS_REVIEW) {
                throw RecordConfirmationBlocked::invalidState();
            }

            if ($record->source === RecordSource::IMAGE && ! $this->hasCompleteImageAggregate($record)) {
                throw RecordConfirmationBlocked::imageBusinessValidationFailed();
            }

            $record->load('details');
            try {
                $businessValidator->validatePersistedManualRecord($record);
            } catch (ValidationException) {
                throw RecordConfirmationBlocked::businessValidationFailed();
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

        return new RecordResource($result->loadResourceAggregates());
    }

    public function cancel(
        CancelRecordRequest $request,
        Record $record,
    ): RecordResource {
        $result = DB::transaction(function () use ($request, $record): Record {
            $record = Record::findLockedOrFail($record->getKey());

            if (! in_array($record->status, [RecordStatus::NEEDS_REVIEW, RecordStatus::ERROR], true)) {
                throw RecordCancellationBlocked::invalidState();
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

        return new RecordResource($result->loadResourceAggregates());
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
