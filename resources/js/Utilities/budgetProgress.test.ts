import { describe, expect, it } from 'vitest';
import {
    computeUtilization,
    progressBarPercent,
    remainingAllocatedMinor,
} from '@/Utilities/budgetProgress';

describe('budgetProgress', () => {
    it('computes remaining allocated budget with exact integer minor units', () => {
        expect(remainingAllocatedMinor(10_000, 2_500)).toBe(7_500n);
        expect(remainingAllocatedMinor(1_000, 1_500)).toBe(-500n);
    });

    it('handles zero allocations without dividing by zero', () => {
        expect(computeUtilization(0, 0)).toEqual({
            kind: 'zero_limit',
            percent: null,
            isOverLimit: false,
        });
        expect(computeUtilization(0, 250)).toEqual({
            kind: 'zero_limit',
            percent: null,
            isOverLimit: true,
        });
        expect(progressBarPercent(computeUtilization(0, 0))).toBe(0);
        expect(progressBarPercent(computeUtilization(0, 250))).toBe(100);
    });

    it('reports integer utilization and overspending for positive limits', () => {
        expect(computeUtilization(10_000, 2_500)).toEqual({
            kind: 'ratio',
            percent: 25,
            isOverLimit: false,
        });
        expect(computeUtilization(10_000, 12_500, true)).toEqual({
            kind: 'ratio',
            percent: 125,
            isOverLimit: true,
        });
        expect(progressBarPercent(computeUtilization(10_000, 12_500, true))).toBe(100);
    });
});
