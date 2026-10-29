import { describe, expect, it } from 'vitest';
import { formatMinorUnits, minorUnitsToDecimalString } from '@/Utilities/money';

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
