<?php

namespace Tests\Feature;

use App\Domain\Reports\ReportQuery;
use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\History;
use App\Models\Record;
use App\Models\RecordDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\EnforceCsrfToken;
use Tests\TestCase;

class ReportExcelApiTest extends TestCase
{
    use RefreshDatabase;

    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function test_the_export_requires_authentication(): void
    {
        $this->getJson('/api/documents/excel?year=2026')->assertUnauthorized();
    }

    public function test_editors_and_viewers_download_a_workbook_with_private_headers(): void
    {
        $this->record(Company::factory()->create(['name' => 'Acme']), [], [['100.00', '10.00']]);

        foreach ([UserRole::EDITOR, UserRole::VIEWER] as $role) {
            $response = $this->actingAs($this->user($role), 'web')->get('/api/documents/excel?year=2026&week=38');

            $response->assertOk();
            $this->assertSame(self::XLSX_MIME, $response->headers->get('Content-Type'));
            $this->assertStringContainsString(
                'attachment; filename=Reporte_Semana_38_2026.xlsx',
                $response->headers->get('Content-Disposition'),
            );
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringNotContainsString('public', $response->headers->get('Cache-Control'));

            $this->assertSame(
                ['Resumen', 'Registros', 'Detalles', 'Efectivo'],
                $this->spreadsheet($response)->getSheetNames(),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('fileNames')]
    public function test_the_file_name_follows_the_filters(array $query, string $expected): void
    {
        $query['company_id'] = isset($query['company_id']) ? Company::factory()->create()->getKey() : null;
        $expected = str_replace('{company}', (string) $query['company_id'], $expected);

        $response = $this->actingAs($this->user(), 'web')
            ->get('/api/documents/excel?'.http_build_query(array_filter($query, static fn ($value) => $value !== null)));

        $response->assertOk();
        $this->assertStringContainsString('filename='.$expected, $response->headers->get('Content-Disposition'));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function fileNames(): array
    {
        return [
            'year' => [['year' => 2026], 'Reporte_2026.xlsx'],
            'year and month' => [['year' => 2026, 'month' => 9], 'Reporte_2026_Mes_09.xlsx'],
            'year and week' => [['year' => 2026, 'week' => 38], 'Reporte_Semana_38_2026.xlsx'],
            'year month and week' => [['year' => 2026, 'month' => 9, 'week' => 38], 'Reporte_Semana_38_2026.xlsx'],
            'year and company' => [['year' => 2026, 'company_id' => 1], 'Reporte_2026_Empresa_{company}.xlsx'],
            'year month and company' => [['year' => 2026, 'month' => 12, 'company_id' => 1], 'Reporte_2026_Mes_12_Empresa_{company}.xlsx'],
            'week and company' => [['year' => 2026, 'week' => 5, 'company_id' => 1], 'Reporte_Semana_5_2026_Empresa_{company}.xlsx'],
        ];
    }

    public function test_the_file_name_never_includes_the_company_name(): void
    {
        $company = Company::factory()->create(['name' => 'Secreta SA']);

        $response = $this->actingAs($this->user(), 'web')
            ->get('/api/documents/excel?year=2026&company_id='.$company->getKey());

        $this->assertStringNotContainsString('Secreta', $response->headers->get('Content-Disposition'));
    }

    public function test_the_export_matches_the_report_totals_and_only_includes_confirmed_records(): void
    {
        $company = Company::factory()->create(['name' => 'Acme']);
        $this->record($company, ['week' => 1], [['100.00', '10.00']]);
        $this->record($company, ['week' => 2], [['200.00', '20.00'], ['50.00', '5.00']]);
        foreach (RecordStatus::cases() as $status) {
            if ($status !== RecordStatus::CONFIRMED) {
                $this->record($company, ['status' => $status], [['9999.00', '999.00']]);
            }
        }

        $this->actingAs($this->user(), 'web');

        $totals = $this->getJson('/api/reports?year=2026')->assertOk()->json('data.totals');
        $spreadsheet = $this->spreadsheet($this->get('/api/documents/excel?year=2026'));

        $summary = $spreadsheet->getSheetByName('Resumen');
        $this->assertSame(
            ['TOTAL', (float) $totals['income_total'], (float) $totals['commission_total'], (float) $totals['return_total']],
            [$summary->getCell('A3')->getValue(), (float) $summary->getCell('B3')->getValue(), (float) $summary->getCell('C3')->getValue(), (float) $summary->getCell('D3')->getValue()],
        );
        $this->assertSame(2, $spreadsheet->getSheetByName('Registros')->getHighestRow() - 1);
        $this->assertSame(3, $spreadsheet->getSheetByName('Detalles')->getHighestRow() - 1);
    }

    public function test_pagination_parameters_do_not_limit_the_export(): void
    {
        $company = Company::factory()->create();
        foreach (range(1, 4) as $week) {
            $this->record($company, ['week' => $week], [['10.00', '1.00']]);
        }

        $spreadsheet = $this->spreadsheet(
            $this->actingAs($this->user(), 'web')->get('/api/documents/excel?year=2026&per_page=1&page=3'),
        );

        $this->assertSame(4, $spreadsheet->getSheetByName('Registros')->getHighestRow() - 1);
    }

    public function test_an_empty_result_downloads_a_workbook_with_four_sheets_and_zero_totals(): void
    {
        $spreadsheet = $this->spreadsheet(
            $this->actingAs($this->user(), 'web')->get('/api/documents/excel?year=2026'),
        );

        $this->assertSame(['Resumen', 'Registros', 'Detalles', 'Efectivo'], $spreadsheet->getSheetNames());
        $summary = $spreadsheet->getSheetByName('Resumen');
        $this->assertSame(2, $summary->getHighestRow());
        $this->assertSame('TOTAL', $summary->getCell('A2')->getValue());
        $this->assertSame(0.0, (float) $summary->getCell('D2')->getValue());
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('invalidQueries')]
    public function test_invalid_filters_return_validation_errors(array $query, string $field): void
    {
        $this->actingAs($this->user(), 'web')
            ->getJson('/api/documents/excel?'.http_build_query($query))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidQueries(): array
    {
        return [
            'missing year' => [[], 'year'],
            'unknown company' => [['year' => 2026, 'company_id' => 999999], 'company_id'],
            'month out of range' => [['year' => 2026, 'month' => 13], 'month'],
            'week out of range' => [['year' => 2026, 'week' => 54], 'week'],
            'per page too large' => [['year' => 2026, 'per_page' => 101], 'per_page'],
        ];
    }

    public function test_the_temporary_workbook_is_deleted_after_the_response_is_sent(): void
    {
        $this->record(Company::factory()->create(), [], [['100.00', '10.00']]);
        $before = $this->temporaryWorkbooks();

        $response = $this->actingAs($this->user(), 'web')->get('/api/documents/excel?year=2026');

        $path = $response->baseResponse->getFile()->getPathname();
        $this->assertFileExists($path);

        $response->streamedContent();

        $this->assertFileDoesNotExist($path);
        $this->assertSame($before, $this->temporaryWorkbooks());
    }

    public function test_a_builder_failure_returns_a_safe_500_and_leaves_no_temporary_file(): void
    {
        $before = $this->temporaryWorkbooks();
        $this->partialMock(ReportQuery::class, static function ($mock): void {
            $mock->shouldReceive('export')->andThrow(new RuntimeException('secret internal detail'));
        });

        $response = $this->actingAs($this->user(), 'web')->getJson('/api/documents/excel?year=2026');

        $response->assertStatus(500)->assertExactJson(['message' => 'The Excel report could not be generated.']);
        $this->assertStringNotContainsString('secret internal detail', $response->getContent());
        $this->assertSame($before, $this->temporaryWorkbooks());
    }

    public function test_exporting_changes_no_data_and_writes_no_history(): void
    {
        $record = $this->record(Company::factory()->create(), [], [['100.00', '10.00']]);
        Delivery::factory()->create(['record_id' => $record->getKey(), 'prepared_return_amount' => '90.00']);
        $histories = History::query()->count();
        $snapshot = json_encode(Record::query()->get()->toArray());

        $this->actingAs($this->user(), 'web')->get('/api/documents/excel?year=2026')->assertOk()->streamedContent();

        $this->assertSame($histories, History::query()->count());
        $this->assertSame($snapshot, json_encode(Record::query()->get()->toArray()));
    }

    public function test_the_export_needs_no_csrf_token_because_it_only_reads(): void
    {
        config(['sanctum.middleware.validate_csrf_token' => EnforceCsrfToken::class]);

        $this->withSession(['_token' => 'expected-csrf-token'])
            ->actingAs($this->user(), 'web')
            ->get('/api/documents/excel?year=2026', ['Origin' => 'http://localhost:3000'])
            ->assertOk();
    }

    private function user(UserRole $role = UserRole::VIEWER): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{0: string, 1: string}>  $movements  [income, commission]
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

        foreach ($movements as $index => [$income, $commission]) {
            RecordDetail::factory()->create([
                'record_id' => $record->getKey(),
                'income' => $income,
                'commission' => $commission,
                'return_amount' => number_format((float) $income - (float) $commission, 2, '.', ''),
                'order' => $index + 1,
            ]);
        }

        return $record;
    }

    private function spreadsheet(TestResponse $response): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'test-xlsx-');
        file_put_contents($path, $response->streamedContent());

        try {
            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return list<string>
     */
    private function temporaryWorkbooks(): array
    {
        $files = glob(sys_get_temp_dir().'/tpvs-report-*') ?: [];
        sort($files);

        return $files;
    }
}
