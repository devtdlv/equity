import { describe, expect, it } from 'vitest';
import {
    findBudgetForPeriod,
    formatMonthLabel,
    isPastUtcMonth,
    periodStartFor,
    shiftMonth,
} from '@/Utilities/budgetPeriod';

describe('budgetPeriod', () => {
    it('builds UTC period start dates and shifts months across year boundaries', () => {
        expect(periodStartFor(2026, 10)).toBe('2026-10-01');
        expect(shiftMonth(2026, 1, -1)).toEqual({ year: 2025, month: 12 });
        expect(shiftMonth(2025, 12, 1)).toEqual({ year: 2026, month: 1 });
        expect(formatMonthLabel(2026, 10)).toBe('October 2026');
    });

    it('marks earlier UTC months as past while keeping the current month editable', () => {
        const now = new Date(Date.UTC(2026, 9, 15)); // 2026-10-15 UTC

        expect(isPastUtcMonth(2026, 10, now)).toBe(false);
        expect(isPastUtcMonth(2026, 9, now)).toBe(true);
        expect(isPastUtcMonth(2025, 12, now)).toBe(true);
        expect(isPastUtcMonth(2026, 11, now)).toBe(false);
    });

    it('finds the unique month and currency budget without cross-currency matches', () => {
        const budgets = [
            { id: 1, period_start: '2026-10-01', currency_code: 'USD' },
            { id: 2, period_start: '2026-10-01', currency_code: 'JPY' },
            { id: 3, period_start: '2026-09-01', currency_code: 'USD' },
        ];

        expect(findBudgetForPeriod(budgets, 2026, 10, 'usd')?.id).toBe(1);
        expect(findBudgetForPeriod(budgets, 2026, 10, 'JPY')?.id).toBe(2);
        expect(findBudgetForPeriod(budgets, 2026, 11, 'USD')).toBeNull();
    });
});
