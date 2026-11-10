import { Link, router, usePage } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';
import { Button } from '@/Components/Button';
import { Card } from '@/Components/Card';
import { EmptyState } from '@/Components/EmptyState';
import { FormField } from '@/Components/FormField';
import { PageHeader } from '@/Components/PageHeader';
import { Select } from '@/Components/Select';
import { CashFlowTrendChart } from '@/Components/Reports/CashFlowTrendChart';
import { CategorySpendChart } from '@/Components/Reports/CategorySpendChart';
import { AuthenticatedLayout } from '@/Layouts/AuthenticatedLayout';
import type {
    ReportMoneyView,
    ReportsIndexPageProps,
    SharedPageProps,
} from '@/Types';

function MoneyLine({ label, money }: { label: string; money: ReportMoneyView }) {
    return (
        <div className="flex min-w-0 items-baseline justify-between gap-3">
            <span className="shrink-0 text-xs text-ink-muted">{label}</span>
            <span
                className="min-w-0 break-words text-right font-mono text-sm font-semibold tabular-nums text-ink"
                data-amount-minor={money.amount_minor}
            >
                {money.formatted}
            </span>
        </div>
    );
}

function formatShareBps(shareBps: number): string {
    const whole = Math.floor(shareBps / 100);
    const frac = String(shareBps % 100).padStart(2, '0');

    return `${whole}.${frac}%`;
}

