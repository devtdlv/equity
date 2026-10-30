<?php

namespace App\Policies;

use App\Models\RecurringOccurrence;
use App\Models\User;

class RecurringOccurrencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->exists;
    }

    public function view(User $user, RecurringOccurrence $occurrence): bool
    {
        return (int) $user->id === (int) $occurrence->user_id;
    }

    public function skip(User $user, RecurringOccurrence $occurrence): bool
    {
        return (int) $user->id === (int) $occurrence->user_id;
    }

    public function linkPayment(User $user, RecurringOccurrence $occurrence): bool
    {
        return (int) $user->id === (int) $occurrence->user_id;
    }

    public function unlinkPayment(User $user, RecurringOccurrence $occurrence): bool
    {
        return (int) $user->id === (int) $occurrence->user_id;
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(): bool
    {
        return false;
    }
}
