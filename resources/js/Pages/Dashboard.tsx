import { Link, usePage } from '@inertiajs/react';
import { Badge } from '@/Components/Badge';
import { Card } from '@/Components/Card';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { AuthenticatedLayout } from '@/Layouts/AuthenticatedLayout';
import type {
    DashboardCurrencySection,
    DashboardMoneyView,
    DashboardPageProps,
    SharedPageProps,
} from '@/Types';
import { budgetWarningLabel, resolveBudgetWarningKind } from '@/Utilities/budgetWarning';

const WORKSPACE_MODULES: ReadonlyArray<{
    title: string;
    href: string;
    headingId: string;
    descriptionId: string;
    description: string;
}> = [
    {
        title: 'Accounts',
        href: '/accounts',
        headingId: 'dashboard-module-accounts',
        descriptionId: 'dashboard-module-accounts-desc',
        description:
            'Manage checking, savings, cash, and credit accounts. Balances are derived from the ledger.',
    },
    {
        title: 'Transactions',
        href: '/transactions',
        headingId: 'dashboard-module-transactions',
        descriptionId: 'dashboard-module-transactions-desc',
        description: 'Record income, expenses, and transfers. Reversals post compensating entries.',
    },
    {
        title: 'Budgets',
        href: '/budgets',
        headingId: 'dashboard-module-budgets',
        descriptionId: 'dashboard-module-budgets-desc',
        description: 'Set monthly expense targets by category, kept separate for each currency.',
    },
    {
        title: 'Bills & Subscriptions',
        href: '/bills',
        headingId: 'dashboard-module-bills',
        descriptionId: 'dashboard-module-bills-desc',
        description:
            'Track recurring obligations and link an existing expense to a scheduled occurrence. Linking does not create a transaction.',
    },
    {
        title: 'Savings Goals',
        href: '/savings',
        headingId: 'dashboard-module-savings',
        descriptionId: 'dashboard-module-savings-desc',
        description:
            'Earmark amounts toward a target. Allocations do not transfer money or change account balances.',
    },
    {
        title: 'Financial Reports',
        href: '/reports',
        headingId: 'dashboard-module-reports',
        descriptionId: 'dashboard-module-reports-desc',
        description:
            'Review income, expenses, category spending, and account history by month or custom range.',
    },
];

function MoneyLine({ label, money }: { label: string; money: DashboardMoneyView }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <span className="text-xs text-ink-muted">{label}</span>
            <span
                className="font-mono text-sm font-semibold tabular-nums text-ink"
                data-amount-minor={money.amount_minor}
            >
                {money.formatted}
            </span>
        </div>
    );
}

function billStatusLabel(status: string, isOverdue: boolean): string {
    if (isOverdue && status === 'partially_paid') {
        return 'Overdue · Partially paid';
    }

    switch (status) {
        case 'overdue':
            return 'Overdue';
        case 'due':
            return 'Due today';
        case 'partially_paid':
            return 'Partially paid';
        case 'upcoming':
            return 'Upcoming';
        default:
            return isOverdue ? `Overdue · ${status}` : status;
    }
}

function progressPercent(basisPoints: number): string {
    const safe = Math.max(0, Math.min(10_000, Math.trunc(basisPoints)));
    const whole = Math.trunc(safe / 100);
    const fraction = (safe % 100).toString().padStart(2, '0');
    return `${whole}.${fraction}%`;
}

