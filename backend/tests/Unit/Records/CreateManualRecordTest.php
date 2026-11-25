<?php

namespace Tests\Unit\Records;

use App\Domain\Records\Actions\CreateManualRecord;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Models\Company;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateManualRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_confirmed_manual_record_with_calculated_totals_and_history(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();

        $record = app(CreateManualRecord::class)->handle($this->payload($company->id), $user->id);

        $this->assertSame(RecordSource::MANUAL, $record->source);
        $this->assertSame(RecordStatus::CONFIRMED, $record->status);
        $this->assertSame('2026-09-14', $record->fresh()->week_start_date->toDateString());
        $this->assertDatabaseHas('records', [
            'id' => $record->id,
            'commission_percentage' => '12.50',
            'commission' => '125.00',
            'return_amount' => '875.00',
        ]);
        $this->assertDatabaseHas('record_details', [
            'record_id' => $record->id,
            'affiliation' => 'AFF-1',
            'bank' => 'LUUM',
            'income' => '1000.00',
            'order' => 1,
        ]);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $user->id,
            'reason' => 'Registro creado manualmente',
        ]);
    }

    public function test_invalid_business_data_creates_nothing(): void
    {
        $user = User::factory()->create();
        $payload = $this->payload(Company::factory()->create()->id);
        $payload['commission_percentage'] = '100.01';

        try {
            app(CreateManualRecord::class)->handle($payload, $user->id);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('commission_percentage', $exception->errors());
        }

        $this->assertSame(0, Record::query()->count());
        $this->assertDatabaseCount('histories', 0);
    }

    /** @return array<string, mixed> */
    private function payload(int $companyId): array
    {
        return [
            'company_id' => $companyId,
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
            'commission_percentage' => '12.5',
            'details' => [
                ['affiliation' => 'AFF-1', 'bank' => 'LUUM', 'income' => '1000.00'],
            ],
        ];
    }
}
