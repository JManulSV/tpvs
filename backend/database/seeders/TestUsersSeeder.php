<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class TestUsersSeeder extends Seeder
{
    /**
     * Create the fixed test accounts in non-production environments only.
     *
     * Existing users are left unchanged when this seeder is run again.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Test users cannot be seeded in production.');
        }

        User::firstOrCreate(
            ['email' => 'editor@test.com'],
            [
                'name' => 'Test Editor',
                'password' => '12345678',
                'role' => UserRole::EDITOR,
            ]
        );

        User::firstOrCreate(
            ['email' => 'viewer@test.com'],
            [
                'name' => 'Test Viewer',
                'password' => '12345678',
                'role' => UserRole::VIEWER,
            ]
        );
    }
}
