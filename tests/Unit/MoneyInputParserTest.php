<?php

use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerAccountClassification;
use App\Exceptions\InvalidMonetaryAmountException;
use App\Exceptions\LedgerIntegrityException;
use App\Support\Money;
use App\Support\MoneyInputParser;

describe('CategoryType enum', function (): void {
    test('provides expected backed values, labels, and ledger classification mappings', function (): void {
        expect(CategoryType::Income->value)->toBe('income')
            ->and(CategoryType::Expense->value)->toBe('expense')
            ->and(CategoryType::Income->label())->toBe('Income')
            ->and(CategoryType::Expense->label())->toBe('Expense')
            ->and(CategoryType::Income->ledgerClassification())->toBe(LedgerAccountClassification::Income)
            ->and(CategoryType::Expense->ledgerClassification())->toBe(LedgerAccountClassification::Expense)
            ->and(CategoryType::values())->toBe(['income', 'expense']);
    });
});

describe('FinancialTransactionType enum', function (): void {
    test('provides expected backed values and labels', function (): void {
        expect(FinancialTransactionType::Income->value)->toBe('income')
            ->and(FinancialTransactionType::Expense->value)->toBe('expense')
            ->and(FinancialTransactionType::Transfer->value)->toBe('transfer')
            ->and(FinancialTransactionType::Income->label())->toBe('Income')
            ->and(FinancialTransactionType::Expense->label())->toBe('Expense')
            ->and(FinancialTransactionType::Transfer->label())->toBe('Transfer')
            ->and(FinancialTransactionType::values())->toBe(['income', 'expense', 'transfer']);
    });
});

describe('MoneyInputParser', function (): void {
    test('parses valid exponent-2 inputs into exact integer minor units', function (string $input, int $expectedMinorUnits): void {
        $result = MoneyInputParser::parseToMinorUnits($input, 2);

        expect($result)->toBeInt()
            ->and($result)->toBe($expectedMinorUnits);
    })->with([
        'whole number' => ['1250', 125000],
        'single fractional digit' => ['1250.5', 125050],
        'two fractional digits' => ['1250.50', 125050],
        'smallest positive amount' => ['0.01', 1],
        'one tenth exact (0.1)' => ['0.1', 10],
        'single unit' => ['1', 100],
        'leading zeros with decimals' => ['0001.20', 120],
        'many leading zeros' => ['0000000000000000000042.99', 4299],
        'leading and trailing whitespace' => ["  1250.50 \n\t ", 125050],
        'exact maximum supported amount (decimal)' => ['10000000000000000.00', Money::MAX_MINOR_AMOUNT],
        'exact maximum supported amount (whole)' => ['10000000000000000', Money::MAX_MINOR_AMOUNT],
    ]);

    test('parses valid exponent-0 inputs into exact integer minor units', function (string $input, int $expectedMinorUnits): void {
        $result = MoneyInputParser::parseToMinorUnits($input, 0);

        expect($result)->toBeInt()
            ->and($result)->toBe($expectedMinorUnits);
    })->with([
        'standard whole number' => ['15800', 15800],
        'smallest positive amount' => ['1', 1],
        'leading zeros' => ['0005', 5],
        'many leading zeros' => ['0000000000000000000015800', 15800],
        'leading and trailing whitespace' => ['  15800  ', 15800],
        'exact maximum supported amount' => ['1000000000000000000', Money::MAX_MINOR_AMOUNT],
    ]);

    test('rejects invalid, zero, negative, malformed, or out-of-bounds inputs', function (string $input, int $exponent): void {
        expect(fn () => MoneyInputParser::parseToMinorUnits($input, $exponent))
            ->toThrow(LedgerIntegrityException::class);
    })->with([
        'empty string' => ['', 2],
        'whitespace only' => ['   ', 2],
        'zero whole' => ['0', 2],
        'zero decimal' => ['0.00', 2],
        'zero with leading zeros' => ['0000.00', 2],
        'zero for exponent 0' => ['0', 0],
        'negative amount' => ['-10.00', 2],
        'explicit plus sign' => ['+10.00', 2],
        'excess fractional digits for exponent 2' => ['12.345', 2],
        'trailing zero beyond exponent 2' => ['12.340', 2],
        'fractional input for exponent 0' => ['15800.5', 0],
        'decimal point with zero for exponent 0' => ['15800.0', 0],
        'leading decimal point' => ['.50', 2],
        'trailing decimal point' => ['1250.', 2],
        'multiple decimal separators' => ['12.50.00', 2],
        'scientific notation lowercase' => ['1e3', 2],
        'scientific notation uppercase' => ['1E5', 0],
        'comma separator' => ['1,250.50', 2],
        'currency symbol prefix' => ['$100.00', 2],
        'currency symbolpeso' => ['₱100.00', 2],
        'internal space' => ['12 50.00', 2],
        'NaN string' => ['NaN', 2],
        'Infinity string' => ['Infinity', 2],
        'non-ASCII Arabic-Indic numerals' => ['١٢٣.٤٥', 2],
        'fullwidth numerals' => ['１２３', 0],
        'negative exponent' => ['100', -1],
        'exponent above 6' => ['100', 7],
        'one minor unit above maximum (exponent 2)' => ['10000000000000000.01', 2],
        'one minor unit above maximum (exponent 0)' => ['1000000000000000001', 0],
        '64-bit integer overflow string' => ['9223372036854775808', 0],
        'extremely long numeric string' => [str_repeat('9', 100), 2],
        'extremely long leading zeros string' => [str_repeat('0', 65).'1', 2],
    ]);
});

