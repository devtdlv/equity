import { forwardRef, type InputHTMLAttributes } from 'react';
import { Input } from '@/Components/Input';

export interface FormFieldProps extends InputHTMLAttributes<HTMLInputElement> {
    id: string;
    label: string;
    error?: string;
    hint?: string;
}

export const FormField = forwardRef<HTMLInputElement, FormFieldProps>(function FormField(
    { id, label, error, hint, className = '', ...inputProps },
    ref,
) {
    const errorId = `${id}-error`;
    const hintId = `${id}-hint`;
    const describedBy = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ');

    return (
        <div className="space-y-1.5">
            <label htmlFor={id} className="block text-sm font-medium text-ink">
                {label}
            </label>
            <Input
                ref={ref}
                id={id}
                invalid={Boolean(error)}
                aria-describedby={describedBy || undefined}
                className={className}
                {...inputProps}
            />
            {hint && !error ? (
                <p id={hintId} className="text-xs leading-relaxed text-ink-muted">
                    {hint}
                </p>
            ) : null}
            {error ? (
                <p id={errorId} role="alert" className="text-xs font-medium text-negative">
                    {error}
                </p>
            ) : null}
        </div>
    );
});
