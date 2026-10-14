<?php

namespace App\Http\Controllers\Api;

use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\IndexCompaniesRequest;
use App\Http\Requests\Companies\StoreCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CompanyController extends Controller
{
    public function index(IndexCompaniesRequest $request)
    {
        $validated = $request->validated();
        $search = isset($validated['search']) ? trim($validated['search']) : null;

        $companies = Company::query()
            ->withCount(['records as records_count' => static fn ($query) => $query
                ->where('status', RecordStatus::CONFIRMED->value)])
            ->when($search !== null && $search !== '', static fn ($query) => $query
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 15)
            ->appends(array_filter(['search' => $search]));

        return CompanyResource::collection($companies);
    }

    public function show(Company $company): CompanyResource
    {
        Gate::authorize('view', $company);

        return new CompanyResource($company);
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        try {
            $company = Company::query()->create($request->validated());
        } catch (QueryException $exception) {
            if (! $this->isNormalizedNameUniqueViolation($exception)) {
                throw $exception;
            }

            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'name' => ['A company with this name already exists.'],
                ],
            ], 422);
        }

        return (new CompanyResource($company))
            ->response()
            ->setStatusCode(201);
    }

    private function isNormalizedNameUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505'
            && str_contains($exception->getMessage(), 'companies_normalized_name_unique');
    }
}
