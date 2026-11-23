<?php

namespace Tests\Unit\Records;

use App\Domain\Records\Actions\CancelRecord;
use App\Domain\Records\Exceptions\RecordCancellationBlocked;
use App\Enums\RecordStatus;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_record_in_error_is_canceled_with_the_given_reason(): void
    {
        $user = User::factory()->create();
        $record = Record::factory()->create(['status' => RecordStatus::ERROR]);

        $canceled = app(CancelRecord::class)->handle($record, $user->id, 'Imagen ilegible');

        $this->assertSame(RecordStatus::CANCELED, $canceled->status);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $user->id,
            'previous_value' => RecordStatus::ERROR->value,
            'new_value' => RecordStatus::CANCELED->value,
            'reason' => 'Imagen ilegible',
        ]);
    }

    public function test_a_confirmed_record_cannot_be_canceled(): void
    {
        $record = Record::factory()->create(['status' => RecordStatus::CONFIRMED]);

        try {
            app(CancelRecord::class)->handle($record, User::factory()->create()->id, 'Duplicado');
            $this->fail('Expected the cancellation to be blocked.');
        } catch (RecordCancellationBlocked $blocked) {
            $this->assertSame('RECORD_CANCELLATION_BLOCKED', $blocked->errorCode);
            $this->assertSame(409, $blocked->status);
        }

        $this->assertSame(RecordStatus::CONFIRMED, $record->fresh()->status);
        $this->assertDatabaseCount('histories', 0);
    }
}
