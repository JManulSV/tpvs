<?php

namespace App\Http\Controllers\Api;

use App\Domain\Records\Actions\CancelRecord;
use App\Domain\Records\Actions\ConfirmRecord;
use App\Domain\Records\Actions\CreateManualRecord;
use App\Domain\Records\Actions\UpdateRecord;
use App\Domain\Records\RecordFilters;
use App\Domain\Records\RecordQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\CancelRecordRequest;
use App\Http\Requests\Records\ConfirmRecordRequest;
use App\Http\Requests\Records\IndexRecordHistoryRequest;
use App\Http\Requests\Records\IndexRecordsRequest;
use App\Http\Requests\Records\StoreRecordRequest;
use App\Http\Requests\Records\UpdateRecordRequest;
use App\Http\Resources\HistoryResource;
use App\Http\Resources\RecordResource;
use App\Models\History;
use App\Models\Record;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class RecordController extends Controller
{
    public function store(StoreRecordRequest $request, CreateManualRecord $createManualRecord): JsonResponse
    {
        $record = $createManualRecord->handle($request->validated(), $request->user()->id);

        return (new RecordResource($record->loadResourceAggregates()))
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

    public function update(UpdateRecordRequest $request, Record $record, UpdateRecord $updateRecord): RecordResource
    {
        $record = $updateRecord->handle($record, $request->validated(), $request->user()->id);

        return new RecordResource($record->loadResourceAggregates());
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

    public function confirm(ConfirmRecordRequest $request, Record $record, ConfirmRecord $confirmRecord): RecordResource
    {
        $record = $confirmRecord->handle($record, $request->user()->id);

        return new RecordResource($record->loadResourceAggregates());
    }

    public function cancel(CancelRecordRequest $request, Record $record, CancelRecord $cancelRecord): RecordResource
    {
        $record = $cancelRecord->handle($record, $request->user()->id, $request->validated('reason'));

        return new RecordResource($record->loadResourceAggregates());
    }
}
