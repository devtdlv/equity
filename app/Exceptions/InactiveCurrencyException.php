<?php

namespace App\Exceptions;

class InactiveCurrencyException extends LedgerIntegrityException
{
    public const PUBLIC_FIELD = 'currency_code';
}
