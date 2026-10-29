<?php

namespace App\Support;

use App\Exceptions\LedgerIntegrityException;

final class MoneyInputParser
{
    /**
     * Maximum permitted raw input length to prevent excessive string processing.
     */
    public const MAX_INPUT_LENGTH = 64;

    /**
     * Parses a positive decimal string into exact integer minor units without floating-point arithmetic.
     *
     * @throws LedgerIntegrityException
     */
    public static function parseToMinorUnits(string $input, int $exponent): int
    {
        return self::parseCanonicalMinorUnits($input, $exponent, allowZero: false);
    }

    /**
     * Parses a non-negative decimal string into exact integer minor units.
     *
     * Zero is permitted (used for explicit budget allocation limits).
     *
     * @throws LedgerIntegrityException
     */
    public static function parseNonNegativeToMinorUnits(string $input, int $exponent): int
    {
        return self::parseCanonicalMinorUnits($input, $exponent, allowZero: true);
    }

    /**
     * @throws LedgerIntegrityException
     */
    private static function parseCanonicalMinorUnits(string $input, int $exponent, bool $allowZero): int
    {
        if ($exponent < 0 || $exponent > 6) {
            throw new LedgerIntegrityException('Currency minor-unit exponent must be between 0 and 6.');
        }

        if (strlen($input) > self::MAX_INPUT_LENGTH) {
            throw new LedgerIntegrityException('Monetary amount input exceeds maximum permitted length.');
        }

        $trimmed = trim($input, " \t\n\r\0\x0B");

        if ($trimmed === '') {
            throw new LedgerIntegrityException('Monetary amount is required.');
        }

        if ($exponent === 0) {
            if (str_contains($trimmed, '.')) {
                throw new LedgerIntegrityException('Currency does not support fractional minor units.');
            }

            if (preg_match('/\A[0-9]+\z/', $trimmed) !== 1) {
                throw new LedgerIntegrityException(
                    $allowZero
                        ? 'Monetary amount must be a valid non-negative whole number.'
                        : 'Monetary amount must be a valid positive whole number.',
                );
            }

            $majorPart = $trimmed;
            $minorDigits = '';
        } else {
            if (preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', $trimmed) !== 1) {
                throw new LedgerIntegrityException(
                    $allowZero
                        ? 'Monetary amount must be a valid non-negative number.'
                        : 'Monetary amount must be a valid positive number.',
                );
            }

            $parts = explode('.', $trimmed, 2);
            $majorPart = $parts[0];
            $fractionalPart = $parts[1] ?? '';

            if (strlen($fractionalPart) > $exponent) {
                throw new LedgerIntegrityException(sprintf(
                    'Monetary amount cannot have more than %d decimal places.',
                    $exponent,
                ));
            }

            $minorDigits = str_pad($fractionalPart, $exponent, '0', STR_PAD_RIGHT);
        }

        $normalizedMajor = ltrim($majorPart, '0');
        $combinedDigits = $normalizedMajor.$minorDigits;
        $canonicalMinorDigits = ltrim($combinedDigits, '0');

        if ($canonicalMinorDigits === '') {
            if ($allowZero) {
                return 0;
            }

            throw new LedgerIntegrityException('Monetary amount must be greater than zero.');
        }

        $maxMinorDigits = (string) Money::MAX_MINOR_AMOUNT;
        $canonicalLength = strlen($canonicalMinorDigits);
        $maxLength = strlen($maxMinorDigits);

        if (
            $canonicalLength > $maxLength
            || ($canonicalLength === $maxLength && strcmp($canonicalMinorDigits, $maxMinorDigits) > 0)
        ) {
            throw new LedgerIntegrityException('Monetary amount exceeds safe 64-bit integer bounds.');
        }

        return (int) $canonicalMinorDigits;
    }
}
