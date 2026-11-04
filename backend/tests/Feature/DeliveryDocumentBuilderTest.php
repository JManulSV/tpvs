<?php

namespace Tests\Feature;

use App\Domain\Documents\DeliveryDocumentBuilder;
use App\Enums\RecordStatus;
use App\Models\Delivery;
use App\Models\DeliveryCashDenomination;
use App\Models\Record;
use App\Models\RecordDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class DeliveryDocumentBuilderTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_it_builds_the_office_receipt_with_all_expected_content(): void
    {
        $delivery = $this->delivery();

        $path = $this->build($delivery, $this->image('jpeg'));
        $text = $this->documentText($path);

        $this->assertStringContainsString('MIÉRCOLES 08 DE JULIO DE 2026, GUADALAJARA, JALISCO.', $text);
        $this->assertStringContainsString('C. SOL QUEZAL FLORES GÓMEZ', $text);
        $this->assertStringContainsString('la cantidad de $920.00 (NOVECIENTOS VEINTE PESOS 00/100 M.N.)', $text);
        $this->assertStringContainsString('uso de la terminal bancaria', $text);
        $this->assertStringContainsString('07 DE SEPTIEMBRE AL 13 DE SEPTIEMBRE DE 2026', $text);
        $this->assertStringContainsString('Mismos que se entregan de la siguiente manera:', $text);
        $this->assertStringContainsString('Efectivo bruto: $920.00', $text);
        $this->assertStringContainsString('Importe de entrega: $920.00', $text);
        $this->assertStringContainsString('ENTREGA', $text);
        $this->assertStringContainsString('RECIBE', $text);
    }

    public function test_the_denomination_table_lists_all_eleven_rows_with_quantities_and_totals(): void
    {
        $path = $this->build($this->delivery(), $this->image('png'));
        $rows = $this->tableRows($path, 0);

        $this->assertCount(12, $rows);
        $this->assertSame(['DENOMINACION', 'CANTIDAD', 'TOTAL'], $rows[0]);
        $this->assertSame(
            [
                ['1000', '0', '$0.00'],
                ['500', '1', '$500.00'],
                ['200', '2', '$400.00'],
                ['100', '0', '$0.00'],
                ['50', '0', '$0.00'],
                ['20', '0', '$0.00'],
                ['10', '2', '$20.00'],
                ['5', '0', '$0.00'],
                ['2', '0', '$0.00'],
                ['1', '0', '$0.00'],
                ['0.5', '0', '$0.00'],
            ],
            array_slice($rows, 1),
        );
    }

    public function test_cash_and_document_totals_are_labelled_below_the_table_and_signatures_carry_names(): void
    {
        $path = $this->build($this->delivery(), $this->image('jpeg'));
        $text = $this->documentText($path);

        $belowTable = substr($text, strpos($text, 'DENOMINACION'));
        $this->assertStringContainsString('Efectivo bruto: $920.00', $belowTable);
        $this->assertStringContainsString('Importe de entrega: $920.00', $belowTable);

        $signatures = $this->tableRows($path, 1);
        $this->assertSame(['ENTREGA', 'RECIBE'], $signatures[0]);
        $this->assertSame(['SOL QUEZAL FLORES GÓMEZ', 'JUAN PÉREZ'], $signatures[2]);
    }

    public function test_document_uses_the_adjusted_amount_in_numbers_and_words_and_prints_customer_change(): void
    {
        $path = $this->build(
            $this->delivery(),
            $this->image('jpeg'),
            '900.50',
            true,
        );
        $text = $this->documentText($path);

        $this->assertStringContainsString(
            'la cantidad de $900.50 (NOVECIENTOS PESOS 50/100 M.N.)',
            $text,
        );
        $this->assertStringContainsString('Efectivo bruto: $920.00', $text);
        $this->assertStringContainsString('Importe de entrega: $900.50', $text);
        $this->assertStringContainsString('Cambio que dará el cliente: $19.50', $text);
    }

    public function test_receiver_name_is_blank_when_not_provided(): void
    {
        $path = $this->build($this->delivery(receivedBy: null), $this->image('jpeg'));

        $this->assertSame(['SOL QUEZAL FLORES GÓMEZ', ''], $this->tableRows($path, 1)[2]);
    }

    public function test_week_range_across_years_shows_both_years(): void
    {
        $path = $this->build(
            $this->delivery(weekStart: '2025-12-29', weekEnd: '2026-01-04'),
            $this->image('jpeg'),
        );

        $this->assertStringContainsString(
            '29 DE DICIEMBRE DE 2025 AL 04 DE ENERO DE 2026',
            $this->documentText($path),
        );
    }

    public function test_special_characters_in_names_are_escaped(): void
    {
        $path = $this->build(
            $this->delivery(deliveredBy: 'Pérez & Hijos <S.A.>'),
            $this->image('jpeg'),
        );

        $this->assertStringContainsString('PÉREZ & HIJOS <S.A.>', $this->documentText($path));
    }

    public function test_jpeg_and_png_images_are_embedded_with_their_original_format(): void
    {
        foreach (['jpeg' => 'jpg', 'png' => 'png'] as $type => $extension) {
            $path = $this->build($this->delivery(), $this->image($type));

            $media = $this->mediaEntries($path);
            $this->assertCount(1, $media, $type);
            $this->assertStringEndsWith('.'.$extension, $media[0], $type);
        }
    }

    public function test_webp_is_converted_to_png_without_touching_the_original(): void
    {
        $original = $this->image('webp');
        $originalHash = hash_file('sha256', $original);

        $path = $this->build($this->delivery(), $original);

        $media = $this->mediaEntries($path);
        $this->assertCount(1, $media);
        $this->assertStringEndsWith('.png', $media[0]);
        $this->assertSame($originalHash, hash_file('sha256', $original));
        $this->assertFileExists($original);
    }

    public function test_large_images_are_scaled_proportionally_to_fit_the_page(): void
    {
        $path = $this->build($this->delivery(), $this->image('jpeg', 3000, 2000));

        preg_match('/<v:shape [^>]*style="width:(\d+)pt; height:(\d+)pt/', $this->documentXml($path), $match);

        $this->assertNotEmpty($match);
        $width = (int) $match[1];
        $height = (int) $match[2];
        $this->assertEqualsWithDelta(3 / 2, $width / $height, 0.02);
        $this->assertLessThanOrEqual(420, $width);
        $this->assertLessThanOrEqual(230, $height);
    }

    public function test_an_unreadable_image_fails_and_leaves_no_temporary_documents(): void
    {
        $before = glob(sys_get_temp_dir().'/tpvs-delivery-*') ?: [];
        $broken = $this->temporary('broken-');
        file_put_contents($broken, 'not an image');

        try {
            $this->builder()->build($this->delivery(), $broken, '920.00', false);
            $this->fail('A RuntimeException was expected.');
        } catch (RuntimeException) {
            $this->assertSame($before, glob(sys_get_temp_dir().'/tpvs-delivery-*') ?: []);
        }
    }

    public function test_building_does_not_modify_the_delivery_or_record(): void
    {
        $delivery = $this->delivery();
        $updatedAt = $delivery->fresh()->updated_at;
        $recordUpdatedAt = $delivery->record->fresh()->updated_at;

        $this->build($delivery, $this->image('jpeg'));

        $this->assertEquals($updatedAt, $delivery->fresh()->updated_at);
        $this->assertEquals($recordUpdatedAt, $delivery->record->fresh()->updated_at);
        $this->assertSame(3, DeliveryCashDenomination::query()->count());
    }

    private function delivery(
        string $deliveredBy = 'Sol Quezal Flores Gómez',
        ?string $receivedBy = 'Juan Pérez',
        string $weekStart = '2026-09-07',
        string $weekEnd = '2026-09-13',
    ): Delivery {
        $record = Record::factory()->create([
            'status' => RecordStatus::CONFIRMED,
            'year' => 2026,
            'week_start_date' => $weekStart,
            'week_end_date' => $weekEnd,
        ]);

        RecordDetail::factory()->create([
            'record_id' => $record->getKey(),
            'income' => '1000.00',
            'commission' => '80.00',
            'return_amount' => '920.00',
            'order' => 1,
        ]);

        $delivery = Delivery::factory()->create([
            'record_id' => $record->getKey(),
            'delivery_date' => '2026-07-08',
            'delivered_by_name' => $deliveredBy,
            'received_by_name' => $receivedBy,
            'prepared_return_amount' => '920.00',
        ]);

        foreach ([['500.00', 1], ['200.00', 2], ['10.00', 2]] as [$value, $quantity]) {
            DeliveryCashDenomination::factory()->create([
                'delivery_id' => $delivery->getKey(),
                'value' => $value,
                'quantity' => $quantity,
            ]);
        }

        return $delivery;
    }

    private function builder(): DeliveryDocumentBuilder
    {
        return $this->app->make(DeliveryDocumentBuilder::class);
    }

    private function build(
        Delivery $delivery,
        string $imagePath,
        string $documentReturnAmount = '920.00',
        bool $customerWillGiveChange = false,
    ): string {
        $path = $this->builder()->build($delivery, $imagePath, $documentReturnAmount, $customerWillGiveChange);
        $this->cleanup[] = $path;

        return $path;
    }

    private function image(string $type, int $width = 320, int $height = 200): string
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocate($canvas, 40, 120, 200));

        $path = $this->temporary('image-').'.'.$type;
        match ($type) {
            'jpeg' => imagejpeg($canvas, $path),
            'png' => imagepng($canvas, $path),
            'webp' => imagewebp($canvas, $path),
        };
        imagedestroy($canvas);

        return $path;
    }

    private function temporary(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        $this->cleanup[] = $path;

        return $path;
    }

    private function documentXml(string $path): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The result must be a valid zip/docx file.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        return $xml;
    }

    private function documentText(string $path): string
    {
        $xml = preg_replace('/<\/w:p>/', "\n", $this->documentXml($path));

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1);
    }

    /** @return list<list<string>> */
    private function tableRows(string $path, int $tableIndex): array
    {
        $dom = new \DOMDocument;
        $dom->loadXML($this->documentXml($path));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $rows = [];
        foreach ($xpath->query('//w:tbl')->item($tableIndex)->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'tr') as $row) {
            $cells = [];
            foreach ($xpath->query('./w:tc', $row) as $cell) {
                $cells[] = $cell->textContent;
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /** @return list<string> */
    private function mediaEntries(string $path): array
    {
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

        return $entries;
    }
}
