import { describe, expect, it } from 'vitest';
import type { RecurringOccurrenceRecord, RecurringObligationRecord } from '@/Types';
import {
    buildBillsOverviewSummary,
    obligationDraftFingerprint,
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
    it('keeps month totals separated by currency and counts status indicators', () => {
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
                    display_status: 'overdue',
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
