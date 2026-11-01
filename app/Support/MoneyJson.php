<?php

namespace App\Support;

/**
 * Precision-safe JSON serialization for monetary minor-unit integers.
 *
 * JavaScript Number cannot represent all integers above Number.MAX_SAFE_INTEGER
 * (9007199254740991). Savings amounts may reach Money::MAX_MINOR_AMOUNT (10^18),
 * so HTTP responses must emit exact digit strings rather than JSON numbers.
 */
final class MoneyJson
{
    /**
     * Convert a signed PHP int minor amount to an exact decimal digit string.
     *
     * Uses integer-to-string conversion only — no float coercion.
     */
    public static function encodeMinor(int $amountMinor): string
    {
        return (string) $amountMinor;
    }
}
