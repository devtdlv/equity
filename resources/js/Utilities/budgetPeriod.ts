export interface YearMonth {
    year: number;
    month: number;
}

/**
 * UTC calendar month start date as YYYY-MM-01.
 */
export function periodStartFor(year: number, month: number): string {
    const safeYear = Math.trunc(year);
    const safeMonth = Math.trunc(month);

    return `${String(safeYear).padStart(4, '0')}-${String(safeMonth).padStart(2, '0')}-01`;
}

/**
 * Shift a calendar month by `delta` months using UTC date math.
 */
export function shiftMonth(year: number, month: number, delta: number): YearMonth {
    const date = new Date(Date.UTC(Math.trunc(year), Math.trunc(month) - 1 + Math.trunc(delta), 1));

    return {
        year: date.getUTCFullYear(),
        month: date.getUTCMonth() + 1,
    };
}

/**
 * True when the given UTC calendar month is strictly before the current UTC month.
 */
export function isPastUtcMonth(year: number, month: number, now: Date = new Date()): boolean {
    const currentYear = now.getUTCFullYear();
    const currentMonth = now.getUTCMonth() + 1;

    return year < currentYear || (year === currentYear && month < currentMonth);
}

/**
 * Human-readable month label in UTC (e.g. "October 2026").
 */
export function formatMonthLabel(year: number, month: number): string {
    return new Date(Date.UTC(Math.trunc(year), Math.trunc(month) - 1, 1)).toLocaleString('en-US', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

/**
 * Find a budget whose period_start matches the selected month and currency.
 */
export function findBudgetForPeriod<T extends { period_start: string; currency_code: string }>(
    budgets: readonly T[],
    year: number,
    month: number,
    currencyCode: string,
): T | null {
    const periodStart = periodStartFor(year, month);
    const normalizedCurrency = currencyCode.trim().toUpperCase();

    return (
        budgets.find(
            (budget) =>
                budget.period_start === periodStart &&
                budget.currency_code.toUpperCase() === normalizedCurrency,
        ) ?? null
    );
}
