import { describe, expect, it } from 'vitest';
import {
    formatMinorUnits,
    minorUnitsToDecimalString,
    validateOptionalSignedDecimalInput,
} from '@/Utilities/money';

describe('formatMinorUnits', () => {
    it('formats 2-decimal currencies using BigInt without floating-point drift', () => {
        expect(formatMinorUnits(0, 'USD', 2, '$')).toBe('$0.00');
        expect(formatMinorUnits(123456, 'USD', 2, '$')).toBe('$1,234.56');
        expect(formatMinorUnits(-505, 'PHP', 2, '₱')).toBe('-₱5.05');
    });

    it('formats 0-decimal currencies such as JPY without decimal places', () => {
        expect(formatMinorUnits(15800, 'JPY', 0, '¥')).toBe('¥15,800');
        expect(formatMinorUnits(0, 'JPY', 0, '¥')).toBe('¥0');
    });
});

describe('minorUnitsToDecimalString', () => {
    it('converts minor units to exact decimal strings without rounding', () => {
        expect(minorUnitsToDecimalString(12550, 2)).toBe('125.50');
        expect(minorUnitsToDecimalString(0, 2)).toBe('0.00');
        expect(minorUnitsToDecimalString(15800, 0)).toBe('15800');
    });
});

describe('validateOptionalSignedDecimalInput', () => {
    it('allows empty optional input and signed values within currency precision', () => {
        expect(validateOptionalSignedDecimalInput('', 2)).toBeNull();
        expect(validateOptionalSignedDecimalInput('   ', 2)).toBeNull();
        expect(validateOptionalSignedDecimalInput('1000.50', 2)).toBeNull();
        expect(validateOptionalSignedDecimalInput('-500.25', 2)).toBeNull();
        expect(validateOptionalSignedDecimalInput('0', 2)).toBeNull();
        expect(validateOptionalSignedDecimalInput('-0.00', 2)).toBeNull();
        expect(validateOptionalSignedDecimalInput('1000', 0)).toBeNull();
        expect(validateOptionalSignedDecimalInput('-1000', 0)).toBeNull();
        expect(validateOptionalSignedDecimalInput('  -10.5  ', 2)).toBeNull();
    });

    it('rejects excess precision, fractions for exponent 0, and malformed signed strings', () => {
        expect(validateOptionalSignedDecimalInput('12.345', 2)).toBe(
            'Monetary amount cannot have more than 2 decimal places.',
        );
        expect(validateOptionalSignedDecimalInput('15800.5', 0)).toBe(
            'Currency does not support fractional minor units.',
        );
        expect(validateOptionalSignedDecimalInput('+10.00', 2)).toBe(
            'Monetary amount must be a valid signed number.',
        );
        expect(validateOptionalSignedDecimalInput('1,250.50', 2)).toBe(
            'Monetary amount must be a valid signed number.',
        );
        expect(validateOptionalSignedDecimalInput('1e3', 2)).toBe(
            'Monetary amount must be a valid signed number.',
        );
        expect(validateOptionalSignedDecimalInput('.50', 2)).toBe(
            'Monetary amount must be a valid signed number.',
        );
        expect(validateOptionalSignedDecimalInput('1.', 2)).toBe(
            'Monetary amount must be a valid signed number.',
        );
        expect(validateOptionalSignedDecimalInput('-', 2)).toBe(
            'Monetary amount must be a valid signed number.',
        );
        expect(validateOptionalSignedDecimalInput('--10.00', 2)).toBe(
            'Monetary amount must be a valid signed number.',
        );
        expect(validateOptionalSignedDecimalInput('15800.0', 0)).toBe(
            'Currency does not support fractional minor units.',
        );
    });
});
