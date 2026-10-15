<?php

namespace Tests\Feature;

use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Record;
use App\Models\RecordDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_reads_require_authentication(): void
    {
        $record = Record::factory()->create();

        $this->getJson('/api/records')->assertUnauthorized();
        $this->getJson('/api/records/'.$record->id)->assertUnauthorized();
    }

    public function test_editors_and_viewers_can_list_and_view_records(): void
    {
        $record = $this->createRecordWithDetails();

        foreach ([UserRole::EDITOR, UserRole::VIEWER] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user, 'web')
                ->getJson('/api/records')
                ->assertOk()
                ->assertJsonPath('data.0.id', $record->id)
                ->assertJsonPath('data.0.company.id', $record->company_id)
                ->assertJsonPath('data.0.source', RecordSource::MANUAL->value)
                ->assertJsonPath('data.0.status', RecordStatus::NEEDS_REVIEW->value)
                ->assertJsonPath('data.0.details.0.operation_number', 'OP-12345');

            $this->actingAs($user, 'web')
                ->getJson('/api/records/'.$record->id)
                ->assertOk()
                ->assertJsonPath('data.id', $record->id)
                ->assertJsonPath('data.details.0.income', '100.00')
                ->assertJsonPath('data.details.0.commission', '5.00')
                ->assertJsonPath('data.details.0.return_amount', '95.00')
                ->assertJsonPath('data.income_total', '100.00')
                ->assertJsonPath('data.commission_total', '5.00')
                ->assertJsonPath('data.return_total', '95.00');
        }
    }

    public function test_list_supports_case_insensitive_search_across_record_fields(): void
    {
        $company = Company::factory()->create(['name' => 'Northwind Supply']);
        $record = $this->createRecordWithDetails($company, [
            'affiliation' => 'AFF-928',
            'branch' => 'Guadalajara Centro',
        ], ['operation_number' => 'OP-74531']);
        $other = $this->createRecordWithDetails(Company::factory()->create(['name' => 'Contoso']));

        foreach ([' NORTHWIND ', 'op-745', 'aff-928', 'GUADALAJARA'] as $search) {
            $this->actingAs(User::factory()->create(), 'web')
                ->getJson('/api/records?search='.urlencode($search))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $record->id)
                ->assertJsonPath('meta.total', 1);
        }

        $this->assertNotSame($record->id, $other->id);
    }

    public function test_list_filters_records_and_month_uses_week_end_date(): void
    {
        $company = Company::factory()->create();
        $matching = $this->createRecordWithDetails($company, [
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
            'source' => RecordSource::MANUAL,
            'status' => RecordStatus::CONFIRMED,
        ]);
        $this->createRecordWithDetails($company, [
            'year' => 2026,
            'week' => 39,
            'week_start_date' => '2026-09-21',
            'week_end_date' => '2026-09-27',
            'source' => RecordSource::IMAGE,
            'status' => RecordStatus::NEEDS_REVIEW,
        ]);
        $this->createRecordWithDetails(Company::factory()->create(), [
            'year' => 2025,
            'week' => 38,
            'week_start_date' => '2025-09-15',
            'week_end_date' => '2025-09-21',
        ]);

        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/records?company_id='.$company->id.'&year=2026&month=9&week=38&status=CONFIRMED&source=MANUAL')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_list_is_paginated_and_ordered_by_most_recent_update(): void
    {
        $older = $this->createRecordWithDetails();
        $newer = $this->createRecordWithDetails();
        $oldTime = now()->subMinute();
        $newTime = now();
        $older->forceFill(['updated_at' => $oldTime])->save();
        $newer->forceFill(['updated_at' => $newTime])->save();

        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/records?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('links.next', fn ($value) => is_string($value));
    }

    public function test_invalid_filters_and_pagination_return_validation_errors(): void
    {
        $user = User::factory()->create();

        foreach ([
            '/api/records?page=0',
            '/api/records?per_page=101',
            '/api/records?per_page=not-a-number',
            '/api/records?search='.str_repeat('a', 256),
            '/api/records?company_id=999999',
            '/api/records?month=13',
            '/api/records?status=UNKNOWN',
            '/api/records?source=UNKNOWN',
            '/api/records?week=1',
            '/api/records?year=2025&week=53',
        ] as $url) {
            $this->actingAs($user, 'web')
                ->getJson($url)
                ->assertUnprocessable("Expected validation error for {$url}");
        }
    }

    public function test_show_returns_not_found_for_unknown_record(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/records/999999')
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $recordAttributes
     * @param  array<string, mixed>  $detailAttributes
     */
    private function createRecordWithDetails(
        ?Company $company = null,
        array $recordAttributes = [],
        array $detailAttributes = [],
    ): Record {
        $record = Record::factory()
            ->for($company ?? Company::factory())
            ->create($recordAttributes);

        RecordDetail::factory()->for($record)->create(array_merge([
            'operation_number' => 'OP-12345',
            'income' => '100.00',
            'commission_percentage' => '5.00',
            'commission' => '5.00',
            'return_amount' => '95.00',
            'order' => 1,
        ], $detailAttributes));

        return $record;
    }
}
