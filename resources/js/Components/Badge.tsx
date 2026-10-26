import type { HTMLAttributes, ReactNode } from 'react';

export type BadgeVariant = 'neutral' | 'accent' | 'positive' | 'warning' | 'negative';

export interface BadgeProps extends HTMLAttributes<HTMLSpanElement> {
    variant?: BadgeVariant;
    dot?: boolean;
    children: ReactNode;
}

const VARIANT_CLASSES: Record<BadgeVariant, { container: string; dot: string }> = {
    neutral: {
        container: 'border-border-subtle bg-surface-muted text-ink-secondary',
        dot: 'bg-ink-muted',
    },
    accent: {
        container: 'border-accent-border bg-accent-subtle text-accent-strong dark:text-accent',
        dot: 'bg-accent',
    },
    positive: {
        container: 'border-positive-border bg-positive-subtle text-positive',
        dot: 'bg-positive',
    },
    warning: {
        container: 'border-warning-border bg-warning-subtle text-warning',
        dot: 'bg-warning',
    },
    negative: {
        container: 'border-negative-border bg-negative-subtle text-negative',
        dot: 'bg-negative',
    },
};

export function Badge({
    variant = 'neutral',
    dot = false,
    className = '',
    children,
    ...spanProps
}: BadgeProps) {
    const styles = VARIANT_CLASSES[variant];

    return (
        <span
            className={`inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium tracking-wide ${styles.container} ${className}`.trim()}
            {...spanProps}
        >
            {dot ? (
                <span className={`h-1.5 w-1.5 rounded-full ${styles.dot}`} aria-hidden="true" />
            ) : null}
            <span>{children}</span>
        </span>
    );
}
