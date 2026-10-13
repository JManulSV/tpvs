<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\Support\EnforceCsrfToken;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sanctum.middleware.validate_csrf_token' => EnforceCsrfToken::class,
        ]);

        Route::middleware(['api', 'auth:sanctum'])->get('/_test/auth/read', static fn () => response()->json([
            'ok' => true,
        ]));

        Route::middleware(['api', 'auth:sanctum', 'editor'])->post('/_test/auth/mutate', static fn () => response()->json([
            'ok' => true,
        ]));
    }

    public function test_login_returns_the_public_user_and_regenerates_the_session(): void
    {
        $user = User::factory()->create([
            'name' => 'Test Editor',
            'email' => 'editor@example.test',
            'role' => UserRole::EDITOR,
            'password' => 'secret-password',
        ]);

        $this->getCsrfToken();

        $this->withSession(['_token' => 'valid-csrf-token'])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'secret-password',
            ], $this->statefulHeaders('valid-csrf-token'))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $user->id,
                    'name' => 'Test Editor',
                    'email' => 'editor@example.test',
                    'role' => 'EDITOR',
                ],
            ]);

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_invalid_login_credentials_return_unauthorized(): void
    {
        User::factory()->create([
            'email' => 'editor@example.test',
            'password' => 'secret-password',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'editor@example.test',
            'password' => 'wrong-password',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'The provided credentials are incorrect.');
    }

    public function test_invalid_login_input_returns_validation_errors(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_me_returns_public_identity_for_an_authenticated_user(): void
    {
        $user = User::factory()->create([
            'name' => 'Test Viewer',
            'email' => 'viewer@example.test',
            'role' => UserRole::VIEWER,
        ]);

        $this->actingAs($user, 'web')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $user->id,
                    'name' => 'Test Viewer',
                    'email' => 'viewer@example.test',
                    'role' => 'VIEWER',
                ],
            ]);
    }

    public function test_me_rejects_an_unauthenticated_request(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_invalidates_the_authenticated_session(): void
    {
        $user = User::factory()->create();
        $csrfToken = 'logout-csrf-token';

        $this->withSession(['_token' => $csrfToken])
            ->actingAs($user, 'web')
            ->postJson('/api/auth/logout', [], $this->statefulHeaders($csrfToken))
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertGuest('web');
        Auth::forgetGuards();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_stateful_mutation_with_invalid_csrf_token_returns_419(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'editor@example.test',
            'password' => 'secret-password',
        ], $this->statefulHeaders('invalid-csrf-token'))
            ->assertStatus(419);
    }

    public function test_authenticated_editor_can_mutate(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);

        $this->actingAs($editor, 'web')
            ->postJson('/_test/auth/mutate')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_authenticated_viewer_cannot_mutate(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::VIEWER]);

        $this->actingAs($viewer, 'web')
            ->postJson('/_test/auth/mutate')
            ->assertForbidden();
    }

    public function test_editor_and_viewer_can_read_authenticated_resources(): void
    {
        foreach ([UserRole::EDITOR, UserRole::VIEWER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'web')
                ->getJson('/_test/auth/read')
                ->assertOk()
                ->assertJsonPath('ok', true);
        }
    }

    private function getCsrfToken(): void
    {
        $this->get('/sanctum/csrf-cookie', [
            'Origin' => 'http://localhost:3000',
        ])->assertNoContent();
    }

    /**
     * @return array<string, string>
     */
    private function statefulHeaders(string $csrfToken): array
    {
        return [
            'Origin' => 'http://localhost:3000',
            'X-CSRF-TOKEN' => $csrfToken,
        ];
    }
}