export default function ReportsIndex() {
    const page = usePage<SharedPageProps & ReportsIndexPageProps>();
    const {
        filters,
        period,
        currencies,
        accounts,
        account_history: accountHistory,
        empty,
    } = page.props;

    const [mode, setMode] = useState<'month' | 'custom'>(filters.mode);
    const [month, setMonth] = useState(filters.month ?? period.month ?? '');
    const [startDate, setStartDate] = useState(filters.start_date ?? period.start);
    const [endDate, setEndDate] = useState(filters.end_date ?? period.end);
    const [accountId, setAccountId] = useState(
        filters.financial_account_id !== null ? String(filters.financial_account_id) : '',
    );
    const [applying, setApplying] = useState(false);

    const activeAccounts = useMemo(
        () => accounts.filter((account) => !account.is_archived),
        [accounts],
    );

    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setApplying(true);

        const params: Record<string, string> = {
            mode,
        };

        if (mode === 'month') {
            params.month = month;
        } else {
            params.start_date = startDate;
            params.end_date = endDate;
        }

        if (accountId !== '') {
            params.financial_account_id = accountId;
        }

        router.get('/reports', params, {
            preserveScroll: true,
            onFinish: () => setApplying(false),
        });
    };

    return (
        <AuthenticatedLayout title="Financial Reports">
            <div className="flex w-full min-w-0 flex-col gap-8">
                <PageHeader
                    eyebrow="Insights"
                    title="Financial Reports"
                    description="Income, expenses, category spending, and account history by period. Amounts stay in their native currencies—no conversion."
                />

                <Card padding="md" className="shadow-none">
                    <form
                        onSubmit={applyFilters}
                        className="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                        aria-label="Report filters"
                    >
                        <div className="space-y-1.5">
                            <label htmlFor="report-mode" className="block text-sm font-medium text-ink">
                                Range type
                            </label>
                            <Select
                                id="report-mode"
                                value={mode}
                                onChange={(event) =>
                                    setMode(event.target.value === 'custom' ? 'custom' : 'month')
                                }
                            >
                                <option value="month">Calendar month (UTC)</option>
                                <option value="custom">Custom dates (UTC)</option>
                            </Select>
                        </div>

                        {mode === 'month' ? (
                            <FormField
                                id="report-month"
                                name="month"
                                label="Month"
                                type="month"
                                value={month}
                                onChange={(event) => setMonth(event.target.value)}
                                required
                            />
                        ) : (
                            <>
                                <FormField
                                    id="report-start"
                                    name="start_date"
                                    label="Start date"
                                    type="date"
                                    value={startDate}
                                    onChange={(event) => setStartDate(event.target.value)}
                                    required
                                />
                                <FormField
                                    id="report-end"
                                    name="end_date"
                                    label="End date"
                                    type="date"
                                    value={endDate}
                                    onChange={(event) => setEndDate(event.target.value)}
                                    required
                                />
                            </>
                        )}

                        <div className="space-y-1.5">
                            <label
                                htmlFor="report-account"
                                className="block text-sm font-medium text-ink"
                            >
                                Account history
                            </label>
                            <Select
                                id="report-account"
                                value={accountId}
                                onChange={(event) => setAccountId(event.target.value)}
                            >
                                <option value="">All accounts (summary only)</option>
                                {activeAccounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.name} ({account.currency_code})
                                    </option>
                                ))}
                            </Select>
                        </div>

                        <div className="flex items-end md:col-span-2 xl:col-span-4">
                            <Button type="submit" loading={applying}>
                                Apply filters
                            </Button>
                        </div>
                    </form>
                    <p className="mt-3 text-xs text-ink-muted">
                        Showing {period.label} · {period.start} to {period.end} (UTC)
                    </p>
                </Card>

                {!empty.has_accounts ? (
                    <EmptyState
                        title="Add an account to unlock reports"
                        description="Reports summarize posted income and expenses from your ledger. Create a financial account, then record transactions."
                    >
                        <Link
                            href="/accounts"
                            className="inline-flex min-h-10 items-center justify-center rounded-lg border border-transparent bg-accent px-4 py-2 text-sm font-medium text-accent-foreground shadow-2xs hover:bg-accent-strong"
                        >
                            Go to Accounts
                        </Link>
                    </EmptyState>
                ) : null}

                {empty.has_accounts && !empty.has_period_activity && currencies.length === 0 ? (
                    <EmptyState
                        title="No activity in this period"
                        description="There are no active income or expense transactions for the selected dates. Transfers and reversed transactions are excluded."
                    >
                        <Link
                            href="/transactions"
                            className="inline-flex min-h-10 items-center justify-center rounded-lg border border-border-strong bg-surface-elevated px-4 py-2 text-sm font-medium text-ink hover:bg-surface-muted"
                        >
                            View transactions
                        </Link>
                    </EmptyState>
                ) : null}

                {currencies.map((section) => (
                    <section
                        key={section.currency_code}
                        aria-labelledby={`report-currency-${section.currency_code}`}
                        className="space-y-4"
                    >
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <h2
                                id={`report-currency-${section.currency_code}`}
                                className="text-lg font-semibold tracking-tight text-ink"
                            >
                                {section.currency_code}
                            </h2>
                            <p className="text-xs text-ink-muted">
                                Native currency totals · no FX conversion
                            </p>
                        </div>

                        <div className="grid min-w-0 gap-4 lg:grid-cols-3">
                            <Card padding="md" className="min-w-0 shadow-none lg:col-span-1">
                                <h3 className="text-sm font-semibold text-ink">Period summary</h3>
                                <div className="mt-3 min-w-0 space-y-1.5">
                                    <MoneyLine label="Income" money={section.summary.income} />
                                    <MoneyLine label="Expenses" money={section.summary.expenses} />
                                    <MoneyLine label="Net cash flow" money={section.summary.net} />
                                </div>
                            </Card>

                            <Card padding="md" className="min-w-0 shadow-none lg:col-span-2">
                                <h3 className="text-sm font-semibold text-ink">Cash-flow trend</h3>
                                <div className="mt-4 min-w-0">
                                    <CashFlowTrendChart
                                        points={section.cash_flow_trend}
                                        currencyCode={section.currency_code}
                                    />
                                </div>
                            </Card>
                        </div>

                        <Card padding="md" className="shadow-none">
                            <h3 className="text-sm font-semibold text-ink">Spending by category</h3>
                            <div className="mt-4 grid gap-6 lg:grid-cols-2">
                                <CategorySpendChart
                                    rows={section.category_spending}
                                    currencyCode={section.currency_code}
                                />
                                {section.category_spending.length > 0 ? (
                                    <div className="overflow-x-auto">
                                        <table className="min-w-full text-left text-sm">
                                            <caption className="sr-only">
                                                {section.currency_code} category spending table
                                            </caption>
                                            <thead className="border-b border-border-subtle text-xs uppercase tracking-wide text-ink-muted">
                                                <tr>
                                                    <th scope="col" className="py-2 pr-3 font-medium">
                                                        Category
                                                    </th>
                                                    <th scope="col" className="py-2 pr-3 font-medium">
                                                        Spent
                                                    </th>
                                                    <th scope="col" className="py-2 font-medium">
                                                        Share
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {section.category_spending.map((row) => (
                                                    <tr
                                                        key={`table-${row.category_id ?? 'none'}-${row.category_name}`}
                                                        className="border-b border-border-subtle/70"
                                                    >
                                                        <td className="py-2.5 pr-3 text-ink">
                                                            {row.category_name}
                                                        </td>
                                                        <td
                                                            className="max-w-[10rem] break-words py-2.5 pr-3 font-mono tabular-nums text-ink"
                                                            data-amount-minor={row.total.amount_minor}
                                                        >
                                                            {row.total.formatted}
                                                        </td>
                                                        <td className="py-2.5 font-mono text-ink-muted">
                                                            {formatShareBps(row.share_bps)}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : null}
                            </div>
                        </Card>
                    </section>
                ))}

                {accountHistory ? (
                    <section aria-labelledby="report-account-history" className="space-y-4">
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <h2
                                id="report-account-history"
                                className="text-lg font-semibold tracking-tight text-ink"
                            >
                                Account history · {accountHistory.account.name}
                            </h2>
                            <Link
                                href={`/transactions?financial_account_id=${accountHistory.account.id}&start_date=${period.start}&end_date=${period.end}`}
                                className="text-sm font-medium text-accent hover:underline"
                            >
                                Open in Transactions
                            </Link>
                        </div>
                        <Card padding="md" className="shadow-none">
                            {accountHistory.account.balance ? (
                                <p className="mb-4 text-sm text-ink-muted">
                                    Current ledger balance:{' '}
                                    <span className="font-mono font-semibold text-ink">
                                        {accountHistory.account.balance.formatted}
                                    </span>
                                </p>
                            ) : null}
                            {accountHistory.transactions.length === 0 ? (
                                <p className="text-sm text-ink-muted">
                                    No active transactions for this account in the selected period.
                                </p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full text-left text-sm">
                                        <caption className="sr-only">
                                            Account transaction history for {accountHistory.account.name}
                                        </caption>
                                        <thead className="border-b border-border-subtle text-xs uppercase tracking-wide text-ink-muted">
                                            <tr>
                                                <th scope="col" className="py-2 pr-3 font-medium">
                                                    Date
                                                </th>
                                                <th scope="col" className="py-2 pr-3 font-medium">
                                                    Type
                                                </th>
                                                <th scope="col" className="py-2 pr-3 font-medium">
                                                    Description
                                                </th>
                                                <th scope="col" className="py-2 pr-3 font-medium">
                                                    Category
                                                </th>
                                                <th scope="col" className="py-2 font-medium">
                                                    Amount
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {accountHistory.transactions.map((txn) => (
                                                <tr
                                                    key={txn.id}
                                                    className="border-b border-border-subtle/70"
                                                >
                                                    <td className="py-2.5 pr-3 font-mono text-ink-muted">
                                                        {txn.entry_date}
                                                    </td>
                                                    <td className="py-2.5 pr-3 text-ink">
                                                        {txn.type_label}
                                                    </td>
                                                    <td className="py-2.5 pr-3 text-ink">
                                                        {txn.description}
                                                    </td>
                                                    <td className="py-2.5 pr-3 text-ink-muted">
                                                        {txn.category?.name ?? '—'}
                                                    </td>
                                                    <td
                                                        className="py-2.5 font-mono tabular-nums text-ink"
                                                        data-amount-minor={txn.amount_minor}
                                                    >
                                                        {txn.amount_formatted}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                            {accountHistory.meta.capped ? (
                                <p className="mt-3 text-xs text-ink-muted">
                                    Showing the latest {accountHistory.meta.limit} of{' '}
                                    {accountHistory.meta.total} active transactions. Use Transactions
                                    for the full list.
                                </p>
                            ) : null}
                        </Card>
                    </section>
                ) : null}
            </div>
        </AuthenticatedLayout>
    );
}
