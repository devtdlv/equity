<?php

use App\Exceptions\LedgerIntegrityException;
use App\Support\Money;

/**
 * Euclidean gcd for independent test proofs (not the production helper under test).
 */
function testGcd(int $left, int $right): int
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

test('checkedMulDivFloor computes exact floor ratios without float math', function (): void {
    expect(Money::checkedMulDivFloor(1, 10_000, 4))->toBe(2500)
        ->and(Money::checkedMulDivFloor(1, 10_000, 3))->toBe(3333)
        ->and(Money::checkedMulDivFloor(2, 10_000, 3))->toBe(6666)
        ->and(Money::checkedMulDivFloor(0, 10_000, 100))->toBe(0)
        ->and(Money::checkedMulDivFloor(50, 10_000, 100))->toBe(5000)
        ->and(Money::checkedMulDivFloor(50, 0, 100))->toBe(0);
});

test('checkedMulDivFloor never reports full progress for amounts just below the divisor', function (): void {
    $target = Money::MAX_MINOR_AMOUNT;
    $justBelow = Money::MAX_MINOR_AMOUNT - 1;

    expect(Money::checkedMulDivFloor($justBelow, 10_000, $target))->toBeLessThan(10_000)
        ->and(Money::checkedMulDivFloor($justBelow, 10_000, $target))->toBe(9999)
        ->and(Money::checkedMulDivFloor($target, 10_000, $target))->toBe(10_000);
});

test('checkedMulDivFloor handles gcd-reduced near-max products', function (): void {
    $allocated = Money::MAX_MINOR_AMOUNT - 1;
    $target = Money::MAX_MINOR_AMOUNT;

    // gcd(10000, 10^18) = 10000 reduces multiplier to 1, so this is the direct path.
    expect(testGcd(10_000, $target))->toBe(10_000)
        ->and(Money::checkedMulDivFloor($allocated, 10_000, $target))->toBe(9999);
});

test('checkedMulDivFloor exercises bit-scan for coprime near-max denominator', function (): void {
    // Coprime to 10000 (odd and not divisible by 5), within MAX_MINOR_AMOUNT.
    $denominator = 999_999_999_999_999_989;
    $numerator = $denominator - 1;
    $multiplier = 10_000;

    expect($denominator)->toBeLessThanOrEqual(Money::MAX_MINOR_AMOUNT)
        ->and($numerator)->toBeLessThanOrEqual(Money::MAX_MINOR_AMOUNT)
        ->and(testGcd($multiplier, $denominator))->toBe(1);

    // After gcd=1, remainder == numerator. Direct multiply is unsafe iff:
    // remainder > intdiv(PHP_INT_MAX, multiplier).
    expect($numerator)->toBeGreaterThan(intdiv(PHP_INT_MAX, $multiplier));

    // Independent expected value: floor((d-1)*10000/d) = 9999 for d > 1.
    expect(Money::checkedMulDivFloor($numerator, $multiplier, $denominator))->toBe(9999)
        ->and(Money::checkedMulDivFloor($numerator, $multiplier, $denominator))
        ->toBeLessThan(10_000);
});

test('checkedMulDivFloor bit-scan near half ratio with overflow-prone intermediate', function (): void {
    $denominator = 999_999_999_999_999_989;
    $numerator = intdiv($denominator, 2);
    $multiplier = 10_000;

    expect(testGcd($multiplier, $denominator))->toBe(1)
        ->and($numerator)->toBeGreaterThan(intdiv(PHP_INT_MAX, $multiplier));

    // floor((floor(d/2))*10000/d) = 4999 for this odd denominator.
    expect(Money::checkedMulDivFloor($numerator, $multiplier, $denominator))->toBe(4999);
});

test('checkedMulDivFloor rejects operands outside the supported domain', function (): void {
    expect(fn () => Money::checkedMulDivFloor(1, 10_000, 0))
        ->toThrow(LedgerIntegrityException::class)
        ->and(fn () => Money::checkedMulDivFloor(-1, 10_000, 10))
        ->toThrow(LedgerIntegrityException::class)
        ->and(fn () => Money::checkedMulDivFloor(1, -10_000, 10))
        ->toThrow(LedgerIntegrityException::class)
        ->and(fn () => Money::checkedMulDivFloor(Money::MAX_MINOR_AMOUNT + 1, 10_000, 10))
        ->toThrow(LedgerIntegrityException::class, 'MAX_MINOR_AMOUNT')
        ->and(fn () => Money::checkedMulDivFloor(1, Money::MAX_MINOR_AMOUNT + 1, 10))
        ->toThrow(LedgerIntegrityException::class, 'MAX_MINOR_AMOUNT')
        ->and(fn () => Money::checkedMulDivFloor(1, 10_000, Money::MAX_MINOR_AMOUNT + 1))
        ->toThrow(LedgerIntegrityException::class, 'MAX_MINOR_AMOUNT');
});
