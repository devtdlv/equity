import type { InputHTMLAttributes } from 'react';

export interface FormFieldProps extends InputHTMLAttributes<HTMLInputElement> {
    id: string;
    label: string;
    error?: string;
    hint?: string;
}

export function FormField({
    id,
    label,
    error,
    hint,
    className = '',
    ...inputProps
}: FormFieldProps) {
    const errorId = `${id}-error`;
    const hintId = `${id}-hint`;
    const describedBy = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ');

    return (
        <div className="space-y-1.5">
            <label htmlFor={id} className="block text-sm font-medium text-ink">
                {label}
            </label>
            <input
                id={id}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy || undefined}
                className={`block w-full rounded-lg border px-3.5 py-2.5 text-sm text-ink shadow-2xs transition-colors placeholder:text-ink-muted/70 focus:outline-none focus:ring-2 ${
                    error
                        ? 'border-red-500 bg-red-50/40 focus:border-red-600 focus:ring-red-500/20'
                        : 'border-border-strong bg-surface-elevated focus:border-accent focus:ring-accent/20'
                } ${className}`.trim()}
                {...inputProps}
            />
            {hint && !error ? (
                <p id={hintId} className="text-xs text-ink-muted">
                    {hint}
                </p>
            ) : null}
            {error ? (
                <p id={errorId} role="alert" className="text-xs font-medium text-red-600">
                    {error}
                </p>
            ) : null}
        </div>
    );
}
