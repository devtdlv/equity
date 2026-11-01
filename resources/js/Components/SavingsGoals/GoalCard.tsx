import { Badge } from '@/Components/Badge';
import { Card } from '@/Components/Card';
import { SavingsProgressBar } from '@/Components/SavingsGoals/SavingsProgressBar';
import type { CurrencyOption, SavingsGoalProgressRecord } from '@/Types';
import { formatSavingsAmount, isNonZeroMinorString } from '@/Utilities/savingsMoney';

export interface GoalCardProps {
    goal: SavingsGoalProgressRecord;
    currency: CurrencyOption | undefined;
    selected: boolean;
    onSelect: (goalId: number) => void;
}

function statusBadgeVariant(
    status: SavingsGoalProgressRecord['status'],
): 'accent' | 'neutral' {
    return status === 'active' ? 'accent' : 'neutral';
}

export function GoalCard({ goal, currency, selected, onSelect }: GoalCardProps) {
    const overfunded = isNonZeroMinorString(goal.overfunded_amount_minor);
    const formatAmount = (amountMinor: string): string =>
        formatSavingsAmount(amountMinor, goal.currency_code, currency);

    return (
        <Card
            as="article"
            padding="sm"
            className={`transition-colors ${
                selected
                    ? 'border-accent-border bg-accent-subtle/40 ring-1 ring-accent-border'
                    : 'hover:border-border-strong'
            }`}
        >
            <button
                type="button"
                onClick={() => onSelect(goal.id)}
                aria-pressed={selected}
                className="w-full rounded-xl text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring/40"
            >
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0 space-y-1">
                        <h3 className="truncate text-sm font-semibold text-ink">{goal.name}</h3>
                        <p className="font-mono text-[11px] text-ink-muted">{goal.currency_code}</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-1.5">
                        <Badge variant={statusBadgeVariant(goal.status)}>
                            {goal.status === 'active' ? 'Active' : 'Archived'}
                        </Badge>
                        {overfunded ? <Badge variant="warning">Overfunded</Badge> : null}
                        {goal.target_reached && !overfunded ? (
                            <Badge variant="positive">Target reached</Badge>
                        ) : null}
                    </div>
                </div>

                <dl className="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <div>
                        <dt className="font-mono text-[10px] tracking-wider text-ink-muted uppercase">
                            Target
                        </dt>
                        <dd className="mt-0.5 text-sm font-medium tabular-nums text-ink">
                            {formatAmount(goal.target_amount_minor)}
                        </dd>
                    </div>
                    <div>
                        <dt className="font-mono text-[10px] tracking-wider text-ink-muted uppercase">
                            Allocated
                        </dt>
                        <dd className="mt-0.5 text-sm font-medium tabular-nums text-ink">
                            {formatAmount(goal.allocated_amount_minor)}
                        </dd>
                    </div>
                    <div>
                        <dt className="font-mono text-[10px] tracking-wider text-ink-muted uppercase">
                            Remaining to target
                        </dt>
                        <dd className="mt-0.5 text-sm font-medium tabular-nums text-ink">
                            {formatAmount(goal.remaining_amount_minor)}
                        </dd>
                    </div>
                </dl>

                <div className="mt-4">
                    <SavingsProgressBar progressBasisPoints={goal.progress_basis_points} />
                </div>

                {goal.target_date ? (
                    <p className="mt-3 font-mono text-[11px] text-ink-muted">
                        Target date {goal.target_date}
                    </p>
                ) : null}
            </button>
        </Card>
    );
}
