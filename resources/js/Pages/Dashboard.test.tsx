import { fireEvent, render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Dashboard from '@/Pages/Dashboard';

const mockPost = vi.fn();
let mockUrl = '/dashboard';

const money = (amountMinor: string, currencyCode: string, formatted: string) => ({
    amount_minor: amountMinor,
    currency_code: currencyCode,
    exponent: 2,
    symbol: currencyCode === 'PHP' ? '₱' : '$',
    formatted,
});

let mockCurrencies: unknown[] = [];
let mockBills = {
    items: [] as unknown[],
    overdue_count: 0,
    upcoming_count: 0,
    horizon_days: 14,
    display_cap: 8,
};
let mockSavings = {
    portfolio: [] as unknown[],
    goals: [] as unknown[],
    earmark_note:
        'Savings goal allocations are earmarks. They do not transfer money or change financial account balances.',
    active_goal_count: 0,
    archived_goal_count: 0,
};
let mockEmpty = {
    has_accounts: false,
    has_transactions_this_month: false,
    has_budgets_this_month: false,
    has_bill_items: false,
    has_active_savings_goals: false,
};

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
        url: mockUrl,
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
            period: {
                year: 2026,
                month: 11,
                start: '2026-11-01',
                end: '2026-11-30',
                timezone: 'UTC',
            },
            currencies: mockCurrencies,
            bills: mockBills,
            savings: mockSavings,
            empty: mockEmpty,
            links: {
                accounts: '/accounts',
                transactions: '/transactions',
                budgets: '/budgets',
                bills: '/bills',
                savings: '/savings',
            },
        },
    }),
    useForm: () => ({
        data: {},
        setData: vi.fn(),
        post: mockPost,
        processing: false,
        errors: {},
        reset: vi.fn(),
    }),
}));

