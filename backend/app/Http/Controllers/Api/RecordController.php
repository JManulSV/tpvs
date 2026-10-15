<?php

namespace App\Http\Controllers\Api;

use App\Domain\Records\BusinessValidator;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\IndexRecordsRequest;
use App\Http\Requests\Records\StoreRecordRequest;
use App\Http\Requests\Records\UpdateRecordRequest;
use App\Http\Resources\RecordResource;
use App\Models\Record;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RecordController extends Controller
{
    public function store(StoreRecordRequest $request, BusinessValidator $businessValidator): JsonResponse
    {
        $validated = $request->validated();
        $details = $businessValidator->validateAndCalculateManualDetails($validated);

        $record = DB::transaction(function () use ($validated, $details): Record {
            $record = Record::query()->create([
                'company_id' => $validated['company_id'],
                'source' => RecordSource::MANUAL,
                'status' => RecordStatus::NEEDS_REVIEW,
                'year' => $validated['year'],
                'week' => $validated['week'],
                'week_start_date' => $validated['week_start_date'],
                'week_end_date' => $validated['week_end_date'],
                'affiliation' => $validated['affiliation'],
                'branch' => $validated['branch'],
            ]);

            $record->details()->createMany($details);

            return $record;
        });

        $record->load(['company', 'details']);
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
            ->with(['company', 'details'])
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
                        ->orWhereRaw('LOWER(affiliation) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(branch) LIKE ?', [$term])
                        ->orWhereHas('details', static fn ($detailsQuery) => $detailsQuery
                            ->whereRaw('LOWER(operation_number) LIKE ?', [$term]));
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
    ): RecordResource|JsonResponse {
        if ($record->source !== RecordSource::MANUAL || $record->status !== RecordStatus::NEEDS_REVIEW) {
            return response()->json([
                'message' => 'The record cannot be updated in its current state.',
                'code' => 'RECORD_UPDATE_BLOCKED',
                'details' => [
                    'reason' => $record->source !== RecordSource::MANUAL ? 'INVALID_SOURCE' : 'INVALID_STATE',
                ],
            ], 409);
        }

        $validated = $request->validated();
        $details = $businessValidator->validateAndCalculateManualDetails($validated);

        $record = DB::transaction(function () use ($request, $record, $validated, $details): Record {
            $record->update([
                'company_id' => $validated['company_id'],
                'year' => $validated['year'],
                'week' => $validated['week'],
                'week_start_date' => $validated['week_start_date'],
                'week_end_date' => $validated['week_end_date'],
                'affiliation' => $validated['affiliation'],
                'branch' => $validated['branch'],
            ]);

            $record->details()->delete();
            $record->details()->createMany($details);

            $record->histories()->create([
                'user_id' => $request->user()->id,
                'field_name' => 'manual_record_updated',
                'previous_value' => null,
                'new_value' => null,
                'reason' => $validated['reason'],
            ]);

            return $record;
        });

        $record->load(['company', 'details']);
        $record->loadSum('details as income_total', 'income');
        $record->loadSum('details as commission_total', 'commission');
        $record->loadSum('details as return_total', 'return_amount');

        return new RecordResource($record);
    }

    public function show(Record $record): RecordResource
    {
        Gate::authorize('view', $record);

        $record->load(['company', 'details']);
        $record->loadSum('details as income_total', 'income');
        $record->loadSum('details as commission_total', 'commission');
        $record->loadSum('details as return_total', 'return_amount');

        return new RecordResource($record);
    }
}
