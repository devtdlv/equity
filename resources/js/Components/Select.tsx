import { forwardRef, type SelectHTMLAttributes } from 'react';

export interface SelectOption {
    value: string;
    label: string;
    disabled?: boolean;
}

export interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
    invalid?: boolean;
    options?: readonly SelectOption[];
}

export const Select = forwardRef<HTMLSelectElement, SelectProps>(function Select(
    { invalid = false, options, children, className = '', ...selectProps },
    ref,
) {
    return (
        <select
            ref={ref}
            aria-invalid={invalid ? true : selectProps['aria-invalid']}
            className={`block min-h-10 w-full rounded-lg border px-3.5 py-2 text-sm text-ink shadow-2xs transition-colors focus-visible:outline-none focus-visible:ring-2 disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-65 ${
                invalid
                    ? 'border-negative bg-negative-subtle/40 focus-visible:border-negative focus-visible:ring-negative/25'
                    : 'border-border-strong bg-surface-elevated focus-visible:border-accent focus-visible:ring-focus-ring/25'
            } ${className}`.trim()}
            {...selectProps}
        >
            {options
                ? options.map((option) => (
                      <option key={option.value} value={option.value} disabled={option.disabled}>
                          {option.label}
                      </option>
                  ))
                : children}
        </select>
    );
});
