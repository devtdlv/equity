import { Link, usePage } from '@inertiajs/react';
import { Badge } from '@/Components/Badge';
import { Card } from '@/Components/Card';
import { PageHeader } from '@/Components/PageHeader';
import { AuthenticatedLayout } from '@/Layouts/AuthenticatedLayout';
import type { SharedPageProps } from '@/Types';

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
];

export default function Dashboard() {
    const page = usePage<SharedPageProps>();
    const userName = page.props?.auth?.user?.name;
    const greeting = userName
        ? `Welcome back, ${userName}. Your workspace is ready.`
        : 'Welcome to Equity. Your workspace is ready.';

    return (
        <AuthenticatedLayout title="Dashboard">
            <div className="space-y-8">
                <PageHeader eyebrow="Workspace Overview" title="Dashboard" description={greeting} />

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
                            <h3 className="text-sm font-semibold text-ink">Ledger & Reports</h3>
                            <Badge variant="neutral">Planned</Badge>
                        </div>
                        <p className="mt-2 text-xs leading-relaxed text-ink-muted">
                            Charts, reports, cash-flow forecasting, and foreign-exchange conversion
                            are not part of this workspace yet.
                        </p>
                    </Card>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
