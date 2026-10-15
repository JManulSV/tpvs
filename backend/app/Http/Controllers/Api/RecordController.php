<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Records\IndexRecordsRequest;
use App\Http\Resources\RecordResource;
use App\Models\Record;
use Illuminate\Support\Facades\Gate;

class RecordController extends Controller
{
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
