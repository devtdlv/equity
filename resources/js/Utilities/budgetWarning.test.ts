import { describe, expect, it } from 'vitest';
import { budgetWarningLabel, resolveBudgetWarningKind } from '@/Utilities/budgetWarning';

describe('resolveBudgetWarningKind', () => {
    it('returns null when is_over_limit is false', () => {
        expect(
            resolveBudgetWarningKind({
                isOverLimit: false,
                allocatedMinor: '3750000',
                spentAllocatedMinor: '5000000',
            }),
        ).toBeNull();
    });

    it('returns budget when aggregate spent exceeds allocation', () => {
        expect(
            resolveBudgetWarningKind({
                isOverLimit: true,
                allocatedMinor: '3750000',
                spentAllocatedMinor: '4000000',
            }),
        ).toBe('budget');
        expect(budgetWarningLabel('budget')).toBe('Budget over limit');
    });

    it('returns category when aggregate is within allocation but is_over_limit is true', () => {
        expect(
            resolveBudgetWarningKind({
                isOverLimit: true,
                allocatedMinor: '3750000',
                spentAllocatedMinor: '3249850',
            }),
        ).toBe('category');
        expect(budgetWarningLabel('category')).toBe('Category over limit');
    });
});
