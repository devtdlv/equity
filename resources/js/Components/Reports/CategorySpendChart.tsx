import type { ReportCategorySpend } from '@/Types';

export interface CategorySpendChartProps {
    rows: ReportCategorySpend[];
    currencyCode: string;
}

export function CategorySpendChart({ rows, currencyCode }: CategorySpendChartProps) {
    if (rows.length === 0) {
        return (
            <p className="text-sm text-ink-muted">
                No expense activity in {currencyCode} for this period.
            </p>
        );
    }

    return (
        <div className="space-y-3">
            <ul className="space-y-3" aria-label={`${currencyCode} spending by category`}>
                {rows.map((row) => {
                    const widthPercent = Math.min(100, Math.max(0, row.share_bps / 100));

                    return (
                        <li key={`${row.category_id ?? 'none'}-${row.category_name}`}>
                            <div className="mb-1 flex items-baseline justify-between gap-3">
                                <span className="text-sm font-medium text-ink">{row.category_name}</span>
                                <span
                                    className="font-mono text-sm tabular-nums text-ink"
                                    data-amount-minor={row.total.amount_minor}
                                >
                                    {row.total.formatted}
                                </span>
                            </div>
                            <div
                                className="h-2 overflow-hidden rounded-full bg-surface-muted"
                                role="presentation"
                            >
                                <div
                                    className="h-full rounded-full bg-accent"
                                    style={{ width: `${widthPercent}%` }}
                                />
                            </div>
                            <p className="mt-1 font-mono text-[11px] text-ink-muted">
                                {`${Math.floor(row.share_bps / 100)}.${String(row.share_bps % 100).padStart(2, '0')}%`}{' '}
                                of expenses
                            </p>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
