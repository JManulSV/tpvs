<?php

namespace Tests\Support\Concerns;

use App\Enums\UserRole;
use App\Models\User;

trait CreatesUsers
{
    protected function user(UserRole $role = UserRole::VIEWER): User
    {
        return User::factory()->create(['role' => $role]);
    }
}
