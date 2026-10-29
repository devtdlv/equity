import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TransactionsIndex from '@/Pages/Transactions/Index';
import type {
    CategoryRecord,
    CurrencyOption,
    FinancialTransactionItem,
    PaginationMeta,
    TransactionAccountOption,
    TransactionFiltersState,
} from '@/Types';

vi.mock('axios', () => {
    const get = vi.fn();
    const post = vi.fn();
    const patch = vi.fn();
    const isAxiosError = (err: unknown): boolean =>
        typeof err === 'object' && err !== null && 'isAxiosError' in err;

    return {
        default: {
            get,
            post,
            patch,
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
        url: '/transactions',
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
    { code: 'PHP', name: 'Philippine Peso', symbol: '₱', minor_unit_exponent: 2 },
    { code: 'USD', name: 'US Dollar', symbol: '$', minor_unit_exponent: 2 },
    { code: 'JPY', name: 'Japanese Yen', symbol: '¥', minor_unit_exponent: 0 },
];

const SAMPLE_ACCOUNTS: TransactionAccountOption[] = [
    {
        id: 10,
        name: 'BPI Checking',
        type: 'checking',
        type_label: 'Checking',
        classification: 'asset',
        currency_code: 'PHP',
        currency_symbol: '₱',
        minor_unit_exponent: 2,
        is_archived: false,
    },
    {
        id: 11,
        name: 'BPI Savings',
        type: 'savings',
        type_label: 'Savings',
        classification: 'asset',
        currency_code: 'PHP',
        currency_symbol: '₱',
        minor_unit_exponent: 2,
        is_archived: false,
    },
    {
        id: 12,
        name: 'Chase USD Card',
        type: 'credit_card',
        type_label: 'Credit Card',
        classification: 'liability',
        currency_code: 'USD',
        currency_symbol: '$',
        minor_unit_exponent: 2,
        is_archived: false,
    },
    {
        id: 13,
        name: 'Tokyo Yen Wallet',
        type: 'cash',
        type_label: 'Cash',
        classification: 'asset',
        currency_code: 'JPY',
        currency_symbol: '¥',
        minor_unit_exponent: 0,
        is_archived: false,
    },
];

const SAMPLE_CATEGORIES: CategoryRecord[] = [
    {
        id: 101,
        name: 'Groceries',
        type: 'expense',
        type_label: 'Expense',
        classification: 'expense',
        is_archived: false,
        archived_at: null,
    },
    {
        id: 102,
        name: 'Salary',
        type: 'income',
        type_label: 'Income',
        classification: 'income',
        is_archived: false,
        archived_at: null,
    },
    {
        id: 103,
        name: 'Old Subscriptions',
        type: 'expense',
        type_label: 'Expense',
        classification: 'expense',
        is_archived: true,
        archived_at: '2026-10-20T00:00:00Z',
    },
];

const SAMPLE_TRANSACTIONS: FinancialTransactionItem[] = [
    {
        id: 501,
        journal_entry_id: 901,
        type: 'income',
        type_label: 'Income',
        is_reversal: false,
        is_reversed: false,
        reversed_transaction_id: null,
        reversal_transaction_id: null,
        reversal_reason: null,
        entry_date: '2026-10-25',
        description: 'October Payroll',
        notes: 'Direct deposit',
        currency_code: 'PHP',
        amount_minor: 4500050,
        amount_formatted: '₱45,000.50',
        financial_account: {
            id: 10,
            name: 'BPI Checking',
            type: 'checking',
            type_label: 'Checking',
            currency_code: 'PHP',
            is_archived: false,
        },
        transfer_financial_account: null,
        category: {
            id: 102,
            name: 'Salary',
            type: 'income',
            type_label: 'Income',
            is_archived: false,
        },
        created_at: '2026-10-25T08:00:00Z',
    },
    {
        id: 502,
        journal_entry_id: 902,
        type: 'expense',
        type_label: 'Expense',
        is_reversal: false,
        is_reversed: false,
        reversed_transaction_id: null,
        reversal_transaction_id: null,
        reversal_reason: null,
        entry_date: '2026-10-26',
        description: 'Supermarket run',
        notes: null,
        currency_code: 'PHP',
        amount_minor: 320025,
        amount_formatted: '₱3,200.25',
        financial_account: {
            id: 10,
            name: 'BPI Checking',
            type: 'checking',
            type_label: 'Checking',
            currency_code: 'PHP',
            is_archived: false,
        },
        transfer_financial_account: null,
        category: {
            id: 103,
            name: 'Old Subscriptions',
            type: 'expense',
            type_label: 'Expense',
            is_archived: true,
        },
        created_at: '2026-10-26T09:00:00Z',
    },
    {
        id: 503,
        journal_entry_id: 903,
        type: 'transfer',
        type_label: 'Transfer',
        is_reversal: false,
        is_reversed: false,
        reversed_transaction_id: null,
        reversal_transaction_id: null,
        reversal_reason: null,
        entry_date: '2026-10-27',
        description: 'Move to savings',
        notes: 'Emergency fund',
        currency_code: 'PHP',
        amount_minor: 1000000,
        amount_formatted: '₱10,000.00',
        financial_account: {
            id: 10,
            name: 'BPI Checking',
            type: 'checking',
            type_label: 'Checking',
            currency_code: 'PHP',
            is_archived: false,
        },
        transfer_financial_account: {
            id: 11,
            name: 'BPI Savings',
            type: 'savings',
            type_label: 'Savings',
            currency_code: 'PHP',
            is_archived: false,
        },
        category: null,
        created_at: '2026-10-27T10:00:00Z',
    },
];

const DEFAULT_META: PaginationMeta = {
    current_page: 1,
    last_page: 2,
    per_page: 15,
    total: 3,
    from: 1,
    to: 3,
};

const DEFAULT_FILTERS: TransactionFiltersState = {
    type: null,
    financial_account_id: null,
    category_id: null,
    start_date: null,
    end_date: null,
};

describe('TransactionsIndex page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the empty state when there are no transactions', () => {
        render(
            <TransactionsIndex
                transactions={[]}
                meta={{ ...DEFAULT_META, total: 0, last_page: 1 }}
                filters={DEFAULT_FILTERS}
                accounts={SAMPLE_ACCOUNTS}
                categories={SAMPLE_CATEGORIES}
                currencies={SAMPLE_CURRENCIES}
            />,
        );

        expect(
            screen.getByRole('heading', {
                level: 2,
                name: 'You have not recorded any transactions yet.',
            }),
        ).toBeInTheDocument();
    });

    it('renders income, expense, and transfer items distinctly including archived category labels', () => {
        render(
            <TransactionsIndex
                transactions={SAMPLE_TRANSACTIONS}
                meta={DEFAULT_META}
                filters={DEFAULT_FILTERS}
                accounts={SAMPLE_ACCOUNTS}
                categories={SAMPLE_CATEGORIES}
                currencies={SAMPLE_CURRENCIES}
            />,
        );

        expect(screen.getByText('October Payroll')).toBeInTheDocument();
        expect(screen.getByTestId('transaction-amount-501')).toHaveTextContent('+₱45,000.50');

        expect(screen.getByText('Supermarket run')).toBeInTheDocument();
        expect(screen.getByTestId('transaction-amount-502')).toHaveTextContent('-₱3,200.25');
        expect(screen.getByText('Old Subscriptions')).toBeInTheDocument();

        expect(screen.getByText('Move to savings')).toBeInTheDocument();
        expect(screen.getByTestId('transaction-amount-503')).toHaveTextContent('₱10,000.00');
    });

    it('switches between Expense, Income, and Transfer modes, filters categories by type, and restricts transfer destinations to same-currency accounts', () => {
        render(
            <TransactionsIndex
                transactions={SAMPLE_TRANSACTIONS}
                meta={DEFAULT_META}
                filters={DEFAULT_FILTERS}
                accounts={SAMPLE_ACCOUNTS}
                categories={SAMPLE_CATEGORIES}
                currencies={SAMPLE_CURRENCIES}
            />,
        );

        // Default mode is Expense: shows active Expense categories (Groceries) and excludes archived/Income categories
        const expenseCategorySelect = screen.getByLabelText('Expense category');
        expect(expenseCategorySelect).toHaveValue('101');
        expect(expenseCategorySelect.querySelectorAll('option')).toHaveLength(1);

        // Switch to Income mode: shows active Income categories (Salary)
        fireEvent.click(screen.getByRole('button', { name: 'Income' }));
        const incomeCategorySelect = screen.getByLabelText('Income category');
        expect(incomeCategorySelect).toHaveValue('102');

        // Switch to JPY account in Income mode: verify zero-decimal hint and inputMode
        fireEvent.change(screen.getByLabelText('Destination account'), {
            target: { value: '13' },
        });
        expect(
            screen.getByText('Enter a whole-number amount in JPY (¥). No decimal places.'),
        ).toBeInTheDocument();

        // Switch to Transfer mode with BPI Checking (PHP) selected:
        // Only BPI Savings (PHP) is eligible; USD and JPY accounts and BPI Checking itself are excluded.
        fireEvent.click(screen.getByRole('button', { name: 'Transfer' }));
        expect(screen.queryByLabelText('Income category')).toBeNull();
        expect(screen.queryByLabelText('Expense category')).toBeNull();

        fireEvent.change(screen.getByLabelText('Source account'), {
            target: { value: '10' },
        });

        const destinationSelect = screen.getByLabelText('Destination account');
        const destinationOptions = Array.from(destinationSelect.querySelectorAll('option')).map(
            (opt) => opt.textContent,
        );
        expect(destinationOptions).toEqual(['BPI Savings (PHP · Savings)']);

        // Selecting Chase USD Card (only USD account) shows the same-currency transfer notice
        fireEvent.change(screen.getByLabelText('Source account'), {
            target: { value: '12' },
        });
        expect(
            screen.getByText(/Transfers require a second active account in USD/i),
        ).toBeInTheDocument();
    });

    it('locks every transaction field and mode switcher while a request is pending, prevents double-submit, preserves exact payload and UUID on network failure, and prevents option sync from mutating an uncertain retry', async () => {
        const mockedPost = vi.mocked(axios.post);
        const mockedGet = vi.mocked(axios.get);

        mockedGet.mockResolvedValue({
            data: {
                data: SAMPLE_TRANSACTIONS,
                meta: DEFAULT_META,
            },
        });

        let rejectFirstAttempt: ((error: unknown) => void) | undefined;
        mockedPost.mockImplementationOnce(
            () =>
                new Promise((_resolve, reject) => {
                    rejectFirstAttempt = reject;
                }),
        );

        const { rerender } = render(
            <TransactionsIndex
                transactions={SAMPLE_TRANSACTIONS}
                meta={DEFAULT_META}
                filters={DEFAULT_FILTERS}
                accounts={SAMPLE_ACCOUNTS}
                categories={SAMPLE_CATEGORIES}
                currencies={SAMPLE_CURRENCIES}
            />,
        );

        const amountInput = screen.getByLabelText('Amount (PHP)');
        const dateInput = screen.getByLabelText('Transaction date');
        const descriptionInput = screen.getByLabelText('Description');
        const notesInput = screen.getByLabelText('Notes (optional)');
        const accountSelect = screen.getByLabelText('Source account');
        const categorySelect = screen.getByLabelText('Expense category');
        const incomeModeButton = screen.getByRole('button', { name: 'Income' });
        const transferModeButton = screen.getByRole('button', { name: 'Transfer' });

        fireEvent.change(amountInput, { target: { value: '01250.50' } });
        fireEvent.change(descriptionInput, { target: { value: 'Exact string test' } });
        fireEvent.change(notesInput, { target: { value: 'Memo line' } });

        const submitButton = screen.getByRole('button', { name: 'Record expense' });
        fireEvent.submit(submitButton);

        // 1. While request is in-flight, all fields and mode buttons are disabled and duplicate submit is ignored
        expect(amountInput).toBeDisabled();
        expect(dateInput).toBeDisabled();
        expect(descriptionInput).toBeDisabled();
        expect(notesInput).toBeDisabled();
        expect(accountSelect).toBeDisabled();
        expect(categorySelect).toBeDisabled();
        expect(incomeModeButton).toBeDisabled();
        expect(transferModeButton).toBeDisabled();

        // Attempting to change fields or switch mode while in-flight is ignored
        fireEvent.change(amountInput, { target: { value: '9999.99' } });
        fireEvent.click(incomeModeButton);
        fireEvent.submit(submitButton);

        expect(mockedPost).toHaveBeenCalledTimes(1);
        expect(amountInput).toHaveValue('01250.50');

        const firstPayload = mockedPost.mock.calls[0][1] as Record<string, unknown>;
        expect(firstPayload.amount).toBe('01250.50');
        expect(firstPayload.category_id).toBe(101);
        const firstUuid = firstPayload.idempotency_key as string;

        // 2. Network error occurs: form enters 'uncertain_retry' and remains locked
        await act(async () => {
            rejectFirstAttempt?.({
                isAxiosError: true,
                message: 'Network Error',
            });
        });

        expect(screen.getByText(/Connection interrupted while saving/i)).toBeInTheDocument();
        expect(amountInput).toBeDisabled();
        expect(categorySelect).toBeDisabled();
        expect(incomeModeButton).toBeDisabled();

        // 3. Even if the selected category (id: 101) becomes archived via prop/state update while in 'uncertain_retry',
        // automatic synchronization must NOT mutate the locked draft's category_id or rotate the UUID, and shows a warning.
        const categoriesWithGroceriesArchived: CategoryRecord[] = [
            {
                ...SAMPLE_CATEGORIES[0],
                is_archived: true,
                archived_at: '2026-10-28T00:00:00Z',
            },
            {
                id: 199,
                name: 'Fallback Expense',
                type: 'expense',
                type_label: 'Expense',
                classification: 'expense',
                is_archived: false,
                archived_at: null,
            },
            SAMPLE_CATEGORIES[1],
        ];

        rerender(
            <TransactionsIndex
                transactions={SAMPLE_TRANSACTIONS}
                meta={DEFAULT_META}
                filters={DEFAULT_FILTERS}
                accounts={SAMPLE_ACCOUNTS}
                categories={categoriesWithGroceriesArchived}
                currencies={SAMPLE_CURRENCIES}
            />,
        );

        expect(categorySelect).toHaveValue('101');
        expect(
            screen.getByText(
                /The selected account or category was archived while your submission was pending/i,
            ),
        ).toBeInTheDocument();

        // 4. Clicking "Retry submission" submits the exact same payload (category_id: 101, amount: '01250.50') and UUID
        mockedPost.mockResolvedValueOnce({
            data: {
                data: SAMPLE_TRANSACTIONS[1],
                meta: {
                    was_recently_created: false,
                    idempotent_replay: true,
                },
            },
        });

        fireEvent.submit(screen.getByRole('button', { name: 'Retry submission' }));

        await waitFor(() => {
            expect(mockedPost).toHaveBeenCalledTimes(2);
        });

        const secondPayload = mockedPost.mock.calls[1][1] as Record<string, unknown>;
        expect(secondPayload).toEqual(firstPayload);
        expect(secondPayload.idempotency_key).toBe(firstUuid);

        await waitFor(() => {
            expect(
                screen.getByText('Transaction was already recorded. Showing your saved record.'),
            ).toBeInTheDocument();
        });

        // After successful replay, the completed draft is cleared and unlocked for a new entry
        expect(amountInput).not.toBeDisabled();
        expect(amountInput).toHaveValue('');
    });

    it('blocks resubmission on HTTP 409 idempotency conflict until the user explicitly starts a new attempt with a fresh UUID', async () => {
        const mockedPost = vi.mocked(axios.post);
        const mockedGet = vi.mocked(axios.get);

        mockedGet.mockResolvedValue({
            data: {
                data: SAMPLE_TRANSACTIONS,
                meta: DEFAULT_META,
            },
        });

        mockedPost.mockRejectedValueOnce({
            isAxiosError: true,
            response: {
                status: 409,
                data: {
                    message:
                        'Idempotency key has already been used with a different transaction payload.',
                    errors: {
                        idempotency_key: [
                            'Idempotency key has already been used with a different transaction payload.',
                        ],
                    },
                },
            },
        });

        render(
            <TransactionsIndex
                transactions={SAMPLE_TRANSACTIONS}
                meta={DEFAULT_META}
                filters={DEFAULT_FILTERS}
                accounts={SAMPLE_ACCOUNTS}
                categories={SAMPLE_CATEGORIES}
                currencies={SAMPLE_CURRENCIES}
            />,
        );

        const amountInput = screen.getByLabelText('Amount (PHP)');
        const descriptionInput = screen.getByLabelText('Description');

        fireEvent.change(amountInput, { target: { value: '500.00' } });
        fireEvent.change(descriptionInput, { target: { value: 'Conflict test' } });

        fireEvent.submit(screen.getByRole('button', { name: 'Record expense' }));

        await waitFor(() => {
            expect(mockedPost).toHaveBeenCalledTimes(1);
        });

        const conflictingKey = (mockedPost.mock.calls[0][1] as Record<string, unknown>)
            .idempotency_key as string;

        // 1. Conflict banner is displayed, fields are locked, and submit button is disabled
        expect(
            screen.getByText(
                /This submission conflicts with a previous request using the same idempotency key and was not recorded/i,
            ),
        ).toBeInTheDocument();

        const blockedSubmitButton = screen.getByRole('button', {
            name: 'Submission blocked (conflict)',
        });
        expect(blockedSubmitButton).toBeDisabled();
        expect(amountInput).toBeDisabled();

        // Attempting to submit the form while conflict-blocked does NOT call axios.post
        fireEvent.submit(blockedSubmitButton);
        expect(mockedPost).toHaveBeenCalledTimes(1);

        // 2. Clicking "Start new attempt" unlocks the form and generates a fresh UUID
        fireEvent.click(screen.getByRole('button', { name: 'Start new attempt' }));

        expect(amountInput).not.toBeDisabled();
        expect(amountInput).toHaveValue('500.00');

        mockedPost.mockResolvedValueOnce({
            data: {
                data: SAMPLE_TRANSACTIONS[1],
                meta: {
                    was_recently_created: true,
                    idempotent_replay: false,
                },
            },
        });

        fireEvent.submit(screen.getByRole('button', { name: 'Record expense' }));

        await waitFor(() => {
            expect(mockedPost).toHaveBeenCalledTimes(2);
        });

        const newAttemptKey = (mockedPost.mock.calls[1][1] as Record<string, unknown>)
            .idempotency_key as string;
        expect(newAttemptKey).not.toBe(conflictingKey);
    });

    it('renders 422 validation errors and rotates UUID only when the user modifies the draft after the deterministic error', async () => {
        const mockedPost = vi.mocked(axios.post);
        mockedPost.mockRejectedValueOnce({
            isAxiosError: true,
            response: {
                status: 422,
                data: {
                    errors: {
                        amount: ['Monetary amount must be greater than zero.'],
                    },
                },
            },
        });

        render(
            <TransactionsIndex
                transactions={SAMPLE_TRANSACTIONS}
                meta={DEFAULT_META}
                filters={DEFAULT_FILTERS}
                accounts={SAMPLE_ACCOUNTS}
                categories={SAMPLE_CATEGORIES}
                currencies={SAMPLE_CURRENCIES}
            />,
        );

        const amountInput = screen.getByLabelText('Amount (PHP)');
        const descriptionInput = screen.getByLabelText('Description');

        fireEvent.change(amountInput, { target: { value: '0.00' } });
        fireEvent.change(descriptionInput, { target: { value: 'Zero test' } });

        fireEvent.submit(screen.getByRole('button', { name: 'Record expense' }));

        await waitFor(() => {
            expect(
                screen.getByText('Monetary amount must be greater than zero.'),
            ).toBeInTheDocument();
        });

        const firstAttemptKey = (mockedPost.mock.calls[0][1] as Record<string, unknown>)
            .idempotency_key as string;

        // Correcting the amount rotates the UUID
        fireEvent.change(amountInput, { target: { value: '250.00' } });

        mockedPost.mockResolvedValueOnce({
            data: {
                data: SAMPLE_TRANSACTIONS[1],
                meta: {
                    was_recently_created: true,
                    idempotent_replay: false,
                },
            },
        });

        fireEvent.submit(screen.getByRole('button', { name: 'Record expense' }));

        await waitFor(() => {
            expect(mockedPost).toHaveBeenCalledTimes(2);
        });

        const secondAttemptKey = (mockedPost.mock.calls[1][1] as Record<string, unknown>)
            .idempotency_key as string;
        expect(secondAttemptKey).not.toBe(firstAttemptKey);
    });

    it('applies filters, preserves filters across pagination, and clears filters', async () => {
        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockResolvedValue({
            data: {
                data: [SAMPLE_TRANSACTIONS[2]],
                meta: {
                    current_page: 2,
                    last_page: 2,
                    per_page: 15,
                    total: 16,
                    from: 16,
                    to: 16,
                },
            },
        });

        render(
            <TransactionsIndex
                transactions={SAMPLE_TRANSACTIONS}
                meta={DEFAULT_META}
                filters={DEFAULT_FILTERS}
                accounts={SAMPLE_ACCOUNTS}
                categories={SAMPLE_CATEGORIES}
                currencies={SAMPLE_CURRENCIES}
            />,
        );

        fireEvent.change(screen.getByLabelText('Type'), {
            target: { value: 'transfer' },
        });
        fireEvent.change(screen.getByLabelText('Account'), {
            target: { value: '11' },
        });

        fireEvent.submit(screen.getByRole('button', { name: 'Apply filters' }));

        await waitFor(() => {
            expect(mockedGet).toHaveBeenCalledWith(
                '/transactions',
                expect.objectContaining({
                    params: {
                        page: 1,
                        type: 'transfer',
                        financial_account_id: 11,
                    },
                }),
            );
        });

        // Clear filters resets to page 1 with no filter params
        fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));

        await waitFor(() => {
            expect(mockedGet).toHaveBeenLastCalledWith(
                '/transactions',
                expect.objectContaining({
                    params: { page: 1 },
                }),
            );
        });
    });

    it('supports creating, renaming, archiving, and restoring categories within the Transactions page', async () => {
        const mockedPost = vi.mocked(axios.post);
        const mockedPatch = vi.mocked(axios.patch);

        render(
            <TransactionsIndex
                transactions={SAMPLE_TRANSACTIONS}
                meta={DEFAULT_META}
                filters={DEFAULT_FILTERS}
                accounts={SAMPLE_ACCOUNTS}
                categories={SAMPLE_CATEGORIES}
                currencies={SAMPLE_CURRENCIES}
            />,
        );

        // Open category management panel
        fireEvent.click(screen.getByRole('button', { name: 'Manage categories' }));
        expect(
            screen.getByRole('heading', {
                level: 2,
                name: 'Manage income and expense categories',
            }),
        ).toBeInTheDocument();

        // 1. Create a new category
        mockedPost.mockResolvedValueOnce({
            data: {
                data: {
                    id: 104,
                    name: 'Utilities',
                    type: 'expense',
                    type_label: 'Expense',
                    classification: 'expense',
                    is_archived: false,
                    archived_at: null,
                },
            },
        });

        fireEvent.change(screen.getByLabelText('New category name'), {
            target: { value: 'Utilities' },
        });
        fireEvent.submit(screen.getByRole('button', { name: 'Add category' }));

        await waitFor(() => {
            expect(screen.getByText('Category "Utilities" created.')).toBeInTheDocument();
        });

        // 2. Rename a category
        mockedPatch.mockResolvedValueOnce({
            data: {
                data: {
                    id: 101,
                    name: 'Supermarket & Groceries',
                    type: 'expense',
                    type_label: 'Expense',
                    classification: 'expense',
                    is_archived: false,
                    archived_at: null,
                },
            },
        });

        fireEvent.click(screen.getByRole('button', { name: 'Rename category Groceries' }));
        fireEvent.change(screen.getByLabelText('Rename "Groceries" (Expense)'), {
            target: { value: 'Supermarket & Groceries' },
        });
        fireEvent.submit(screen.getByRole('button', { name: 'Save category name' }));

        await waitFor(() => {
            expect(
                screen.getByText('Category renamed to "Supermarket & Groceries".'),
            ).toBeInTheDocument();
        });

        // 3. Archive a category via confirmation dialog
        mockedPost.mockResolvedValueOnce({
            data: {
                data: {
                    id: 102,
                    name: 'Salary',
                    type: 'income',
                    type_label: 'Income',
                    classification: 'income',
                    is_archived: true,
                    archived_at: '2026-10-28T00:00:00Z',
                },
            },
        });

        fireEvent.click(screen.getByRole('button', { name: 'Archive category Salary' }));
        expect(screen.getByRole('dialog', { name: 'Archive Salary?' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Confirm archive' }));

        await waitFor(() => {
            expect(screen.getByText('Category "Salary" archived.')).toBeInTheDocument();
        });

        // 4. Restore an archived category
        mockedPost.mockResolvedValueOnce({
            data: {
                data: {
                    id: 103,
                    name: 'Old Subscriptions',
                    type: 'expense',
                    type_label: 'Expense',
                    classification: 'expense',
                    is_archived: false,
                    archived_at: null,
                },
            },
        });

        fireEvent.click(screen.getByRole('button', { name: 'Restore category Old Subscriptions' }));

        await waitFor(() => {
            expect(screen.getByText('Category "Old Subscriptions" restored.')).toBeInTheDocument();
        });
    });

    it('shows Reverse only for eligible transactions and identifies reversed or compensating entries', () => {
        const transactions: FinancialTransactionItem[] = [
            SAMPLE_TRANSACTIONS[0],
            {
                ...SAMPLE_TRANSACTIONS[1],
                id: 602,
                is_reversed: true,
                reversal_transaction_id: 603,
            },
            {
                ...SAMPLE_TRANSACTIONS[1],
                id: 603,
                description: 'Reversal: Supermarket run',
                is_reversal: true,
                is_reversed: false,
                reversed_transaction_id: 602,
                reversal_transaction_id: null,
                reversal_reason: 'Duplicate purchase',
            },
        ];

        render(
            <TransactionsIndex
                transactions={transactions}
                meta={{ ...DEFAULT_META, total: 3, last_page: 1 }}
                filters={DEFAULT_FILTERS}
                accounts={SAMPLE_ACCOUNTS}
                categories={SAMPLE_CATEGORIES}
                currencies={SAMPLE_CURRENCIES}
            />,
        );

        expect(screen.getByTestId('reverse-transaction-501')).toBeInTheDocument();
        expect(screen.queryByTestId('reverse-transaction-602')).not.toBeInTheDocument();
        expect(screen.queryByTestId('reverse-transaction-603')).not.toBeInTheDocument();
        expect(screen.getByText('Reversed')).toBeInTheDocument();
        expect(screen.getByText('Reversal')).toBeInTheDocument();
        expect(screen.getByText(/Compensating entry for transaction #602/i)).toBeInTheDocument();
        expect(screen.getByText(/Reason: Duplicate purchase/i)).toBeInTheDocument();
        expect(screen.getByText(/Reversed by compensating entry #603/i)).toBeInTheDocument();
        expect(screen.getByTestId('transaction-amount-603')).not.toHaveTextContent('+');
        expect(screen.getByTestId('transaction-amount-603')).not.toHaveTextContent('-');
    });

    it('requires confirmation and a nonempty reason before posting a reversal', async () => {
        const mockedPost = vi.mocked(axios.post);

        render(
            <TransactionsIndex
                transactions={SAMPLE_TRANSACTIONS}
                meta={DEFAULT_META}
                filters={DEFAULT_FILTERS}
                accounts={SAMPLE_ACCOUNTS}
                categories={SAMPLE_CATEGORIES}
                currencies={SAMPLE_CURRENCIES}
            />,
        );

        fireEvent.click(screen.getByTestId('reverse-transaction-501'));
        expect(
            screen.getByRole('dialog', { name: 'Reverse this transaction?' }),
        ).toBeInTheDocument();
        expect(screen.getByText(/posts a new compensating transaction/i)).toBeInTheDocument();
        expect(screen.getByText('October Payroll')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.queryByRole('dialog', { name: 'Reverse this transaction?' }),
        ).not.toBeInTheDocument();
        expect(mockedPost).not.toHaveBeenCalled();

        fireEvent.click(screen.getByTestId('reverse-transaction-501'));
        const confirmButton = screen.getByRole('button', { name: 'Confirm reversal' });
        expect(confirmButton).toBeDisabled();

        fireEvent.change(screen.getByLabelText(/Reversal reason/i), {
            target: { value: '   ' },
        });
        expect(confirmButton).toBeDisabled();
        expect(mockedPost).not.toHaveBeenCalled();
    });

    it('posts the reverse endpoint with UUID and reason, then refreshes history on success', async () => {
        const mockedPost = vi.mocked(axios.post);
        const mockedGet = vi.mocked(axios.get);

        const original = {
            ...SAMPLE_TRANSACTIONS[0],
            is_reversed: true,
            reversal_transaction_id: 701,
        };
        const reversal: FinancialTransactionItem = {
            ...SAMPLE_TRANSACTIONS[0],
            id: 701,
            journal_entry_id: 971,
            description: 'Reversal: October Payroll',
            is_reversal: true,
            is_reversed: false,
            reversed_transaction_id: 501,
            reversal_transaction_id: null,
            reversal_reason: 'Payroll posted twice',
        };

        mockedPost.mockResolvedValueOnce({
            data: {
                data: { original, reversal },
                meta: { was_recently_created: true, idempotent_replay: false },
            },
        });
        mockedGet.mockResolvedValueOnce({
            data: {
                data: [reversal, original, SAMPLE_TRANSACTIONS[1], SAMPLE_TRANSACTIONS[2]],
                meta: { ...DEFAULT_META, total: 4 },
            },
        });

        render(
            <TransactionsIndex
                transactions={SAMPLE_TRANSACTIONS}
                meta={DEFAULT_META}
                filters={DEFAULT_FILTERS}
                accounts={SAMPLE_ACCOUNTS}
                categories={SAMPLE_CATEGORIES}
                currencies={SAMPLE_CURRENCIES}
            />,
        );

        fireEvent.click(screen.getByTestId('reverse-transaction-501'));
        fireEvent.change(screen.getByLabelText(/Reversal reason/i), {
            target: { value: 'Payroll posted twice' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Confirm reversal' }));

        await waitFor(() => {
            expect(mockedPost).toHaveBeenCalledWith(
                '/transactions/501/reverse',
                expect.objectContaining({
                    reason: 'Payroll posted twice',
                    idempotency_key: expect.stringMatching(
                        /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
                    ),
                }),
                expect.objectContaining({
                    headers: { Accept: 'application/json' },
                }),
            );
        });

        await waitFor(() => {
            expect(mockedGet).toHaveBeenCalledWith(
                '/transactions',
                expect.objectContaining({
                    params: { page: 1 },
                }),
            );
        });

        await waitFor(() => {
            expect(
                screen.getByText(/Transaction reversed\. A compensating entry was posted/i),
            ).toBeInTheDocument();
            expect(screen.getByText('Reversal: October Payroll')).toBeInTheDocument();
            expect(screen.queryByTestId('reverse-transaction-501')).not.toBeInTheDocument();
        });
    });

    it('preserves exact reverse payload and key on network uncertainty and blocks automatic new keys', async () => {
        const mockedPost = vi.mocked(axios.post);
        mockedPost
            .mockRejectedValueOnce({
                isAxiosError: true,
                message: 'Network Error',
            })
            .mockResolvedValueOnce({
                data: {
                    data: {
                        original: {
                            ...SAMPLE_TRANSACTIONS[0],
                            is_reversed: true,
                            reversal_transaction_id: 701,
                        },
                        reversal: {
                            ...SAMPLE_TRANSACTIONS[0],
                            id: 701,
                            is_reversal: true,
                            reversed_transaction_id: 501,
                            reversal_reason: 'Correcting duplicate',
                        },
                    },
                    meta: { was_recently_created: false, idempotent_replay: true },
                },
            });

        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockResolvedValue({
            data: {
                data: SAMPLE_TRANSACTIONS,
                meta: DEFAULT_META,
            },
        });

        render(
            <TransactionsIndex
                transactions={SAMPLE_TRANSACTIONS}
                meta={DEFAULT_META}
                filters={DEFAULT_FILTERS}
                accounts={SAMPLE_ACCOUNTS}
                categories={SAMPLE_CATEGORIES}
                currencies={SAMPLE_CURRENCIES}
            />,
        );

        fireEvent.click(screen.getByTestId('reverse-transaction-501'));
        fireEvent.change(screen.getByLabelText(/Reversal reason/i), {
            target: { value: 'Correcting duplicate' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Confirm reversal' }));

        await waitFor(() => {
            expect(screen.getByText(/Connection interrupted while reversing/i)).toBeInTheDocument();
        });

        expect(screen.getByLabelText(/Reversal reason/i)).toBeDisabled();
        const firstPayload = mockedPost.mock.calls[0][1] as Record<string, unknown>;
        const firstKey = firstPayload.idempotency_key as string;

        fireEvent.click(screen.getByRole('button', { name: 'Retry exact reversal' }));

        await waitFor(() => {
            expect(mockedPost).toHaveBeenCalledTimes(2);
        });

        const secondPayload = mockedPost.mock.calls[1][1] as Record<string, unknown>;
        expect(secondPayload.idempotency_key).toBe(firstKey);
        expect(secondPayload.reason).toBe('Correcting duplicate');
        expect(mockedPost.mock.calls[1][0]).toBe('/transactions/501/reverse');

        await waitFor(() => {
            expect(screen.getByText(/Reversal was already recorded/i)).toBeInTheDocument();
        });
    });

    it('handles HTTP 409 reverse conflicts without auto-retry and refreshes history', async () => {
        const mockedPost = vi.mocked(axios.post);
        const mockedGet = vi.mocked(axios.get);

        const conflictError = {
            isAxiosError: true,
            response: {
                status: 409,
                data: {
                    message: 'This financial transaction has already been reversed.',
                    errors: {
                        transaction: ['This financial transaction has already been reversed.'],
                    },
                },
            },
        };
        mockedPost.mockRejectedValueOnce(conflictError);

        mockedGet.mockResolvedValueOnce({
            data: {
                data: [
                    {
                        ...SAMPLE_TRANSACTIONS[0],
                        is_reversed: true,
                        reversal_transaction_id: 777,
                    },
                    SAMPLE_TRANSACTIONS[1],
                    SAMPLE_TRANSACTIONS[2],
                ],
                meta: DEFAULT_META,
            },
        });

        render(
            <TransactionsIndex
                transactions={SAMPLE_TRANSACTIONS}
                meta={DEFAULT_META}
                filters={DEFAULT_FILTERS}
                accounts={SAMPLE_ACCOUNTS}
                categories={SAMPLE_CATEGORIES}
                currencies={SAMPLE_CURRENCIES}
            />,
        );

        fireEvent.click(screen.getByTestId('reverse-transaction-501'));
        fireEvent.change(screen.getByLabelText(/Reversal reason/i), {
            target: { value: 'Late correction' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Confirm reversal' }));

        await waitFor(() => {
            expect(mockedPost).toHaveBeenCalledTimes(1);
            expect(mockedGet).toHaveBeenCalled();
        });

        await waitFor(() => {
            expect(
                screen.getByText(
                    (_, element) =>
                        element?.textContent ===
                        'This financial transaction has already been reversed.',
                ),
            ).toBeInTheDocument();
        });

        expect(
            screen.queryByRole('button', { name: 'Retry exact reversal' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));

        await waitFor(() => {
            expect(screen.queryByTestId('reverse-transaction-501')).not.toBeInTheDocument();
            expect(screen.getByText('Reversed')).toBeInTheDocument();
        });
    });

    it('prevents duplicate reverse clicks while a request is in flight', async () => {
        const mockedPost = vi.mocked(axios.post);
        let resolvePost: ((value: unknown) => void) | null = null;
        mockedPost.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    resolvePost = resolve;
                }),
        );

        render(
            <TransactionsIndex
                transactions={SAMPLE_TRANSACTIONS}
                meta={DEFAULT_META}
                filters={DEFAULT_FILTERS}
                accounts={SAMPLE_ACCOUNTS}
                categories={SAMPLE_CATEGORIES}
                currencies={SAMPLE_CURRENCIES}
            />,
        );

        fireEvent.click(screen.getByTestId('reverse-transaction-502'));
        fireEvent.change(screen.getByLabelText(/Reversal reason/i), {
            target: { value: 'Wrong category' },
        });

        fireEvent.click(screen.getByRole('button', { name: 'Confirm reversal' }));
        fireEvent.click(screen.getByRole('button', { name: 'Confirm reversal' }));
        fireEvent.click(screen.getByRole('button', { name: 'Confirm reversal' }));

        await waitFor(() => {
            expect(mockedPost).toHaveBeenCalledTimes(1);
        });

        await act(async () => {
            resolvePost?.({
                data: {
                    data: {
                        original: {
                            ...SAMPLE_TRANSACTIONS[1],
                            is_reversed: true,
                            reversal_transaction_id: 800,
                        },
                        reversal: {
                            ...SAMPLE_TRANSACTIONS[1],
                            id: 800,
                            is_reversal: true,
                            reversed_transaction_id: 502,
                            reversal_reason: 'Wrong category',
                        },
                    },
                    meta: { was_recently_created: true, idempotent_replay: false },
                },
            });
        });
    });
});
