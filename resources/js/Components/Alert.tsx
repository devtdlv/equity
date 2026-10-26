import type { HTMLAttributes, ReactNode } from 'react';

export type AlertVariant = 'info' | 'positive' | 'warning' | 'negative';

export interface AlertProps extends HTMLAttributes<HTMLDivElement> {
    variant?: AlertVariant;
    title?: string;
    children: ReactNode;
}

const VARIANT_STYLES: Record<AlertVariant, { container: string; label: string }> = {
    info: {
        container: 'border-accent-border bg-accent-subtle text-accent-strong dark:text-accent',
        label: 'Status',
    },
    positive: {
        container: 'border-positive-border bg-positive-subtle text-positive',
        label: 'Confirmed',
    },
    warning: {
        container: 'border-warning-border bg-warning-subtle text-warning',
        label: 'Notice',
    },
    negative: {
        container: 'border-negative-border bg-negative-subtle text-negative',
        label: 'Error',
    },
};

export function Alert({
    variant = 'info',
    title,
    role,
    className = '',
    children,
    ...divProps
}: AlertProps) {
    const resolvedRole = role ?? (variant === 'negative' ? 'alert' : 'status');
    const styles = VARIANT_STYLES[variant];

    return (
        <div
            role={resolvedRole}
            className={`rounded-xl border px-4 py-3 text-xs leading-relaxed ${styles.container} ${className}`.trim()}
            {...divProps}
        >
            <div className="flex items-start gap-2.5">
                <span className="font-mono text-[11px] font-semibold tracking-wider uppercase">
                    {title ?? styles.label}:
                </span>
                <div className="flex-1 font-medium">{children}</div>
            </div>
        </div>
    );
}