describe('MoneyInputParser signed amounts', function (): void {
    test('parses negative, zero, and positive signed exponent-2 amounts', function (string $input, int $expectedMinorUnits): void {
        $result = MoneyInputParser::parseSignedToMinorUnits($input, 2);

        expect($result)->toBeInt()
            ->and($result)->toBe($expectedMinorUnits);
    })->with([
        'positive whole' => ['1250', 125000],
        'positive two fractional digits' => ['1250.50', 125050],
        'positive one fractional digit' => ['10.5', 1050],
        'smallest positive' => ['0.01', 1],
        'negative two fractional digits' => ['-10.00', -1000],
        'negative one fractional digit' => ['-10.5', -1050],
        'smallest negative' => ['-0.01', -1],
        'zero whole' => ['0', 0],
        'zero decimal' => ['0.00', 0],
        'negative zero' => ['-0', 0],
        'negative zero decimal' => ['-0.00', 0],
        'trimmed negative' => ["  -10.00 \n\t ", -1000],
        'exact maximum' => ['10000000000000000.00', Money::MAX_MINOR_AMOUNT],
        'exact minimum' => ['-10000000000000000.00', -Money::MAX_MINOR_AMOUNT],
    ]);

    test('parses signed exponent-0 amounts for currencies without fractional minor units', function (string $input, int $expectedMinorUnits): void {
        $result = MoneyInputParser::parseSignedToMinorUnits($input, 0);

        expect($result)->toBeInt()
            ->and($result)->toBe($expectedMinorUnits);
    })->with([
        'positive whole' => ['15800', 15800],
        'negative whole' => ['-15800', -15800],
        'zero' => ['0', 0],
        'negative zero' => ['-0', 0],
        'exact maximum' => ['1000000000000000000', Money::MAX_MINOR_AMOUNT],
        'exact minimum' => ['-1000000000000000000', -Money::MAX_MINOR_AMOUNT],
    ]);

    test('rejects malformed, over-precise, or out-of-bounds signed amounts', function (string $input, int $exponent): void {
        expect(fn () => MoneyInputParser::parseSignedToMinorUnits($input, $exponent))
            ->toThrow(InvalidMonetaryAmountException::class);
    })->with([
        'empty string' => ['', 2],
        'whitespace only' => ['   ', 2],
        'plus sign' => ['+10.00', 2],
        'minus only' => ['-', 2],
        'double minus' => ['--10.00', 2],
        'minus with internal space' => ['- 10.00', 2],
        'excess fractional digits' => ['12.345', 2],
        'excess fractional digits negative' => ['-12.345', 2],
        'fraction for exponent 0' => ['15800.5', 0],
        'negative fraction for exponent 0' => ['-15800.5', 0],
        'leading decimal' => ['.50', 2],
        'trailing decimal' => ['1250.', 2],
        'scientific notation' => ['-1e3', 2],
        'comma grouping' => ['-1,250.50', 2],
        'overflow exponent 2' => ['10000000000000000.01', 2],
        'negative overflow exponent 2' => ['-10000000000000000.01', 2],
        'overflow exponent 0' => ['1000000000000000001', 0],
        '64-bit overflow string' => ['9223372036854775808', 0],
    ]);

    test('invalid exponent remains a ledger integrity failure rather than an amount-field error', function (int $exponent): void {
        $thrown = null;

        try {
            MoneyInputParser::parseSignedToMinorUnits('100', $exponent);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        expect($thrown)->toBeInstanceOf(LedgerIntegrityException::class)
            ->and($thrown)->not->toBeInstanceOf(InvalidMonetaryAmountException::class);
    })->with([
        'below range' => [-1],
        'above range' => [7],
    ]);
});
