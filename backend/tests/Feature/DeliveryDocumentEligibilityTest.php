<?php

namespace Tests\Feature;

use App\Domain\Documents\DeliveryDocumentEligibility;
use App\Domain\Documents\Exceptions\DocumentGenerationBlockedException;
use App\Enums\RecordStatus;
use App\Models\Delivery;
use App\Models\DeliveryCashDenomination;
use App\Models\Image;
use App\Models\Record;
use App\Models\RecordDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeliveryDocumentEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('record_images');
    }

    public function test_a_valid_delivery_returns_the_absolute_image_path(): void
    {
        $delivery = $this->delivery();

        $path = $this->eligibility()->check($delivery, $delivery->record, '920.00', false);

        $this->assertSame(Storage::disk('record_images')->path('sample.jpg'), $path);
        $this->assertFileExists($path);
    }

    public function test_a_record_that_is_not_confirmed_is_blocked(): void
    {
        foreach ([RecordStatus::NEEDS_REVIEW, RecordStatus::CANCELED, RecordStatus::ERROR] as $status) {
            $delivery = $this->delivery(status: $status);

            $this->assertBlocked(DocumentGenerationBlockedException::INVALID_RECORD_STATE, $delivery);
        }
    }

    public function test_a_record_without_an_image_is_blocked(): void
    {
        $delivery = $this->delivery(withImage: false);

        $this->assertBlocked(DocumentGenerationBlockedException::MISSING_IMAGE, $delivery);
    }

    public function test_a_stale_delivery_is_blocked_with_both_amounts(): void
    {
        $delivery = $this->delivery(prepared: '900.00');

        $blocked = $this->assertBlocked(DocumentGenerationBlockedException::STALE_DELIVERY, $delivery);

        $this->assertSame(
            ['reason' => 'STALE_DELIVERY', 'prepared_amount' => '900.00', 'current_amount' => '920.00'],
            $blocked->responseDetails(),
        );
    }

    public function test_a_cash_mismatch_is_blocked_with_expected_cash_and_difference(): void
    {
        $delivery = $this->delivery(denominations: [['500.00', 1], ['200.00', 2]]);

        $blocked = $this->assertBlocked(DocumentGenerationBlockedException::CASH_MISMATCH, $delivery, '920.00');

        $this->assertSame(
            [
                'reason' => 'CASH_MISMATCH',
                'expected_amount' => '920.00',
                'cash_amount' => '900.00',
                'difference' => '-20.00',
            ],
            $blocked->responseDetails(),
        );
    }

    public function test_an_unsupported_document_adjustment_is_a_validation_error(): void
    {
        $delivery = $this->delivery();

        try {
            $this->eligibility()->check($delivery, $delivery->record, '920.25', false);
            $this->fail('Expected the unsupported document amount to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document_return_amount', $exception->errors());
        }
    }

    public function test_a_missing_image_file_is_blocked_without_leaking_the_path(): void
    {
        Log::spy();
        $delivery = $this->delivery(createFile: false);

        $blocked = $this->assertBlocked(DocumentGenerationBlockedException::IMAGE_UNAVAILABLE, $delivery);

        $this->assertSame(['reason' => 'IMAGE_UNAVAILABLE'], $blocked->responseDetails());
        $this->assertStringNotContainsString('sample.jpg', $blocked->getMessage());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_checks_run_in_the_documented_order(): void
    {
        $delivery = $this->delivery(
            status: RecordStatus::NEEDS_REVIEW,
            withImage: false,
            prepared: '1.00',
            denominations: [['500.00', 1]],
        );
        $this->assertBlocked(DocumentGenerationBlockedException::INVALID_RECORD_STATE, $delivery);

        $delivery->record->update(['status' => RecordStatus::CONFIRMED]);
        $this->assertBlocked(DocumentGenerationBlockedException::MISSING_IMAGE, $delivery->fresh());

        Image::factory()->create(['record_id' => $delivery->record_id, 'file_path' => 'sample.jpg']);
        $this->assertBlocked(DocumentGenerationBlockedException::STALE_DELIVERY, $delivery->fresh());

        $delivery->update(['prepared_return_amount' => '920.00']);
        $this->assertBlocked(
            DocumentGenerationBlockedException::CASH_MISMATCH,
            $delivery->fresh(),
            '920.00',
            false,
        );

        DeliveryCashDenomination::query()->where('delivery_id', $delivery->getKey())->delete();
        foreach ([['500.00', 1], ['200.00', 2], ['10.00', 2]] as [$value, $quantity]) {
            DeliveryCashDenomination::factory()->create([
                'delivery_id' => $delivery->getKey(),
                'value' => $value,
                'quantity' => $quantity,
            ]);
        }
        $this->assertBlocked(DocumentGenerationBlockedException::IMAGE_UNAVAILABLE, $delivery->fresh());
    }

    public function test_checking_does_not_modify_any_data(): void
    {
        $delivery = $this->delivery();
        $updatedAt = $delivery->fresh()->updated_at;

        $this->eligibility()->check($delivery, $delivery->record, '920.00', false);

        $this->assertEquals($updatedAt, $delivery->fresh()->updated_at);
        $this->assertSame(3, DeliveryCashDenomination::query()->count());
    }

    private function assertBlocked(
        string $reason,
        Delivery $delivery,
        string $documentReturnAmount = '920.00',
        bool $customerWillGiveChange = false,
    ): DocumentGenerationBlockedException {
        try {
            $this->eligibility()->check(
                $delivery,
                $delivery->record()->first(),
                $documentReturnAmount,
                $customerWillGiveChange,
            );
        } catch (DocumentGenerationBlockedException $blocked) {
            $this->assertSame($reason, $blocked->reason);

            return $blocked;
        }

        $this->fail("Expected generation to be blocked with {$reason}.");
    }

    private function eligibility(): DeliveryDocumentEligibility
    {
        return $this->app->make(DeliveryDocumentEligibility::class);
    }

    /**
     * @param  list<array{0: string, 1: int}>|null  $denominations
     */
    private function delivery(
        RecordStatus $status = RecordStatus::CONFIRMED,
        bool $withImage = true,
        bool $createFile = true,
        string $prepared = '920.00',
        ?array $denominations = null,
    ): Delivery {
        $record = Record::factory()->create(['status' => $status]);

        RecordDetail::factory()->create([
            'record_id' => $record->getKey(),
            'income' => '1000.00',
            'commission' => '80.00',
            'return_amount' => '920.00',
            'order' => 1,
        ]);

        if ($withImage) {
            Image::factory()->create(['record_id' => $record->getKey(), 'file_path' => 'sample.jpg']);

            if ($createFile) {
                Storage::disk('record_images')->put('sample.jpg', 'image-bytes');
            }
        }

        $delivery = Delivery::factory()->create([
            'record_id' => $record->getKey(),
            'prepared_return_amount' => $prepared,
        ]);

        foreach ($denominations ?? [['500.00', 1], ['200.00', 2], ['10.00', 2]] as [$value, $quantity]) {
            DeliveryCashDenomination::factory()->create([
                'delivery_id' => $delivery->getKey(),
                'value' => $value,
                'quantity' => $quantity,
            ]);
        }

        return $delivery;
    }
}
