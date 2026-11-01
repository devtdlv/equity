import { savingsProgressFromBasisPoints } from '@/Utilities/savingsProgress';

export interface SavingsProgressBarProps {
    progressBasisPoints: number;
    className?: string;
}

export function SavingsProgressBar({
    progressBasisPoints,
    className = '',
}: SavingsProgressBarProps) {
    const progress = savingsProgressFromBasisPoints(progressBasisPoints);

    return (
        <div className={`space-y-1.5 ${className}`.trim()}>
            <div className="flex items-baseline justify-between gap-3">
                <span className="font-mono text-xs text-ink-muted">Progress</span>
                <span className="font-mono text-xs font-medium tabular-nums text-ink">
                    {progress.percentExact}%
                    {progress.isOverfunded ? (
                        <span className="ml-1.5 text-warning">Overfunded</span>
                    ) : null}
                </span>
            </div>
            <div
                className="h-2 overflow-hidden rounded-full bg-surface-subtle"
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={progress.barPercent}
                aria-label={progress.accessibleLabel}
            >
                <div
                    className={`h-full rounded-full transition-[width] ${
                        progress.isOverfunded ? 'bg-warning' : 'bg-accent'
                    }`}
                    style={{ width: `${progress.barPercent}%` }}
                />
            </div>
        </div>
    );
}
