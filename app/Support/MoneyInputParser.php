<?php

namespace App\Support;

use App\Exceptions\InvalidMonetaryAmountException;
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
     * @throws InvalidMonetaryAmountException
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
     * @throws InvalidMonetaryAmountException
     * @throws LedgerIntegrityException
     */
    public static function parseNonNegativeToMinorUnits(string $input, int $exponent): int
    {
        return self::parseCanonicalMinorUnits($input, $exponent, allowZero: true);
    }

    /**
     * Parses a signed decimal string into exact integer minor units without floating-point arithmetic.
     *
     * Accepted form after trimming leading/trailing ASCII whitespace:
     * optional ASCII minus, then the same canonical decimal as the unsigned parsers
     * (digits, optional `.` plus at most `exponent` fractional digits; no `.` when exponent is 0).
     *
     * Zero and negative zero (`0`, `0.00`, `-0`, `-0.00`) become integer `0`.
     * Plus signs, grouping commas, exponent notation, internal whitespace, and a minus
     * that is not a single leading sign are rejected.
     *
     * @throws InvalidMonetaryAmountException
     * @throws LedgerIntegrityException
     */
    public static function parseSignedToMinorUnits(string $input, int $exponent): int
    {
        return self::parseCanonicalMinorUnits(
            $input,
            $exponent,
            allowZero: true,
            allowNegative: true,
        );
    }

    /**
     * @throws InvalidMonetaryAmountException
     * @throws LedgerIntegrityException
     */
    private static function parseCanonicalMinorUnits(
        string $input,
        int $exponent,
        bool $allowZero,
        bool $allowNegative = false,
    ): int {
        if ($exponent < 0 || $exponent > 6) {
            throw new LedgerIntegrityException('Currency minor-unit exponent must be between 0 and 6.');
        }

        if (strlen($input) > self::MAX_INPUT_LENGTH) {
            throw new InvalidMonetaryAmountException('Monetary amount input exceeds maximum permitted length.');
        }

        $trimmed = trim($input, " \t\n\r\0\x0B");

        if ($trimmed === '') {
            throw new InvalidMonetaryAmountException('Monetary amount is required.');
        }

        $isNegative = false;

        if ($allowNegative && str_starts_with($trimmed, '-')) {
            $isNegative = true;
            $trimmed = substr($trimmed, 1);

            if ($trimmed === '') {
                throw new InvalidMonetaryAmountException(
                    self::invalidAmountMessage($exponent, $allowZero, $allowNegative),
                );
            }
        }

        if ($exponent === 0) {
            if (str_contains($trimmed, '.')) {
                throw new InvalidMonetaryAmountException('Currency does not support fractional minor units.');
            }

            if (preg_match('/\A[0-9]+\z/', $trimmed) !== 1) {
                throw new InvalidMonetaryAmountException(
                    self::invalidAmountMessage($exponent, $allowZero, $allowNegative),
                );
            }

            $majorPart = $trimmed;
            $minorDigits = '';
        } else {
            if (preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', $trimmed) !== 1) {
                throw new InvalidMonetaryAmountException(
                    self::invalidAmountMessage($exponent, $allowZero, $allowNegative),
                );
            }

            $parts = explode('.', $trimmed, 2);
            $majorPart = $parts[0];
            $fractionalPart = $parts[1] ?? '';

            if (strlen($fractionalPart) > $exponent) {
                throw new InvalidMonetaryAmountException(sprintf(
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

            throw new InvalidMonetaryAmountException('Monetary amount must be greater than zero.');
        }

        $maxMinorDigits = (string) Money::MAX_MINOR_AMOUNT;
        $canonicalLength = strlen($canonicalMinorDigits);
        $maxLength = strlen($maxMinorDigits);

        if (
            $canonicalLength > $maxLength
            || ($canonicalLength === $maxLength && strcmp($canonicalMinorDigits, $maxMinorDigits) > 0)
        ) {
            throw new InvalidMonetaryAmountException('Monetary amount exceeds safe 64-bit integer bounds.');
        }

        $amountMinor = (int) $canonicalMinorDigits;

        return $isNegative ? -$amountMinor : $amountMinor;
    }

    private static function invalidAmountMessage(int $exponent, bool $allowZero, bool $allowNegative): string
    {
        if ($allowNegative) {
            return $exponent === 0
                ? 'Monetary amount must be a valid signed whole number.'
                : 'Monetary amount must be a valid signed number.';
        }

        if ($allowZero) {
            return $exponent === 0
                ? 'Monetary amount must be a valid non-negative whole number.'
                : 'Monetary amount must be a valid non-negative number.';
        }

        return $exponent === 0
            ? 'Monetary amount must be a valid positive whole number.'
            : 'Monetary amount must be a valid positive number.';
    }
}