function CurrencyOverviewCard({
    section,
    links,
}: {
    section: DashboardCurrencySection;
    links: Required<DashboardPageProps>['links'];
}) {
    const budgetWarning = section.budget
        ? budgetWarningLabel(
              resolveBudgetWarningKind({
                  isOverLimit: section.budget.is_over_limit,
                  allocatedMinor: section.budget.allocated.amount_minor,
                  spentAllocatedMinor: section.budget.spent_allocated.amount_minor,
              }),
          )
        : null;

    return (
        <Card padding="sm" className="shadow-none" data-testid={`currency-overview-${section.currency_code}`}>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-sm font-semibold text-ink">{section.currency_code}</h3>
                <Badge variant="neutral">{section.currency_code}</Badge>
            </div>

            <div className="space-y-4">
                <section aria-labelledby={`position-${section.currency_code}`}>
                    <div className="mb-2 flex items-center justify-between gap-2">
                        <h4
                            id={`position-${section.currency_code}`}
                            className="text-xs font-semibold uppercase tracking-wide text-ink-secondary"
                        >
                            Active account position
                        </h4>
                        <Link href={links.accounts} className="text-xs font-medium text-accent hover:underline">
                            Accounts
                        </Link>
                    </div>
                    {section.position ? (
                        <div className="space-y-1.5">
                            <MoneyLine label="Assets" money={section.position.assets} />
                            <MoneyLine label="Liabilities" money={section.position.liabilities} />
                            <MoneyLine label="Net" money={section.position.net} />
                            <p className="pt-1 text-xs text-ink-muted">
                                {section.position.active_account_count} active
                                {section.position.archived_account_count > 0
                                    ? ` · ${section.position.archived_account_count} archived (excluded)`
                                    : ''}
                            </p>
                        </div>
                    ) : (
                        <p className="text-xs text-ink-muted">No active accounts in this currency.</p>
                    )}
                </section>

                <section aria-labelledby={`cashflow-${section.currency_code}`}>
                    <div className="mb-2 flex items-center justify-between gap-2">
                        <h4
                            id={`cashflow-${section.currency_code}`}
                            className="text-xs font-semibold uppercase tracking-wide text-ink-secondary"
                        >
                            This month (UTC)
                        </h4>
                        <Link
                            href={links.transactions}
                            className="text-xs font-medium text-accent hover:underline"
                        >
                            Transactions
                        </Link>
                    </div>
                    <div className="space-y-1.5">
                        <MoneyLine label="Income" money={section.cash_flow.income} />
                        <MoneyLine label="Expenses" money={section.cash_flow.expenses} />
                        <MoneyLine label="Net cash flow" money={section.cash_flow.net} />
                    </div>
                </section>

                <section aria-labelledby={`budget-${section.currency_code}`}>
                    <div className="mb-2 flex items-center justify-between gap-2">
                        <h4
                            id={`budget-${section.currency_code}`}
                            className="text-xs font-semibold uppercase tracking-wide text-ink-secondary"
                        >
                            Budget
                        </h4>
                        <Link href={links.budgets} className="text-xs font-medium text-accent hover:underline">
                            Budgets
                        </Link>
                    </div>
                    {section.budget ? (
                        <div className="space-y-1.5">
                            <MoneyLine label="Allocated" money={section.budget.allocated} />
                            <MoneyLine label="Spent (allocated)" money={section.budget.spent_allocated} />
                            <MoneyLine label="Remaining" money={section.budget.remaining} />
                            <MoneyLine label="Unbudgeted spending" money={section.budget.unbudgeted_spent} />
                            {budgetWarning ? (
                                <Badge variant="warning">{budgetWarning}</Badge>
                            ) : null}
                        </div>
                    ) : (
                        <p className="text-xs text-ink-muted">
                            No budget for {section.currency_code} this month.{' '}
                            <Link href={links.budgets} className="font-medium text-accent hover:underline">
                                Create one
                            </Link>
                        </p>
                    )}
                </section>
            </div>
        </Card>
    );
}

