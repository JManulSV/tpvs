<?php

namespace App\Domain\Documents;

use App\Domain\Deliveries\DeliveryValidator;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportQuery;
use App\Models\Record;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

class ReportWorkbookBuilder
{
    private const CURRENCY = '$#,##0.00';

    private const DATE = 'dd/mm/yyyy';

    private const DECIMAL = '0.00';

    private const INTEGER = '0';

    public function __construct(
        private readonly ReportQuery $reports,
        private readonly DeliveryValidator $validator,
    ) {}

    /**
     * Build the report workbook and return the path of a temporary `.xlsx`.
     * The caller owns the file and must delete it.
     */
    public function build(ReportFilters $filters): string
    {
        $path = $this->temporaryPath('tpvs-report-');
        $spreadsheet = null;

        try {
            $spreadsheet = $this->compose($filters);
            (new Xlsx($spreadsheet))->save($path);

            return $path;
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception;
        } finally {
            $spreadsheet?->disconnectWorksheets();
        }
    }

    private function compose(ReportFilters $filters): Spreadsheet
    {
        $records = $this->reports->export($filters);

        $spreadsheet = new Spreadsheet;
        $this->summarySheet($spreadsheet->getActiveSheet(), $filters);
        $this->recordsSheet($spreadsheet->createSheet(), $records);
        $this->detailsSheet($spreadsheet->createSheet(), $records);
        $this->cashSheet($spreadsheet->createSheet(), $records);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function summarySheet(Worksheet $sheet, ReportFilters $filters): void
    {
        $sheet->setTitle('Resumen');

        $rows = [];
        foreach ($this->reports->companies($filters) as $company) {
            $rows[] = [
                self::text($company['company']['name']),
                self::money($company['income_total']),
                self::money($company['commission_total']),
                self::money($company['return_total']),
            ];
        }

        $totals = $this->reports->totals($filters);
        $rows[] = [
            self::text('TOTAL'),
            self::money($totals['income_total']),
            self::money($totals['commission_total']),
            self::money($totals['return_total']),
        ];

        $this->table($sheet, ['Empresa', 'Ingreso', 'Comisión', 'Retorno'], $rows);
        $sheet->getStyle('A'.(count($rows) + 1).':D'.(count($rows) + 1))->getFont()->setBold(true);
    }

    /**
     * @param  Collection<int, Record>  $records
     */
    private function recordsSheet(Worksheet $sheet, Collection $records): void
    {
        $sheet->setTitle('Registros');

        $rows = $records->map(static fn (Record $record): array => [
            self::integer($record->getKey()),
            self::text($record->company->name),
            self::integer($record->year),
            self::integer($record->week),
            self::date($record->week_start_date),
            self::date($record->week_end_date),
            self::integer($record->details_count),
            self::money($record->income_total ?? '0.00'),
            self::money($record->commission_total ?? '0.00'),
            self::money($record->return_total ?? '0.00'),
            self::date($record->delivery?->delivery_date),
        ])->all();

        $this->table($sheet, [
            'ID registro', 'Empresa', 'Año', 'Semana', 'Inicio', 'Fin', 'Movimientos',
            'Ingreso', 'Comisión', 'Retorno', 'Fecha de entrega',
        ], $rows);
    }

    /**
     * @param  Collection<int, Record>  $records
     */
    private function detailsSheet(Worksheet $sheet, Collection $records): void
    {
        $sheet->setTitle('Detalles');

        $rows = [];
        foreach ($records as $record) {
            foreach ($record->details as $detail) {
                $rows[] = [
                    self::integer($record->getKey()),
                    self::integer($detail->order),
                    self::text($detail->affiliation),
                    self::text($detail->branch),
                    self::money($detail->income),
                    self::decimal($detail->commission_percentage),
                    self::money($detail->commission),
                    self::money($detail->return_amount),
                ];
            }
        }

        $this->table($sheet, [
            'ID registro', 'Orden', 'Afiliación', 'Sucursal', 'Ingreso', '% Comisión', 'Comisión', 'Retorno',
        ], $rows);
    }

    /**
     * @param  Collection<int, Record>  $records
     */
    private function cashSheet(Worksheet $sheet, Collection $records): void
    {
        $sheet->setTitle('Efectivo');

        $rows = [];
        foreach ($records as $record) {
            $delivery = $record->delivery;
            if ($delivery === null) {
                continue;
            }

            $quantities = [];
            foreach ($delivery->cashDenominations as $denomination) {
                $quantities[(string) $denomination->value] = (int) $denomination->quantity;
            }

            $row = [
                self::integer($record->getKey()),
                self::text($record->company->name),
                self::integer($record->week),
                self::date($delivery->delivery_date),
                self::text($delivery->delivered_by_name),
                self::text($delivery->received_by_name),
                self::money($delivery->prepared_return_amount),
            ];

            foreach (DeliveryValidator::DENOMINATIONS as $denomination) {
                $row[] = self::integer($quantities[$denomination] ?? 0);
            }

            $cash = $delivery->cashDenominations
                ->map(static fn ($denomination): array => [
                    'value' => (string) $denomination->value,
                    'quantity' => (int) $denomination->quantity,
                ])
                ->all();

            $row[] = self::money($this->validator->cashTotal($cash));
            $row[] = self::text(
                $this->validator->isStale((string) $delivery->prepared_return_amount, (string) ($record->return_total ?? '0.00'))
                    ? 'Sí'
                    : 'No',
            );

            $rows[] = $row;
        }

        $this->table($sheet, [
            'ID registro', 'Empresa', 'Semana', 'Fecha de entrega', 'Entrega', 'Recibe', 'Importe preparado',
            ...array_map(static fn (string $value): string => str_ends_with($value, '.00') ? substr($value, 0, -3) : $value, DeliveryValidator::DENOMINATIONS),
            'Total efectivo', 'Desactualizada',
        ], $rows);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<array{0: string, 1: mixed}>>  $rows
     */
    private function table(Worksheet $sheet, array $headers, array $rows): void
    {
        foreach ($headers as $index => $header) {
            $sheet->getCell([$index + 1, 1])->setValueExplicit($header, DataType::TYPE_STRING);
        }

        $sheet->getStyle([1, 1, count($headers), 1])->getFont()->setBold(true);
        $sheet->freezePane('A2');

        foreach ($rows as $rowIndex => $cells) {
            foreach ($cells as $columnIndex => $cell) {
                $this->put($sheet, $columnIndex + 1, $rowIndex + 2, $cell);
            }
        }

        foreach (range(1, count($headers)) as $column) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }
    }

    /**
     * @param  array{0: string, 1: mixed}  $cell
     */
    private function put(Worksheet $sheet, int $column, int $row, array $cell): void
    {
        [$kind, $value] = $cell;

        if ($value === null) {
            return;
        }

        $target = $sheet->getCell([$column, $row]);
        $format = $sheet->getStyle([$column, $row, $column, $row])->getNumberFormat();

        switch ($kind) {
            case 'text':
                $target->setValueExplicit((string) $value, DataType::TYPE_STRING);
                break;
            case 'money':
                $target->setValueExplicit((float) $value, DataType::TYPE_NUMERIC);
                $format->setFormatCode(self::CURRENCY);
                break;
            case 'decimal':
                $target->setValueExplicit((float) $value, DataType::TYPE_NUMERIC);
                $format->setFormatCode(self::DECIMAL);
                break;
            case 'integer':
                $target->setValueExplicit((int) $value, DataType::TYPE_NUMERIC);
                $format->setFormatCode(self::INTEGER);
                break;
            case 'date':
                $target->setValueExplicit(Date::PHPToExcel($value), DataType::TYPE_NUMERIC);
                $format->setFormatCode(self::DATE);
                break;
        }
    }

    /**
     * @return array{0: string, 1: mixed}
     */
    private static function text(?string $value): array
    {
        return ['text', $value];
    }

    /**
     * @return array{0: string, 1: mixed}
     */
    private static function money(mixed $value): array
    {
        return ['money', $value];
    }

    /**
     * @return array{0: string, 1: mixed}
     */
    private static function decimal(mixed $value): array
    {
        return ['decimal', $value];
    }

    /**
     * @return array{0: string, 1: mixed}
     */
    private static function integer(mixed $value): array
    {
        return ['integer', $value];
    }

    /**
     * @return array{0: string, 1: mixed}
     */
    private static function date(mixed $value): array
    {
        return ['date', $value];
    }

    private function temporaryPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw new RuntimeException('A temporary file could not be created.');
        }

        return $path;
    }
}
