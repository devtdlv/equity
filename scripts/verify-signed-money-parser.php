<?php

/**
 * Offline verification for App\Support\MoneyInputParser signed and unsigned APIs.
 *
 * Uses synthetic decimal strings only. Does not bootstrap Laravel, connect to a
 * database, or start the application.
 */

declare(strict_types=1);

use App\Exceptions\LedgerIntegrityException;
use App\Support\Money;
use App\Support\MoneyInputParser;

$root = dirname(__DIR__);

require $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Exceptions'.DIRECTORY_SEPARATOR.'LedgerIntegrityException.php';
require $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Exceptions'.DIRECTORY_SEPARATOR.'InvalidMonetaryAmountException.php';
require $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'Money.php';
require $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'MoneyInputParser.php';

$passed = 0;
$failed = 0;
$failures = [];

function equity_money_assert(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed, $failures;

    if ($condition) {
        $passed++;
        echo 'PASS  '.$name."\n";

        return;
    }

    $failed++;
    $message = $name.($detail !== '' ? ': '.$detail : '');
    $failures[] = $message;
    echo 'FAIL  '.$message."\n";
}

/**
 * @param  callable(string, int): int  $parser
 */
function equity_money_expect_value(string $name, callable $parser, string $input, int $exponent, int $expected): void
{
    try {
        $actual = $parser($input, $exponent);
        equity_money_assert(
            $name,
            $actual === $expected,
            'expected '.$expected.', got '.$actual,
        );
    } catch (Throwable $exception) {
        equity_money_assert(
            $name,
            false,
            'unexpected '.$exception::class.': '.$exception->getMessage(),
        );
    }
}

/**
 * @param  callable(string, int): int  $parser
 */
function equity_money_expect_reject(string $name, callable $parser, string $input, int $exponent): void
{
    try {
        $actual = $parser($input, $exponent);
        equity_money_assert($name, false, 'expected rejection, got '.$actual);
    } catch (LedgerIntegrityException) {
        equity_money_assert($name, true);
    } catch (Throwable $exception) {
        equity_money_assert(
            $name,
            false,
            'expected LedgerIntegrityException, got '.$exception::class.': '.$exception->getMessage(),
        );
    }
}

$signed = static fn (string $input, int $exponent): int => MoneyInputParser::parseSignedToMinorUnits($input, $exponent);
$positive = static fn (string $input, int $exponent): int => MoneyInputParser::parseToMinorUnits($input, $exponent);
$nonNegative = static fn (string $input, int $exponent): int => MoneyInputParser::parseNonNegativeToMinorUnits($input, $exponent);

echo "Signed parser: positive, negative, zero\n";

equity_money_expect_value('signed exponent-2 whole number', $signed, '1250', 2, 125000);
equity_money_expect_value('signed exponent-2 two fractional digits', $signed, '1250.50', 2, 125050);
equity_money_expect_value('signed exponent-2 one fractional digit', $signed, '1250.5', 2, 125050);
equity_money_expect_value('signed exponent-2 smallest positive', $signed, '0.01', 2, 1);
equity_money_expect_value('signed negative exponent-2', $signed, '-10.00', 2, -1000);
equity_money_expect_value('signed negative one fractional digit', $signed, '-10.5', 2, -1050);
equity_money_expect_value('signed negative smallest unit', $signed, '-0.01', 2, -1);
equity_money_expect_value('signed negative whole number', $signed, '-42', 2, -4200);
equity_money_expect_value('signed leading zeros negative', $signed, '-0001.20', 2, -120);
equity_money_expect_value('signed trimmed ASCII whitespace negative', $signed, "  -10.00 \n\t ", 2, -1000);
equity_money_expect_value('signed zero whole', $signed, '0', 2, 0);
equity_money_expect_value('signed zero decimal', $signed, '0.00', 2, 0);
equity_money_expect_value('signed zero leading zeros', $signed, '0000.00', 2, 0);
equity_money_expect_value('signed negative zero whole', $signed, '-0', 2, 0);
equity_money_expect_value('signed negative zero decimal', $signed, '-0.00', 2, 0);
equity_money_expect_value('signed negative zero padded', $signed, '-000.00', 2, 0);

