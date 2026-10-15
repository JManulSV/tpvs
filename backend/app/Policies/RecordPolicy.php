<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Record;
use App\Models\User;

class RecordPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::EDITOR, UserRole::VIEWER], true);
    }

    public function view(User $user, Record $record): bool
    {
        return in_array($user->role, [UserRole::EDITOR, UserRole::VIEWER], true);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::EDITOR;
    }
}
