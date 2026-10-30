import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import BillsIndex from '@/Pages/Bills/Index';
import type {
    CategoryRecord,
    CurrencyOption,
    FinancialTransactionItem,
    PaginationMeta,
    RecurringOccurrenceRecord,
    RecurringObligationRecord,
    TransactionAccountOption,
} from '@/Types';

let uuidCounter = 0;

vi.mock('@/Utilities/uuid', () => ({
    generateUuidV4: () => {
        uuidCounter += 1;
        return `00000000-0000-4000-8000-${String(uuidCounter).padStart(12, '0')}`;
    },
}));

vi.mock('axios', () => {
    const get = vi.fn();
    const post = vi.fn();
    const patch = vi.fn();
    const del = vi.fn();
    const isAxiosError = (err: unknown): boolean =>
        typeof err === 'object' && err !== null && 'isAxiosError' in err;

    return {
        default: {
            get,
            post,
            patch,
            delete: del,
            isAxiosError,
        },
    };
});

vi.mock('@inertiajs/react', () => ({
    Head: ({ title, children }: { title?: string; children?: ReactNode }) => (
        <>
            {title ? <title>{title}</title> : null}
            {children}
        </>
    ),
    Link: ({
        href,
        children,
        ...props
    }: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({
        url: '/bills',
        props: {
            app: { name: 'Equity' },
            auth: {
                user: {
                    id: 1,
                    name: 'Morgan Lee',
                    email: 'morgan@example.com',
                },
            },
            flash: { status: null },
            categories: SAMPLE_CATEGORIES,
            accounts: SAMPLE_ACCOUNTS,
            currencies: SAMPLE_CURRENCIES,
            initial_year: 2026,
            initial_month: 10,
            today: '2026-10-15',
        },
    }),
    useForm: () => ({
        data: {},
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
    }),
}));

const SAMPLE_CURRENCIES: CurrencyOption[] = [
    { code: 'USD', name: 'US Dollar', symbol: '$', minor_unit_exponent: 2 },
    { code: 'PHP', name: 'Philippine Peso', symbol: '₱', minor_unit_exponent: 2 },
];

const SAMPLE_CATEGORIES: CategoryRecord[] = [
    {
        id: 101,
        name: 'Subscriptions',
        type: 'expense',
        type_label: 'Expense',
        classification: 'expense',
        is_archived: false,
        archived_at: null,
    },
    {
        id: 102,
        name: 'Housing',
        type: 'expense',
        type_label: 'Expense',
        classification: 'expense',
        is_archived: false,
        archived_at: null,
    },
];

const SAMPLE_ACCOUNTS: TransactionAccountOption[] = [
    {
        id: 1,
        name: 'Checking',
        type: 'checking',
        type_label: 'Checking',
        classification: 'asset',
        currency_code: 'USD',
        currency_symbol: '$',
        minor_unit_exponent: 2,
        is_archived: false,
    },
    {
        id: 2,
        name: 'PHP Wallet',
        type: 'cash',
        type_label: 'Cash',
        classification: 'asset',
        currency_code: 'PHP',
        currency_symbol: '₱',
        minor_unit_exponent: 2,
        is_archived: false,
    },
];

function obligation(
    overrides: Partial<RecurringObligationRecord> = {},
): RecurringObligationRecord {
    return {
        id: 10,
        idempotency_key: '11111111-1111-4111-8111-111111111111',
        name: 'Netflix',
        description: 'Streaming',
        currency_code: 'USD',
        amount_minor: 1_599,
        category_id: 101,
        category_type: 'expense',
        financial_account_id: 1,
        start_date: '2026-10-01',
        frequency: 'monthly',
        interval_count: 1,
        anchor_day: 1,
        ends_of_month: false,
        next_occurrence_date: '2026-11-01',
        status: 'active',
        paused_at: null,
        cancelled_at: null,
        currency: {
            code: 'USD',
            name: 'US Dollar',
            symbol: '$',
            minor_unit_exponent: 2,
        },
        category: {
            id: 101,
            name: 'Subscriptions',
            type: 'expense',
            type_label: 'Expense',
            is_archived: false,
        },
        financial_account: {
            id: 1,
            name: 'Checking',
            type: 'checking',
            type_label: 'Checking',
            currency_code: 'USD',
            is_archived: false,
        },
        created_at: '2026-10-01T00:00:00+00:00',
        updated_at: '2026-10-01T00:00:00+00:00',
        ...overrides,
    };
}

function occurrence(
    overrides: Partial<RecurringOccurrenceRecord> = {},
): RecurringOccurrenceRecord {
    return {
        id: 20,
        obligation_id: 10,
        obligation_name: 'Netflix',
        due_date: '2026-10-01',
        currency_code: 'USD',
        expected_amount_minor: 1_599,
        paid_amount_minor: 0,
        remaining_amount_minor: 1_599,
        overpaid_amount_minor: 0,
        status: 'scheduled',
        display_status: 'upcoming',
        is_overdue: false,
        is_due_today: false,
        is_partially_paid: false,
        is_paid: false,
        active_slot_key: '2026-10-01',
        skipped_at: null,
        cancelled_at: null,
        active_linked_transaction_ids: [],
        all_linked_transaction_ids: [],
        payment_links: [],
        currency: {
            code: 'USD',
            name: 'US Dollar',
            symbol: '$',
            minor_unit_exponent: 2,
        },
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

function pageMeta(overrides: Partial<PaginationMeta> = {}): PaginationMeta {
    return {
        current_page: 1,
        last_page: 1,
        per_page: 15,
        total: 1,
        from: 1,
        to: 1,
        ...overrides,
    };
}

const mockedAxios = axios as unknown as {
    get: ReturnType<typeof vi.fn>;
    post: ReturnType<typeof vi.fn>;
    patch: ReturnType<typeof vi.fn>;
    delete: ReturnType<typeof vi.fn>;
};

function mockListPayload(
    obligations: RecurringObligationRecord[],
    overviewOccurrences: RecurringOccurrenceRecord[],
    listOccurrences: RecurringOccurrenceRecord[] = overviewOccurrences,
    meta: PaginationMeta = pageMeta({ total: listOccurrences.length }),
) {
    mockedAxios.get.mockImplementation(async (url: string) => {
        if (url === '/recurring-obligations') {
            return { data: { data: obligations } };
        }

        if (url === '/recurring-occurrences') {
            return { data: { data: listOccurrences, meta } };
        }

        if (url === '/transactions') {
            return { data: { data: [] } };
        }

        throw new Error(`Unexpected GET ${url}`);
    });
}

async function renderReady(
    obligations: RecurringObligationRecord[] = [obligation()],
    overviewOccurrences: RecurringOccurrenceRecord[] = [occurrence()],
    listOccurrences?: RecurringOccurrenceRecord[],
    meta?: PaginationMeta,
) {
    mockListPayload(obligations, overviewOccurrences, listOccurrences, meta);
    render(<BillsIndex />);
    await waitFor(() => {
        expect(screen.queryByLabelText('Loading bills overview')).not.toBeInTheDocument();
    });
}

describe('BillsIndex', () => {
    beforeEach(() => {
        mockedAxios.get.mockReset();
        mockedAxios.post.mockReset();
        mockedAxios.patch.mockReset();
        mockedAxios.delete.mockReset();
        uuidCounter = 0;
    });

    afterEach(() => {
        vi.clearAllMocks();
    });

    it('renders the page header and navigation entry', async () => {
        await renderReady();

        expect(
            screen.getByRole('heading', { name: 'Bills & Subscriptions' }),
        ).toBeInTheDocument();
        const billsLinks = screen.getAllByRole('link', { name: 'Bills & Subscriptions' });
        expect(billsLinks[0]).toHaveAttribute('href', '/bills');
        expect(billsLinks[0]).toHaveAttribute('aria-current', 'page');
    });

    it('shows the first-use empty state when no obligations exist', async () => {
        await renderReady([], []);

        expect(
            screen.getByRole('heading', { name: 'No bills or subscriptions yet' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Create your first bill' })).toBeInTheDocument();
    });

    it('keeps multi-currency month totals separated in the overview', async () => {
        await renderReady(
            [
                obligation(),
                obligation({
                    id: 11,
                    name: 'Rent',
                    currency_code: 'PHP',
                    amount_minor: 500_000,
                    category_id: 102,
                }),
            ],
            [
                occurrence({
                    id: 20,
                    expected_amount_minor: 1_599,
                    remaining_amount_minor: 1_599,
                    currency_code: 'USD',
                    display_status: 'upcoming',
                }),
                occurrence({
                    id: 21,
                    obligation_id: 11,
                    obligation_name: 'Rent',
                    due_date: '2026-10-31',
                    currency_code: 'PHP',
                    expected_amount_minor: 500_000,
                    remaining_amount_minor: 500_000,
                    display_status: 'upcoming',
                }),
            ],
        );

        expect(screen.getByText('USD this month')).toBeInTheDocument();
        expect(screen.getByText('PHP this month')).toBeInTheDocument();
        expect(screen.getByText('Currencies are never combined. Overpaid: $0.00')).toBeInTheDocument();
        expect(screen.getByText('Currencies are never combined. Overpaid: ₱0.00')).toBeInTheDocument();
    });

    it('creates an obligation with a UUID idempotency key and reuses it on unchanged retries', async () => {
        await renderReady([], []);

        fireEvent.click(screen.getByRole('button', { name: 'Create your first bill' }));

        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Spotify' } });
        fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '9.99' } });
        fireEvent.change(screen.getByLabelText('Expense category'), {
            target: { value: '101' },
        });

        mockedAxios.post.mockRejectedValueOnce({
            isAxiosError: true,
            response: { status: 500, data: { message: 'Temporary failure' } },
        });

        fireEvent.click(screen.getByRole('button', { name: 'Create bill' }));

        await waitFor(() => {
            expect(mockedAxios.post).toHaveBeenCalled();
        });

        const firstKey = mockedAxios.post.mock.calls[0][1].idempotency_key as string;
        expect(firstKey).toMatch(/^00000000-0000-4000-8000-\d{12}$/);

        mockedAxios.post.mockResolvedValueOnce({
            data: {
                data: obligation({ name: 'Spotify', amount_minor: 999 }),
                meta: { idempotent_replay: false },
            },
        });
        mockListPayload(
            [obligation({ name: 'Spotify', amount_minor: 999 })],
            [occurrence({ obligation_name: 'Spotify', expected_amount_minor: 999 })],
        );

        fireEvent.click(screen.getByRole('button', { name: 'Create bill' }));

        await waitFor(() => {
            expect(mockedAxios.post).toHaveBeenCalledTimes(2);
        });
        expect(mockedAxios.post.mock.calls[1][1].idempotency_key).toBe(firstKey);
    });

    it('issues a new UUID when the create payload changes', async () => {
        await renderReady([], []);

        fireEvent.click(screen.getByRole('button', { name: 'Create your first bill' }));
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'A' } });
        fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '1.00' } });
        fireEvent.change(screen.getByLabelText('Expense category'), {
            target: { value: '101' },
        });

        mockedAxios.post.mockResolvedValueOnce({
            data: { data: obligation({ name: 'A' }), meta: { idempotent_replay: false } },
        });
        mockListPayload([obligation({ name: 'A' })], [occurrence({ obligation_name: 'A' })]);

        fireEvent.click(screen.getByRole('button', { name: 'Create bill' }));
        await waitFor(() => {
            expect(mockedAxios.post).toHaveBeenCalled();
        });
        const firstKey = mockedAxios.post.mock.calls[0][1].idempotency_key as string;

        fireEvent.click(screen.getByRole('button', { name: 'Add bill' }));
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'B' } });
        fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '2.00' } });
        fireEvent.change(screen.getByLabelText('Expense category'), {
            target: { value: '101' },
        });

        mockedAxios.post.mockResolvedValueOnce({
            data: { data: obligation({ id: 12, name: 'B' }), meta: { idempotent_replay: false } },
        });
        mockListPayload(
            [obligation({ name: 'A' }), obligation({ id: 12, name: 'B' })],
            [occurrence()],
        );

        fireEvent.click(screen.getByRole('button', { name: 'Create bill' }));
        await waitFor(() => {
            expect(mockedAxios.post).toHaveBeenCalledTimes(2);
        });
        const secondKey = mockedAxios.post.mock.calls[1][1].idempotency_key as string;
        expect(secondKey).not.toBe(firstKey);
        expect(mockedAxios.post.mock.calls[1][1].name).toBe('B');
    });

    it('surfaces validation errors on create', async () => {
        await renderReady([], []);
        fireEvent.click(screen.getByRole('button', { name: 'Create your first bill' }));

        mockedAxios.post.mockRejectedValueOnce({
            isAxiosError: true,
            response: {
                status: 422,
                data: {
                    message: 'The given data was invalid.',
                    errors: { amount: ['Amount must be a valid decimal string.'] },
                },
            },
        });

        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Broken' } });
        fireEvent.change(screen.getByLabelText('Amount'), { target: { value: 'abc' } });
        fireEvent.change(screen.getByLabelText('Expense category'), {
            target: { value: '101' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Create bill' }));

        await waitFor(() => {
            expect(
                screen.getAllByText('Amount must be a valid decimal string.').length,
            ).toBeGreaterThan(0);
        });
    });

    it('edits an obligation via PATCH', async () => {
        await renderReady();

        fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Netflix Premium' } });

        mockedAxios.patch.mockResolvedValueOnce({
            data: { data: obligation({ name: 'Netflix Premium' }) },
        });
        mockListPayload(
            [obligation({ name: 'Netflix Premium' })],
            [occurrence({ obligation_name: 'Netflix Premium' })],
        );

        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => {
            expect(mockedAxios.patch).toHaveBeenCalledWith(
                '/recurring-obligations/10',
                expect.objectContaining({ name: 'Netflix Premium' }),
                expect.any(Object),
            );
        });
    });

    it('pauses, resumes, and cancels obligations', async () => {
        await renderReady([obligation()]);

        mockedAxios.post.mockResolvedValue({ data: { data: obligation({ status: 'paused' }) } });
        mockListPayload([obligation({ status: 'paused' })], [occurrence()]);

        fireEvent.click(screen.getByRole('button', { name: 'Pause' }));
        await waitFor(() => {
            expect(mockedAxios.post).toHaveBeenCalledWith(
                '/recurring-obligations/10/pause',
                null,
                expect.any(Object),
            );
        });

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Resume' })).toBeInTheDocument();
        });

        mockedAxios.post.mockResolvedValue({ data: { data: obligation({ status: 'active' }) } });
        mockListPayload([obligation({ status: 'active' })], [occurrence()]);
        fireEvent.click(screen.getByRole('button', { name: 'Resume' }));
        await waitFor(() => {
            expect(mockedAxios.post).toHaveBeenCalledWith(
                '/recurring-obligations/10/resume',
                null,
                expect.any(Object),
            );
        });

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Pause' })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cancel bill' }));
        await waitFor(() => {
            expect(mockedAxios.post).toHaveBeenCalledWith(
                '/recurring-obligations/10/cancel',
                null,
                expect.any(Object),
            );
        });
    });

    it('lists occurrences with due and overdue statuses, including overdue partial payments', async () => {
        await renderReady(
            [obligation()],
            [
                occurrence({
                    id: 20,
                    display_status: 'due',
                    is_due_today: true,
                    due_date: '2026-10-15',
                }),
                occurrence({
                    id: 21,
                    display_status: 'partially_paid',
                    is_overdue: true,
                    is_partially_paid: true,
                    due_date: '2026-09-01',
                    paid_amount_minor: 500,
                    remaining_amount_minor: 1_099,
                    active_linked_transaction_ids: [55],
                    all_linked_transaction_ids: [55],
                }),
                occurrence({
                    id: 22,
                    display_status: 'skipped',
                    status: 'skipped',
                    due_date: '2026-08-01',
                }),
            ],
            [
                occurrence({
                    id: 20,
                    display_status: 'due',
                    is_due_today: true,
                    due_date: '2026-10-15',
                }),
                occurrence({
                    id: 21,
                    display_status: 'partially_paid',
                    is_overdue: true,
                    is_partially_paid: true,
                    due_date: '2026-09-01',
                    paid_amount_minor: 500,
                    remaining_amount_minor: 1_099,
                    active_linked_transaction_ids: [55],
                    all_linked_transaction_ids: [55],
                }),
                occurrence({
                    id: 22,
                    display_status: 'skipped',
                    status: 'skipped',
                    due_date: '2026-08-01',
                }),
            ],
        );

        expect(screen.getAllByText('Due today').length).toBeGreaterThan(0);
        expect(screen.getByText('Partially paid')).toBeInTheDocument();
        expect(screen.getByText('Still overdue')).toBeInTheDocument();
        expect(screen.getAllByText('Skipped').length).toBeGreaterThan(0);
    });

    it('applies occurrence filters and pagination', async () => {
        await renderReady(
            [obligation()],
            [occurrence()],
            [occurrence()],
            pageMeta({ current_page: 1, last_page: 2, total: 20 }),
        );

        fireEvent.change(screen.getByLabelText('Currency'), { target: { value: 'USD' } });
        await waitFor(() => {
            expect(mockedAxios.get).toHaveBeenCalledWith(
                '/recurring-occurrences',
                expect.objectContaining({
                    params: expect.objectContaining({ currency_code: 'USD', page: 1 }),
                }),
            );
        });

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        await waitFor(() => {
            expect(mockedAxios.get).toHaveBeenCalledWith(
                '/recurring-occurrences',
                expect.objectContaining({
                    params: expect.objectContaining({ page: 2 }),
                }),
            );
        });
    });

    it('links an eligible expense and remembers the payment link id', async () => {
        const candidate: FinancialTransactionItem = {
            id: 77,
            journal_entry_id: 900,
            type: 'expense',
            type_label: 'Expense',
            is_reversal: false,
            is_reversed: false,
            reversed_transaction_id: null,
            reversal_transaction_id: null,
            reversal_reason: null,
            entry_date: '2026-10-02',
            description: 'Netflix charge',
            notes: null,
            currency_code: 'USD',
            amount_minor: 1_599,
            amount_formatted: '$15.99',
            financial_account: {
                id: 1,
                name: 'Checking',
                type: 'checking',
                type_label: 'Checking',
                currency_code: 'USD',
                is_archived: false,
            },
            transfer_financial_account: null,
            category: {
                id: 101,
                name: 'Subscriptions',
                type: 'expense',
                type_label: 'Expense',
                is_archived: false,
            },
            created_at: null,
        };

        await renderReady();

        mockedAxios.get.mockImplementation(async (url: string) => {
            if (url === '/transactions') {
                return { data: { data: [candidate] } };
            }
            if (url === '/recurring-obligations') {
                return { data: { data: [obligation()] } };
            }
            if (url === '/recurring-occurrences') {
                return {
                    data: {
                        data: [
                            occurrence({
                                paid_amount_minor: 1_599,
                                remaining_amount_minor: 0,
                                is_paid: true,
                                display_status: 'paid',
                                active_linked_transaction_ids: [77],
                                all_linked_transaction_ids: [77],
                            }),
                        ],
                        meta: pageMeta(),
                    },
                };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        mockedAxios.post.mockResolvedValueOnce({
            data: {
                data: {
                    payment_link: {
                        id: 501,
                        financial_transaction_id: 77,
                    },
                    occurrence: occurrence({
                        paid_amount_minor: 1_599,
                        remaining_amount_minor: 0,
                        is_paid: true,
                        display_status: 'paid',
                        active_linked_transaction_ids: [77],
                        all_linked_transaction_ids: [77],
                    }),
                },
                meta: { idempotent_replay: false },
            },
        });

        fireEvent.click(screen.getByRole('button', { name: 'Link payment' }));
        await waitFor(() => {
            expect(screen.getByText(/Netflix charge/)).toBeInTheDocument();
        });

        fireEvent.click(screen.getByLabelText(/Netflix charge/));
        fireEvent.click(screen.getByRole('button', { name: 'Link expense' }));

        await waitFor(() => {
            expect(mockedAxios.post).toHaveBeenCalledWith(
                '/recurring-occurrences/20/payment-links',
                { financial_transaction_id: 77 },
                expect.any(Object),
            );
        });
    });

    it('unlinks a payment using link IDs from the GET read model without POST discovery', async () => {
        await renderReady(
            [obligation()],
            [
                occurrence({
                    paid_amount_minor: 1_599,
                    remaining_amount_minor: 0,
                    is_paid: true,
                    display_status: 'paid',
                    active_linked_transaction_ids: [77],
                    all_linked_transaction_ids: [77],
                    payment_links: [
                        {
                            id: 501,
                            occurrence_id: 20,
                            financial_transaction_id: 77,
                            amount_minor: 1_599,
                            currency_code: 'USD',
                            is_active: true,
                            is_reversed: false,
                        },
                    ],
                }),
            ],
        );

        expect(mockedAxios.post).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Details' }));
        fireEvent.click(screen.getByRole('button', { name: 'Unlink' }));

        expect(
            screen.getByText(/The original expense stays on your ledger and is not reversed/),
        ).toBeInTheDocument();

        mockedAxios.delete.mockResolvedValueOnce({
            data: {
                data: {
                    occurrence: occurrence({
                        paid_amount_minor: 0,
                        remaining_amount_minor: 1_599,
                        is_paid: false,
                        display_status: 'upcoming',
                        active_linked_transaction_ids: [],
                        all_linked_transaction_ids: [],
                        payment_links: [],
                    }),
                },
            },
        });
        mockListPayload([obligation()], [occurrence()]);

        fireEvent.click(screen.getByRole('button', { name: 'Unlink payment' }));

        await waitFor(() => {
            expect(mockedAxios.delete).toHaveBeenCalledWith(
                '/recurring-occurrences/20/payment-links/501',
                expect.any(Object),
            );
        });
        expect(mockedAxios.post).not.toHaveBeenCalled();
    });

    it('requires confirmation before skipping and posts to the skip endpoint', async () => {
        await renderReady();

        fireEvent.click(screen.getByRole('button', { name: 'Skip' }));
        expect(screen.getByText(/due date stays occupied/i)).toBeInTheDocument();

        mockedAxios.post.mockResolvedValueOnce({
            data: { data: occurrence({ status: 'skipped', display_status: 'skipped' }) },
        });
        mockListPayload(
            [obligation()],
            [occurrence({ status: 'skipped', display_status: 'skipped' })],
        );

        fireEvent.click(screen.getByRole('button', { name: 'Skip occurrence' }));
        await waitFor(() => {
            expect(mockedAxios.post).toHaveBeenCalledWith(
                '/recurring-occurrences/20/skip',
                null,
                expect.any(Object),
            );
        });
    });

    it('shows reversed linked payments as not effective toward paid totals', async () => {
        await renderReady(
            [obligation()],
            [
                occurrence({
                    paid_amount_minor: 0,
                    remaining_amount_minor: 1_599,
                    display_status: 'overdue',
                    is_overdue: true,
                    active_linked_transaction_ids: [],
                    all_linked_transaction_ids: [88],
                    payment_links: [
                        {
                            id: 601,
                            occurrence_id: 20,
                            financial_transaction_id: 88,
                            amount_minor: 1_599,
                            currency_code: 'USD',
                            is_active: false,
                            is_reversed: true,
                        },
                    ],
                }),
            ],
        );

        fireEvent.click(screen.getByRole('button', { name: 'Details' }));
        expect(screen.getByText('Linked but not effective (reversed)')).toBeInTheDocument();
        expect(screen.getByText(/Transaction #88/)).toBeInTheDocument();
        expect(screen.getAllByText(/Paid/).some((node) => /\$0\.00/.test(node.textContent ?? ''))).toBe(
            true,
        );
        expect(mockedAxios.post).not.toHaveBeenCalled();
    });

    it('handles recoverable network failures without inventing data', async () => {
        mockedAxios.get.mockRejectedValue({
            isAxiosError: true,
            response: { status: 500, data: { message: 'boom' } },
        });

        render(<BillsIndex />);

        await waitFor(() => {
            expect(
                screen.getByRole('heading', { name: 'Bills could not be loaded' }),
            ).toBeInTheDocument();
        });
        expect(
            screen.getByText('The server could not complete this request. Please try again shortly.'),
        ).toBeInTheDocument();

        mockListPayload([obligation()], [occurrence()]);
        fireEvent.click(screen.getByRole('button', { name: 'Retry' }));

        await waitFor(() => {
            expect(
                screen.queryByRole('heading', { name: 'Bills could not be loaded' }),
            ).not.toBeInTheDocument();
            expect(screen.getAllByText('Netflix').length).toBeGreaterThan(0);
        });
    });

    it('shows a filtered empty state when occurrences are empty but obligations exist', async () => {
        await renderReady([obligation()], [], [], pageMeta({ total: 0, from: null, to: null }));

        expect(
            screen.getByRole('heading', { name: 'No occurrences for these filters' }),
        ).toBeInTheDocument();
    });

    it('locks create after a 409 conflict until a new attempt is started', async () => {
        await renderReady([], []);
        fireEvent.click(screen.getByRole('button', { name: 'Create your first bill' }));
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Conflict' } });
        fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '5.00' } });
        fireEvent.change(screen.getByLabelText('Expense category'), {
            target: { value: '101' },
        });

        mockedAxios.post.mockRejectedValueOnce({
            isAxiosError: true,
            response: {
                status: 409,
                data: {
                    message: 'Conflict',
                    errors: { idempotency_key: ['Idempotency key conflict.'] },
                },
            },
        });

        fireEvent.click(screen.getByRole('button', { name: 'Create bill' }));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Start new attempt' })).toBeInTheDocument();
        });
        expect(screen.getByRole('button', { name: 'Create bill' })).toBeDisabled();
        const conflictKey = mockedAxios.post.mock.calls[0][1].idempotency_key as string;

        fireEvent.click(screen.getByRole('button', { name: 'Start new attempt' }));
        mockedAxios.post.mockResolvedValueOnce({
            data: { data: obligation({ name: 'Conflict' }), meta: { idempotent_replay: false } },
        });
        mockListPayload([obligation({ name: 'Conflict' })], [occurrence()]);

        fireEvent.click(screen.getByRole('button', { name: 'Create bill' }));
        await waitFor(() => {
            expect(mockedAxios.post).toHaveBeenCalledTimes(2);
        });
        const retryKey = mockedAxios.post.mock.calls[1][1].idempotency_key as string;
        expect(retryKey).not.toBe(conflictKey);
    });
});