echo "\nSigned parser: exponent-zero currencies\n";

equity_money_expect_value('signed exponent-0 positive', $signed, '15800', 0, 15800);
equity_money_expect_value('signed exponent-0 negative', $signed, '-15800', 0, -15800);
equity_money_expect_value('signed exponent-0 smallest negative', $signed, '-1', 0, -1);
equity_money_expect_value('signed exponent-0 zero', $signed, '0', 0, 0);
equity_money_expect_value('signed exponent-0 negative zero', $signed, '-0', 0, 0);
equity_money_expect_value('signed exponent-0 leading zeros negative', $signed, '-0005', 0, -5);

echo "\nSigned parser: precision and boundaries\n";

equity_money_expect_value('signed exact maximum exponent-2', $signed, '10000000000000000.00', 2, Money::MAX_MINOR_AMOUNT);
equity_money_expect_value('signed exact minimum exponent-2', $signed, '-10000000000000000.00', 2, -Money::MAX_MINOR_AMOUNT);
equity_money_expect_value('signed exact maximum exponent-0', $signed, '1000000000000000000', 0, Money::MAX_MINOR_AMOUNT);
equity_money_expect_value('signed exact minimum exponent-0', $signed, '-1000000000000000000', 0, -Money::MAX_MINOR_AMOUNT);
equity_money_expect_reject('signed overflow exponent-2 positive', $signed, '10000000000000000.01', 2);
equity_money_expect_reject('signed overflow exponent-2 negative', $signed, '-10000000000000000.01', 2);
equity_money_expect_reject('signed overflow exponent-0 positive', $signed, '1000000000000000001', 0);
equity_money_expect_reject('signed overflow exponent-0 negative', $signed, '-1000000000000000001', 0);
equity_money_expect_reject('signed 64-bit overflow string', $signed, '9223372036854775808', 0);
equity_money_expect_reject('signed 64-bit overflow string negative', $signed, '-9223372036854775808', 0);
equity_money_expect_reject('signed excess fractional digits', $signed, '12.345', 2);
equity_money_expect_reject('signed excess fractional digits negative', $signed, '-12.345', 2);
equity_money_expect_reject('signed trailing zero beyond exponent', $signed, '12.340', 2);
equity_money_expect_reject('signed fraction for exponent 0', $signed, '15800.5', 0);
equity_money_expect_reject('signed negative fraction for exponent 0', $signed, '-15800.5', 0);

echo "\nSigned parser: malformed strings\n";

equity_money_expect_reject('signed empty string', $signed, '', 2);
equity_money_expect_reject('signed whitespace only', $signed, '   ', 2);
equity_money_expect_reject('signed plus sign', $signed, '+10.00', 2);
equity_money_expect_reject('signed plus sign exponent 0', $signed, '+10', 0);
equity_money_expect_reject('signed minus only', $signed, '-', 2);
equity_money_expect_reject('signed double minus', $signed, '--10.00', 2);
equity_money_expect_reject('signed minus with internal space', $signed, '- 10.00', 2);
equity_money_expect_reject('signed unicode minus', $signed, "\u{2212}10.00", 2);
equity_money_expect_reject('signed leading decimal', $signed, '.50', 2);
equity_money_expect_reject('signed leading decimal negative', $signed, '-.50', 2);
equity_money_expect_reject('signed trailing decimal', $signed, '1250.', 2);
equity_money_expect_reject('signed trailing decimal negative', $signed, '-1250.', 2);
equity_money_expect_reject('signed multiple separators', $signed, '12.50.00', 2);
equity_money_expect_reject('signed scientific lowercase', $signed, '1e3', 2);
equity_money_expect_reject('signed scientific uppercase', $signed, '1E5', 0);
equity_money_expect_reject('signed scientific negative exponent form', $signed, '-1e3', 2);
equity_money_expect_reject('signed comma grouping', $signed, '1,250.50', 2);
equity_money_expect_reject('signed comma grouping negative', $signed, '-1,250.50', 2);
equity_money_expect_reject('signed currency prefix', $signed, '$100.00', 2);
equity_money_expect_reject('signed internal space', $signed, '12 50.00', 2);
equity_money_expect_reject('signed NaN', $signed, 'NaN', 2);
equity_money_expect_reject('signed Infinity', $signed, 'Infinity', 2);
equity_money_expect_reject('signed non-ASCII numerals', $signed, '١٢٣.٤٥', 2);
equity_money_expect_reject('signed invalid exponent below range', $signed, '100', -1);
equity_money_expect_reject('signed invalid exponent above range', $signed, '100', 7);
equity_money_expect_reject('signed extremely long numeric string', $signed, str_repeat('9', 100), 2);
equity_money_expect_reject('signed over-length leading zeros', $signed, str_repeat('0', 65).'1', 2);

