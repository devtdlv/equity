import type {
    RecurringOccurrenceDisplayStatusValue,
    RecurringOccurrenceRecord,
    RecurringObligationRecord,
} from '@/Types';

export interface CurrencyMonthTotals {
    currency_code: string;
    expected_minor: number;
    paid_minor: number;
    remaining_minor: number;
    overpaid_minor: number;
    occurrence_count: number;
}

export interface BillsOverviewSummary {
    upcoming_count: number;
    overdue_count: number;
    due_today_count: number;
    active_obligation_count: number;
    /** Per-currency month totals. Never combine across currencies. */
    month_by_currency: CurrencyMonthTotals[];
}

function isInUtcMonth(dueDate: string, year: number, month: number): boolean {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(dueDate)) {
        return false;
    }

    const dueYear = Number(dueDate.slice(0, 4));
    const dueMonth = Number(dueDate.slice(5, 7));

    return dueYear === year && dueMonth === month;
}

function checkedAddMinor(left: number, right: number): number {
    const sum = left + right;
    if (!Number.isSafeInteger(sum)) {
        throw new RangeError('Monetary total exceeded safe integer bounds.');
    }

    return sum;
}

/**
 * Outstanding bill rows still needing payment attention.
 * Fully paid, skipped, and cancelled occurrences are excluded even when
 * calendar flags (is_due_today / is_overdue) remain true.
 * Partially paid rows with remaining_amount_minor > 0 stay outstanding.
 */
function isOutstandingOccurrence(occurrence: RecurringOccurrenceRecord): boolean {
    if (occurrence.status === 'skipped' || occurrence.status === 'cancelled') {
        return false;
    }

    if (occurrence.is_paid || occurrence.display_status === 'paid') {
        return false;
    }

    return occurrence.remaining_amount_minor > 0;
}

/**
 * Builds overview metrics from already-fetched obligation and occurrence records.
 * Month money totals are grouped by currency_code and never cross-summed.
 */
export function buildBillsOverviewSummary(
    obligations: RecurringObligationRecord[],
    occurrences: RecurringOccurrenceRecord[],
    year: number,
    month: number,
): BillsOverviewSummary {
    let upcomingCount = 0;
    let overdueCount = 0;
    let dueTodayCount = 0;

    const monthMap = new Map<string, CurrencyMonthTotals>();

    for (const occurrence of occurrences) {
        const outstanding = isOutstandingOccurrence(occurrence);

        if (outstanding && occurrence.is_overdue) {
            overdueCount += 1;
        }
        if (outstanding && occurrence.is_due_today) {
            dueTodayCount += 1;
        }
        if (occurrence.display_status === 'upcoming') {
            upcomingCount += 1;
        }

        if (!isInUtcMonth(occurrence.due_date, year, month)) {
            continue;
        }

        // Skipped/cancelled historical rows do not count toward expected month totals.
        if (occurrence.status === 'skipped' || occurrence.status === 'cancelled') {
            continue;
        }

        const existing = monthMap.get(occurrence.currency_code) ?? {
            currency_code: occurrence.currency_code,
            expected_minor: 0,
            paid_minor: 0,
            remaining_minor: 0,
            overpaid_minor: 0,
            occurrence_count: 0,
        };

        existing.expected_minor = checkedAddMinor(
            existing.expected_minor,
            occurrence.expected_amount_minor,
        );
        existing.paid_minor = checkedAddMinor(existing.paid_minor, occurrence.paid_amount_minor);
        existing.remaining_minor = checkedAddMinor(
            existing.remaining_minor,
            occurrence.remaining_amount_minor,
        );
        existing.overpaid_minor = checkedAddMinor(
            existing.overpaid_minor,
            occurrence.overpaid_amount_minor,
        );
        existing.occurrence_count += 1;
        monthMap.set(occurrence.currency_code, existing);
    }

    const monthByCurrency = Array.from(monthMap.values()).sort((a, b) =>
        a.currency_code.localeCompare(b.currency_code),
    );

    return {
        upcoming_count: upcomingCount,
        overdue_count: overdueCount,
        due_today_count: dueTodayCount,
        active_obligation_count: obligations.filter((item) => item.status === 'active').length,
        month_by_currency: monthByCurrency,
    };
}

export function displayStatusLabel(status: RecurringOccurrenceDisplayStatusValue): string {
    switch (status) {
        case 'upcoming':
            return 'Upcoming';
        case 'due':
            return 'Due today';
        case 'overdue':
            return 'Overdue';
        case 'partially_paid':
            return 'Partially paid';
        case 'paid':
            return 'Paid';
        case 'skipped':
            return 'Skipped';
        case 'cancelled':
            return 'Cancelled';
        default:
            return status;
    }
}

export function obligationStatusLabel(status: RecurringObligationRecord['status']): string {
    switch (status) {
        case 'active':
            return 'Active';
        case 'paused':
            return 'Paused';
        case 'cancelled':
            return 'Cancelled';
        default:
            return status;
    }
}

export function frequencyLabel(frequency: RecurringObligationRecord['frequency']): string {
    switch (frequency) {
        case 'weekly':
            return 'Weekly';
        case 'biweekly':
            return 'Biweekly';
        case 'monthly':
            return 'Monthly';
        case 'quarterly':
            return 'Quarterly';
        case 'yearly':
            return 'Yearly';
        default:
            return frequency;
    }
}

/**
 * Stable fingerprint of create/edit payload fields used to decide when a new
 * idempotency UUID is required (payload changed) versus retrying the same key.
 */
export function obligationDraftFingerprint(draft: {
    name: string;
    description: string;
    amount: string;
    currency_code: string;
    category_id: string;
    financial_account_id: string;
    start_date: string;
    frequency: string;
    anchor_day: string;
    ends_of_month: boolean;
}): string {
    return [
        draft.name.trim(),
        draft.description.trim(),
        draft.amount.trim(),
        draft.currency_code.trim().toUpperCase(),
        draft.category_id.trim(),
        draft.financial_account_id.trim(),
        draft.start_date.trim(),
        draft.frequency.trim().toLowerCase(),
        draft.anchor_day.trim(),
        draft.ends_of_month ? '1' : '0',
    ].join('\u001f');
}
