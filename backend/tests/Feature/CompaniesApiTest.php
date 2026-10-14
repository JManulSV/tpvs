<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Record;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompaniesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_endpoints_require_authentication(): void
    {
        $company = Company::factory()->create();

        $this->getJson('/api/companies')->assertUnauthorized();
        $this->getJson('/api/companies/'.$company->id)->assertUnauthorized();
        $this->postJson('/api/companies', ['name' => 'New Company'])->assertUnauthorized();
    }

    public function test_editors_and_viewers_can_list_and_view_companies(): void
    {
        $company = Company::factory()->create(['name' => 'Acme']);

        foreach ([UserRole::EDITOR, UserRole::VIEWER] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user, 'web')
                ->getJson('/api/companies')
                ->assertOk()
                ->assertJsonPath('data.0.id', $company->id)
                ->assertJsonPath('data.0.name', 'Acme');

            $this->actingAs($user, 'web')
                ->getJson('/api/companies/'.$company->id)
                ->assertOk()
                ->assertExactJson(['data' => [
                    'id' => $company->id,
                    'name' => 'Acme',
                ]]);
        }
    }

    public function test_list_returns_paginated_companies_with_confirmed_record_counts(): void
    {
        $first = Company::factory()->create(['name' => 'Alpha']);
        $second = Company::factory()->create(['name' => 'Beta']);
        $third = Company::factory()->create(['name' => 'Gamma']);

        Record::factory()->for($first)->count(2)->create(['status' => RecordStatus::CONFIRMED]);
        Record::factory()->for($first)->create(['status' => RecordStatus::NEEDS_REVIEW]);
        Record::factory()->for($second)->create(['status' => RecordStatus::CANCELED]);

        $response = $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/companies?per_page=2');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.records_count', 2)
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.1.records_count', 0)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('links.next', fn ($value) => is_string($value));

        $this->assertSame('Gamma', $third->fresh()->name);
    }

    public function test_list_search_is_case_insensitive_and_matches_substrings(): void
    {
        $match = Company::factory()->create(['name' => 'Northwind Trading']);
        Company::factory()->create(['name' => 'Southwind Trading']);

        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/companies?search=%20NORTHWIND%20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_invalid_search_and_pagination_parameters_return_validation_errors(): void
    {
        $user = User::factory()->create();

        foreach ([
            '/api/companies?page=0',
            '/api/companies?page=not-a-number',
            '/api/companies?per_page=0',
            '/api/companies?per_page=101',
            '/api/companies?search='.str_repeat('a', 256),
        ] as $url) {
            $this->actingAs($user, 'web')
                ->getJson($url)
                ->assertUnprocessable();
        }
    }

    public function test_show_returns_not_found_for_unknown_company(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/companies/999999')
            ->assertNotFound();
    }

    public function test_editor_can_create_company_and_name_is_trimmed(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => UserRole::EDITOR]), 'web')
            ->postJson('/api/companies', ['name' => '  Northwind  ']);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'name']])
            ->assertJsonPath('data.name', 'Northwind')
            ->assertJsonMissingPath('data.records_count');

        $this->assertDatabaseHas('companies', ['name' => 'Northwind']);
    }

    public function test_viewer_cannot_create_company(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::VIEWER]), 'web')
            ->postJson('/api/companies', ['name' => 'Northwind'])
            ->assertForbidden();
    }

    public function test_company_creation_validates_required_and_maximum_name_length(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);

        $this->actingAs($editor, 'web')
            ->postJson('/api/companies', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->actingAs($editor, 'web')
            ->postJson('/api/companies', ['name' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_company_creation_rejects_names_that_duplicate_after_trim_and_case_folding(): void
    {
        Company::factory()->create(['name' => 'Northwind']);

        $this->actingAs(User::factory()->create(['role' => UserRole::EDITOR]), 'web')
            ->postJson('/api/companies', ['name' => '  NORTHWIND  '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_database_rejects_duplicate_normalized_company_names(): void
    {
        Company::factory()->create(['name' => 'Northwind']);

        $this->expectException(QueryException::class);

        Company::factory()->create(['name' => '  NORTHWIND  ']);
    }

    public function test_company_can_be_associated_with_a_record(): void
    {
        $company = Company::factory()->create();
        $record = Record::factory()->for($company)->create();

        $this->assertTrue($record->fresh()->company->is($company));
        $this->assertTrue($company->fresh()->records->first()->is($record));
    }
}
