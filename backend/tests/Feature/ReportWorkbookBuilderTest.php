<?php

namespace Tests\Feature;

use App\Domain\Documents\ReportWorkbookBuilder;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportQuery;
use App\Enums\RecordStatus;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\DeliveryCashDenomination;
use App\Models\Record;
use App\Models\RecordDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Tests\TestCase;

class ReportWorkbookBuilderTest extends TestCase
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

    public function test_the_workbook_has_exactly_the_four_spanish_sheets_in_order(): void
    {
        $this->scenario();

        $spreadsheet = $this->workbook(new ReportFilters(2026));

        $this->assertSame(['Resumen', 'Registros', 'Detalles', 'Efectivo'], $spreadsheet->getSheetNames());
        $this->assertSame(0, $spreadsheet->getActiveSheetIndex());
    }

    public function test_summary_has_one_row_per_company_and_a_total_matching_the_report_totals(): void
    {
        $this->scenario();
        $filters = new ReportFilters(2026);

        $sheet = $this->workbook($filters)->getSheetByName('Resumen');
        $totals = $this->app->make(ReportQuery::class)->totals($filters);

        $this->assertSame(['Empresa', 'Ingreso', 'Comisión', 'Retorno'], $this->row($sheet, 1, 4));
        $this->assertSame(['Acme', 1500.0, 150.0, 1350.0], $this->row($sheet, 2, 4));
        $this->assertSame(['Zeta', 300.0, 49.5, 250.5], $this->row($sheet, 3, 4));
        $this->assertSame(
            ['TOTAL', (float) $totals['income_total'], (float) $totals['commission_total'], (float) $totals['return_total']],
            $this->row($sheet, 4, 4),
        );
        $this->assertSame(4, $sheet->getHighestRow());
        $this->assertTrue($sheet->getStyle('A4')->getFont()->getBold());
    }

    public function test_amounts_are_numeric_cells_with_currency_format(): void
    {
        $this->scenario();

        $sheet = $this->workbook(new ReportFilters(2026))->getSheetByName('Resumen');

        foreach (['B2', 'C2', 'D2', 'B4'] as $coordinate) {
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($coordinate)->getDataType(), $coordinate);
            $this->assertSame('$#,##0.00', $sheet->getStyle($coordinate)->getNumberFormat()->getFormatCode(), $coordinate);
        }
    }

    public function test_records_sheet_lists_one_row_per_record_without_affiliation_or_branch(): void
    {
        $scenario = $this->scenario();

        $sheet = $this->workbook(new ReportFilters(2026))->getSheetByName('Registros');

        $this->assertSame([
            'ID registro', 'Empresa', 'Año', 'Semana', 'Inicio', 'Fin', 'Movimientos',
            'Ingreso', 'Comisión', 'Retorno', 'Fecha de entrega',
        ], $this->row($sheet, 1, 11));
        $this->assertSame(3, $sheet->getHighestRow() - 1);

        $first = $this->row($sheet, 2, 11);
        $this->assertSame($scenario['acmeWithDelivery']->getKey(), (int) $first[0]);
        $this->assertSame('Acme', $first[1]);
        $this->assertSame(2026, (int) $first[2]);
        $this->assertSame(38, (int) $first[3]);
        $this->assertSame(2, (int) $first[6]);
        $this->assertSame([1000.0, 100.0, 900.0], array_slice($first, 7, 3));

        $this->assertSame('2026-09-07', Date::excelToDateTimeObject($sheet->getCell('E2')->getValue())->format('Y-m-d'));
        $this->assertSame('2026-09-13', Date::excelToDateTimeObject($sheet->getCell('F2')->getValue())->format('Y-m-d'));
        $this->assertSame('2026-09-15', Date::excelToDateTimeObject($sheet->getCell('K2')->getValue())->format('Y-m-d'));
        foreach (['E2', 'F2', 'K2'] as $coordinate) {
            $this->assertSame('dd/mm/yyyy', $sheet->getStyle($coordinate)->getNumberFormat()->getFormatCode(), $coordinate);
            $this->assertTrue(Date::isDateTime($sheet->getCell($coordinate)), $coordinate);
        }

        $this->assertNull($sheet->getCell('K3')->getValue(), 'A record without Delivery has an empty delivery date.');
        $this->assertNotContains('Afiliación', $this->row($sheet, 1, 11));
        $this->assertNotContains('Sucursal', $this->row($sheet, 1, 11));
    }

    public function test_details_sheet_lists_every_movement_with_blank_percentage_for_fixed_commissions(): void
    {
        $scenario = $this->scenario();

        $sheet = $this->workbook(new ReportFilters(2026))->getSheetByName('Detalles');

        $this->assertSame(
            ['ID registro', 'Orden', 'Afiliación', 'Sucursal', 'Ingreso', '% Comisión', 'Comisión', 'Retorno'],
            $this->row($sheet, 1, 8),
        );
        $this->assertSame(4, $sheet->getHighestRow() - 1);

        $recordId = $scenario['acmeWithDelivery']->getKey();
        $this->assertSame([$recordId, 1, 'AFF-1', 'Centro', 600.0, 10.0, 60.0, 540.0], $this->typed($this->row($sheet, 2, 8)));
        $this->assertSame([$recordId, 2, 'AFF-2', 'Norte', 400.0, null, 40.0, 360.0], $this->typed($this->row($sheet, 3, 8)));
        $this->assertSame('0.00', $sheet->getStyle('F2')->getNumberFormat()->getFormatCode());
    }

    public function test_cash_sheet_has_one_row_per_delivery_with_all_denominations_and_totals(): void
    {
        $scenario = $this->scenario();

        $sheet = $this->workbook(new ReportFilters(2026))->getSheetByName('Efectivo');

        $this->assertSame([
            'ID registro', 'Empresa', 'Semana', 'Fecha de entrega', 'Entrega', 'Recibe', 'Importe preparado',
            '1000', '500', '200', '100', '50', '20', '10', '5', '2', '1', '0.50',
            'Total efectivo', 'Desactualizada',
        ], $this->row($sheet, 1, 20));
        $this->assertSame(2, $sheet->getHighestRow() - 1, 'Records without a Delivery do not appear.');

        $first = $this->row($sheet, 2, 20);
        $this->assertSame($scenario['acmeWithDelivery']->getKey(), (int) $first[0]);
        $this->assertSame('Acme', $first[1]);
        $this->assertSame('Ana', $first[4]);
        $this->assertSame('Beto', $first[5]);
        $this->assertSame(900.0, $first[6]);
        $this->assertSame([0, 1, 2, 0, 0, 0, 0, 0, 0, 0, 0], array_map('intval', array_slice($first, 7, 11)));
        $this->assertSame(900.0, $first[18]);
        $this->assertSame('No', $first[19]);

        $second = $this->row($sheet, 3, 20);
        $this->assertSame(270.0, $second[6]);
        $this->assertSame('Sí', $second[19], 'A Delivery prepared for an older amount is marked as outdated.');
        $this->assertSame(250.5, $second[18]);
        $this->assertSame(1, (int) $second[17]);
        $this->assertSame('$#,##0.00', $sheet->getStyle('S2')->getNumberFormat()->getFormatCode());
    }

    public function test_an_empty_result_still_has_four_sheets_with_headers_and_a_zero_total(): void
    {
        $this->scenario();

        $spreadsheet = $this->workbook(new ReportFilters(1999));

        $this->assertSame(['Resumen', 'Registros', 'Detalles', 'Efectivo'], $spreadsheet->getSheetNames());
        $summary = $spreadsheet->getSheetByName('Resumen');
        $this->assertSame(2, $summary->getHighestRow());
        $this->assertSame(['TOTAL', 0.0, 0.0, 0.0], $this->row($summary, 2, 4));

        foreach (['Registros', 'Detalles', 'Efectivo'] as $name) {
            $this->assertSame(1, $spreadsheet->getSheetByName($name)->getHighestRow(), $name);
        }
    }

    public function test_non_confirmed_records_and_other_filters_are_excluded(): void
    {
        $scenario = $this->scenario();
        $this->record($scenario['acme'], ['status' => RecordStatus::NEEDS_REVIEW], [['9999.00', '999.00']]);

        $sheet = $this->workbook(new ReportFilters(2026, month: 9, companyId: $scenario['acme']->getKey()))
            ->getSheetByName('Registros');

        $this->assertSame(2, $sheet->getHighestRow() - 1);
        $this->assertSame(['Acme', 'Acme'], [$sheet->getCell('B2')->getValue(), $sheet->getCell('B3')->getValue()]);
        $this->assertSame([1000.0, 500.0], [(float) $sheet->getCell('H2')->getValue(), (float) $sheet->getCell('H3')->getValue()]);
    }

    public function test_text_that_looks_like_a_formula_is_stored_as_text(): void
    {
        $company = Company::factory()->create(['name' => '=HYPERLINK("http://x","Acme")']);
        $record = $this->record($company, [], [['10.00', '1.00']]);
        $record->details()->update(['affiliation' => '=1+1', 'branch' => '@SUM(1)']);

        $spreadsheet = $this->workbook(new ReportFilters(2026));

        $details = $spreadsheet->getSheetByName('Detalles');
        $this->assertSame(DataType::TYPE_STRING, $details->getCell('C2')->getDataType());
        $this->assertSame('=1+1', $details->getCell('C2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $details->getCell('D2')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $spreadsheet->getSheetByName('Resumen')->getCell('A2')->getDataType());
    }

    public function test_decimal_totals_keep_cent_precision(): void
    {
        $company = Company::factory()->create();
        $this->record($company, [], [['0.10', '0.00'], ['0.20', '0.00']]);

        $sheet = $this->workbook(new ReportFilters(2026))->getSheetByName('Resumen');

        $this->assertEqualsWithDelta(0.30, $sheet->getCell('B3')->getValue(), 0.0000001);
        $this->assertSame('0.30', number_format($sheet->getCell('B3')->getValue(), 2, '.', ''));
    }

    public function test_a_failure_leaves_no_temporary_workbook(): void
    {
        $before = glob(sys_get_temp_dir().'/tpvs-report-*') ?: [];
        $this->partialMock(ReportQuery::class, static function ($mock): void {
            $mock->shouldReceive('export')->andThrow(new RuntimeException('boom'));
        });

        try {
            $this->app->make(ReportWorkbookBuilder::class)->build(new ReportFilters(2026));
            $this->fail('A RuntimeException was expected.');
        } catch (RuntimeException) {
            $this->assertSame($before, glob(sys_get_temp_dir().'/tpvs-report-*') ?: []);
        }
    }

    public function test_building_does_not_modify_any_data(): void
    {
        $this->scenario();
        $records = $this->snapshot();
        $deliveries = Delivery::query()->count();

        $this->workbook(new ReportFilters(2026));

        $this->assertSame($records, $this->snapshot());
        $this->assertSame($deliveries, Delivery::query()->count());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function snapshot(): array
    {
        return json_decode((string) json_encode(
            Record::query()->orderBy('id')->get()->map->only(['id', 'status', 'updated_at'])->all(),
        ), true);
    }

    /**
     * @return array{acme: Company, zeta: Company, acmeWithDelivery: Record, acmeWithoutDelivery: Record, zetaStale: Record}
     */
    private function scenario(): array
    {
        $acme = Company::factory()->create(['name' => 'Acme']);
        $zeta = Company::factory()->create(['name' => 'Zeta']);

        $acmeWithDelivery = $this->record($acme, [
            'week' => 38,
            'week_start_date' => '2026-09-07',
            'week_end_date' => '2026-09-13',
        ], [['600.00', '60.00', '10.00'], ['400.00', '40.00', null]]);
        $acmeWithoutDelivery = $this->record($acme, [
            'week' => 39,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
        ], [['500.00', '50.00', null]]);
        $zetaStale = $this->record($zeta, [
            'week' => 38,
            'week_start_date' => '2026-09-07',
            'week_end_date' => '2026-09-13',
        ], [['300.00', '30.00', null]]);

        $delivery = Delivery::factory()->create([
            'record_id' => $acmeWithDelivery->getKey(),
            'delivery_date' => '2026-09-15',
            'delivered_by_name' => 'Ana',
            'received_by_name' => 'Beto',
            'prepared_return_amount' => '900.00',
        ]);
        foreach ([['500.00', 1], ['200.00', 2]] as [$value, $quantity]) {
            DeliveryCashDenomination::factory()->create(['delivery_id' => $delivery->getKey(), 'value' => $value, 'quantity' => $quantity]);
        }

        $staleDelivery = Delivery::factory()->create([
            'record_id' => $zetaStale->getKey(),
            'delivery_date' => '2026-09-16',
            'delivered_by_name' => 'Carla',
            'received_by_name' => null,
            'prepared_return_amount' => '270.00',
        ]);
        foreach ([['200.00', 1], ['50.00', 1], ['0.50', 1]] as [$value, $quantity]) {
            DeliveryCashDenomination::factory()->create(['delivery_id' => $staleDelivery->getKey(), 'value' => $value, 'quantity' => $quantity]);
        }
        // The Record return changed after the Delivery was prepared.
        RecordDetail::query()->where('record_id', $zetaStale->getKey())->update(['return_amount' => '250.50', 'commission' => '49.50']);

        return compact('acme', 'zeta', 'acmeWithDelivery', 'acmeWithoutDelivery', 'zetaStale');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{0: string, 1: string, 2?: string|null}>  $movements  [income, commission, percentage]
     */
    private function record(Company $company, array $attributes, array $movements): Record
    {
        $record = Record::factory()->create(array_merge([
            'company_id' => $company->getKey(),
            'status' => RecordStatus::CONFIRMED,
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-07',
            'week_end_date' => '2026-09-13',
        ], $attributes));

        foreach ($movements as $index => $movement) {
            RecordDetail::factory()->create([
                'record_id' => $record->getKey(),
                'affiliation' => 'AFF-'.($index + 1),
                'branch' => $index === 0 ? 'Centro' : 'Norte',
                'income' => $movement[0],
                'commission' => $movement[1],
                'commission_percentage' => $movement[2] ?? null,
                'return_amount' => number_format((float) $movement[0] - (float) $movement[1], 2, '.', ''),
                'order' => $index + 1,
            ]);
        }

        return $record;
    }

    private function workbook(ReportFilters $filters): Spreadsheet
    {
        $path = $this->app->make(ReportWorkbookBuilder::class)->build($filters);
        $this->cleanup[] = $path;

        return IOFactory::load($path);
    }

    /**
     * @return list<mixed>
     */
    private function row(Worksheet $sheet, int $row, int $columns): array
    {
        $values = [];
        for ($column = 1; $column <= $columns; $column++) {
            $values[] = $sheet->getCell([$column, $row])->getValue();
        }

        return $values;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    private function typed(array $values): array
    {
        return array_map(static fn ($value) => is_int($value) || is_string($value) || $value === null ? $value : (float) $value, $values);
    }
}
