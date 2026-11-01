import { describe, expect, it } from 'vitest';
import {
    formatSavingsAmount,
    formatSavingsMinorUnits,
    formatSavingsMinorUnitsFallback,
    isNonZeroMinorString,
    parseMinorUnitString,
    SavingsMoneyFormatError,
} from '@/Utilities/savingsMoney';

describe('parseMinorUnitString', () => {
    it('parses digit strings via BigInt without Number coercion', () => {
        expect(parseMinorUnitString('0')).toBe(0n);
        expect(parseMinorUnitString('9007199254740993')).toBe(9007199254740993n);
        expect(parseMinorUnitString('-125')).toBe(-125n);
        expect(parseMinorUnitString(42n)).toBe(42n);
    });

    it('rejects non-digit strings', () => {
        expect(() => parseMinorUnitString('1.5')).toThrow(SavingsMoneyFormatError);
        expect(() => parseMinorUnitString('1e6')).toThrow(SavingsMoneyFormatError);
        expect(() => parseMinorUnitString('')).toThrow(SavingsMoneyFormatError);
    });

    it('preserves values above Number.MAX_SAFE_INTEGER that Number would corrupt', () => {
        const exact = '9007199254740993';
        // Number literals above MAX_SAFE_INTEGER are themselves imprecise; compare via BigInt.
        expect(BigInt(Math.trunc(Number(exact)))).not.toBe(9007199254740993n);
        expect(parseMinorUnitString(exact)).toBe(9007199254740993n);
        expect(parseMinorUnitString(exact).toString()).toBe(exact);
    });
});

describe('formatSavingsMinorUnits', () => {
    it('formats two-decimal currencies from digit strings', () => {
        expect(formatSavingsMinorUnits('123456', 'USD', 2, '$')).toBe('$1,234.56');
        expect(formatSavingsMinorUnits('0', 'PHP', 2, '₱')).toBe('₱0.00');
    });

    it('formats zero-decimal currencies', () => {
        expect(formatSavingsMinorUnits('15800', 'JPY', 0, '¥')).toBe('¥15,800');
    });

    it('formats three-decimal currencies', () => {
        expect(formatSavingsMinorUnits('1234567', 'KWD', 3, 'KD ')).toBe('KD 1,234.567');
    });

    it('formats large digit strings without precision loss', () => {
        expect(formatSavingsMinorUnits('9007199254740993', 'USD', 2, '$')).toBe(
            '$90,071,992,547,409.93',
        );
        expect(formatSavingsMinorUnits('1000000000000000000', 'PHP', 2, '₱')).toBe(
            '₱10,000,000,000,000,000.00',
        );
    });

    it('formats negative amounts for read-only presentation', () => {
        expect(formatSavingsMinorUnits('-505', 'USD', 2, '$')).toBe('-$5.05');
    });

    it('falls back to currency code when symbol is empty', () => {
        expect(formatSavingsMinorUnits('100', 'PHP', 2, '')).toBe('PHP 1.00');
    });

    it('does not invent decimals when exponent is unusable', () => {
        expect(formatSavingsMinorUnits('12345', 'JPY', Number.NaN, '¥')).toBe(
            'JPY 12345 minor units',
        );
    });
});

describe('formatSavingsAmount / fallback', () => {
    it('formats with trusted metadata for 0/2/3 decimal currencies', () => {
        expect(
            formatSavingsAmount('15800', 'JPY', {
                code: 'JPY',
                symbol: '¥',
                minor_unit_exponent: 0,
            }),
        ).toBe('¥15,800');
        expect(
            formatSavingsAmount('123456', 'USD', {
                code: 'USD',
                symbol: '$',
                minor_unit_exponent: 2,
            }),
        ).toBe('$1,234.56');
        expect(
            formatSavingsAmount('1234567', 'KWD', {
                code: 'KWD',
                symbol: 'KD ',
                minor_unit_exponent: 3,
            }),
        ).toBe('KD 1,234.567');
    });

    it('preserves exact minor digits when metadata is missing or unknown', () => {
        expect(formatSavingsAmount('12345', 'XYZ', undefined)).toBe('XYZ 12345 minor units');
        expect(formatSavingsAmount('12345', 'xyz', null)).toBe('XYZ 12345 minor units');
        expect(
            formatSavingsAmount('9007199254740993', 'ZZZ', {
                code: 'ZZZ',
                symbol: '?',
                minor_unit_exponent: Number.NaN,
            }),
        ).toBe('ZZZ 9007199254740993 minor units');
        expect(formatSavingsMinorUnitsFallback('0', 'PHP')).toBe('PHP 0 minor units');
    });

    it('never invents a two-decimal major-unit display without metadata', () => {
        expect(formatSavingsAmount('12345', 'JPY', undefined)).not.toContain('123.45');
        expect(formatSavingsAmount('12345', 'JPY', undefined)).toBe('JPY 12345 minor units');
    });
});

describe('isNonZeroMinorString', () => {
    it('detects zero and non-zero exact strings', () => {
        expect(isNonZeroMinorString('0')).toBe(false);
        expect(isNonZeroMinorString('1')).toBe(true);
        expect(isNonZeroMinorString('000')).toBe(false);
    });
});
