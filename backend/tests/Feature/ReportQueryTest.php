<?php

namespace Tests\Feature;

use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportQuery;
use App\Enums\RecordStatus;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\DeliveryCashDenomination;
use App\Models\Record;
use App\Models\RecordDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_confirmed_records_are_included_in_every_output(): void
    {
        $company = Company::factory()->create(['name' => 'Acme']);

        $confirmed = $this->record($company, ['status' => RecordStatus::CONFIRMED], [['100.00', '10.00']]);
        foreach (RecordStatus::cases() as $status) {
            if ($status !== RecordStatus::CONFIRMED) {
                $this->record($company, ['status' => $status], [['9999.00', '999.00']]);
            }
        }

        $filters = new ReportFilters(2026);
        $query = $this->query();

        $this->assertSame(1, $query->totals($filters)['records_count']);
        $this->assertSame('100.00', $query->totals($filters)['income_total']);
        $this->assertCount(1, $query->companies($filters));
        $this->assertSame('100.00', $query->companies($filters)[0]['income_total']);
        $this->assertSame([$confirmed->getKey()], $query->paginate($filters, 1, 15)->pluck('id')->all());
        $this->assertSame([$confirmed->getKey()], $query->export($filters)->pluck('id')->all());
    }

    public function test_year_filter_uses_the_record_business_year(): void
    {
        $company = Company::factory()->create();
        $this->record($company, ['year' => 2025], [['100.00', '10.00']]);
        $current = $this->record($company, ['year' => 2026], [['200.00', '20.00']]);

        $this->assertSame(
            [$current->getKey()],
            $this->query()->export(new ReportFilters(2026))->pluck('id')->all(),
        );
        $this->assertSame(0, $this->query()->totals(new ReportFilters(2024))['records_count']);
    }

    public function test_month_filter_uses_the_month_of_the_week_end_date(): void
    {
        $company = Company::factory()->create();
        $crossing = $this->record($company, [
            'week_start_date' => '2026-08-28',
            'week_end_date' => '2026-09-03',
        ], [['100.00', '10.00']]);

        $this->assertSame([$crossing->getKey()], $this->query()->export(new ReportFilters(2026, month: 9))->pluck('id')->all());
        $this->assertSame([], $this->query()->export(new ReportFilters(2026, month: 8))->pluck('id')->all());
    }

    public function test_a_week_ending_in_the_next_calendar_year_keeps_its_business_year(): void
    {
        $company = Company::factory()->create();
        $record = $this->record($company, [
            'year' => 2026,
            'week' => 53,
            'week_start_date' => '2026-12-28',
            'week_end_date' => '2027-01-03',
        ], [['100.00', '10.00']]);

        $this->assertSame([$record->getKey()], $this->query()->export(new ReportFilters(2026))->pluck('id')->all());
        $this->assertSame([$record->getKey()], $this->query()->export(new ReportFilters(2026, month: 1))->pluck('id')->all());
        $this->assertSame([], $this->query()->export(new ReportFilters(2027))->pluck('id')->all());
    }

    public function test_week_company_and_month_filters_combine_with_and(): void
    {
        $acme = Company::factory()->create(['name' => 'Acme']);
        $other = Company::factory()->create(['name' => 'Other']);

        $match = $this->record($acme, ['week' => 38, 'week_start_date' => '2026-09-07', 'week_end_date' => '2026-09-13'], [['100.00', '10.00']]);
        $otherWeek = $this->record($acme, ['week' => 39, 'week_start_date' => '2026-09-14', 'week_end_date' => '2026-09-20'], [['100.00', '10.00']]);
        $otherCompany = $this->record($other, ['week' => 38, 'week_start_date' => '2026-09-07', 'week_end_date' => '2026-09-13'], [['100.00', '10.00']]);
        $otherMonth = $this->record($acme, ['week' => 38, 'week_start_date' => '2026-09-28', 'week_end_date' => '2026-10-04'], [['100.00', '10.00']]);

        $ids = fn (ReportFilters $filters): array => $this->query()->export($filters)->pluck('id')->sort()->values()->all();

        $this->assertSame([$match->getKey()], $ids(new ReportFilters(2026, month: 9, week: 38, companyId: $acme->getKey())));
        $this->assertSame(
            collect([$match, $otherMonth])->pluck('id')->sort()->values()->all(),
            $ids(new ReportFilters(2026, week: 38, companyId: $acme->getKey())),
        );
        $this->assertSame(
            collect([$match, $otherWeek, $otherCompany])->pluck('id')->sort()->values()->all(),
            $ids(new ReportFilters(2026, month: 9)),
        );
        $this->assertSame([$otherCompany->getKey()], $ids(new ReportFilters(2026, month: 9, week: 38, companyId: $other->getKey())));
    }

    public function test_totals_count_records_not_movements_and_ignore_pagination(): void
    {
        $company = Company::factory()->create();
        $this->record($company, [], [['100.00', '10.00'], ['50.00', '5.00'], ['25.00', '2.50']]);
        $this->record($company, [], [['200.00', '20.00']]);

        $filters = new ReportFilters(2026);

        $this->assertSame([
            'records_count' => 2,
            'income_total' => '375.00',
            'commission_total' => '37.50',
            'return_total' => '337.50',
        ], $this->query()->totals($filters));

        $page = $this->query()->paginate($filters, 1, 1);
        $this->assertCount(1, $page->items());
        $this->assertSame(2, $page->total());
        $this->assertSame('375.00', $this->query()->totals($filters)['income_total']);
    }

    public function test_decimal_sums_are_exact(): void
    {
        $company = Company::factory()->create();
        $this->record($company, [], [['0.10', '0.00'], ['0.20', '0.00'], ['9999999999.99', '0.00']]);

        $totals = $this->query()->totals(new ReportFilters(2026));

        $this->assertSame('10000000000.29', $totals['income_total']);
        $this->assertSame('0.00', $totals['commission_total']);
        $this->assertSame('10000000000.29', $totals['return_total']);
    }

    public function test_company_breakdown_is_grouped_and_ordered_by_name(): void
    {
        $zeta = Company::factory()->create(['name' => 'Zeta']);
        $alpha = Company::factory()->create(['name' => 'Alpha']);

        $this->record($zeta, [], [['300.00', '30.00']]);
        $this->record($alpha, [], [['100.00', '10.00']]);
        $this->record($alpha, [], [['50.00', '5.00'], ['50.00', '5.00']]);

        $companies = $this->query()->companies(new ReportFilters(2026));

        $this->assertSame(['Alpha', 'Zeta'], array_map(static fn (array $row) => $row['company']['name'], $companies));
        $this->assertSame([
            'company' => ['id' => $alpha->getKey(), 'name' => 'Alpha'],
            'records_count' => 2,
            'income_total' => '200.00',
            'commission_total' => '20.00',
            'return_total' => '180.00',
        ], $companies[0]);
        $this->assertSame(1, $companies[1]['records_count']);
        $this->assertSame('300.00', $companies[1]['income_total']);
    }

    public function test_period_spans_the_earliest_start_and_latest_end(): void
    {
        $company = Company::factory()->create();
        $this->record($company, ['week_start_date' => '2026-09-07', 'week_end_date' => '2026-09-13'], [['100.00', '10.00']]);
        $this->record($company, ['week_start_date' => '2026-08-28', 'week_end_date' => '2026-09-03'], [['100.00', '10.00']]);
        $this->record($company, ['week_start_date' => '2026-09-14', 'week_end_date' => '2026-09-20'], [['100.00', '10.00']]);

        $this->assertSame(
            ['week_start_date' => '2026-08-28', 'week_end_date' => '2026-09-20'],
            $this->query()->period(new ReportFilters(2026)),
        );
    }

    public function test_an_empty_result_has_zero_totals_and_no_period(): void
    {
        $filters = new ReportFilters(2026);
        $query = $this->query();

        $this->assertSame([
            'records_count' => 0,
            'income_total' => '0.00',
            'commission_total' => '0.00',
            'return_total' => '0.00',
        ], $query->totals($filters));
        $this->assertSame([], $query->companies($filters));
        $this->assertNull($query->period($filters));
        $this->assertSame(0, $query->paginate($filters, 1, 15)->total());
        $this->assertCount(0, $query->export($filters));
    }

    public function test_records_are_ordered_by_company_year_week_start_date_and_id(): void
    {
        $beta = Company::factory()->create(['name' => 'Beta']);
        $alpha = Company::factory()->create(['name' => 'Alpha']);

        $betaWeek1 = $this->record($beta, ['week' => 1, 'week_start_date' => '2026-01-05', 'week_end_date' => '2026-01-11'], [['1.00', '0.00']]);
        $alphaWeek5 = $this->record($alpha, ['week' => 5, 'week_start_date' => '2026-02-02', 'week_end_date' => '2026-02-08'], [['1.00', '0.00']]);
        $alphaWeek2First = $this->record($alpha, ['week' => 2, 'week_start_date' => '2026-01-12', 'week_end_date' => '2026-01-18'], [['1.00', '0.00']]);
        $alphaWeek2Second = $this->record($alpha, ['week' => 2, 'week_start_date' => '2026-01-12', 'week_end_date' => '2026-01-18'], [['1.00', '0.00']]);

        $expected = [
            $alphaWeek2First->getKey(),
            $alphaWeek2Second->getKey(),
            $alphaWeek5->getKey(),
            $betaWeek1->getKey(),
        ];

        $this->assertSame($expected, $this->query()->export(new ReportFilters(2026))->pluck('id')->all());
        $this->assertSame($expected, $this->query()->paginate(new ReportFilters(2026), 1, 15)->pluck('id')->all());
    }

    public function test_pagination_returns_requested_page_with_per_record_totals_and_delivery(): void
    {
        $company = Company::factory()->create(['name' => 'Acme']);
        $first = $this->record($company, ['week' => 1, 'week_start_date' => '2026-01-05', 'week_end_date' => '2026-01-11'], [['100.00', '10.00'], ['50.00', '5.00']]);
        $second = $this->record($company, ['week' => 2, 'week_start_date' => '2026-01-12', 'week_end_date' => '2026-01-18'], [['200.00', '20.00']]);
        Delivery::factory()->create([
            'record_id' => $second->getKey(),
            'delivery_date' => '2026-01-20',
            'prepared_return_amount' => '180.00',
        ]);

        $filters = new ReportFilters(2026);
        $firstPage = $this->query()->paginate($filters, 1, 1);
        $secondPage = $this->query()->paginate($filters, 2, 1);

        $this->assertSame(2, $firstPage->lastPage());
        $this->assertSame($first->getKey(), $firstPage->items()[0]->id);
        $this->assertSame(2, $firstPage->items()[0]->details_count);
        $this->assertSame('150.00', $firstPage->items()[0]->income_total);
        $this->assertSame('15.00', $firstPage->items()[0]->commission_total);
        $this->assertSame('135.00', $firstPage->items()[0]->return_total);
        $this->assertNull($firstPage->items()[0]->delivery);

        $this->assertSame($second->getKey(), $secondPage->items()[0]->id);
        $this->assertSame('2026-01-20', $secondPage->items()[0]->delivery->delivery_date->toDateString());
        $this->assertSame('Acme', $secondPage->items()[0]->company->name);
    }

    public function test_export_loads_ordered_movements_delivery_and_denominations_without_n_plus_one(): void
    {
        $company = Company::factory()->create();

        foreach (range(1, 5) as $week) {
            $record = $this->record($company, [
                'week' => $week,
                'week_start_date' => '2026-01-05',
                'week_end_date' => '2026-01-11',
            ], [['100.00', '10.00'], ['50.00', '5.00']]);

            $delivery = Delivery::factory()->create(['record_id' => $record->getKey(), 'prepared_return_amount' => '135.00']);
            DeliveryCashDenomination::factory()->create(['delivery_id' => $delivery->getKey(), 'value' => '100.00', 'quantity' => 1]);
            DeliveryCashDenomination::factory()->create(['delivery_id' => $delivery->getKey(), 'value' => '20.00', 'quantity' => 1]);
        }

        DB::enableQueryLog();
        $records = $this->query()->export(new ReportFilters(2026));
        $records->each(function (Record $record): void {
            $record->company->name;
            $record->details->each(fn (RecordDetail $detail) => $detail->income);
            $record->delivery?->cashDenominations->count();
        });
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(5, $records);
        $this->assertLessThanOrEqual(5, $queries);
        $this->assertSame([1, 2], $records->first()->details->pluck('order')->all());
        $this->assertCount(2, $records->first()->delivery->cashDenominations);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{0: string, 1: string}>  $movements  [income, commission]
     */
    private function record(Company $company, array $attributes, array $movements): Record
    {
        $record = Record::factory()->create(array_merge([
            'company_id' => $company->getKey(),
            'status' => RecordStatus::CONFIRMED,
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-07',
            'week_end_date' => '2026-09-13',
        ], $attributes));

        foreach ($movements as $index => [$income, $commission]) {
            RecordDetail::factory()->create([
                'record_id' => $record->getKey(),
                'income' => $income,
                'commission' => $commission,
                'return_amount' => number_format((float) $income - (float) $commission, 2, '.', ''),
                'order' => $index + 1,
            ]);
        }

        return $record;
    }

    private function query(): ReportQuery
    {
        return $this->app->make(ReportQuery::class);
    }
}