echo "\nUnsigned parsers: existing behavior preserved\n";

equity_money_expect_value('unsigned positive exponent-2 whole', $positive, '1250', 2, 125000);
equity_money_expect_value('unsigned positive exponent-2 decimals', $positive, '1250.50', 2, 125050);
equity_money_expect_value('unsigned positive smallest unit', $positive, '0.01', 2, 1);
equity_money_expect_value('unsigned positive one tenth', $positive, '0.1', 2, 10);
equity_money_expect_value('unsigned positive leading zeros', $positive, '0001.20', 2, 120);
equity_money_expect_value('unsigned positive trimmed whitespace', $positive, "  1250.50 \n\t ", 2, 125050);
equity_money_expect_value('unsigned positive exact maximum exponent-2', $positive, '10000000000000000.00', 2, Money::MAX_MINOR_AMOUNT);
equity_money_expect_value('unsigned positive exponent-0', $positive, '15800', 0, 15800);
equity_money_expect_value('unsigned positive exact maximum exponent-0', $positive, '1000000000000000000', 0, Money::MAX_MINOR_AMOUNT);

equity_money_expect_value('non-negative zero whole', $nonNegative, '0', 2, 0);
equity_money_expect_value('non-negative zero decimal', $nonNegative, '0.00', 2, 0);
equity_money_expect_value('non-negative zero exponent-0', $nonNegative, '0', 0, 0);
equity_money_expect_value('non-negative positive amount', $nonNegative, '10.50', 2, 1050);

equity_money_expect_reject('unsigned positive rejects zero', $positive, '0', 2);
equity_money_expect_reject('unsigned positive rejects zero decimal', $positive, '0.00', 2);
equity_money_expect_reject('unsigned positive rejects zero exponent-0', $positive, '0', 0);
equity_money_expect_reject('unsigned positive rejects minus', $positive, '-10.00', 2);
equity_money_expect_reject('unsigned positive rejects minus exponent-0', $positive, '-10', 0);
equity_money_expect_reject('unsigned positive rejects plus', $positive, '+10.00', 2);
equity_money_expect_reject('non-negative rejects minus', $nonNegative, '-10.00', 2);
equity_money_expect_reject('non-negative rejects minus zero', $nonNegative, '-0', 2);
equity_money_expect_reject('non-negative rejects plus', $nonNegative, '+10.00', 2);
equity_money_expect_reject('unsigned positive rejects comma', $positive, '1,250.50', 2);
equity_money_expect_reject('unsigned positive rejects scientific', $positive, '1e3', 2);
equity_money_expect_reject('unsigned positive rejects excess precision', $positive, '12.345', 2);
equity_money_expect_reject('unsigned positive rejects overflow', $positive, '10000000000000000.01', 2);

echo "\n{$passed} passed, {$failed} failed\n";

if ($failed > 0) {
    fwrite(STDERR, "\nFailed cases:\n- ".implode("\n- ", $failures)."\n");
    exit(1);
}

exit(0);
