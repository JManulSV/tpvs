<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Record;
use App\Models\RecordDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-November 2026, mid-afternoon in Mexico City.
        Carbon::setTestNow(Carbon::parse('2026-11-15T20:00:00Z'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_editor_receives_the_full_shape(): void
    {
        $acme = Company::factory()->create(['name' => 'Acme']);
        $record = $this->record($acme, RecordStatus::CONFIRMED, [
            'week' => 45,
            'week_start_date' => '2026-11-02',
            'week_end_date' => '2026-11-08',
        ], [['10000.00', '200.00']]);

        $this->actingAs($this->user(UserRole::EDITOR), 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'period' => ['year' => 2026, 'month' => 11, 'timezone' => 'America/Mexico_City'],
                    'status_counts' => [
                        'needs_review' => 0,
                        'error' => 0,
                        'pending' => 0,
                        'processing' => 0,
                        'confirmed_month' => 1,
                        'canceled_month' => 0,
                    ],
                    'month_summary' => ['confirmed_count' => 1, 'return_total' => '9800.00'],
                    'recent_activity' => [[
                        'id' => $record->getKey(),
                        'company' => ['id' => $acme->getKey(), 'name' => 'Acme'],
                        'year' => 2026,
                        'week' => 45,
                        'status' => 'CONFIRMED',
                        'updated_at' => $record->fresh()->updated_at->toISOString(),
                    ]],
                ],
            ]);
    }

    public function test_viewer_does_not_receive_status_counts(): void
    {
        $this->record(Company::factory()->create(), RecordStatus::NEEDS_REVIEW);

        $response = $this->actingAs($this->user(UserRole::VIEWER), 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['period', 'month_summary', 'recent_activity'],
            ]);

        $this->assertArrayNotHasKey('status_counts', $response->json('data'));
        $this->assertSame(['period', 'month_summary', 'recent_activity'], array_keys($response->json('data')));
    }

    public function test_work_queue_states_are_counted_without_period(): void
    {
        $company = Company::factory()->create();
        $old = ['year' => 2025, 'week' => 10, 'week_start_date' => '2025-03-03', 'week_end_date' => '2025-03-09'];

        $this->record($company, RecordStatus::NEEDS_REVIEW, $old);
        $this->record($company, RecordStatus::NEEDS_REVIEW);
        $this->record($company, RecordStatus::ERROR, $old);
        $this->record($company, RecordStatus::PENDING, $this->withoutPeriod());
        $this->record($company, RecordStatus::PENDING, $this->withoutPeriod());
        $this->record($company, RecordStatus::PENDING, $this->withoutPeriod());
        $this->record($company, RecordStatus::PROCESSING, $this->withoutPeriod());
        $this->record($company, RecordStatus::CONFIRMED, $old);
        $this->record($company, RecordStatus::CANCELED, $old);
        $this->record($company, RecordStatus::CANCELED);

        $this->actingAs($this->user(UserRole::EDITOR), 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.status_counts', [
                'needs_review' => 2,
                'error' => 1,
                'pending' => 3,
                'processing' => 1,
                'confirmed_month' => 0,
                'canceled_month' => 1,
            ]);
    }

    public function test_month_uses_week_end_date_for_weeks_crossing_months(): void
    {
        $company = Company::factory()->create();
        $this->record($company, RecordStatus::CONFIRMED, [
            'week' => 44,
            'week_start_date' => '2026-10-27',
            'week_end_date' => '2026-11-02',
        ], [['1000.00', '20.00']]);
        $this->record($company, RecordStatus::CONFIRMED, [
            'week' => 43,
            'week_start_date' => '2026-10-20',
            'week_end_date' => '2026-10-26',
        ], [['500.00', '10.00']]);

        $this->actingAs($this->user(UserRole::EDITOR), 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.status_counts.confirmed_month', 1)
            ->assertJsonPath('data.month_summary', ['confirmed_count' => 1, 'return_total' => '980.00']);

        Carbon::setTestNow(Carbon::parse('2026-10-28T18:00:00Z'));

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.period.month', 10)
            ->assertJsonPath('data.status_counts.confirmed_month', 1)
            ->assertJsonPath('data.month_summary', ['confirmed_count' => 1, 'return_total' => '490.00']);
    }

    public function test_records_without_week_end_date_are_not_counted_in_month(): void
    {
        $company = Company::factory()->create();
        $this->record($company, RecordStatus::CANCELED, $this->withoutPeriod());

        $this->actingAs($this->user(UserRole::EDITOR), 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.status_counts.canceled_month', 0)
            ->assertJsonPath('data.month_summary.confirmed_count', 0);
    }

    public function test_current_month_uses_the_business_timezone(): void
    {
        // 2026-12-01 03:00 UTC is 2026-11-30 21:00 in Mexico City.
        Carbon::setTestNow(Carbon::parse('2026-12-01T03:00:00Z'));

        $this->actingAs($this->user(UserRole::VIEWER), 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.period', ['year' => 2026, 'month' => 11, 'timezone' => 'America/Mexico_City']);
    }

    public function test_month_return_total_matches_the_monthly_report(): void
    {
        $acme = Company::factory()->create(['name' => 'Acme']);
        $north = Company::factory()->create(['name' => 'North']);
        $this->record($acme, RecordStatus::CONFIRMED, [], [['10000.00', '200.00'], ['333.33', '6.67']]);
        $this->record($north, RecordStatus::CONFIRMED, [
            'week' => 46,
            'week_start_date' => '2026-11-09',
            'week_end_date' => '2026-11-15',
        ], [['1234.56', '24.69']]);
        $this->record($north, RecordStatus::NEEDS_REVIEW, [], [['999.00', '9.00']]);
        $this->record($north, RecordStatus::CONFIRMED, [
            'week' => 40,
            'week_start_date' => '2026-09-28',
            'week_end_date' => '2026-10-04',
        ], [['50.00', '1.00']]);

        $user = $this->user(UserRole::VIEWER);
        $report = $this->actingAs($user, 'web')
            ->getJson('/api/reports?year=2026&month=11')
            ->assertOk()
            ->json('data.totals');

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.month_summary', [
                'confirmed_count' => $report['records_count'],
                'return_total' => $report['return_total'],
            ])
            ->assertJsonPath('data.month_summary.return_total', '11336.53');
    }

    public function test_empty_month_returns_zero_totals(): void
    {
        $this->actingAs($this->user(UserRole::EDITOR), 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.month_summary', ['confirmed_count' => 0, 'return_total' => '0.00'])
            ->assertJsonPath('data.recent_activity', []);
    }

    public function test_recent_activity_is_limited_to_five_most_recently_updated(): void
    {
        $company = Company::factory()->create();
        $records = [];

        foreach (range(1, 7) as $minutesAgo) {
            $record = $this->record($company, RecordStatus::CONFIRMED);
            $record->forceFill(['updated_at' => now()->subMinutes($minutesAgo)])->saveQuietly();
            $records[$minutesAgo] = $record;
        }

        $orphan = $this->record(null, RecordStatus::PENDING, $this->withoutPeriod());
        $orphan->forceFill(['updated_at' => now()])->saveQuietly();

        $this->actingAs($this->user(UserRole::EDITOR), 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonCount(5, 'data.recent_activity')
            ->assertJsonPath('data.recent_activity.*.id', [
                $orphan->getKey(),
                $records[1]->getKey(),
                $records[2]->getKey(),
                $records[3]->getKey(),
                $records[4]->getKey(),
            ])
            ->assertJsonPath('data.recent_activity.0.company', null)
            ->assertJsonPath('data.recent_activity.0.week', null)
            ->assertJsonPath('data.recent_activity.0.year', null);
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /**
     * @return array<string, null>
     */
    private function withoutPeriod(): array
    {
        return ['year' => null, 'week' => null, 'week_start_date' => null, 'week_end_date' => null];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{0: string, 1: string}>  $movements
     */
    private function record(?Company $company, RecordStatus $status, array $attributes = [], array $movements = []): Record
    {
        $record = Record::factory()->create(array_merge([
            'company_id' => $company?->getKey(),
            'status' => $status,
            'year' => 2026,
            'week' => 45,
            'week_start_date' => '2026-11-02',
            'week_end_date' => '2026-11-08',
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
