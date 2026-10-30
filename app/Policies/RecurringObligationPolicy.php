<?php

namespace App\Policies;

use App\Models\RecurringObligation;
use App\Models\User;

class RecurringObligationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->exists;
    }

    public function view(User $user, RecurringObligation $obligation): bool
    {
        return (int) $user->id === (int) $obligation->user_id;
    }

    public function create(User $user): bool
    {
        return $user->exists;
    }

    public function update(User $user, RecurringObligation $obligation): bool
    {
        return (int) $user->id === (int) $obligation->user_id;
    }

    public function pause(User $user, RecurringObligation $obligation): bool
    {
        return (int) $user->id === (int) $obligation->user_id;
    }

    public function resume(User $user, RecurringObligation $obligation): bool
    {
        return (int) $user->id === (int) $obligation->user_id;
    }

    public function cancel(User $user, RecurringObligation $obligation): bool
    {
        return (int) $user->id === (int) $obligation->user_id;
    }

    public function delete(): bool
    {
        return false;
    }
}
