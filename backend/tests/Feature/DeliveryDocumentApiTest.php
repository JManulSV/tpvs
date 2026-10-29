<?php

namespace Tests\Feature;

use App\Domain\Documents\DeliveryDocumentBuilder;
use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\Delivery;
use App\Models\DeliveryCashDenomination;
use App\Models\History;
use App\Models\Image;
use App\Models\Record;
use App\Models\RecordDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\EnforceCsrfToken;
use Tests\TestCase;
use ZipArchive;

class DeliveryDocumentApiTest extends TestCase
{
    use RefreshDatabase;

    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('record_images');
    }

    public function test_an_editor_downloads_a_valid_word_document_with_private_headers(): void
    {
        $delivery = $this->preparedDelivery();

        $response = $this->actingAs($this->user(UserRole::EDITOR), 'web')
            ->post('/api/deliveries/'.$delivery->id.'/document');

        $response->assertOk();
        $this->assertSame(self::DOCX_MIME, $response->headers->get('Content-Type'));

        $record = $delivery->record;
        $this->assertStringContainsString(
            'attachment; filename=entrega-registro-'.$record->id.'-semana-'.$record->week.'-'.$record->year.'.docx',
            $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', $response->headers->get('Cache-Control'));

        $text = $this->documentText($response->streamedContent());
        $this->assertStringContainsString('NOVECIENTOS VEINTE PESOS 00/100 M.N.', $text);
        $this->assertStringContainsString('GUADALAJARA, JALISCO.', $text);
    }

    public function test_jpeg_png_and_webp_images_all_generate_a_document_with_one_embedded_picture(): void
    {
        foreach (['jpeg' => 'jpg', 'png' => 'png', 'webp' => 'png'] as $type => $embeddedExtension) {
            $delivery = $this->preparedDelivery(imageType: $type);

            $response = $this->actingAs($this->user(UserRole::EDITOR), 'web')
                ->post('/api/deliveries/'.$delivery->id.'/document');

            $response->assertOk();
            $media = $this->mediaEntries($response->streamedContent());
            $this->assertCount(1, $media, $type);
            $this->assertStringEndsWith('.'.$embeddedExtension, $media[0], $type);
        }
    }

    public function test_generation_does_not_modify_the_record_delivery_denominations_or_image(): void
    {
        $delivery = $this->preparedDelivery();
        $imagePath = Storage::disk('record_images')->path($delivery->record->image->file_path);
        $imageHash = hash_file('sha256', $imagePath);
        $before = $this->snapshot($delivery);

        $this->actingAs($this->user(UserRole::EDITOR), 'web')
            ->post('/api/deliveries/'.$delivery->id.'/document')
            ->assertOk()
            ->streamedContent();

        $this->assertSame($before, $this->snapshot($delivery));
        $this->assertSame($imageHash, hash_file('sha256', $imagePath));
    }

    public function test_the_temporary_document_is_deleted_after_the_response_is_sent(): void
    {
        $delivery = $this->preparedDelivery();
        $before = $this->temporaryDocuments();

        $response = $this->actingAs($this->user(UserRole::EDITOR), 'web')
            ->post('/api/deliveries/'.$delivery->id.'/document');

        $path = $response->baseResponse->getFile()->getPathname();
        $this->assertFileExists($path);

        $response->streamedContent();

        $this->assertFileDoesNotExist($path);
        $this->assertSame($before, $this->temporaryDocuments());
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

    public function test_each_generation_adds_its_own_history_event_and_it_is_visible_in_record_history(): void
    {
        $delivery = $this->preparedDelivery();
        $editor = $this->user(UserRole::EDITOR);

        foreach (range(1, 2) as $_) {
            $this->actingAs($editor, 'web')->post('/api/deliveries/'.$delivery->id.'/document')->assertOk();
        }

        $this->assertSame(2, History::query()->where('field_name', 'document_generated')->count());

        $this->actingAs($editor, 'web')
            ->getJson('/api/records/'.$delivery->record_id.'/history')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.field_name', 'document_generated')
            ->assertJsonPath('data.0.reason', 'Documento Word generado');
    }

    public function test_requires_authentication(): void
    {
        $delivery = $this->preparedDelivery();

        $this->postJson('/api/deliveries/'.$delivery->id.'/document')->assertUnauthorized();

        $this->assertDatabaseCount('histories', 0);
    }

    public function test_a_viewer_cannot_generate_the_document(): void
    {
        $delivery = $this->preparedDelivery();

        $this->actingAs($this->user(UserRole::VIEWER), 'web')
            ->postJson('/api/deliveries/'.$delivery->id.'/document')
            ->assertForbidden();

        $this->assertDatabaseCount('histories', 0);
    }

    public function test_an_unknown_delivery_returns_not_found(): void
    {
        $this->actingAs($this->user(UserRole::EDITOR), 'web')
            ->postJson('/api/deliveries/999999/document')
            ->assertNotFound();
    }

    public function test_a_stateful_request_with_an_invalid_csrf_token_returns_419(): void
    {
        config(['sanctum.middleware.validate_csrf_token' => EnforceCsrfToken::class]);
        $delivery = $this->preparedDelivery();

        $this->withSession(['_token' => 'expected-csrf-token'])
            ->actingAs($this->user(UserRole::EDITOR), 'web')
            ->post('/api/deliveries/'.$delivery->id.'/document', [], [
                'Origin' => 'http://localhost:3000',
                'X-CSRF-TOKEN' => 'invalid-csrf-token',
            ])
            ->assertStatus(419);

        $this->assertDatabaseCount('histories', 0);
    }

    public function test_records_that_are_not_confirmed_are_blocked(): void
    {
        foreach ([RecordStatus::NEEDS_REVIEW, RecordStatus::CANCELED] as $status) {
            $delivery = $this->preparedDelivery(status: $status);

            $this->assertBlocked($delivery, ['reason' => 'INVALID_RECORD_STATE']);
        }

        $this->assertDatabaseCount('histories', 0);
    }

    public function test_a_record_without_an_image_is_blocked(): void
    {
        $this->assertBlocked($this->preparedDelivery(imageType: null), ['reason' => 'MISSING_IMAGE']);
    }

    public function test_a_delivery_prepared_with_an_older_amount_is_blocked(): void
    {
        $delivery = $this->preparedDelivery();
        RecordDetail::query()->where('record_id', $delivery->record_id)->update(['return_amount' => '1000.00']);

        $this->assertBlocked($delivery, [
            'reason' => 'STALE_DELIVERY',
            'prepared_amount' => '920.00',
            'current_amount' => '1000.00',
        ]);
    }

    public function test_a_cash_breakdown_that_does_not_match_is_blocked(): void
    {
        $delivery = $this->preparedDelivery();
        DeliveryCashDenomination::query()
            ->where('delivery_id', $delivery->id)
            ->where('value', '10.00')
            ->delete();

        $this->assertBlocked($delivery, [
            'reason' => 'CASH_MISMATCH',
            'expected_amount' => '920.00',
            'cash_amount' => '900.00',
            'difference' => '-20.00',
        ]);
    }

    public function test_an_unreadable_image_file_is_blocked_without_exposing_its_path(): void
    {
        $delivery = $this->preparedDelivery();
        Storage::disk('record_images')->delete($delivery->record->image->file_path);

        $response = $this->assertBlocked($delivery, ['reason' => 'IMAGE_UNAVAILABLE']);

        $this->assertStringNotContainsString($delivery->record->image->file_path, $response->getContent());
        $this->assertStringNotContainsString(Storage::disk('record_images')->path(''), $response->getContent());
    }

    public function test_a_builder_failure_returns_a_safe_500_without_history_or_leftovers(): void
    {
        $delivery = $this->preparedDelivery();
        $before = $this->temporaryDocuments();
        $this->mock(DeliveryDocumentBuilder::class)
            ->shouldReceive('build')
            ->andThrow(new RuntimeException('failure at /secret/storage/path'));

        $response = $this->actingAs($this->user(UserRole::EDITOR), 'web')
            ->postJson('/api/deliveries/'.$delivery->id.'/document');

        $response->assertStatus(500)
            ->assertExactJson(['message' => 'The delivery document could not be generated.']);
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertDatabaseCount('histories', 0);
        $this->assertSame($before, $this->temporaryDocuments());
    }

    public function test_a_history_failure_after_building_leaves_no_history_and_removes_the_document(): void
    {
        $delivery = $this->preparedDelivery();
        $before = $this->temporaryDocuments();
        Event::listen('eloquent.creating: '.History::class, static function (): void {
            throw new RuntimeException('history write failed');
        });

        $this->actingAs($this->user(UserRole::EDITOR), 'web')
            ->postJson('/api/deliveries/'.$delivery->id.'/document')
            ->assertStatus(500);

        $this->assertDatabaseCount('histories', 0);
        $this->assertSame($before, $this->temporaryDocuments());
    }

    /** @param  array<string, string>  $details */
    private function assertBlocked(Delivery $delivery, array $details): TestResponse
    {
        $before = $this->snapshot($delivery);
        $temporary = $this->temporaryDocuments();

        $response = $this->actingAs($this->user(UserRole::EDITOR), 'web')
            ->postJson('/api/deliveries/'.$delivery->id.'/document');

        $response->assertStatus(409)->assertExactJson([
            'message' => 'The delivery document cannot be generated.',
            'code' => 'DOCUMENT_GENERATION_BLOCKED',
            'details' => $details,
        ]);

        $this->assertSame(0, History::query()->where('field_name', 'document_generated')->count());
        $this->assertSame($before, $this->snapshot($delivery));
        $this->assertSame($temporary, $this->temporaryDocuments());

        return $response;
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function preparedDelivery(
        RecordStatus $status = RecordStatus::CONFIRMED,
        ?string $imageType = 'jpeg',
    ): Delivery {
        $record = Record::factory()->create(['status' => $status]);
        RecordDetail::factory()->create([
            'record_id' => $record->id,
            'income' => '1000.00',
            'commission' => '80.00',
            'return_amount' => '920.00',
            'order' => 1,
        ]);

        if ($imageType !== null) {
            $key = 'sample-'.$record->id.'.'.($imageType === 'jpeg' ? 'jpg' : $imageType);
            Storage::disk('record_images')->put($key, $this->imageBytes($imageType));
            Image::factory()->create([
                'record_id' => $record->id,
                'file_path' => $key,
                'mime_type' => 'image/'.$imageType,
            ]);
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

    private function imageBytes(string $type): string
    {
        $canvas = imagecreatetruecolor(320, 200);
        ob_start();
        match ($type) {
            'jpeg' => imagejpeg($canvas),
            'png' => imagepng($canvas),
            'webp' => imagewebp($canvas),
        };

        return ob_get_clean();
    }

    /** @return array<string, mixed> */
    private function snapshot(Delivery $delivery): array
    {
        $record = Record::query()->with('details', 'image')->findOrFail($delivery->record_id);

        return json_decode(json_encode([
            'record' => $record->only(['status', 'source', 'week', 'year', 'updated_at']),
            'details' => $record->details->map->only(['id', 'income', 'commission', 'return_amount', 'updated_at'])->all(),
            'image' => $record->image?->only(['id', 'file_path', 'mime_type', 'file_size', 'updated_at']),
            'delivery' => Delivery::query()->findOrFail($delivery->id)->only([
                'delivery_date', 'delivered_by_name', 'received_by_name', 'prepared_return_amount', 'updated_at',
            ]),
            'denominations' => DeliveryCashDenomination::query()
                ->where('delivery_id', $delivery->id)
                ->orderBy('value')
                ->get(['value', 'quantity'])
                ->toArray(),
        ]), true);
    }

    /** @return list<string> */
    private function temporaryDocuments(): array
    {
        $files = array_merge(
            glob(sys_get_temp_dir().'/tpvs-delivery-*') ?: [],
            glob(sys_get_temp_dir().'/tpvs-image-*') ?: [],
        );
        sort($files);

        return $files;
    }

    private function documentText(string $docx): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test-docx-');
        file_put_contents($path, $docx);

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path) === true, 'The response must be a valid docx file.');
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
        } finally {
            @unlink($path);
        }

        return html_entity_decode(strip_tags(preg_replace('/<\/w:p>/', "\n", $xml)), ENT_QUOTES | ENT_XML1);
    }

    /** @return list<string> */
    private function mediaEntries(string $docx): array
    {
        $path = tempnam(sys_get_temp_dir(), 'test-docx-');
        file_put_contents($path, $docx);

        try {
            $zip = new ZipArchive;
            $zip->open($path);
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (str_starts_with($name, 'word/media/')) {
                    $entries[] = $name;
                }
            }
            $zip->close();
        } finally {
            @unlink($path);
        }

        return $entries;
    }
}
