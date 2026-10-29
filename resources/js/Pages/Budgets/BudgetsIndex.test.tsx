import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import axios from 'axios';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import BudgetsIndex from '@/Pages/Budgets/Index';
import type {
    BudgetRecord,
    BudgetSpendingSummaryRecord,
    CategoryRecord,
    CurrencyOption,
} from '@/Types';

vi.mock('axios', () => {
    const get = vi.fn();
    const post = vi.fn();
    const put = vi.fn();
    const isAxiosError = (err: unknown): boolean =>
        typeof err === 'object' && err !== null && 'isAxiosError' in err;

    return {
        default: {
            get,
            post,
            put,
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
        url: '/budgets',
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
            currencies: SAMPLE_CURRENCIES,
            initial_year: 2026,
            initial_month: 10,
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
    { code: 'JPY', name: 'Japanese Yen', symbol: '¥', minor_unit_exponent: 0 },
    { code: 'PHP', name: 'Philippine Peso', symbol: '₱', minor_unit_exponent: 2 },
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
        name: 'Dining',
        type: 'expense',
        type_label: 'Expense',
        classification: 'expense',
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
        archived_at: '2026-01-01T00:00:00+00:00',
    },
    {
        id: 201,
        name: 'Salary',
        type: 'income',
        type_label: 'Income',
        classification: 'income',
        is_archived: false,
        archived_at: null,
    },
];

const USD_BUDGET: BudgetRecord = {
    id: 50,
    currency_code: 'USD',
    period_start: '2026-10-01',
    period_end: '2026-10-31',
    currency: {
        code: 'USD',
        name: 'US Dollar',
        symbol: '$',
        minor_unit_exponent: 2,
    },
    created_at: '2026-10-01T00:00:00+00:00',
    updated_at: '2026-10-01T00:00:00+00:00',
};

const JPY_BUDGET: BudgetRecord = {
    id: 51,
    currency_code: 'JPY',
    period_start: '2026-10-01',
    period_end: '2026-10-31',
    currency: {
        code: 'JPY',
        name: 'Japanese Yen',
        symbol: '¥',
        minor_unit_exponent: 0,
    },
    created_at: '2026-10-01T00:00:00+00:00',
    updated_at: '2026-10-01T00:00:00+00:00',
};

function makeSummary(
    overrides: Partial<BudgetSpendingSummaryRecord> = {},
): BudgetSpendingSummaryRecord {
    return {
        budget: USD_BUDGET,
        allocations: [
            {
                category_id: 101,
                category_name: 'Groceries',
                category_is_archived: false,
                allocated_minor: 10_000,
                actual_minor: 2_500,
                remaining_minor: 7_500,
                is_over_limit: false,
            },
            {
                category_id: 102,
                category_name: 'Dining',
                category_is_archived: false,
                allocated_minor: 0,
                actual_minor: 0,
                remaining_minor: 0,
                is_over_limit: false,
            },
        ],
        totals: {
            allocated_minor: 10_000,
            allocated_actual_minor: 2_500,
            unbudgeted_actual_minor: 1_500,
            total_actual_minor: 4_000,
        },
        ...overrides,
    };
}

const mockedAxios = axios as unknown as {
    get: ReturnType<typeof vi.fn>;
    post: ReturnType<typeof vi.fn>;
    put: ReturnType<typeof vi.fn>;
};

async function renderReady(listData: BudgetRecord[] = [USD_BUDGET], summary = makeSummary()) {
    mockedAxios.get.mockImplementation(async (url: string) => {
        if (url === '/budgets') {
            return { data: { data: listData, meta: {}, filters: {} } };
        }

        if (url.startsWith('/budgets/')) {
            return { data: { data: summary } };
        }

        throw new Error(`Unexpected GET ${url}`);
    });

    render(<BudgetsIndex />);

    await waitFor(() => {
        expect(screen.queryByLabelText('Loading budget overview')).not.toBeInTheDocument();
    });
}

describe('BudgetsIndex', () => {
    beforeEach(() => {
        mockedAxios.get.mockReset();
        mockedAxios.post.mockReset();
        mockedAxios.put.mockReset();
        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.setSystemTime(new Date(Date.UTC(2026, 9, 15, 12, 0, 0)));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('navigates months while preserving the selected currency', async () => {
        await renderReady([]);

        expect(screen.getByText('October 2026')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Currency'), { target: { value: 'JPY' } });

        await waitFor(() => {
            expect(mockedAxios.get).toHaveBeenCalledWith(
                '/budgets',
                expect.objectContaining({
                    params: expect.objectContaining({ currency_code: 'JPY' }),
                }),
            );
        });

        mockedAxios.get.mockClear();
        mockedAxios.get.mockResolvedValue({ data: { data: [], meta: {}, filters: {} } });

        fireEvent.click(screen.getByRole('button', { name: 'Previous month' }));

        await waitFor(() => {
            expect(screen.getByText('September 2026')).toBeInTheDocument();
        });

        expect(mockedAxios.get).toHaveBeenCalledWith(
            '/budgets',
            expect.objectContaining({
                params: expect.objectContaining({ currency_code: 'JPY' }),
            }),
        );
    });

    it('shows an empty create state when no budget exists for the selected month and currency', async () => {
        await renderReady([]);

        expect(
            screen.getByRole('heading', { name: 'No USD budget for October 2026.' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Create budget' })).toBeInTheDocument();
    });

    it('creates a budget and loads the authoritative summary', async () => {
        mockedAxios.get.mockResolvedValue({ data: { data: [], meta: {}, filters: {} } });
        mockedAxios.post.mockResolvedValue({
            data: { data: USD_BUDGET, meta: { was_recently_created: true } },
        });

        render(<BudgetsIndex />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Create budget' })).toBeInTheDocument();
        });

        mockedAxios.get.mockImplementation(async (url: string) => {
            if (url === '/budgets') {
                return { data: { data: [USD_BUDGET], meta: {}, filters: {} } };
            }

            if (url === '/budgets/50') {
                return { data: { data: makeSummary() } };
            }

            throw new Error(`Unexpected GET ${url}`);
        });

        fireEvent.click(screen.getByRole('button', { name: 'Create budget' }));

        await waitFor(() => {
            expect(mockedAxios.post).toHaveBeenCalledWith(
                '/budgets',
                { currency_code: 'USD', year: 2026, month: 10 },
                expect.any(Object),
            );
        });

        await waitFor(() => {
            expect(screen.getByText('Total allocated')).toBeInTheDocument();
            expect(screen.getAllByText('$100.00').length).toBeGreaterThan(0);
        });
    });

    it('reconciles duplicate create conflicts without posting again', async () => {
        mockedAxios.get.mockResolvedValue({ data: { data: [], meta: {}, filters: {} } });
        mockedAxios.post.mockRejectedValue({
            isAxiosError: true,
            response: {
                status: 409,
                data: {
                    message: 'A budget already exists for this month and currency.',
                    errors: {
                        budget: ['A budget already exists for this month and currency.'],
                    },
                },
            },
        });

        render(<BudgetsIndex />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Create budget' })).toBeInTheDocument();
        });

        mockedAxios.get.mockImplementation(async (url: string) => {
            if (url === '/budgets') {
                return { data: { data: [USD_BUDGET], meta: {}, filters: {} } };
            }

            if (url === '/budgets/50') {
                return { data: { data: makeSummary() } };
            }

            throw new Error(`Unexpected GET ${url}`);
        });

        fireEvent.click(screen.getByRole('button', { name: 'Create budget' }));

        await waitFor(() => {
            expect(screen.getByText(/already exists/i)).toBeInTheDocument();
            expect(screen.getByText('Total allocated')).toBeInTheDocument();
        });

        expect(mockedAxios.post).toHaveBeenCalledTimes(1);
    });

    it('reconciles uncertain POSTs by fetching before allowing another create attempt', async () => {
        mockedAxios.get.mockResolvedValue({ data: { data: [], meta: {}, filters: {} } });
        mockedAxios.post.mockRejectedValue({
            isAxiosError: true,
            response: undefined,
            message: 'Network Error',
        });

        render(<BudgetsIndex />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Create budget' })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Create budget' }));

        await waitFor(() => {
            expect(screen.getByText(/did not complete clearly/i)).toBeInTheDocument();
        });

        expect(mockedAxios.post).toHaveBeenCalledTimes(1);

        mockedAxios.get.mockResolvedValue({ data: { data: [USD_BUDGET], meta: {}, filters: {} } });
        mockedAxios.get.mockImplementation(async (url: string) => {
            if (url === '/budgets') {
                return { data: { data: [USD_BUDGET], meta: {}, filters: {} } };
            }

            if (url === '/budgets/50') {
                return { data: { data: makeSummary() } };
            }

            throw new Error(`Unexpected GET ${url}`);
        });

        fireEvent.click(screen.getByRole('button', { name: 'Create budget' }));

        await waitFor(() => {
            expect(screen.getByText(/existing budget/i)).toBeInTheDocument();
            expect(screen.getByText('Total allocated')).toBeInTheDocument();
        });

        expect(mockedAxios.post).toHaveBeenCalledTimes(1);
    });

    it('distinguishes zero allocations from unset categories and shows archived allocated categories as read-only', async () => {
        const summary = makeSummary({
            allocations: [
                {
                    category_id: 101,
                    category_name: 'Groceries',
                    category_is_archived: false,
                    allocated_minor: 10_000,
                    actual_minor: 2_500,
                    remaining_minor: 7_500,
                    is_over_limit: false,
                },
                {
                    category_id: 102,
                    category_name: 'Dining',
                    category_is_archived: false,
                    allocated_minor: 0,
                    actual_minor: 0,
                    remaining_minor: 0,
                    is_over_limit: false,
                },
                {
                    category_id: 103,
                    category_name: 'Old Subscriptions',
                    category_is_archived: true,
                    allocated_minor: 5_000,
                    actual_minor: 1_000,
                    remaining_minor: 4_000,
                    is_over_limit: false,
                },
            ],
            totals: {
                allocated_minor: 15_000,
                allocated_actual_minor: 3_500,
                unbudgeted_actual_minor: 0,
                total_actual_minor: 3_500,
            },
        });

        await renderReady([USD_BUDGET], summary);

        expect(screen.getByText('Zero allocated')).toBeInTheDocument();
        expect(screen.getByText('Archived')).toBeInTheDocument();
        expect(screen.queryByText('Salary')).not.toBeInTheDocument();

        const archivedInput = document.getElementById('allocation-103');
        expect(archivedInput).not.toBeNull();
        expect(archivedInput).toBeDisabled();

        const archivedCard = screen
            .getByRole('heading', { name: 'Old Subscriptions' })
            .closest('li');
        expect(archivedCard).not.toBeNull();
        expect(
            within(archivedCard as HTMLElement).queryByRole('button', {
                name: 'Update allocation',
            }),
        ).toBeNull();
        expect(
            within(archivedCard as HTMLElement).queryByRole('button', {
                name: 'Set allocation',
            }),
        ).toBeNull();
    });

    it('updates a category allocation with the exact decimal string and refreshes from the API summary', async () => {
        await renderReady();

        const groceriesInput = screen.getByLabelText('Allocation (USD)', {
            selector: '#allocation-101',
        });
        fireEvent.change(groceriesInput, { target: { value: '150.00' } });

        mockedAxios.put.mockResolvedValue({
            data: {
                data: {
                    allocation: {},
                    previous_amount_minor: 10_000,
                    summary: makeSummary({
                        allocations: [
                            {
                                category_id: 101,
                                category_name: 'Groceries',
                                category_is_archived: false,
                                allocated_minor: 15_000,
                                actual_minor: 2_500,
                                remaining_minor: 12_500,
                                is_over_limit: false,
                            },
                        ],
                        totals: {
                            allocated_minor: 15_000,
                            allocated_actual_minor: 2_500,
                            unbudgeted_actual_minor: 1_500,
                            total_actual_minor: 4_000,
                        },
                    }),
                },
            },
        });

        const groceriesCard = screen.getByRole('heading', { name: 'Groceries' }).closest('li');
        expect(groceriesCard).not.toBeNull();
        fireEvent.submit((groceriesCard as HTMLElement).querySelector('form') as HTMLFormElement);

        await waitFor(() => {
            expect(mockedAxios.put).toHaveBeenCalledWith(
                '/budgets/50/allocations/101',
                { amount: '150.00' },
                expect.any(Object),
            );
        });

        await waitFor(() => {
            expect(screen.getAllByText('$150.00').length).toBeGreaterThan(0);
        });
    });

    it('shows spending progress, overspending, unbudgeted totals, and JPY formatting from API values', async () => {
        const jpySummary = makeSummary({
            budget: JPY_BUDGET,
            allocations: [
                {
                    category_id: 101,
                    category_name: 'Groceries',
                    category_is_archived: false,
                    allocated_minor: 10_000,
                    actual_minor: 12_500,
                    remaining_minor: -2_500,
                    is_over_limit: true,
                },
                {
                    category_id: 102,
                    category_name: 'Dining',
                    category_is_archived: false,
                    allocated_minor: 0,
                    actual_minor: 400,
                    remaining_minor: -400,
                    is_over_limit: true,
                },
            ],
            totals: {
                allocated_minor: 10_000,
                allocated_actual_minor: 12_900,
                unbudgeted_actual_minor: 800,
                total_actual_minor: 13_700,
            },
        });

        mockedAxios.get.mockImplementation(
            async (url: string, config?: { params?: { currency_code?: string } }) => {
                if (url === '/budgets') {
                    const code = config?.params?.currency_code ?? 'USD';
                    return {
                        data: {
                            data: code === 'JPY' ? [JPY_BUDGET] : [],
                            meta: {},
                            filters: {},
                        },
                    };
                }

                if (url === '/budgets/51') {
                    return { data: { data: jpySummary } };
                }

                throw new Error(`Unexpected GET ${url}`);
            },
        );

        render(<BudgetsIndex />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Create budget' })).toBeInTheDocument();
        });

        fireEvent.change(screen.getByLabelText('Currency'), { target: { value: 'JPY' } });

        await waitFor(() => {
            expect(screen.getAllByText('¥10,000').length).toBeGreaterThan(0);
            expect(screen.getAllByText('Over limit').length).toBeGreaterThan(0);
            expect(screen.getByText('Over zero')).toBeInTheDocument();
            expect(screen.getByText(/outside allocated categories/i)).toBeInTheDocument();
        });
    });

    it('marks past months read-only without a create action', async () => {
        mockedAxios.get.mockResolvedValue({ data: { data: [], meta: {}, filters: {} } });

        render(<BudgetsIndex />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Create budget' })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Previous month' }));

        await waitFor(() => {
            expect(screen.getByText('September 2026')).toBeInTheDocument();
            expect(screen.getByText(/Past month · read-only/i)).toBeInTheDocument();
            expect(screen.queryByRole('button', { name: 'Create budget' })).not.toBeInTheDocument();
        });
    });

    it('surfaces tenant-safe API failure messages without inventing financial totals', async () => {
        mockedAxios.get.mockRejectedValue({
            isAxiosError: true,
            response: { status: 403, data: { message: 'Forbidden tenant detail' } },
        });

        render(<BudgetsIndex />);

        await waitFor(() => {
            expect(
                screen.getByText('You do not have permission to manage this budget.'),
            ).toBeInTheDocument();
        });

        expect(screen.queryByText('Total allocated')).not.toBeInTheDocument();
        expect(screen.queryByText('Forbidden tenant detail')).not.toBeInTheDocument();
    });

    it('shows precise allocation validation errors from the API', async () => {
        await renderReady();

        mockedAxios.put.mockRejectedValue({
            isAxiosError: true,
            response: {
                status: 422,
                data: {
                    message: 'The given data was invalid.',
                    errors: {
                        amount: ['Amount has too many fractional digits for USD.'],
                    },
                },
            },
        });

        const groceriesCard = screen.getByRole('heading', { name: 'Groceries' }).closest('li');
        fireEvent.submit((groceriesCard as HTMLElement).querySelector('form') as HTMLFormElement);

        await waitFor(() => {
            expect(
                screen.getAllByText('Amount has too many fractional digits for USD.').length,
            ).toBeGreaterThan(0);
        });
    });

    it('includes Budgets in authenticated navigation with an active state', async () => {
        await renderReady([]);

        const budgetsLinks = screen.getAllByRole('link', { name: 'Budgets' });
        expect(budgetsLinks.length).toBeGreaterThan(0);
        expect(budgetsLinks[0]).toHaveAttribute('href', '/budgets');
        expect(budgetsLinks[0]).toHaveAttribute('aria-current', 'page');
    });
});
