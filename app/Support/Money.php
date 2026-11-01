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

    /**
     * Exact floor((numerator * multiplier) / denominator) using integer arithmetic only.
     *
     * Avoids the intermediate product overflow that naive multiplication hits near
     * {@see self::MAX_MINOR_AMOUNT} (e.g. allocated * 10000 for progress basis points).
     *
     * Supported domain (enforced):
     * - numerator >= 0 and numerator <= MAX_MINOR_AMOUNT
     * - multiplier >= 0 and multiplier <= MAX_MINOR_AMOUNT
     * - denominator > 0 and denominator <= MAX_MINOR_AMOUNT
     *
     * The mathematical result must also fit in a signed 64-bit PHP int; otherwise an
     * integrity exception is thrown. No floating-point arithmetic is used.
     *
     * Algorithm:
     * 1. Reduce multiplier/denominator by gcd.
     * 2. Decompose numerator = q*denominator + r.
     * 3. Result = q*multiplier + floor(r*multiplier/denominator).
     * 4. For step 3, multiply directly when safe; otherwise use a bit-scan whose
     *    intermediates stay below 2*MAX_MINOR_AMOUNT (provably < PHP_INT_MAX).
     */
    public static function checkedMulDivFloor(int $numerator, int $multiplier, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new LedgerIntegrityException('Division requires a positive denominator.');
        }

        if ($numerator < 0 || $multiplier < 0) {
            throw new LedgerIntegrityException('checkedMulDivFloor requires non-negative operands.');
        }

        if (
            $numerator > self::MAX_MINOR_AMOUNT
            || $multiplier > self::MAX_MINOR_AMOUNT
            || $denominator > self::MAX_MINOR_AMOUNT
        ) {
            throw new LedgerIntegrityException(
                'checkedMulDivFloor operands must not exceed Money::MAX_MINOR_AMOUNT.',
            );
        }

        if ($numerator === 0 || $multiplier === 0) {
            return 0;
        }

        $gcd = self::gcdNonNegative($multiplier, $denominator);
        $multiplier = intdiv($multiplier, $gcd);
        $denominator = intdiv($denominator, $gcd);

        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        $high = $quotient === 0
            ? 0
            : self::checkedMultiplyFittingInt($quotient, $multiplier);

        $low = self::floorScaledRemainder($remainder, $multiplier, $denominator);

        return self::checkedAddFittingInt($high, $low);
    }

    /**
     * Non-negative multiply that must fit in a signed 64-bit PHP integer.
     */
    private static function checkedMultiplyFittingInt(int $left, int $right): int
    {
        if ($left === 0 || $right === 0) {
            return 0;
        }

        if ($left > intdiv(PHP_INT_MAX, $right)) {
            throw new LedgerIntegrityException('Monetary arithmetic overflow detected.');
        }

        return $left * $right;
    }

    /**
     * Non-negative add that must fit in a signed 64-bit PHP integer.
     */
    private static function checkedAddFittingInt(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new LedgerIntegrityException('Monetary arithmetic overflow detected.');
        }

        return $left + $right;
    }

    /**
     * floor((remainder * multiplier) / denominator) with remainder in [0, denominator).
     */
    private static function floorScaledRemainder(int $remainder, int $multiplier, int $denominator): int
    {
        if ($remainder === 0 || $multiplier === 0) {
            return 0;
        }

        if ($remainder <= intdiv(PHP_INT_MAX, $multiplier)) {
            return intdiv($remainder * $multiplier, $denominator);
        }

        // Bit-scan over multiplier. Invariants while remainder < denominator <= MAX:
        // 2*mod < 2*MAX < PHP_INT_MAX, and mod+remainder < 2*MAX < PHP_INT_MAX.
        $result = 0;
        $mod = 0;

        for ($bit = 62; $bit >= 0; $bit--) {
            $result = self::checkedMultiplyFittingInt($result, 2);
            $mod *= 2;

            if ($mod >= $denominator) {
                $mod -= $denominator;
                $result = self::checkedAddFittingInt($result, 1);
            }

            if ((($multiplier >> $bit) & 1) === 1) {
                $mod += $remainder;

                while ($mod >= $denominator) {
                    $mod -= $denominator;
                    $result = self::checkedAddFittingInt($result, 1);
                }
            }
        }

        return $result;
    }

    private static function gcdNonNegative(int $left, int $right): int
    {
        $a = abs($left);
        $b = abs($right);

        while ($b !== 0) {
            $temporary = $b;
            $b = $a % $b;
            $a = $temporary;
        }

        return max(1, $a);
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
