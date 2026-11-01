<?php

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a positive monetary minor-unit amount as a canonical digit string.
 *
 * Rejects JSON numbers, floats, exponents, signs, leading zeros, and values above
 * Money::MAX_MINOR_AMOUNT without float coercion.
 */
final class PositiveMinorAmountString implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be provided as a decimal digit string.');

            return;
        }

        if (! preg_match('/\A[1-9]\d*\z/', $value)) {
            $fail('The :attribute must be a positive integer digit string without leading zeros.');

            return;
        }

        $max = (string) Money::MAX_MINOR_AMOUNT;

        if (strlen($value) > strlen($max) || (strlen($value) === strlen($max) && $value > $max)) {
            $fail('The :attribute must not exceed the supported monetary maximum.');

            return;
        }
    }

    /**
     * Convert a validated digit string to a PHP int. Call only after validation passes.
     */
    public static function toInt(string $value): int
    {
        return (int) $value;
    }
}
