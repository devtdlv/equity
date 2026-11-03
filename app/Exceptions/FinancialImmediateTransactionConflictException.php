<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a financial IMMEDIATE write is requested while a DEFERRED (or unknown)
 * outer transaction is already active. Fail closed — do not silently proceed.
 */
final class FinancialImmediateTransactionConflictException extends RuntimeException
{
}
