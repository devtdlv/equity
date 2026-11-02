<?php

namespace App\Exceptions;

class InvalidMonetaryAmountException extends LedgerIntegrityException
{
    public const PUBLIC_FIELD = 'starting_balance';
}
