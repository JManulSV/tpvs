<?php

namespace Tests\Unit\Records;

use App\Domain\Records\Actions\UpdateRecord;
use App\Domain\Records\Exceptions\RecordUpdateBlocked;
use App\Enums\RecordStatus;
use App\Models\Company;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_a_confirmed_record_replaces_details_and_returns_it_to_review(): void
    {
        $user = User::factory()->create();
        $record = $this->confirmedRecord();

        $updated = app(UpdateRecord::class)->handle($record, $this->payload($record, income: '2000.00'), $user->id);

        $this->assertSame(RecordStatus::NEEDS_REVIEW, $updated->status);
        $this->assertSame(['2000.00'], $updated->details()->pluck('income')->all());
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'field_name' => 'status',
            'previous_value' => RecordStatus::CONFIRMED->value,
            'new_value' => RecordStatus::NEEDS_REVIEW->value,
            'reason' => 'Corrección de ingreso',
        ]);
    }

    public function test_a_confirmed_record_without_changes_is_blocked_and_untouched(): void
    {
        $user = User::factory()->create();
        $record = $this->confirmedRecord();

        try {
            app(UpdateRecord::class)->handle($record, $this->payload($record, income: '1000.00'), $user->id);
            $this->fail('Expected the update to be blocked.');
        } catch (RecordUpdateBlocked $blocked) {
            $this->assertSame('RECORD_UPDATE_NO_CHANGES', $blocked->errorCode);
            $this->assertSame(422, $blocked->status);
        }

        $this->assertSame(RecordStatus::CONFIRMED, $record->fresh()->status);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_a_canceled_record_cannot_be_updated(): void
    {
        $record = Record::factory()->create(['status' => RecordStatus::CANCELED]);

        $this->expectExceptionObject(RecordUpdateBlocked::invalidState());

        app(UpdateRecord::class)->handle($record, $this->payload($record, income: '1000.00'), User::factory()->create()->id);
    }

    private function confirmedRecord(): Record
    {
        $record = Record::factory()->for(Company::factory())->create([
            'status' => RecordStatus::CONFIRMED,
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
        ]);
        $record->details()->create([
            'affiliation' => 'AFF-1',
            'branch' => 'Centro',
            'income' => '1000.00',
            'commission_percentage' => '10.00',
            'commission' => '100.00',
            'return_amount' => '900.00',
            'order' => 1,
        ]);

        return $record;
    }

    /** @return array<string, mixed> */
    private function payload(Record $record, string $income): array
    {
        return [
            'company_id' => $record->company_id,
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
            'details' => [
                ['affiliation' => 'AFF-1', 'branch' => 'Centro', 'income' => $income, 'commission_percentage' => '10.00'],
            ],
            'reason' => 'Corrección de ingreso',
        ];
    }
}
