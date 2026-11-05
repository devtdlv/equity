import { render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ReportsIndex from '@/Pages/Reports/Index';
import type { ReportsIndexPageProps } from '@/Types';

const inertiaGet = vi.fn();

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
    router: {
        get: (...args: unknown[]) => inertiaGet(...args),
    },
    usePage: () => ({
        url: '/reports',
        props: pageProps,
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

let pageProps: ReportsIndexPageProps & {
    app: { name: string };
    auth: { user: { id: number; name: string; email: string } };
    flash: { status: null };
};

function money(
    amountMinor: string,
    currencyCode: string,
    formatted: string,
): ReportsIndexPageProps['currencies'][number]['summary']['income'] {
    return {
        amount_minor: amountMinor,
        currency_code: currencyCode,
        exponent: 2,
        symbol: currencyCode === 'PHP' ? '₱' : '$',
        formatted,
    };
}

describe('ReportsIndex', () => {
    beforeEach(() => {
        inertiaGet.mockReset();
        pageProps = {
            app: { name: 'Equity' },
            auth: {
                user: { id: 1, name: 'Morgan Lee', email: 'morgan@example.com' },
            },
            flash: { status: null },
            filters: {
                mode: 'month',
                month: '2026-11',
                start_date: null,
                end_date: null,
                financial_account_id: null,
            },
            period: {
                mode: 'month',
                start: '2026-11-01',
                end: '2026-11-30',
                label: 'November 2026',
                month: '2026-11',
            },
            currencies: [
                {
                    currency_code: 'PHP',
                    summary: {
                        income: money('1000000', 'PHP', '₱10,000.00'),
                        expenses: money('300000', 'PHP', '₱3,000.00'),
                        net: money('700000', 'PHP', '₱7,000.00'),
                    },
                    category_spending: [
                        {
                            category_id: 1,
                            category_name: 'Rent',
                            total: money('250000', 'PHP', '₱2,500.00'),
                            share_bps: 8333,
                        },
                        {
                            category_id: 2,
                            category_name: 'Groceries',
                            total: money('50000', 'PHP', '₱500.00'),
                            share_bps: 1666,
                        },
                    ],
                    cash_flow_trend: [
                        {
                            period_key: '2026-11',
                            period_label: 'Nov 2026',
                            income: money('1000000', 'PHP', '₱10,000.00'),
                            expenses: money('300000', 'PHP', '₱3,000.00'),
                            net: money('700000', 'PHP', '₱7,000.00'),
                        },
                    ],
                },
            ],
            accounts: [
                {
                    id: 10,
                    name: 'Checking PHP',
                    type: 'checking',
                    type_label: 'Checking',
                    currency_code: 'PHP',
                    is_archived: false,
                    balance: {
                        amount_minor: '700000',
                        formatted: '₱7,000.00',
                        is_liability: false,
                    },
                },
            ],
            account_history: null,
            empty: {
                has_accounts: true,
                has_period_activity: true,
            },
        };
    });

    it('renders period summary charts and category breakdown', () => {
        render(<ReportsIndex />);

        expect(screen.getByRole('heading', { name: 'Financial Reports' })).toBeInTheDocument();
        expect(screen.getByText('November 2026', { exact: false })).toBeInTheDocument();
        expect(screen.getByText('₱10,000.00')).toBeInTheDocument();
        expect(screen.getByText('₱3,000.00')).toBeInTheDocument();
        expect(screen.getByLabelText('PHP cash-flow trend chart')).toBeInTheDocument();
        expect(screen.getAllByText('Rent').length).toBeGreaterThan(0);
        expect(screen.getAllByText('83.33%').length).toBeGreaterThan(0);
        expect(
            screen.getAllByRole('link', { name: 'Financial Reports' })[0],
        ).toHaveAttribute('href', '/reports');
    });

    it('shows an empty state when the period has no activity', () => {
        pageProps.currencies = [];
        pageProps.empty.has_period_activity = false;

        render(<ReportsIndex />);

        expect(
            screen.getByRole('heading', { name: 'No activity in this period' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'View transactions' })).toHaveAttribute(
            'href',
            '/transactions',
        );
    });
});
