export interface StatusBadgeProps {
    label: string;
}

export function StatusBadge({ label }: StatusBadgeProps) {
    return (
        <div
            role="status"
            aria-label={`Development status: ${label}`}
            className="inline-flex items-center gap-2 rounded-full border border-accent-border bg-accent-subtle px-3 py-1 text-xs font-medium tracking-wide text-accent-strong"
        >
            <span className="h-1.5 w-1.5 rounded-full bg-accent" aria-hidden="true" />
            <span>{label}</span>
        </div>
    );
}
