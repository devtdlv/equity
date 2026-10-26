import { Badge } from '@/Components/Badge';

export interface StatusBadgeProps {
    label: string;
}

export function StatusBadge({ label }: StatusBadgeProps) {
    return (
        <Badge variant="accent" dot role="status" aria-label={`Development status: ${label}`}>
            {label}
        </Badge>
    );
}
