import { forwardRef, type InputHTMLAttributes, type ReactNode } from 'react';

export interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
    id: string;
    label: ReactNode;
    description?: string;
}

export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(function Checkbox(
    { id, label, description, className = '', ...checkboxProps },
    ref,
) {
    const descriptionId = description ? `${id}-description` : undefined;

    return (
        <div className="flex items-start gap-2.5">
            <input
                ref={ref}
                id={id}
                type="checkbox"
                aria-describedby={descriptionId}
                className={`mt-0.5 h-4 w-4 rounded border-border-strong bg-surface-elevated text-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring/35 disabled:cursor-not-allowed disabled:opacity-60 ${className}`.trim()}
                {...checkboxProps}
            />
            <div className="text-sm leading-tight">
                <label htmlFor={id} className="cursor-pointer font-medium text-ink-secondary">
                    {label}
                </label>
                {description ? (
                    <p id={descriptionId} className="mt-1 text-xs text-ink-muted">
                        {description}
                    </p>
                ) : null}
            </div>
        </div>
    );
});
