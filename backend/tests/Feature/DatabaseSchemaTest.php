<?php

namespace Tests\Feature;

use App\Models\AiProcessing;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\DeliveryCashDenomination;
use App\Models\History;
use App\Models\Image;
use App\Models\Record;
use App\Models\RecordDetail;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_complete_record_aggregate_and_relationships_can_be_persisted(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $record = Record::factory()->for($company)->create();
        $details = RecordDetail::factory()->for($record)->count(2)->sequence(
            ['order' => 1, 'affiliation' => 'AFF-FIRST', 'branch' => 'Guadalajara'],
            ['order' => 2, 'affiliation' => 'AFF-SECOND', 'branch' => 'Zapopan'],
        )->create();
        $image = Image::factory()->for($record)->create();
        $processing = AiProcessing::factory()->for($image)->create();
        $delivery = Delivery::factory()->for($record)->create();
        $denomination = DeliveryCashDenomination::factory()->for($delivery)->create();
        $history = History::factory()->for($record)->for($user)->create([
            'record_detail_id' => $details->first()->id,
            'delivery_id' => $delivery->id,
        ]);

        $record->load('company', 'details', 'image.aiProcessing', 'delivery.cashDenominations', 'histories');

        $this->assertTrue($record->company->is($company));
        $this->assertCount(2, $record->details);
        $this->assertSame([1, 2], $record->details->pluck('order')->all());
        $this->assertSame(['AFF-FIRST', 'AFF-SECOND'], $record->details->pluck('affiliation')->all());
        $this->assertSame(['Guadalajara', 'Zapopan'], $record->details->pluck('branch')->all());
        $this->assertTrue($record->image->is($image));
        $this->assertTrue($record->image->aiProcessing->is($processing));
        $this->assertTrue($record->delivery->is($delivery));
        $this->assertTrue($record->delivery->cashDenominations->first()->is($denomination));
        $this->assertTrue($record->histories->first()->is($history));
        $this->assertTrue($history->user->is($user));
        $this->assertTrue($history->recordDetail->is($details->first()));
        $this->assertTrue($history->delivery->is($delivery));
    }

    public function test_a_record_cannot_have_more_than_one_active_image(): void
    {
        $record = Record::factory()->create();
        Image::factory()->for($record)->create();

        $this->expectException(QueryException::class);

        Image::factory()->for($record)->create();
    }

    public function test_an_image_cannot_have_more_than_one_ai_processing_row(): void
    {
        $image = Image::factory()->create();
        AiProcessing::factory()->for($image)->create();

        $this->expectException(QueryException::class);

        AiProcessing::factory()->for($image)->create();
    }

    public function test_a_record_cannot_have_more_than_one_delivery(): void
    {
        $record = Record::factory()->create();
        Delivery::factory()->for($record)->create();

        $this->expectException(QueryException::class);

        Delivery::factory()->for($record)->create();
    }

    public function test_a_delivery_cannot_repeat_a_cash_denomination(): void
    {
        $delivery = Delivery::factory()->create();
        DeliveryCashDenomination::factory()->for($delivery)->create(['value' => 0.50]);

        $this->expectException(QueryException::class);

        DeliveryCashDenomination::factory()->for($delivery)->create(['value' => 0.50]);
    }

    public function test_cash_denomination_quantity_must_be_positive(): void
    {
        $this->expectException(QueryException::class);

        DeliveryCashDenomination::factory()->create(['quantity' => 0]);
    }

    public function test_cash_denomination_value_must_be_official(): void
    {
        $this->expectException(QueryException::class);

        DeliveryCashDenomination::factory()->create(['value' => 7.00]);
    }
}
