<?php

namespace App\Policies;

use App\Models\RecurringPaymentLink;
use App\Models\User;

class RecurringPaymentLinkPolicy
{
    public function view(User $user, RecurringPaymentLink $link): bool
    {
        return (int) $user->id === (int) $link->user_id;
    }

    public function delete(User $user, RecurringPaymentLink $link): bool
    {
        return (int) $user->id === (int) $link->user_id;
    }
}
