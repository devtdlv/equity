import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';

export type ButtonVariant = 'primary' | 'secondary' | 'outline' | 'ghost' | 'danger';
export type ButtonSize = 'sm' | 'md' | 'lg';

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: ButtonVariant;
    size?: ButtonSize;
    loading?: boolean;
    fullWidth?: boolean;
    children: ReactNode;
}

const VARIANT_CLASSES: Record<ButtonVariant, string> = {
    primary:
        'bg-accent text-accent-foreground shadow-2xs hover:bg-accent-strong active:opacity-95 border border-transparent',
    secondary:
        'bg-ink text-ink-inverse shadow-2xs hover:opacity-90 active:opacity-95 border border-transparent',
    outline:
        'border border-border-strong bg-surface-elevated text-ink hover:bg-surface-muted active:bg-surface-subtle',
    ghost: 'border border-transparent bg-transparent text-ink-secondary hover:bg-surface-muted hover:text-ink',
    danger: 'border border-negative-border bg-negative-subtle text-negative hover:opacity-90',
};

const SIZE_CLASSES: Record<ButtonSize, string> = {
    sm: 'min-h-8 px-3 py-1.5 text-xs gap-1.5',
    md: 'min-h-10 px-4 py-2 text-sm gap-2',
    lg: 'min-h-11 px-5 py-2.5 text-sm gap-2',
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
    {
        variant = 'primary',
        size = 'md',
        loading = false,
        fullWidth = false,
        disabled,
        type = 'button',
        className = '',
        children,
        ...buttonProps
    },
    ref,
) {
    const isDisabled = Boolean(disabled || loading);

    return (
        <button
            ref={ref}
            type={type}
            disabled={isDisabled}
            aria-busy={loading ? true : undefined}
            className={`inline-flex items-center justify-center rounded-lg font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring/40 disabled:pointer-events-none disabled:opacity-60 ${
                VARIANT_CLASSES[variant]
            } ${SIZE_CLASSES[size]} ${fullWidth ? 'w-full' : ''} ${className}`.trim()}
            {...buttonProps}
        >
            {loading ? (
                <span
                    className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-r-transparent"
                    aria-hidden="true"
                />
            ) : null}
            <span>{children}</span>
        </button>
    );
});
