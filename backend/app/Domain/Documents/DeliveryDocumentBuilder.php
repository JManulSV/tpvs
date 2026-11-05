<?php

namespace App\Domain\Documents;

use App\Domain\Deliveries\DeliveryValidator;
use App\Models\Delivery;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use RuntimeException;
use Throwable;

class DeliveryDocumentBuilder
{
    private const FONT = 'Arial';

    private const FONT_SIZE = 11;

    /** Maximum image box in points, sized so the receipt fits on one Letter page. */
    private const IMAGE_MAX_WIDTH = 420;

    private const IMAGE_MAX_HEIGHT = 230;

    public function __construct(
        private readonly DeliveryValidator $validator,
        private readonly MoneyToWordsService $moneyToWords,
        private readonly SpanishDateFormatter $dates,
    ) {}

    /**
     * Build the delivery receipt and return the path of a temporary `.docx`.
     * The caller owns the file and must delete it.
     */
    public function build(
        Delivery $delivery,
        string $imagePath,
        string $documentReturnAmount,
        bool $customerWillGiveChange,
    ): string {
        $delivery->loadMissing('record', 'cashDenominations');

        $temporaryImage = null;
        $documentPath = null;

        try {
            [$imagePath, $temporaryImage] = $this->imageForDocument($imagePath);

            $documentPath = $this->temporaryPath('tpvs-delivery-');
            $phpWord = $this->compose($delivery, $imagePath, $documentReturnAmount, $customerWillGiveChange);

            IOFactory::createWriter($phpWord, 'Word2007')->save($documentPath);

            return $documentPath;
        } catch (Throwable $exception) {
            if ($documentPath !== null) {
                @unlink($documentPath);
            }

            throw $exception;
        } finally {
            if ($temporaryImage !== null) {
                @unlink($temporaryImage);
            }
        }
    }

    private function compose(
        Delivery $delivery,
        string $imagePath,
        string $documentReturnAmount,
        bool $customerWillGiveChange,
    ): PhpWord {
        Settings::setOutputEscapingEnabled(true);

        $record = $delivery->record;
        $denominations = $delivery->cashDenominations
            ->mapWithKeys(fn ($row): array => [
                (string) $this->validator->canonicalDenomination((string) $row->value) => (int) $row->quantity,
            ]);
        $cashTotal = $this->validator->cashTotal(
            $denominations->map(fn (int $quantity, string $value): array => compact('value', 'quantity'))->values()->all(),
        );
        $reconciliation = $this->validator->documentCashReconciliation(
            $documentReturnAmount,
            $denominations->map(fn (int $quantity, string $value): array => compact('value', 'quantity'))->values()->all(),
            $customerWillGiveChange,
        );

        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName(self::FONT);
        $phpWord->setDefaultFontSize(self::FONT_SIZE);
        $phpWord->setDefaultParagraphStyle(['spaceAfter' => 0, 'spacing' => 0]);

        $section = $phpWord->addSection([
            'pageSizeW' => 12240,
            'pageSizeH' => 15840,
            'marginTop' => 1134,
            'marginBottom' => 1134,
            'marginLeft' => 1134,
            'marginRight' => 1134,
        ]);

        $section->addText(
            $this->dates->heading($delivery->delivery_date).', GUADALAJARA, JALISCO.',
            ['bold' => true],
            ['alignment' => Jc::END, 'spaceAfter' => 360],
        );

        $section->addText(
            sprintf(
                'Mediante la presente hago constar que recibí por parte del C. %s, la cantidad de %s (%s) '
                .'por concepto del uso de la terminal bancaria, correspondiente a la semana del %s',
                mb_strtoupper($delivery->delivered_by_name),
                $this->money($documentReturnAmount),
                $this->moneyToWords->convert($documentReturnAmount),
                $this->dates->weekRange($record->week_start_date, $record->week_end_date),
            ),
            [],
            ['alignment' => Jc::BOTH, 'spaceAfter' => 240],
        );

        [$width, $height] = $this->scaledImageSize($imagePath);
        $section->addImage($imagePath, [
            'width' => $width,
            'height' => $height,
            'alignment' => Jc::CENTER,
        ]);

        $section->addText(
            'Mismos que se entregan de la siguiente manera:',
            [],
            ['spaceBefore' => 240, 'spaceAfter' => 120],
        );

        $this->addCashTable($section, $denominations->all());

        $section->addText(
            'Efectivo bruto: '.$this->money($cashTotal),
            ['bold' => true],
            ['alignment' => Jc::END, 'spaceBefore' => 60],
        );
        $section->addText(
            'Importe de entrega: '.$this->money($documentReturnAmount),
            ['bold' => true],
            ['alignment' => Jc::END],
        );

        if ($reconciliation['change_amount'] !== null) {
            $section->addText(
                'Cambio que dará el cliente: '.$this->money($reconciliation['change_amount']),
                ['bold' => true],
                ['alignment' => Jc::END],
            );
        }

        $this->addSignatures($section, $delivery);

        return $phpWord;
    }

