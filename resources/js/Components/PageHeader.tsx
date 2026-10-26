import type { ReactNode } from 'react';

export interface PageHeaderProps {
    eyebrow?: string;
    title: string;
    description?: string;
    headingId?: string;
    actions?: ReactNode;
}

export function PageHeader({ eyebrow, title, description, headingId, actions }: PageHeaderProps) {
    return (
        <div className="flex flex-col justify-between gap-4 border-b border-border-subtle pb-6 sm:flex-row sm:items-end">
            <div className="space-y-1.5">
                {eyebrow ? (
                    <p className="font-mono text-xs font-medium tracking-wider text-accent uppercase">
                        {eyebrow}
                    </p>
                ) : null}
                <h1
                    id={headingId}
                    className="text-2xl font-semibold tracking-tight text-ink sm:text-3xl"
                >
                    {title}
                </h1>
                {description ? (
                    <p className="max-w-2xl text-sm leading-relaxed text-ink-muted">
                        {description}
                    </p>
                ) : null}
            </div>

            {actions ? <div className="flex shrink-0 items-center gap-3">{actions}</div> : null}
        </div>
    );
}
