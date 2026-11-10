import { describe, expect, it } from 'vitest';
import type { RecurringOccurrenceRecord, RecurringObligationRecord } from '@/Types';
import {
    buildBillsOverviewSummary,
    isPayableOccurrence,
    obligationDraftFingerprint,
    selectNeedsPaymentOccurrences,
} from '@/Utilities/billsSummary';

function occurrence(
    overrides: Partial<RecurringOccurrenceRecord>,
): RecurringOccurrenceRecord {
    return {
        id: 1,
        obligation_id: 10,
        obligation_name: 'Rent',
        due_date: '2026-10-31',
        currency_code: 'PHP',
        expected_amount_minor: 500_000,
        paid_amount_minor: 0,
        remaining_amount_minor: 500_000,
        overpaid_amount_minor: 0,
        status: 'scheduled',
        display_status: 'upcoming',
        is_overdue: false,
        is_due_today: false,
        is_partially_paid: false,
        is_paid: false,
        active_slot_key: '2026-10-31',
        skipped_at: null,
        cancelled_at: null,
        active_linked_transaction_ids: [],
        all_linked_transaction_ids: [],
        payment_links: [],
        currency: null,
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

function obligation(
    overrides: Partial<RecurringObligationRecord> = {},
): RecurringObligationRecord {
    return {
        id: 10,
        idempotency_key: '11111111-1111-4111-8111-111111111111',
        name: 'Rent',
        description: null,
        currency_code: 'PHP',
        amount_minor: 500_000,
        category_id: 1,
        category_type: 'expense',
        financial_account_id: null,
        start_date: '2026-10-31',
        frequency: 'monthly',
        interval_count: 1,
        anchor_day: 31,
        ends_of_month: false,
        next_occurrence_date: '2026-10-31',
        status: 'active',
        paused_at: null,
        cancelled_at: null,
        currency: null,
        category: null,
        financial_account: null,
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

describe('buildBillsOverviewSummary', () => {
    it('keeps month totals separated by currency and counts outstanding status indicators', () => {
        const summary = buildBillsOverviewSummary(
            [obligation(), obligation({ id: 11, status: 'paused', currency_code: 'USD' })],
            [
                occurrence({
                    id: 1,
                    display_status: 'upcoming',
                    due_date: '2026-10-31',
                    currency_code: 'PHP',
                    expected_amount_minor: 500_000,
                    remaining_amount_minor: 500_000,
                }),
                occurrence({
                    id: 2,
                    display_status: 'partially_paid',
                    is_overdue: true,
                    due_date: '2026-10-01',
                    currency_code: 'USD',
                    expected_amount_minor: 2_000,
                    paid_amount_minor: 500,
                    remaining_amount_minor: 1_500,
                    is_partially_paid: true,
                }),
                occurrence({
                    id: 3,
                    display_status: 'due',
                    is_due_today: true,
                    due_date: '2026-10-15',
                    currency_code: 'PHP',
                    expected_amount_minor: 100_000,
                    remaining_amount_minor: 100_000,
                }),
                occurrence({
                    id: 4,
                    status: 'skipped',
                    display_status: 'skipped',
                    due_date: '2026-10-20',
                    currency_code: 'PHP',
                    expected_amount_minor: 50_000,
                    remaining_amount_minor: 50_000,
                }),
            ],
            2026,
            10,
        );

        expect(summary.active_obligation_count).toBe(1);
        expect(summary.upcoming_count).toBe(1);
        expect(summary.overdue_count).toBe(1);
        expect(summary.due_today_count).toBe(1);
        expect(summary.month_by_currency).toEqual([
            {
                currency_code: 'PHP',
                expected_minor: 600_000,
                paid_minor: 0,
                remaining_minor: 600_000,
                overpaid_minor: 0,
                occurrence_count: 2,
            },
            {
                currency_code: 'USD',
                expected_minor: 2_000,
                paid_minor: 500,
                remaining_minor: 1_500,
                overpaid_minor: 0,
                occurrence_count: 1,
            },
        ]);
    });

    it('excludes fully paid due-today and overdue occurrences from outstanding counters', () => {
        const summary = buildBillsOverviewSummary(
            [obligation()],
            [
                occurrence({
                    id: 1,
                    due_date: '2026-10-31',
                    display_status: 'paid',
                    is_due_today: true,
                    is_paid: true,
                    paid_amount_minor: 100_000,
                    remaining_amount_minor: 0,
                    expected_amount_minor: 100_000,
                }),
                occurrence({
                    id: 2,
                    due_date: '2026-10-01',
                    display_status: 'paid',
                    is_overdue: true,
                    is_paid: true,
                    paid_amount_minor: 200_000,
                    remaining_amount_minor: 0,
                    expected_amount_minor: 200_000,
                }),
            ],
            2026,
            10,
        );

        expect(summary.due_today_count).toBe(0);
        expect(summary.overdue_count).toBe(0);
        expect(summary.month_by_currency).toEqual([
            {
                currency_code: 'PHP',
                expected_minor: 300_000,
                paid_minor: 300_000,
                remaining_minor: 0,
                overpaid_minor: 0,
                occurrence_count: 2,
            },
        ]);
    });

    it('counts unpaid due-today and overdue occurrences', () => {
        const summary = buildBillsOverviewSummary(
            [obligation()],
            [
                occurrence({
                    id: 1,
                    due_date: '2026-10-31',
                    display_status: 'due',
                    is_due_today: true,
                    remaining_amount_minor: 100_000,
                    expected_amount_minor: 100_000,
                }),
                occurrence({
                    id: 2,
                    due_date: '2026-10-01',
                    display_status: 'overdue',
                    is_overdue: true,
                    remaining_amount_minor: 200_000,
                    expected_amount_minor: 200_000,
                }),
            ],
            2026,
            10,
        );

        expect(summary.due_today_count).toBe(1);
        expect(summary.overdue_count).toBe(1);
    });

    it('counts partially paid due-today and overdue rows with remaining balance', () => {
        const summary = buildBillsOverviewSummary(
            [obligation()],
            [
                occurrence({
                    id: 1,
                    due_date: '2026-10-31',
                    display_status: 'partially_paid',
                    is_due_today: true,
                    is_partially_paid: true,
                    paid_amount_minor: 40_000,
                    remaining_amount_minor: 60_000,
                    expected_amount_minor: 100_000,
                }),
                occurrence({
                    id: 2,
                    due_date: '2026-10-01',
                    display_status: 'partially_paid',
                    is_overdue: true,
                    is_partially_paid: true,
                    paid_amount_minor: 25_000,
                    remaining_amount_minor: 75_000,
                    expected_amount_minor: 100_000,
                }),
            ],
            2026,
            10,
        );

        expect(summary.due_today_count).toBe(1);
        expect(summary.overdue_count).toBe(1);
    });

    it('does not count partially paid future occurrences as due today or overdue', () => {
        const summary = buildBillsOverviewSummary(
            [obligation()],
            [
                occurrence({
                    id: 1,
                    due_date: '2026-11-30',
                    display_status: 'partially_paid',
                    is_due_today: false,
                    is_overdue: false,
                    is_partially_paid: true,
                    paid_amount_minor: 10_000,
                    remaining_amount_minor: 90_000,
                    expected_amount_minor: 100_000,
                }),
            ],
            2026,
            11,
        );

        expect(summary.due_today_count).toBe(0);
        expect(summary.overdue_count).toBe(0);
        expect(summary.upcoming_count).toBe(0);
    });

    it('excludes skipped and cancelled occurrences from outstanding counters', () => {
        const summary = buildBillsOverviewSummary(
            [obligation()],
            [
                occurrence({
                    id: 1,
                    status: 'skipped',
                    display_status: 'skipped',
                    is_due_today: true,
                    remaining_amount_minor: 100_000,
                    expected_amount_minor: 100_000,
                }),
                occurrence({
                    id: 2,
                    status: 'cancelled',
                    display_status: 'cancelled',
                    is_overdue: true,
                    remaining_amount_minor: 100_000,
                    expected_amount_minor: 100_000,
                    cancelled_at: '2026-10-01T00:00:00+00:00',
                }),
            ],
            2026,
            10,
        );

        expect(summary.due_today_count).toBe(0);
        expect(summary.overdue_count).toBe(0);
        expect(summary.month_by_currency).toEqual([]);
    });

    it('treats zero remaining as non-outstanding even when calendar flags are set', () => {
        const summary = buildBillsOverviewSummary(
            [obligation()],
            [
                occurrence({
                    id: 1,
                    display_status: 'paid',
                    is_due_today: true,
                    is_paid: true,
                    paid_amount_minor: 100_000,
                    remaining_amount_minor: 0,
                    expected_amount_minor: 100_000,
                }),
                occurrence({
                    id: 2,
                    display_status: 'paid',
                    is_overdue: true,
                    is_paid: true,
                    paid_amount_minor: 50_000,
                    remaining_amount_minor: 0,
                    overpaid_amount_minor: 0,
                    expected_amount_minor: 50_000,
                }),
            ],
            2026,
            10,
        );

        expect(summary.due_today_count).toBe(0);
        expect(summary.overdue_count).toBe(0);
    });

    it('preserves mixed-currency month totals without cross-currency summation', () => {
        const summary = buildBillsOverviewSummary(
            [obligation(), obligation({ id: 11, currency_code: 'USD' })],
            [
                occurrence({
                    id: 1,
                    currency_code: 'PHP',
                    due_date: '2026-10-31',
                    expected_amount_minor: 100_000,
                    paid_amount_minor: 100_000,
                    remaining_amount_minor: 0,
                    display_status: 'paid',
                    is_paid: true,
                    is_due_today: true,
                }),
                occurrence({
                    id: 2,
                    currency_code: 'USD',
                    due_date: '2026-10-15',
                    expected_amount_minor: 2_500,
                    paid_amount_minor: 500,
                    remaining_amount_minor: 2_000,
                    display_status: 'partially_paid',
                    is_partially_paid: true,
                    is_overdue: true,
                }),
            ],
            2026,
            10,
        );

        expect(summary.due_today_count).toBe(0);
        expect(summary.overdue_count).toBe(1);
        expect(summary.month_by_currency).toEqual([
            {
                currency_code: 'PHP',
                expected_minor: 100_000,
                paid_minor: 100_000,
                remaining_minor: 0,
                overpaid_minor: 0,
                occurrence_count: 1,
            },
            {
                currency_code: 'USD',
                expected_minor: 2_500,
                paid_minor: 500,
                remaining_minor: 2_000,
                overpaid_minor: 0,
                occurrence_count: 1,
            },
        ]);
    });
});

describe('isPayableOccurrence', () => {
    it('matches the Bills Pay CTA: scheduled and not paid', () => {
        expect(isPayableOccurrence(occurrence({ status: 'scheduled', is_paid: false }))).toBe(true);
        expect(isPayableOccurrence(occurrence({ status: 'scheduled', is_paid: true }))).toBe(false);
        expect(isPayableOccurrence(occurrence({ status: 'skipped', is_paid: false }))).toBe(false);
        expect(isPayableOccurrence(occurrence({ status: 'cancelled', is_paid: false }))).toBe(
            false,
        );
    });

    it('keeps Pay available for zero remaining when is_paid is still false', () => {
        // Matches list CTA. Dialog posting still rejects a zero amount.
        expect(
            isPayableOccurrence(
                occurrence({
                    status: 'scheduled',
                    is_paid: false,
                    remaining_amount_minor: 0,
                    paid_amount_minor: 500_000,
                    expected_amount_minor: 500_000,
                    display_status: 'partially_paid',
                    is_partially_paid: true,
                }),
            ),
        ).toBe(true);
    });

    it('excludes zero-remaining rows that are marked paid', () => {
        expect(
            isPayableOccurrence(
                occurrence({
                    status: 'scheduled',
                    is_paid: true,
                    remaining_amount_minor: 0,
                    paid_amount_minor: 500_000,
                    display_status: 'paid',
                }),
            ),
        ).toBe(false);
    });
});

describe('selectNeedsPaymentOccurrences', () => {
    it('returns payable rows ordered by urgency and excludes paid or terminal statuses', () => {
        const selected = selectNeedsPaymentOccurrences(
            [
                occurrence({
                    id: 1,
                    obligation_name: 'Upcoming',
                    display_status: 'upcoming',
                    due_date: '2026-10-20',
                }),
                occurrence({
                    id: 2,
                    obligation_name: 'Overdue',
                    display_status: 'overdue',
                    is_overdue: true,
                    due_date: '2026-10-01',
                }),
                occurrence({
                    id: 3,
                    obligation_name: 'Paid',
                    display_status: 'paid',
                    is_paid: true,
                    remaining_amount_minor: 0,
                    paid_amount_minor: 500_000,
                }),
                occurrence({
                    id: 4,
                    obligation_name: 'Due today',
                    display_status: 'due',
                    is_due_today: true,
                    due_date: '2026-10-15',
                }),
                occurrence({
                    id: 5,
                    obligation_name: 'Skipped',
                    status: 'skipped',
                    display_status: 'skipped',
                }),
                occurrence({
                    id: 6,
                    obligation_name: 'Partial',
                    display_status: 'partially_paid',
                    is_partially_paid: true,
                    due_date: '2026-10-10',
                    paid_amount_minor: 100_000,
                    remaining_amount_minor: 400_000,
                }),
            ],
            5,
        );

        expect(selected.map((row) => row.obligation_name)).toEqual([
            'Overdue',
            'Due today',
            'Partial',
            'Upcoming',
        ]);
    });

    it('caps the shortcut list at the requested limit', () => {
        const selected = selectNeedsPaymentOccurrences(
            [
                occurrence({ id: 1, due_date: '2026-10-01', is_overdue: true, display_status: 'overdue' }),
                occurrence({ id: 2, due_date: '2026-10-02', is_overdue: true, display_status: 'overdue' }),
                occurrence({ id: 3, due_date: '2026-10-03', is_overdue: true, display_status: 'overdue' }),
            ],
            2,
        );

        expect(selected).toHaveLength(2);
        expect(selected.map((row) => row.id)).toEqual([1, 2]);
    });

    it('includes zero-remaining unpaid scheduled rows to match the Pay CTA', () => {
        const selected = selectNeedsPaymentOccurrences([
            occurrence({
                id: 9,
                obligation_name: 'Zero remainder',
                remaining_amount_minor: 0,
                is_paid: false,
                status: 'scheduled',
            }),
            occurrence({
                id: 10,
                obligation_name: 'Paid fully',
                remaining_amount_minor: 0,
                is_paid: true,
                display_status: 'paid',
            }),
        ]);

        expect(selected.map((row) => row.obligation_name)).toEqual(['Zero remainder']);
    });
});

describe('obligationDraftFingerprint', () => {
    it('changes when payload fields change and stays stable for whitespace-normalized retries', () => {
        const base = {
            name: 'Rent',
            description: '',
            amount: '5000.00',
            currency_code: 'php',
            category_id: '1',
            financial_account_id: '',
            start_date: '2026-10-31',
            frequency: 'monthly',
            anchor_day: '31',
            ends_of_month: false,
        };

        const first = obligationDraftFingerprint(base);
        const retry = obligationDraftFingerprint({ ...base, name: ' Rent ' });
        const changed = obligationDraftFingerprint({ ...base, amount: '5500.00' });

        expect(first).toBe(retry);
        expect(changed).not.toBe(first);
    });
});
