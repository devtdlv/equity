import { usePage } from '@inertiajs/react';
import { Card } from '@/Components/Card';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { AuthenticatedLayout } from '@/Layouts/AuthenticatedLayout';
import type { SharedPageProps } from '@/Types';

const PLANNED_CAPABILITIES: ReadonlyArray<{
    title: string;
    description: string;
    phase: string;
}> = [
    {
        title: 'Accounts & Balances',
        description:
            'Keep your checking, savings, and credit card balances organized in one clear view.',
        phase: 'Upcoming',
    },
    {
        title: 'Transactions & Activity',
        description:
            'Follow your income, everyday spending, and transfers with a reliable, easy-to-read history.',
        phase: 'Upcoming',
    },
    {
        title: 'Budgets & Cash-Flow Planning',
        description:
            'Plan monthly spending targets and stay ahead of upcoming bills and savings goals.',
        phase: 'Upcoming',
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

                <EmptyState
                    eyebrow="Getting Started"
                    title="Your financial overview starts here."
                    description="Equity will bring your accounts, transactions, budgets, and cash-flow planning into one organized workspace. Financial features are currently being developed."
                >
                    <div className="grid grid-cols-1 gap-4 pt-2 md:grid-cols-3">
                        {PLANNED_CAPABILITIES.map((item) => (
                            <Card
                                key={item.title}
                                padding="sm"
                                className="bg-surface-muted/50 shadow-none"
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <h3 className="text-sm font-semibold text-ink">{item.title}</h3>
                                    <span className="rounded bg-surface-elevated px-2 py-0.5 font-mono text-[10px] font-medium text-ink-muted">
                                        {item.phase}
                                    </span>
                                </div>
                                <p className="mt-2 text-xs leading-relaxed text-ink-muted">
                                    {item.description}
                                </p>
                            </Card>
                        ))}
                    </div>
                </EmptyState>
            </div>
        </AuthenticatedLayout>
    );
}
