import { StatusBadge } from '@/Components/StatusBadge';
import { AuthenticatedLayout } from '@/Layouts/AuthenticatedLayout';
import type { DashboardPageProps } from '@/Types';

const DEFAULT_STATUS = 'Phase 2: Secure Session Authentication';

export default function Dashboard({ status = DEFAULT_STATUS }: DashboardPageProps) {
    return (
        <AuthenticatedLayout title="Dashboard">
            <section
                aria-labelledby="dashboard-heading"
                className="rounded-2xl border border-border-subtle bg-surface-elevated p-8 shadow-xs sm:p-12"
            >
                <div className="max-w-2xl space-y-5">
                    <StatusBadge label={status} />

                    <div className="space-y-2">
                        <h1
                            id="dashboard-heading"
                            className="text-2xl font-semibold tracking-tight text-ink sm:text-3xl"
                        >
                            Your financial overview is coming together.
                        </h1>
                        <p className="text-base leading-relaxed text-ink-muted">
                            Your authenticated workspace is ready. Financial accounts, transactions,
                            budgets, and double-entry ledger views will be introduced in upcoming
                            milestones.
                        </p>
                    </div>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