    /** @param  array<string, int>  $quantities  keyed by canonical denomination */
    private function addCashTable(Section $section, array $quantities): void
    {
        $table = $section->addTable([
            'borderSize' => 6,
            'borderColor' => '000000',
            'alignment' => Jc::CENTER,
            'cellMargin' => 40,
        ]);

        $header = ['bold' => true];
        $table->addRow();
        $table->addCell(2800)->addText('DENOMINACION', $header, ['alignment' => Jc::CENTER]);
        $table->addCell(2000)->addText('CANTIDAD', $header, ['alignment' => Jc::CENTER]);
        $table->addCell(2800)->addText('TOTAL', $header, ['alignment' => Jc::CENTER]);

        foreach (DeliveryValidator::DENOMINATIONS as $value) {
            $quantity = $quantities[$value] ?? 0;

            $table->addRow();
            $table->addCell(2800)->addText($this->denominationLabel($value), [], ['alignment' => Jc::END]);
            $table->addCell(2000)->addText((string) $quantity, [], ['alignment' => Jc::CENTER]);
            $table->addCell(2800)->addText(
                $this->money($this->validator->cashTotal([['value' => $value, 'quantity' => $quantity]])),
                [],
                ['alignment' => Jc::END],
            );
        }
    }

    private function addSignatures(Section $section, Delivery $delivery): void
    {
        $section->addTextBreak(2);

        $table = $section->addTable(['alignment' => Jc::CENTER]);

        $table->addRow();
        $table->addCell(4500)->addText('ENTREGA', ['bold' => true], ['alignment' => Jc::CENTER]);
        $table->addCell(4500)->addText('RECIBE', ['bold' => true], ['alignment' => Jc::CENTER]);

        $table->addRow(900);
        $table->addCell(4500, ['valign' => 'bottom'])->addText('____________________', [], ['alignment' => Jc::CENTER]);
        $table->addCell(4500, ['valign' => 'bottom'])->addText('____________________', [], ['alignment' => Jc::CENTER]);

        $table->addRow();
        $table->addCell(4500)->addText(
            mb_strtoupper($delivery->delivered_by_name),
            ['size' => 10],
            ['alignment' => Jc::CENTER],
        );
        $table->addCell(4500)->addText(
            mb_strtoupper((string) $delivery->received_by_name),
            ['size' => 10],
            ['alignment' => Jc::CENTER],
        );
    }

    /**
     * JPEG and PNG are used as stored. WebP is converted to a temporary PNG
     * because Word does not reliably render it; the original is never touched.
     *
     * @return array{0: string, 1: string|null} the path to embed and the temporary file to delete
     */
    private function imageForDocument(string $imagePath): array
    {
        $metadata = @getimagesize($imagePath);

        if ($metadata === false) {
            throw new RuntimeException('The image could not be read for the document.');
        }

        return match ($metadata['mime']) {
            'image/jpeg', 'image/png' => [$imagePath, null],
            'image/webp' => $this->convertWebpToPng($imagePath),
            default => throw new RuntimeException('Unsupported image type for the document.'),
        };
    }

    /** @return array{0: string, 1: string} */
    private function convertWebpToPng(string $imagePath): array
    {
        $source = @imagecreatefromwebp($imagePath);

        if ($source === false) {
            throw new RuntimeException('The WebP image could not be converted for the document.');
        }

        $target = $this->temporaryPath('tpvs-image-');

        try {
            imagepalettetotruecolor($source);
            imagealphablending($source, true);
            imagesavealpha($source, true);

            if (! imagepng($source, $target)) {
                throw new RuntimeException('The WebP image could not be converted for the document.');
            }
        } catch (Throwable $exception) {
            @unlink($target);

            throw $exception;
        } finally {
            imagedestroy($source);
        }

        return [$target, $target];
    }

    /** @return array{0: int, 1: int} */
    private function scaledImageSize(string $imagePath): array
    {
        [$width, $height] = getimagesize($imagePath);

        $scale = min(self::IMAGE_MAX_WIDTH / $width, self::IMAGE_MAX_HEIGHT / $height, 1);

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    private function temporaryPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw new RuntimeException('A temporary file could not be created.');
        }

        return $path;
    }

    /** "1000.00" => "1000", "0.50" => "0.5" */
    private function denominationLabel(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.');
    }

    /** "9800.50" => "$9,800.50" */
    private function money(string $amount): string
    {
        [$whole, $fraction] = explode('.', $amount);

        return '$'.number_format((int) $whole).'.'.$fraction;
    }
}
