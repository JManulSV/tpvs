<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Requests\Reports\IndexReportRequest;
use App\Models\Company;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IndexReportRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_year_alone_is_valid(): void
    {
        $this->assertTrue($this->validator(['year' => 2026])->passes());
    }

    public function test_all_filters_are_valid_together(): void
    {
        $company = Company::factory()->create();

        $this->assertTrue($this->validator([
            'year' => 2026,
            'month' => 9,
            'week' => 38,
            'company_id' => $company->getKey(),
            'page' => 2,
            'per_page' => 100,
        ])->passes());
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected(array $filters, string $field): void
    {
        $validator = $this->validator($filters);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey($field, $validator->errors()->toArray());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidFilters(): array
    {
        return [
            'missing year' => [['month' => 9], 'year'],
            'year zero' => [['year' => 0], 'year'],
            'year too large' => [['year' => 10000], 'year'],
            'year not numeric' => [['year' => 'abc'], 'year'],
            'month zero' => [['year' => 2026, 'month' => 0], 'month'],
            'month too large' => [['year' => 2026, 'month' => 13], 'month'],
            'week zero' => [['year' => 2026, 'week' => 0], 'week'],
            'week too large' => [['year' => 2026, 'week' => 54], 'week'],
            'unknown company' => [['year' => 2026, 'company_id' => 999999], 'company_id'],
            'page zero' => [['year' => 2026, 'page' => 0], 'page'],
            'per page zero' => [['year' => 2026, 'per_page' => 0], 'per_page'],
            'per page too large' => [['year' => 2026, 'per_page' => 101], 'per_page'],
        ];
    }

    public function test_editors_and_viewers_may_view_reports(): void
    {
        foreach ([UserRole::EDITOR, UserRole::VIEWER] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->assertTrue($user->can('viewReports', Record::class));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validator(array $data): \Illuminate\Validation\Validator
    {
        return Validator::make($data, (new IndexReportRequest)->rules());
    }
}
