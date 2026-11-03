<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\History;
use App\Models\Record;
use App\Models\RecordDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_requires_authentication(): void
    {
        $this->getJson('/api/reports?year=2026')->assertUnauthorized();
    }

    public function test_editors_and_viewers_receive_the_full_report_shape(): void
    {
        $acme = Company::factory()->create(['name' => 'Acme']);
        $record = $this->record($acme, [
            'week' => 38,
            'week_start_date' => '2026-09-07',
            'week_end_date' => '2026-09-13',
        ], [['10000.00', '200.00'], ['500.00', '10.00']]);
        Delivery::factory()->create([
            'record_id' => $record->getKey(),
            'delivery_date' => '2026-09-15',
            'prepared_return_amount' => '10290.00',
        ]);

        foreach ([UserRole::EDITOR, UserRole::VIEWER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'web')
                ->getJson('/api/reports?year=2026&month=9&week=38&company_id='.$acme->getKey())
                ->assertOk()
                ->assertExactJson([
                    'data' => [
                        'filters' => ['year' => 2026, 'month' => 9, 'week' => 38, 'company_id' => $acme->getKey()],
                        'period' => ['week_start_date' => '2026-09-07', 'week_end_date' => '2026-09-13'],
                        'totals' => [
                            'records_count' => 1,
                            'income_total' => '10500.00',
                            'commission_total' => '210.00',
                            'return_total' => '10290.00',
                        ],
                        'companies' => [[
                            'company' => ['id' => $acme->getKey(), 'name' => 'Acme'],
                            'records_count' => 1,
                            'income_total' => '10500.00',
                            'commission_total' => '210.00',
                            'return_total' => '10290.00',
                        ]],
                        'records' => [[
                            'id' => $record->getKey(),
                            'company' => ['id' => $acme->getKey(), 'name' => 'Acme'],
                            'year' => 2026,
                            'week' => 38,
                            'week_start_date' => '2026-09-07',
                            'week_end_date' => '2026-09-13',
                            'details_count' => 2,
                            'income_total' => '10500.00',
                            'commission_total' => '210.00',
                            'return_total' => '10290.00',
                            'delivery_date' => '2026-09-15',
                        ]],
                    ],
                    'meta' => ['current_page' => 1, 'per_page' => 15, 'total' => 1, 'last_page' => 1],
                ]);
        }
    }

    public function test_unsent_filters_are_null_and_delivery_date_is_null_without_delivery(): void
    {
        $this->record(Company::factory()->create(), [], [['100.00', '10.00']]);

        $this->actingAs($this->user(), 'web')
            ->getJson('/api/reports?year=2026')
            ->assertOk()
            ->assertJsonPath('data.filters', ['year' => 2026, 'month' => null, 'week' => null, 'company_id' => null])
            ->assertJsonPath('data.records.0.delivery_date', null);
    }

    public function test_an_empty_result_returns_zero_totals_and_no_period(): void
    {
        $this->actingAs($this->user(), 'web')
            ->getJson('/api/reports?year=2026')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'filters' => ['year' => 2026, 'month' => null, 'week' => null, 'company_id' => null],
                    'period' => null,
                    'totals' => [
                        'records_count' => 0,
                        'income_total' => '0.00',
                        'commission_total' => '0.00',
                        'return_total' => '0.00',
                    ],
                    'companies' => [],
                    'records' => [],
                ],
                'meta' => ['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1],
            ]);
    }

    public function test_only_confirmed_records_are_reported(): void
    {
        $company = Company::factory()->create();
        $confirmed = $this->record($company, [], [['100.00', '10.00']]);
        foreach (RecordStatus::cases() as $status) {
            if ($status !== RecordStatus::CONFIRMED) {
                $this->record($company, ['status' => $status], [['5000.00', '500.00']]);
            }
        }

        $this->actingAs($this->user(), 'web')
            ->getJson('/api/reports?year=2026')
            ->assertOk()
            ->assertJsonPath('data.totals.records_count', 1)
            ->assertJsonPath('data.totals.income_total', '100.00')
            ->assertJsonCount(1, 'data.companies')
            ->assertJsonCount(1, 'data.records')
            ->assertJsonPath('data.records.0.id', $confirmed->getKey());
    }

    public function test_totals_and_companies_cover_the_full_set_regardless_of_page(): void
    {
        $company = Company::factory()->create(['name' => 'Acme']);
        foreach (range(1, 3) as $week) {
            $this->record($company, [
                'week' => $week,
                'week_start_date' => '2026-01-05',
                'week_end_date' => '2026-01-11',
            ], [['100.00', '10.00']]);
        }

        $second = $this->actingAs($this->user(), 'web')
            ->getJson('/api/reports?year=2026&per_page=1&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.records')
            ->assertJsonPath('data.records.0.week', 2)
            ->assertJsonPath('data.totals.records_count', 3)
            ->assertJsonPath('data.totals.income_total', '300.00')
            ->assertJsonPath('data.companies.0.records_count', 3)
            ->assertJsonPath('data.companies.0.income_total', '300.00')
            ->assertJsonPath('meta', ['current_page' => 2, 'per_page' => 1, 'total' => 3, 'last_page' => 3]);

        $this->assertSame(
            $second->json('data.totals'),
            $this->getJson('/api/reports?year=2026&per_page=1&page=3')->json('data.totals'),
        );
    }

    public function test_per_page_defaults_to_fifteen_and_accepts_up_to_one_hundred(): void
    {
        $company = Company::factory()->create();
        foreach (range(1, 16) as $week) {
            $this->record($company, ['week' => $week], [['10.00', '1.00']]);
        }

        $this->actingAs($this->user(), 'web');

        $this->getJson('/api/reports?year=2026')
            ->assertOk()
            ->assertJsonCount(15, 'data.records')
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/reports?year=2026&per_page=100')
            ->assertOk()
            ->assertJsonCount(16, 'data.records')
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_records_and_companies_follow_the_documented_order(): void
    {
        $zeta = Company::factory()->create(['name' => 'Zeta']);
        $alpha = Company::factory()->create(['name' => 'Alpha']);
        $zetaRecord = $this->record($zeta, ['week' => 1], [['10.00', '1.00']]);
        $alphaLate = $this->record($alpha, ['week' => 9, 'week_start_date' => '2026-03-02', 'week_end_date' => '2026-03-08'], [['10.00', '1.00']]);
        $alphaEarly = $this->record($alpha, ['week' => 2, 'week_start_date' => '2026-01-12', 'week_end_date' => '2026-01-18'], [['10.00', '1.00']]);

        $this->actingAs($this->user(), 'web')
            ->getJson('/api/reports?year=2026')
            ->assertOk()
            ->assertJsonPath('data.companies.0.company.name', 'Alpha')
            ->assertJsonPath('data.companies.1.company.name', 'Zeta')
            ->assertJsonPath('data.records.*.id', [$alphaEarly->getKey(), $alphaLate->getKey(), $zetaRecord->getKey()]);
    }

    public function test_a_week_crossing_months_belongs_to_the_week_end_month(): void
    {
        $this->record(Company::factory()->create(), [
            'week_start_date' => '2026-08-28',
            'week_end_date' => '2026-09-03',
        ], [['100.00', '10.00']]);

        $this->actingAs($this->user(), 'web');

        $this->getJson('/api/reports?year=2026&month=9')->assertOk()->assertJsonPath('data.totals.records_count', 1);
        $this->getJson('/api/reports?year=2026&month=8')->assertOk()->assertJsonPath('data.totals.records_count', 0);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('invalidQueries')]
    public function test_invalid_filters_return_validation_errors(array $query, string $field): void
    {
        $this->actingAs($this->user(), 'web')
            ->getJson('/api/reports?'.http_build_query($query))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidQueries(): array
    {
        return [
            'missing year' => [[], 'year'],
            'only month' => [['month' => 9], 'year'],
            'unknown company' => [['year' => 2026, 'company_id' => 999999], 'company_id'],
            'month out of range' => [['year' => 2026, 'month' => 13], 'month'],
            'week out of range' => [['year' => 2026, 'week' => 54], 'week'],
            'per page too large' => [['year' => 2026, 'per_page' => 101], 'per_page'],
            'page zero' => [['year' => 2026, 'page' => 0], 'page'],
        ];
    }

    public function test_reading_a_report_changes_no_data_and_writes_no_history(): void
    {
        $this->record(Company::factory()->create(), [], [['100.00', '10.00']]);
        $histories = History::query()->count();
        $updatedAt = Record::query()->first()->updated_at;

        $this->actingAs($this->user(), 'web')->getJson('/api/reports?year=2026')->assertOk();

        $this->assertSame($histories, History::query()->count());
        $this->assertEquals($updatedAt, Record::query()->first()->updated_at);
    }

    private function user(UserRole $role = UserRole::VIEWER): User
    {
        return User::factory()->create(['role' => $role]);
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
}
