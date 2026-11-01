<?php

namespace App\Policies;

use App\Models\SavingsGoal;
use App\Models\User;

class SavingsGoalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->exists;
    }

    public function view(User $user, SavingsGoal $goal): bool
    {
        return (int) $user->id === (int) $goal->user_id;
    }
}
