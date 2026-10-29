<?php

namespace App\Policies;

use App\Models\Budget;
use App\Models\User;

class BudgetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->exists;
    }

    public function view(User $user, Budget $budget): bool
    {
        return (int) $user->id === (int) $budget->user_id;
    }

    public function create(User $user): bool
    {
        return $user->exists;
    }

    public function update(User $user, Budget $budget): bool
    {
        return (int) $user->id === (int) $budget->user_id;
    }

    public function delete(): bool
    {
        return false;
    }
}
