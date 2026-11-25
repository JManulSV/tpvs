<?php

namespace App\Domain\Records;

use App\Models\Record;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class RecordQuery
{
    /**
     * One page of Records for the records screen, most recently updated first,
     * with everything RecordResource renders.
     *
     * @return LengthAwarePaginator<int, Record>
     */
    public function paginate(RecordFilters $filters, int $perPage): LengthAwarePaginator
    {
        return Record::query()
            ->withResourceAggregates()
            ->when($filters->companyId !== null, static fn (Builder $query) => $query
                ->where('company_id', $filters->companyId))
            ->when($filters->year !== null, static fn (Builder $query) => $query
                ->where('year', $filters->year))
            ->when($filters->month !== null, static fn (Builder $query) => $query
                ->whereMonth('week_end_date', $filters->month))
            ->when($filters->week !== null, static fn (Builder $query) => $query
                ->where('week', $filters->week))
            ->when($filters->status !== null, static fn (Builder $query) => $query
                ->where('status', $filters->status))
            ->when($filters->source !== null, static fn (Builder $query) => $query
                ->where('source', $filters->source))
            ->when($filters->search !== null, fn (Builder $query) => $this->search($query, $filters->search))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Case-insensitive match on the company name or any movement's affiliation or bank.
     *
     * @param  Builder<Record>  $query
     */
    private function search(Builder $query, string $search): void
    {
        $term = '%'.mb_strtolower($search).'%';

        $query->where(static function (Builder $query) use ($term): void {
            $query->whereHas('company', static fn (Builder $companyQuery) => $companyQuery
                ->whereRaw('LOWER(name) LIKE ?', [$term]))
                ->orWhereHas('details', static fn (Builder $detailsQuery) => $detailsQuery
                    ->whereRaw('LOWER(affiliation) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(bank) LIKE ?', [$term]));
        });
    }
}
