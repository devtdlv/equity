import type { HTMLAttributes } from 'react';

export interface SeparatorProps extends HTMLAttributes<HTMLHRElement> {
    orientation?: 'horizontal' | 'vertical';
}

export function Separator({
    orientation = 'horizontal',
    className = '',
    ...hrProps
}: SeparatorProps) {
    return (
        <hr
            aria-orientation={orientation}
            className={`border-0 bg-border-subtle ${
                orientation === 'horizontal' ? 'h-px w-full' : 'h-full w-px'
            } ${className}`.trim()}
            {...hrProps}
        />
    );
}
