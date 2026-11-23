<?php

namespace Tests\Feature\Records;

use App\Enums\UserRole;
use App\Models\History;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\BuildsManualRecords;
use Tests\TestCase;

class RecordHistoryApiTest extends TestCase
{
    use BuildsManualRecords, RefreshDatabase;

    public function test_editors_and_viewers_can_read_record_history_with_user_and_values(): void
    {
        $record = $this->createRecordWithDetails();
        $editor = User::factory()->create([
            'role' => UserRole::EDITOR,
            'name' => 'History Editor',
            'email' => 'history.editor@example.com',
        ]);
        $entry = History::factory()->for($record)->for($editor)->create([
            'field_name' => 'details[1].income',
            'previous_value' => '100.00',
            'new_value' => '120.00',
            'reason' => 'Se corrigió el ingreso',
        ]);
        History::factory()->for(Record::factory())->create();

        foreach ([UserRole::EDITOR, UserRole::VIEWER] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user, 'web')
                ->getJson('/api/records/'.$record->id.'/history')
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $entry->id)
                ->assertJsonPath('data.0.field_name', 'details[1].income')
                ->assertJsonPath('data.0.previous_value', '100.00')
                ->assertJsonPath('data.0.new_value', '120.00')
                ->assertJsonPath('data.0.reason', 'Se corrigió el ingreso')
                ->assertJsonPath('data.0.created_at', $entry->created_at?->toISOString())
                ->assertJsonPath('data.0.user.id', $editor->id)
                ->assertJsonPath('data.0.user.name', 'History Editor')
                ->assertJsonPath('data.0.user.email', 'history.editor@example.com')
                ->assertJsonMissingPath('data.0.user.role')
                ->assertJsonPath('meta.total', 1);
        }
    }

    public function test_record_history_is_paginated_and_ordered_most_recent_first(): void
    {
        $record = $this->createRecordWithDetails();
        $user = User::factory()->create(['role' => UserRole::EDITOR]);

        $oldest = History::factory()->for($record)->for($user)->create([
            'field_name' => 'oldest',
            'created_at' => now()->subMinutes(3),
        ]);
        $tiedFirst = History::factory()->for($record)->for($user)->create([
            'field_name' => 'tied_first',
            'created_at' => now()->subMinute(),
        ]);
        $tiedSecond = History::factory()->for($record)->for($user)->create([
            'field_name' => 'tied_second',
            'created_at' => now()->subMinute(),
        ]);
        $newest = History::factory()->for($record)->for($user)->create([
            'field_name' => 'newest',
            'created_at' => now(),
        ]);
        $this->assertGreaterThan($tiedFirst->id, $tiedSecond->id);

        $this->actingAs($user, 'web')
            ->getJson('/api/records/'.$record->id.'/history')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('data.1.id', $tiedSecond->id)
            ->assertJsonPath('data.2.id', $tiedFirst->id)
            ->assertJsonPath('data.3.id', $oldest->id);

        $this->actingAs($user, 'web')
            ->getJson('/api/records/'.$record->id.'/history?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('data.0.id', $tiedFirst->id)
            ->assertJsonPath('data.1.id', $oldest->id);
    }

    public function test_record_history_requires_authentication_and_validates_pagination(): void
    {
        $record = $this->createRecordWithDetails();

        $this->getJson('/api/records/'.$record->id.'/history')->assertUnauthorized();

        $user = User::factory()->create(['role' => UserRole::VIEWER]);

        $this->actingAs($user, 'web')
            ->getJson('/api/records/999999/history')
            ->assertNotFound();

        foreach ([
            '/api/records/'.$record->id.'/history?page=0',
            '/api/records/'.$record->id.'/history?per_page=101',
            '/api/records/'.$record->id.'/history?per_page=not-a-number',
        ] as $url) {
            $this->actingAs($user, 'web')
                ->getJson($url)
                ->assertUnprocessable("Expected validation error for {$url}");
        }
    }
}
