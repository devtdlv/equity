<?php

namespace App\Policies;

use App\Models\FinancialTransaction;
use App\Models\User;

class FinancialTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->exists;
    }

    public function view(User $user, FinancialTransaction $transaction): bool
    {
        return (int) $user->id === (int) $transaction->user_id;
    }

    public function create(User $user): bool
    {
        return $user->exists;
    }

    public function update(): bool
    {
        return false;
    }

    public function reverse(User $user, FinancialTransaction $transaction): bool
    {
        return (int) $user->id === (int) $transaction->user_id;
    }

    public function delete(): bool
    {
        return false;
    }
}
