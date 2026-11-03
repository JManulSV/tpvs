<?php

namespace App\Http\Controllers\Api;

use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\IndexReportRequest;
use App\Http\Resources\ReportRecordResource;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function index(IndexReportRequest $request, ReportQuery $reports): JsonResponse
    {
        $validated = $request->validated();
        $filters = ReportFilters::fromValidated($validated);

        $records = $reports->paginate(
            $filters,
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 15),
        );

        return response()->json([
            'data' => [
                'filters' => $filters->toArray(),
                'period' => $reports->period($filters),
                'totals' => $reports->totals($filters),
                'companies' => $reports->companies($filters),
                'records' => ReportRecordResource::collection($records->getCollection())->resolve($request),
            ],
            'meta' => [
                'current_page' => $records->currentPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
                'last_page' => $records->lastPage(),
            ],
        ]);
    }
}
