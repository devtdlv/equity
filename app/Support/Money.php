<?php

namespace App\Support;

use App\Exceptions\LedgerIntegrityException;

final readonly class Money
{
    /**
     * Maximum safe absolute minor-unit amount (10^18, well within signed 64-bit integer range
     * while preventing intermediate summation overflow up to multiple postings).
     */
    public const MAX_MINOR_AMOUNT = 1_000_000_000_000_000_000;

    public string $currencyCode;

    public function __construct(
        public int $amountMinor,
        string $currencyCode,
        public int $exponent = 2,
    ) {
        $normalizedCode = strtoupper(trim($currencyCode));

        if (strlen($normalizedCode) !== 3 || ! ctype_alpha($normalizedCode)) {
            throw new LedgerIntegrityException('Currency code must be a 3-letter ISO 4217 code.');
        }

        if ($exponent < 0 || $exponent > 6) {
            throw new LedgerIntegrityException('Currency minor-unit exponent must be between 0 and 6.');
        }

        if ($amountMinor > self::MAX_MINOR_AMOUNT || $amountMinor < -self::MAX_MINOR_AMOUNT) {
            throw new LedgerIntegrityException('Monetary amount exceeds safe 64-bit integer bounds.');
        }

        $this->currencyCode = $normalizedCode;
    }

    public static function zero(string $currencyCode, int $exponent = 2): self
    {
        return new self(0, $currencyCode, $exponent);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        $sum = self::checkedAdd($this->amountMinor, $other->amountMinor);

        return new self($sum, $this->currencyCode, $this->exponent);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        $difference = self::checkedSubtract($this->amountMinor, $other->amountMinor);

        return new self($difference, $this->currencyCode, $this->exponent);
    }

    /**
     * Formats the integer minor-unit amount using integer arithmetic only (never binary floats).
     */
    public function format(string $symbol = ''): string
    {
        $isNegative = $this->amountMinor < 0;
        $absoluteMinor = abs($this->amountMinor);

        if ($this->exponent === 0) {
            $formattedNumber = number_format($absoluteMinor, 0, '.', ',');
        } else {
            $divisor = 10 ** $this->exponent;
            $majorUnits = intdiv($absoluteMinor, $divisor);
            $minorUnits = $absoluteMinor % $divisor;

            $formattedMajor = number_format($majorUnits, 0, '.', ',');
            $formattedMinor = str_pad((string) $minorUnits, $this->exponent, '0', STR_PAD_LEFT);
            $formattedNumber = "{$formattedMajor}.{$formattedMinor}";
        }

        $prefix = $symbol !== '' ? $symbol : "{$this->currencyCode} ";

        return ($isNegative ? '-' : '').$prefix.$formattedNumber;
    }

    public static function checkedAdd(int $left, int $right): int
    {
        if (
            ($right > 0 && $left > self::MAX_MINOR_AMOUNT - $right)
            || ($right < 0 && $left < -self::MAX_MINOR_AMOUNT - $right)
        ) {
            throw new LedgerIntegrityException('Monetary arithmetic overflow detected.');
        }

        return $left + $right;
    }

    public static function checkedSubtract(int $left, int $right): int
    {
        if (
            ($right < 0 && $left > self::MAX_MINOR_AMOUNT + $right)
            || ($right > 0 && $left < -self::MAX_MINOR_AMOUNT + $right)
        ) {
            throw new LedgerIntegrityException('Monetary arithmetic overflow detected.');
        }

        return $left - $right;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currencyCode !== $other->currencyCode || $this->exponent !== $other->exponent) {
            throw new LedgerIntegrityException(sprintf(
                'Cannot combine mismatched currencies [%s] and [%s].',
                $this->currencyCode,
                $other->currencyCode,
            ));
        }
    }
}
