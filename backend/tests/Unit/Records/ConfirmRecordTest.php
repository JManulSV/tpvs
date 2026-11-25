<?php

namespace Tests\Unit\Records;

use App\Domain\Records\Actions\ConfirmRecord;
use App\Domain\Records\Exceptions\RecordConfirmationBlocked;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfirmRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_record_in_review_is_confirmed_with_history(): void
    {
        $user = User::factory()->create();
        $record = $this->recordInReview(commission: '100.00');

        $confirmed = app(ConfirmRecord::class)->handle($record, $user->id);

        $this->assertSame(RecordStatus::CONFIRMED, $confirmed->status);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $user->id,
            'previous_value' => RecordStatus::NEEDS_REVIEW->value,
            'new_value' => RecordStatus::CONFIRMED->value,
            'reason' => 'Registro manual confirmado',
        ]);
    }

    public function test_inconsistent_stored_calculations_block_confirmation(): void
    {
        $record = $this->recordInReview(commission: '99.00');

        try {
            app(ConfirmRecord::class)->handle($record, User::factory()->create()->id);
            $this->fail('Expected the confirmation to be blocked.');
        } catch (RecordConfirmationBlocked $blocked) {
            $this->assertSame(['reason' => 'BUSINESS_VALIDATION_FAILED'], $blocked->responseDetails());
            $this->assertSame('The record cannot be confirmed until its business data is valid.', $blocked->getMessage());
        }

        $this->assertSame(RecordStatus::NEEDS_REVIEW, $record->fresh()->status);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_a_record_without_a_commission_percentage_is_blocked(): void
    {
        $record = $this->recordInReview(commission: '100.00');
        $record->forceFill(['commission_percentage' => null])->save();

        try {
            app(ConfirmRecord::class)->handle($record, User::factory()->create()->id);
            $this->fail('Expected the confirmation to be blocked.');
        } catch (RecordConfirmationBlocked $blocked) {
            $this->assertSame(['reason' => 'BUSINESS_VALIDATION_FAILED'], $blocked->responseDetails());
        }

        $this->assertSame(RecordStatus::NEEDS_REVIEW, $record->fresh()->status);
    }

    public function test_an_image_record_without_its_image_is_blocked(): void
    {
        $record = $this->recordInReview(commission: '100.00');
        $record->forceFill(['source' => RecordSource::IMAGE])->save();

        $this->expectExceptionObject(RecordConfirmationBlocked::imageBusinessValidationFailed());

        app(ConfirmRecord::class)->handle($record, User::factory()->create()->id);
    }

    private function recordInReview(string $commission): Record
    {
        $record = Record::factory()->create([
            'status' => RecordStatus::NEEDS_REVIEW,
            'commission_percentage' => '10.00',
            'commission' => $commission,
            'return_amount' => '900.00',
        ]);
        $record->details()->create([
            'affiliation' => 'AFF-1',
            'bank' => 'LUUM',
            'income' => '1000.00',
            'order' => 1,
        ]);

        return $record;
    }
}
