import type { HTMLAttributes, ReactNode } from 'react';

export interface EmptyStateProps extends HTMLAttributes<HTMLDivElement> {
    eyebrow?: string;
    title: string;
    description: string;
    icon?: ReactNode;
    children?: ReactNode;
}

export function EmptyState({
    eyebrow,
    title,
    description,
    icon,
    children,
    className = '',
    ...divProps
}: EmptyStateProps) {
    return (
        <div
            className={`rounded-2xl border border-border-subtle bg-surface-elevated p-8 shadow-xs sm:p-10 ${className}`.trim()}
            {...divProps}
        >
            <div className="max-w-2xl space-y-5">
                {icon ? (
                    <div
                        className="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-accent-border bg-accent-subtle text-accent-strong dark:text-accent"
                        aria-hidden="true"
                    >
                        {icon}
                    </div>
                ) : null}

                {eyebrow ? (
                    <p className="font-mono text-xs font-medium tracking-wider text-accent uppercase">
                        {eyebrow}
                    </p>
                ) : null}

                <div className="space-y-2">
                    <h2 className="text-xl font-semibold tracking-tight text-ink sm:text-2xl">
                        {title}
                    </h2>
                    <p className="text-sm leading-relaxed text-ink-muted sm:text-base">
                        {description}
                    </p>
                </div>

                {children ? <div className="pt-2">{children}</div> : null}
            </div>
        </div>
    );
}
