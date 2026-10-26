import { forwardRef, type InputHTMLAttributes } from 'react';

export interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
    invalid?: boolean;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
    { invalid = false, className = '', ...inputProps },
    ref,
) {
    return (
        <input
            ref={ref}
            aria-invalid={invalid ? true : inputProps['aria-invalid']}
            className={`block min-h-10 w-full rounded-lg border px-3.5 py-2 text-sm text-ink shadow-2xs transition-colors placeholder:text-ink-muted/75 focus-visible:outline-none focus-visible:ring-2 disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-65 ${
                invalid
                    ? 'border-negative bg-negative-subtle/40 focus-visible:border-negative focus-visible:ring-negative/25'
                    : 'border-border-strong bg-surface-elevated focus-visible:border-accent focus-visible:ring-focus-ring/25'
            } ${className}`.trim()}
            {...inputProps}
        />
    );
});
