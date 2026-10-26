import type { HTMLAttributes } from 'react';

export interface SkeletonProps extends HTMLAttributes<HTMLDivElement> {
    label?: string;
}

export function Skeleton({
    label = 'Loading content',
    className = 'h-4 w-full',
    ...divProps
}: SkeletonProps) {
    return (
        <div
            role="status"
            aria-label={label}
            aria-busy="true"
            className={`animate-pulse rounded-md bg-surface-subtle ${className}`.trim()}
            {...divProps}
        />
    );
}
