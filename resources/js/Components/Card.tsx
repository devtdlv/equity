import type { HTMLAttributes, ReactNode } from 'react';

export type CardPadding = 'none' | 'sm' | 'md' | 'lg';

export interface CardProps extends HTMLAttributes<HTMLElement> {
    as?: 'div' | 'section' | 'article';
    padding?: CardPadding;
    children: ReactNode;
}

const PADDING_CLASSES: Record<CardPadding, string> = {
    none: '',
    sm: 'p-4 sm:p-5',
    md: 'p-6 sm:p-8',
    lg: 'p-8 sm:p-10 lg:p-12',
};

export function Card({
    as: Component = 'div',
    padding = 'md',
    className = '',
    children,
    ...elementProps
}: CardProps) {
    return (
        <Component
            className={`rounded-2xl border border-border-subtle bg-surface-elevated text-ink shadow-xs ${PADDING_CLASSES[padding]} ${className}`.trim()}
            {...elementProps}
        >
            {children}
        </Component>
    );
}
