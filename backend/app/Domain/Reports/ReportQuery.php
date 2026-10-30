<?php

namespace App\Domain\Reports;

use App\Enums\RecordStatus;
use App\Models\Company;
use App\Models\Record;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ReportQuery
{
    private const SUMS = <<<'SQL'
        count(distinct records.id) as records_count,
        round(coalesce(sum(record_details.income), 0), 2) as income_total,
        round(coalesce(sum(record_details.commission), 0), 2) as commission_total,
        round(coalesce(sum(record_details.return_amount), 0), 2) as return_total
        SQL;

    /**
     * @return array{records_count: int, income_total: string, commission_total: string, return_total: string}
     */
    public function totals(ReportFilters $filters): array
    {
        $row = $this->base($filters)
            ->toBase()
            ->leftJoin('record_details', 'record_details.record_id', '=', 'records.id')
            ->selectRaw(self::SUMS)
            ->first();

        return [
            'records_count' => (int) $row->records_count,
            'income_total' => (string) $row->income_total,
            'commission_total' => (string) $row->commission_total,
            'return_total' => (string) $row->return_total,
        ];
    }

    /**
     * @return list<array{company: array{id: int, name: string}, records_count: int, income_total: string, commission_total: string, return_total: string}>
     */
    public function companies(ReportFilters $filters): array
    {
        return $this->base($filters)
            ->toBase()
            ->join('companies', 'companies.id', '=', 'records.company_id')
            ->leftJoin('record_details', 'record_details.record_id', '=', 'records.id')
            ->groupBy('companies.id', 'companies.name')
            ->orderBy('companies.name')
            ->orderBy('companies.id')
            ->selectRaw('companies.id as company_id, companies.name as company_name, '.self::SUMS)
            ->get()
            ->map(static fn (object $row): array => [
                'company' => ['id' => (int) $row->company_id, 'name' => (string) $row->company_name],
                'records_count' => (int) $row->records_count,
                'income_total' => (string) $row->income_total,
                'commission_total' => (string) $row->commission_total,
                'return_total' => (string) $row->return_total,
            ])
            ->all();
    }

    /**
     * @return array{week_start_date: string, week_end_date: string}|null
     */
    public function period(ReportFilters $filters): ?array
    {
        $row = $this->base($filters)
            ->toBase()
            ->selectRaw('min(records.week_start_date) as week_start_date, max(records.week_end_date) as week_end_date')
            ->first();

        if ($row->week_start_date === null || $row->week_end_date === null) {
            return null;
        }

        return [
            'week_start_date' => (string) $row->week_start_date,
            'week_end_date' => (string) $row->week_end_date,
        ];
    }

    /**
     * One page of included Records with `details_count`, `income_total`, `commission_total`,
     * `return_total`, and the `company` and `delivery` relations.
     *
     * @return LengthAwarePaginator<int, Record>
     */
    public function paginate(ReportFilters $filters, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->ordered($this->base($filters))
            ->with(['company', 'delivery'])
            ->withCount('details')
            ->withSum('details as income_total', 'income')
            ->withSum('details as commission_total', 'commission')
            ->withSum('details as return_total', 'return_amount')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Every included Record with its ordered movements and its Delivery with cash denominations.
     *
     * @return Collection<int, Record>
     */
    public function export(ReportFilters $filters): Collection
    {
        return $this->ordered($this->base($filters))
            ->with(['company', 'details', 'delivery.cashDenominations'])
            ->get();
    }

    /**
     * @return Builder<Record>
     */
    private function base(ReportFilters $filters): Builder
    {
        return Record::query()
            ->where('records.status', RecordStatus::CONFIRMED)
            ->where('records.year', $filters->year)
            ->when($filters->month !== null, static fn (Builder $query) => $query
                ->whereMonth('records.week_end_date', $filters->month))
            ->when($filters->week !== null, static fn (Builder $query) => $query
                ->where('records.week', $filters->week))
            ->when($filters->companyId !== null, static fn (Builder $query) => $query
                ->where('records.company_id', $filters->companyId));
    }

    /**
     * @param  Builder<Record>  $query
     * @return Builder<Record>
     */
    private function ordered(Builder $query): Builder
    {
        return $query
            ->orderBy(Company::query()->select('name')->whereColumn('companies.id', 'records.company_id'))
            ->orderBy('records.year')
            ->orderBy('records.week')
            ->orderBy('records.week_start_date')
            ->orderBy('records.id');
    }
}
