<?php

namespace App\Domain\Dashboard;

use App\Enums\RecordStatus;
use App\Models\Record;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class DashboardQuery
{
    /**
     * Work-queue states are counted without a period; confirmed and canceled
     * records are counted for the given month with the same rule as reports.
     *
     * @return array{needs_review: int, error: int, pending: int, processing: int, confirmed_month: int, canceled_month: int}
     */
    public function statusCounts(int $year, int $month): array
    {
        $byStatus = Record::query()
            ->toBase()
            ->whereIn('status', [
                RecordStatus::NEEDS_REVIEW->value,
                RecordStatus::ERROR->value,
                RecordStatus::PENDING->value,
                RecordStatus::PROCESSING->value,
            ])
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status');

        $monthly = $this->inMonth(Record::query(), $year, $month)
            ->toBase()
            ->whereIn('status', [RecordStatus::CONFIRMED->value, RecordStatus::CANCELED->value])
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status');

        return [
            'needs_review' => (int) ($byStatus[RecordStatus::NEEDS_REVIEW->value] ?? 0),
            'error' => (int) ($byStatus[RecordStatus::ERROR->value] ?? 0),
            'pending' => (int) ($byStatus[RecordStatus::PENDING->value] ?? 0),
            'processing' => (int) ($byStatus[RecordStatus::PROCESSING->value] ?? 0),
            'confirmed_month' => (int) ($monthly[RecordStatus::CONFIRMED->value] ?? 0),
            'canceled_month' => (int) ($monthly[RecordStatus::CANCELED->value] ?? 0),
        ];
    }

    /**
     * @return array{confirmed_count: int, return_total: string}
     */
    public function monthSummary(int $year, int $month): array
    {
        $row = $this->inMonth(Record::query(), $year, $month)
            ->toBase()
            ->where('records.status', RecordStatus::CONFIRMED->value)
            ->leftJoin('record_details', 'record_details.record_id', '=', 'records.id')
            ->selectRaw('count(distinct records.id) as confirmed_count')
            ->selectRaw('round(coalesce(sum(record_details.return_amount), 0), 2) as return_total')
            ->first();

        return [
            'confirmed_count' => (int) $row->confirmed_count,
            'return_total' => number_format((float) $row->return_total, 2, '.', ''),
        ];
    }

    /**
     * @return Collection<int, Record>
     */
    public function recentActivity(int $limit = 5): Collection
    {
        return Record::query()
            ->with('company')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Same period rule as ReportQuery::base: business year plus the month of week_end_date.
     *
     * @param  Builder<Record>  $query
     * @return Builder<Record>
     */
    private function inMonth(Builder $query, int $year, int $month): Builder
    {
        return $query
            ->where('records.year', $year)
            ->whereMonth('records.week_end_date', $month);
    }
}