export default function Dashboard() {
    const page = usePage<SharedPageProps & DashboardPageProps>();
    const userName = page.props?.auth?.user?.name;
    const greeting = userName
        ? `Welcome back, ${userName}. Your workspace is ready.`
        : 'Welcome to Equity. Your workspace is ready.';

    const overview = {
        period: page.props.period,
        currencies: page.props.currencies,
        bills: page.props.bills,
        savings: page.props.savings,
        empty: page.props.empty,
        links: page.props.links,
    };

    const monthLabel = new Intl.DateTimeFormat('en', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(overview.period.year, overview.period.month - 1, 1)));

    const isCompletelyEmpty =
        !overview.empty.has_accounts &&
        !overview.empty.has_transactions_this_month &&
        !overview.empty.has_budgets_this_month &&
        !overview.empty.has_bill_items &&
        !overview.empty.has_active_savings_goals;

    return (
        <AuthenticatedLayout title="Dashboard">
            <div className="space-y-8">
                <PageHeader
                    eyebrow="Workspace Overview"
                    title="Dashboard"
                    description={`${greeting} Figures below use the UTC calendar month (${monthLabel}). Currencies are never combined.`}
                />

                {isCompletelyEmpty ? (
                    <EmptyState
                        title="Your workspace is empty"
                        description="Create an account to start tracking balances, then add transactions, budgets, bills, and savings goals."
                    >
                        <Link
                            href={overview.links.accounts}
                            className="text-sm font-semibold text-accent hover:underline"
                        >
                            Go to Accounts
                        </Link>
                    </EmptyState>
                ) : null}

                <section aria-labelledby="financial-overview-heading" className="space-y-4">
                    <div className="space-y-1">
                        <h2
                            id="financial-overview-heading"
                            className="text-lg font-semibold tracking-tight text-ink"
                        >
                            Financial overview
                        </h2>
                        <p className="text-sm text-ink-muted">
                            Active account position, cash flow, and budgets for each currency you use.
                        </p>
                    </div>

                    {overview.currencies.length === 0 ? (
                        <Card padding="sm" className="shadow-none">
                            <p className="text-sm text-ink-muted">
                                No currency activity yet.{' '}
                                <Link
                                    href={overview.links.accounts}
                                    className="font-medium text-accent hover:underline"
                                >
                                    Add an account
                                </Link>
                            </p>
                        </Card>
                    ) : (
                        <ul className="grid grid-cols-1 gap-3 xl:grid-cols-2">
                            {overview.currencies.map((section) => (
                                <li key={section.currency_code}>
                                    <CurrencyOverviewCard section={section} links={overview.links} />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section aria-labelledby="bills-overview-heading" className="space-y-4">
                    <div className="flex flex-wrap items-end justify-between gap-2">
                        <div className="space-y-1">
                            <h2
                                id="bills-overview-heading"
                                className="text-lg font-semibold tracking-tight text-ink"
                            >
                                Bills
                            </h2>
                            <p className="text-sm text-ink-muted">
                                Overdue items and bills due within {overview.bills.horizon_days} days
                                (UTC). Showing up to {overview.bills.display_cap}.
                            </p>
                        </div>
                        <Link
                            href={overview.links.bills}
                            className="text-sm font-medium text-accent hover:underline"
                        >
                            Open Bills
                        </Link>
                    </div>

                    <div className="flex flex-wrap gap-2 text-xs text-ink-muted">
                        <Badge variant={overview.bills.overdue_count > 0 ? 'warning' : 'neutral'}>
                            {overview.bills.overdue_count} overdue
                        </Badge>
                        <Badge variant="neutral">{overview.bills.upcoming_count} upcoming</Badge>
                    </div>

                    {overview.bills.items.length === 0 ? (
                        <Card padding="sm" className="shadow-none">
                            <p className="text-sm text-ink-muted">
                                No overdue or upcoming bills in this window.{' '}
                                <Link
                                    href={overview.links.bills}
                                    className="font-medium text-accent hover:underline"
                                >
                                    Manage bills
                                </Link>
                            </p>
                        </Card>
                    ) : (
                        <ul className="space-y-2" data-testid="dashboard-bills-list">
                            {overview.bills.items.map((item) => (
                                <li key={item.occurrence_id}>
                                    <Card padding="sm" className="shadow-none">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="space-y-1">
                                                <p className="text-sm font-semibold text-ink">
                                                    {item.obligation_name}
                                                </p>
                                                <p className="text-xs text-ink-muted">
                                                    Due {item.due_date} · {item.currency_code}
                                                </p>
                                                <Badge
                                                    variant={
                                                        item.is_overdue || item.display_status === 'overdue'
                                                            ? 'warning'
                                                            : 'neutral'
                                                    }
                                                >
                                                    {billStatusLabel(item.display_status, item.is_overdue)}
                                                </Badge>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-xs text-ink-muted">Remaining</p>
                                                <p
                                                    className="font-mono text-sm font-semibold tabular-nums text-ink"
                                                    data-amount-minor={item.remaining.amount_minor}
                                                >
                                                    {item.remaining.formatted}
                                                </p>
                                            </div>
                                        </div>
                                    </Card>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section aria-labelledby="savings-overview-heading" className="space-y-4">
                    <div className="flex flex-wrap items-end justify-between gap-2">
                        <div className="space-y-1">
                            <h2
                                id="savings-overview-heading"
                                className="text-lg font-semibold tracking-tight text-ink"
                            >
                                Savings goals
                            </h2>
                            <p className="text-sm text-ink-muted">{overview.savings.earmark_note}</p>
                        </div>
                        <Link
                            href={overview.links.savings}
                            className="text-sm font-medium text-accent hover:underline"
                        >
                            Open Savings
                        </Link>
                    </div>

                    {!overview.empty.has_active_savings_goals ? (
                        <Card padding="sm" className="shadow-none">
                            <p className="text-sm text-ink-muted">
                                No active savings goals.{' '}
                                <Link
                                    href={overview.links.savings}
                                    className="font-medium text-accent hover:underline"
                                >
                                    Create a goal
                                </Link>
                            </p>
                        </Card>
                    ) : (
                        <div className="space-y-3">
                            {overview.savings.portfolio.length > 0 ? (
                                <ul className="grid grid-cols-1 gap-3 md:grid-cols-2">
                                    {overview.savings.portfolio.map((row) => (
                                        <li key={row.currency_code}>
                                            <Card padding="sm" className="shadow-none">
                                                <p className="text-sm font-semibold text-ink">
                                                    {row.currency_code} portfolio
                                                </p>
                                                <div className="mt-2 space-y-1.5">
                                                    <MoneyLine label="Allocated" money={row.total_allocated} />
                                                    <MoneyLine label="Remaining" money={row.total_remaining} />
                                                    <MoneyLine label="Target" money={row.total_target} />
                                                </div>
                                            </Card>
                                        </li>
                                    ))}
                                </ul>
                            ) : null}

                            <ul className="space-y-2" data-testid="dashboard-savings-goals">
                                {overview.savings.goals.map((goal) => (
                                    <li key={goal.id}>
                                        <Card padding="sm" className="shadow-none">
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div>
                                                    <p className="text-sm font-semibold text-ink">
                                                        {goal.name}
                                                    </p>
                                                    <p className="text-xs text-ink-muted">
                                                        {goal.currency_code} ·{' '}
                                                        {progressPercent(goal.progress_basis_points)}
                                                        {goal.target_reached ? ' · Target reached' : ''}
                                                    </p>
                                                </div>
                                                <div className="text-right">
                                                    <p className="text-xs text-ink-muted">Allocated</p>
                                                    <p
                                                        className="font-mono text-sm font-semibold tabular-nums text-ink"
                                                        data-amount-minor={goal.allocated.amount_minor}
                                                    >
                                                        {goal.allocated.formatted}
                                                    </p>
                                                </div>
                                            </div>
                                        </Card>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </section>

                <section aria-labelledby="workspace-modules-heading" className="space-y-4">
                    <div className="space-y-1">
                        <h2
                            id="workspace-modules-heading"
                            className="text-lg font-semibold tracking-tight text-ink"
                        >
                            Workspace
                        </h2>
                        <p className="text-sm text-ink-muted">
                            Open a module to view and manage your finances. Amounts stay in each
                            module and are never combined across currencies here.
                        </p>
                    </div>

                    <ul className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {WORKSPACE_MODULES.map((item) => (
                            <li key={item.href}>
                                <Link
                                    href={item.href}
                                    aria-labelledby={item.headingId}
                                    aria-describedby={item.descriptionId}
                                    className="block rounded-2xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring/40"
                                >
                                    <Card
                                        padding="sm"
                                        className="h-full shadow-none transition-colors hover:bg-surface-muted"
                                    >
                                        <h3
                                            id={item.headingId}
                                            className="text-sm font-semibold text-ink"
                                        >
                                            {item.title}
                                        </h3>
                                        <p
                                            id={item.descriptionId}
                                            className="mt-2 text-xs leading-relaxed text-ink-muted"
                                        >
                                            {item.description}
                                        </p>
                                    </Card>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>

                <section aria-labelledby="planned-modules-heading" className="space-y-4">
                    <h2
                        id="planned-modules-heading"
                        className="text-lg font-semibold tracking-tight text-ink"
                    >
                        Planned
                    </h2>
                    <Card padding="sm" className="shadow-none">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h3 className="text-sm font-semibold text-ink">
                                Forecasting & currency conversion
                            </h3>
                            <Badge variant="neutral">Planned</Badge>
                        </div>
                        <p className="mt-2 text-xs leading-relaxed text-ink-muted">
                            Cash-flow forecasting and foreign-exchange conversion are not part of
                            this workspace yet. Financial Reports are available now.
                        </p>
                    </Card>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