describe('Dashboard and AuthenticatedLayout', () => {
    beforeEach(() => {
        mockPost.mockClear();
        mockUrl = '/dashboard';
        mockCurrencies = [];
        mockBills = {
            items: [],
            overdue_count: 0,
            upcoming_count: 0,
            horizon_days: 14,
            display_cap: 8,
        };
        mockSavings = {
            portfolio: [],
            goals: [],
            earmark_note:
                'Savings goal allocations are earmarks. They do not transfer money or change financial account balances.',
            active_goal_count: 0,
            archived_goal_count: 0,
        };
        mockEmpty = {
            has_accounts: false,
            has_transactions_this_month: false,
            has_budgets_this_month: false,
            has_bill_items: false,
            has_active_savings_goals: false,
        };
    });

    it('renders the dashboard heading, authenticated greeting, and workspace module links', () => {
        render(<Dashboard />);

        expect(screen.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeInTheDocument();
        expect(screen.getByText(/Welcome back, Morgan Lee/i)).toBeInTheDocument();
        expect(screen.getByTestId('authenticated-user-name')).toHaveTextContent('Morgan Lee');

        const workspace = screen.getByRole('region', { name: 'Workspace' });
        const moduleLinks = within(workspace).getAllByRole('link');
        const moduleHrefs = moduleLinks.map((link) => link.getAttribute('href'));

        expect(moduleHrefs).toEqual([
            '/accounts',
            '/transactions',
            '/budgets',
            '/bills',
            '/savings',
            '/reports',
        ]);
        expect(within(workspace).getByRole('link', { name: 'Accounts' })).toHaveAttribute(
            'href',
            '/accounts',
        );
        expect(within(workspace).getByRole('link', { name: 'Transactions' })).toHaveAttribute(
            'href',
            '/transactions',
        );
        expect(within(workspace).getByRole('link', { name: 'Budgets' })).toHaveAttribute(
            'href',
            '/budgets',
        );
        expect(
            within(workspace).getByRole('link', { name: 'Bills & Subscriptions' }),
        ).toHaveAttribute('href', '/bills');
        expect(within(workspace).getByRole('link', { name: 'Savings Goals' })).toHaveAttribute(
            'href',
            '/savings',
        );

        expect(within(workspace).queryByText('Upcoming')).not.toBeInTheDocument();
        expect(screen.queryByText(/currently being developed/i)).not.toBeInTheDocument();
        expect(
            screen.getByText(/Linking does not create a transaction/i),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/do not transfer money or change account balances/i),
        ).toBeInTheDocument();
        expect(screen.getByText(/Your workspace is empty/i)).toBeInTheDocument();
    });

    it('renders per-currency active account position, cash flow, and earmark note', () => {
        mockEmpty = {
            has_accounts: true,
            has_transactions_this_month: true,
            has_budgets_this_month: false,
            has_bill_items: false,
            has_active_savings_goals: true,
        };
        mockCurrencies = [
            {
                currency_code: 'PHP',
                position: {
                    assets: money('100000', 'PHP', '₱1,000.00'),
                    liabilities: money('25000', 'PHP', '₱250.00'),
                    net: money('75000', 'PHP', '₱750.00'),
                    active_account_count: 2,
                    archived_account_count: 1,
                },
                cash_flow: {
                    income: money('50000', 'PHP', '₱500.00'),
                    expenses: money('4000', 'PHP', '₱40.00'),
                    net: money('46000', 'PHP', '₱460.00'),
                },
                budget: null,
            },
        ];
        mockSavings = {
            portfolio: [
                {
                    currency_code: 'PHP',
                    active_goal_count: 1,
                    total_target: money('100000', 'PHP', '₱1,000.00'),
                    total_allocated: money('25000', 'PHP', '₱250.00'),
                    total_remaining: money('75000', 'PHP', '₱750.00'),
                },
            ],
            goals: [
                {
                    id: 1,
                    name: 'Emergency',
                    currency_code: 'PHP',
                    progress_basis_points: 2500,
                    target_reached: false,
                    allocated: money('25000', 'PHP', '₱250.00'),
                    remaining: money('75000', 'PHP', '₱750.00'),
                    target: money('100000', 'PHP', '₱1,000.00'),
                },
            ],
            earmark_note:
                'Savings goal allocations are earmarks. They do not transfer money or change financial account balances.',
            active_goal_count: 1,
            archived_goal_count: 0,
        };

        render(<Dashboard />);

        expect(screen.getByText('Active account position')).toBeInTheDocument();
        const phpCard = screen.getByTestId('currency-overview-PHP');
        expect(phpCard).toBeInTheDocument();
        expect(within(phpCard).getByText('₱750.00')).toBeInTheDocument();
        expect(screen.getByText(/1 archived \(excluded\)/i)).toBeInTheDocument();
        expect(screen.getByText(/earmarks/i)).toBeInTheDocument();
        expect(screen.getByText('Emergency')).toBeInTheDocument();
        expect(screen.queryByText(/Your workspace is empty/i)).not.toBeInTheDocument();
    });

    it('shows bill counts independently of the visible list and preserves remaining amounts', () => {
        mockEmpty = {
            ...mockEmpty,
            has_bill_items: true,
            has_accounts: true,
        };
        mockBills = {
            items: [
                {
                    occurrence_id: 1,
                    obligation_name: 'Overdue Rent',
                    due_date: '2026-11-01',
                    currency_code: 'PHP',
                    expected: money('10000', 'PHP', '₱100.00'),
                    remaining: money('10000', 'PHP', '₱100.00'),
                    paid: money('0', 'PHP', '₱0.00'),
                    display_status: 'overdue',
                    is_overdue: true,
                },
                {
                    occurrence_id: 2,
                    obligation_name: 'Partial Utilities',
                    due_date: '2026-11-05',
                    currency_code: 'PHP',
                    expected: money('20000', 'PHP', '₱200.00'),
                    remaining: money('15000', 'PHP', '₱150.00'),
                    paid: money('5000', 'PHP', '₱50.00'),
                    display_status: 'partially_paid',
                    is_overdue: true,
                },
            ],
            overdue_count: 3,
            upcoming_count: 9,
            horizon_days: 14,
            display_cap: 8,
        };

        render(<Dashboard />);

        expect(screen.getByText('3 overdue')).toBeInTheDocument();
        expect(screen.getByText('9 upcoming')).toBeInTheDocument();
        expect(screen.getByText('Overdue Rent')).toBeInTheDocument();
        expect(screen.getByText('Overdue · Partially paid')).toBeInTheDocument();
        const list = screen.getByTestId('dashboard-bills-list');
        expect(within(list).getByText('₱100.00')).toBeInTheDocument();
        expect(within(list).getByText('₱150.00')).toBeInTheDocument();
    });

    it('shows Budget over limit when aggregate spent exceeds allocation', () => {
        mockEmpty = {
            ...mockEmpty,
            has_accounts: true,
            has_budgets_this_month: true,
        };
        mockCurrencies = [
            {
                currency_code: 'PHP',
                position: null,
                cash_flow: {
                    income: money('0', 'PHP', '₱0.00'),
                    expenses: money('0', 'PHP', '₱0.00'),
                    net: money('0', 'PHP', '₱0.00'),
                },
                budget: {
                    budget_id: 1,
                    allocated: money('3750000', 'PHP', '₱37,500.00'),
                    spent_allocated: money('4000000', 'PHP', '₱40,000.00'),
                    remaining: money('-250000', 'PHP', '-₱2,500.00'),
                    unbudgeted_spent: money('0', 'PHP', '₱0.00'),
                    total_spent: money('4000000', 'PHP', '₱40,000.00'),
                    is_over_limit: true,
                },
            },
        ];

        render(<Dashboard />);

        expect(screen.getByText('Budget over limit')).toBeInTheDocument();
        expect(screen.queryByText('Category over limit')).not.toBeInTheDocument();
        expect(screen.queryByText('Over limit')).not.toBeInTheDocument();
    });

    it('shows Category over limit when aggregate is within allocation but is_over_limit is true', () => {
        mockEmpty = {
            ...mockEmpty,
            has_accounts: true,
            has_budgets_this_month: true,
        };
        mockCurrencies = [
            {
                currency_code: 'PHP',
                position: null,
                cash_flow: {
                    income: money('0', 'PHP', '₱0.00'),
                    expenses: money('0', 'PHP', '₱0.00'),
                    net: money('0', 'PHP', '₱0.00'),
                },
                budget: {
                    budget_id: 1,
                    allocated: money('3750000', 'PHP', '₱37,500.00'),
                    spent_allocated: money('3249850', 'PHP', '₱32,498.50'),
                    remaining: money('500150', 'PHP', '₱5,001.50'),
                    unbudgeted_spent: money('0', 'PHP', '₱0.00'),
                    total_spent: money('3249850', 'PHP', '₱32,498.50'),
                    is_over_limit: true,
                },
            },
        ];

        render(<Dashboard />);

        expect(screen.getByText('Category over limit')).toBeInTheDocument();
        expect(screen.queryByText('Budget over limit')).not.toBeInTheDocument();
        expect(screen.queryByText('Over limit')).not.toBeInTheDocument();
    });

    it('hides the budget warning when is_over_limit is false', () => {
        mockEmpty = {
            ...mockEmpty,
            has_accounts: true,
            has_budgets_this_month: true,
        };
        mockCurrencies = [
            {
                currency_code: 'PHP',
                position: null,
                cash_flow: {
                    income: money('0', 'PHP', '₱0.00'),
                    expenses: money('0', 'PHP', '₱0.00'),
                    net: money('0', 'PHP', '₱0.00'),
                },
                budget: {
                    budget_id: 1,
                    allocated: money('3750000', 'PHP', '₱37,500.00'),
                    spent_allocated: money('1000000', 'PHP', '₱10,000.00'),
                    remaining: money('2750000', 'PHP', '₱27,500.00'),
                    unbudgeted_spent: money('0', 'PHP', '₱0.00'),
                    total_spent: money('1000000', 'PHP', '₱10,000.00'),
                    is_over_limit: false,
                },
            },
        ];

        render(<Dashboard />);

        expect(screen.queryByText('Budget over limit')).not.toBeInTheDocument();
        expect(screen.queryByText('Category over limit')).not.toBeInTheDocument();
        expect(screen.queryByText('Over limit')).not.toBeInTheDocument();
    });

    it('shows forecasting and FX as planned while Financial Reports is a destination', () => {
        render(<Dashboard />);

        const planned = screen.getByRole('region', { name: 'Planned' });
        expect(
            within(planned).getByRole('heading', { name: 'Forecasting & currency conversion' }),
        ).toBeInTheDocument();
        expect(within(planned).getByText(/Cash-flow forecasting/i)).toBeInTheDocument();
        expect(within(planned).queryByRole('link')).not.toBeInTheDocument();
        expect(
            screen.getAllByRole('link', { name: 'Financial Reports' }).every(
                (link) => link.getAttribute('href') === '/reports',
            ),
        ).toBe(true);
    });

    it('only renders clickable navigation links for implemented routes including reports', () => {
        render(<Dashboard />);

        const links = screen.getAllByRole('link');
        const hrefs = links.map((link) => link.getAttribute('href'));

        expect(
            hrefs.every(
                (href) =>
                    href === '/dashboard' ||
                    href === '/accounts' ||
                    href === '/transactions' ||
                    href === '/budgets' ||
                    href === '/savings' ||
                    href === '/bills' ||
                    href === '/reports' ||
                    href === '/settings',
            ),
        ).toBe(true);
    });

    it('submits a POST request to /logout when the logout button is clicked', () => {
        render(<Dashboard />);

        const logoutButton = screen.getByRole('button', { name: 'Log out' });
        fireEvent.submit(logoutButton);

        expect(mockPost).toHaveBeenCalledWith('/logout');
    });

    it('toggles the responsive mobile navigation menu and closes on Escape or route change', () => {
        const { rerender } = render(<Dashboard />);

        const toggleButton = screen.getByRole('button', { name: 'Toggle navigation menu' });
        expect(toggleButton).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByRole('navigation', { name: 'Mobile navigation' })).toBeNull();

        fireEvent.click(toggleButton);

        expect(toggleButton).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByRole('navigation', { name: 'Mobile navigation' })).toBeInTheDocument();

        fireEvent.keyDown(window, { key: 'Escape' });
        expect(toggleButton).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByRole('navigation', { name: 'Mobile navigation' })).toBeNull();

        fireEvent.click(toggleButton);
        expect(toggleButton).toHaveAttribute('aria-expanded', 'true');

        mockUrl = '/dashboard?tab=overview';
        rerender(<Dashboard />);
        expect(toggleButton).toHaveAttribute('aria-expanded', 'false');
    });
});
