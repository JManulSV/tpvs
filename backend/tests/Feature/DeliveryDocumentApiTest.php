<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\Delivery;
use App\Models\DeliveryCashDenomination;
use App\Models\Image;
use App\Models\Record;
use App\Models\RecordDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeliveryDocumentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_editor_downloads_the_word_document(): void
    {
        $delivery = $this->preparedDelivery();

        $response = $this->actingAs($this->user(UserRole::EDITOR), 'web')
            ->post('/api/deliveries/'.$delivery->id.'/document');

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $response->headers->get('Content-Type'),
        );
        $record = $delivery->record;
        $this->assertStringContainsString(
            'attachment; filename=entrega-registro-'.$record->id.'-semana-'.$record->week.'-'.$record->year.'.docx',
            $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    public function test_a_successful_generation_writes_one_document_generated_event(): void
    {
        $delivery = $this->preparedDelivery();
        $editor = $this->user(UserRole::EDITOR);

        $this->actingAs($editor, 'web')->post('/api/deliveries/'.$delivery->id.'/document')->assertOk();

        $this->assertDatabaseCount('histories', 1);
        $this->assertDatabaseHas('histories', [
            'record_id' => $delivery->record_id,
            'delivery_id' => $delivery->id,
            'user_id' => $editor->id,
            'field_name' => 'document_generated',
            'previous_value' => null,
            'new_value' => null,
            'reason' => 'Documento Word generado',
        ]);
    }

    public function test_a_blocked_generation_writes_no_history(): void
    {
        $delivery = $this->preparedDelivery(withImage: false);

        $this->actingAs($this->user(UserRole::EDITOR), 'web')
            ->postJson('/api/deliveries/'.$delivery->id.'/document')
            ->assertStatus(409);

        $this->assertDatabaseCount('histories', 0);
    }

    public function test_requires_authentication_and_an_editor_role(): void
    {
        $delivery = $this->preparedDelivery();

        $this->postJson('/api/deliveries/'.$delivery->id.'/document')->assertUnauthorized();

        $this->actingAs($this->user(UserRole::VIEWER), 'web')
            ->postJson('/api/deliveries/'.$delivery->id.'/document')
            ->assertForbidden();
    }

    public function test_an_unknown_delivery_returns_not_found(): void
    {
        $this->actingAs($this->user(UserRole::EDITOR), 'web')
            ->postJson('/api/deliveries/999999/document')
            ->assertNotFound();
    }

    public function test_a_blocked_delivery_returns_a_conflict_with_the_reason(): void
    {
        $delivery = $this->preparedDelivery(withImage: false);

        $this->actingAs($this->user(UserRole::EDITOR), 'web')
            ->postJson('/api/deliveries/'.$delivery->id.'/document')
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'The delivery document cannot be generated.',
                'code' => 'DOCUMENT_GENERATION_BLOCKED',
                'details' => ['reason' => 'MISSING_IMAGE'],
            ]);
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function preparedDelivery(bool $withImage = true): Delivery
    {
        Storage::fake('record_images');

        $record = Record::factory()->create(['status' => RecordStatus::CONFIRMED]);
        RecordDetail::factory()->create([
            'record_id' => $record->id,
            'income' => '1000.00',
            'commission' => '80.00',
            'return_amount' => '920.00',
            'order' => 1,
        ]);

        if ($withImage) {
            $canvas = imagecreatetruecolor(320, 200);
            ob_start();
            imagejpeg($canvas);
            Storage::disk('record_images')->put('sample.jpg', ob_get_clean());
            Image::factory()->create(['record_id' => $record->id, 'file_path' => 'sample.jpg']);
        }

        $delivery = Delivery::factory()->create([
            'record_id' => $record->id,
            'prepared_return_amount' => '920.00',
        ]);

        foreach ([['500.00', 1], ['200.00', 2], ['10.00', 2]] as [$value, $quantity]) {
            DeliveryCashDenomination::factory()->create([
                'delivery_id' => $delivery->id,
                'value' => $value,
                'quantity' => $quantity,
            ]);
        }

        return $delivery;
    }
}
