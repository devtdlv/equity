import type { ReportTrendPoint } from '@/Types';

export interface CashFlowTrendChartProps {
    points: ReportTrendPoint[];
    currencyCode: string;
}

function absoluteMinor(value: string): bigint {
    const asBigInt = BigInt(value);
    return asBigInt < 0n ? -asBigInt : asBigInt;
}

export function CashFlowTrendChart({ points, currencyCode }: CashFlowTrendChartProps) {
    const maxMinor = points.reduce((max, point) => {
        const income = absoluteMinor(point.income.amount_minor);
        const expenses = absoluteMinor(point.expenses.amount_minor);
        const local = income > expenses ? income : expenses;
        return local > max ? local : max;
    }, 0n);

    const chartHeight = 160;
    const barWidth = points.length > 36 ? 10 : 14;
    const groupGap = points.length > 36 ? 18 : 28;
    const width = Math.max(points.length * (barWidth * 2 + groupGap), 240);

    return (
        <div className="min-w-0 space-y-3">
            <div className="flex flex-wrap items-center gap-4 text-xs text-ink-muted">
                <span className="inline-flex items-center gap-1.5">
                    <span className="h-2.5 w-2.5 rounded-sm bg-positive" aria-hidden="true" />
                    Income
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <span className="h-2.5 w-2.5 rounded-sm bg-negative" aria-hidden="true" />
                    Expenses
                </span>
                <span className="font-mono text-[11px] uppercase tracking-wide">{currencyCode}</span>
            </div>
            <div className="min-w-0 overflow-x-auto">
                <svg
                    role="img"
                    aria-label={`${currencyCode} cash-flow trend chart`}
                    width={width}
                    height={chartHeight + 36}
                    viewBox={`0 0 ${width} ${chartHeight + 36}`}
                    className="min-w-full"
                >
                    {points.map((point, index) => {
                        const groupX = index * (barWidth * 2 + groupGap) + 8;
                        const incomeH =
                            maxMinor === 0n
                                ? 0
                                : Number((absoluteMinor(point.income.amount_minor) * BigInt(chartHeight)) / maxMinor);
                        const expenseH =
                            maxMinor === 0n
                                ? 0
                                : Number(
                                      (absoluteMinor(point.expenses.amount_minor) * BigInt(chartHeight)) /
                                          maxMinor,
                                  );

                        return (
                            <g key={point.period_key}>
                                <title>
                                    {`${point.period_label}: income ${point.income.formatted}, expenses ${point.expenses.formatted}`}
                                </title>
                                <rect
                                    x={groupX}
                                    y={chartHeight - incomeH}
                                    width={barWidth}
                                    height={incomeH}
                                    className="fill-positive"
                                    rx={2}
                                />
                                <rect
                                    x={groupX + barWidth + 4}
                                    y={chartHeight - expenseH}
                                    width={barWidth}
                                    height={expenseH}
                                    className="fill-negative"
                                    rx={2}
                                />
                                <text
                                    x={groupX + barWidth}
                                    y={chartHeight + 18}
                                    textAnchor="middle"
                                    className="fill-ink-muted text-[10px]"
                                >
                                    {point.period_label}
                                </text>
                            </g>
                        );
                    })}
                </svg>
            </div>
            {/*
              Keep screen-reader period totals, but clip the sr-only list.
              Tailwind `sr-only` uses absolute + nowrap; long formatted amounts
              otherwise expand document scrollWidth on narrow viewports.
            */}
            <div className="relative h-px w-px overflow-hidden">
                <ul className="sr-only">
                    {points.map((point) => (
                        <li key={`a11y-${point.period_key}`}>
                            {point.period_label}: income {point.income.formatted}, expenses{' '}
                            {point.expenses.formatted}, net {point.net.formatted}
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}
