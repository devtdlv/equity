import { forwardRef, type TextareaHTMLAttributes } from 'react';

export interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
    invalid?: boolean;
}

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(function Textarea(
    { invalid = false, rows = 4, className = '', ...textareaProps },
    ref,
) {
    return (
        <textarea
            ref={ref}
            rows={rows}
            aria-invalid={invalid ? true : textareaProps['aria-invalid']}
            className={`block w-full rounded-lg border px-3.5 py-2.5 text-sm text-ink shadow-2xs transition-colors placeholder:text-ink-muted/75 focus-visible:outline-none focus-visible:ring-2 disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-65 ${
                invalid
                    ? 'border-negative bg-negative-subtle/40 focus-visible:border-negative focus-visible:ring-negative/25'
                    : 'border-border-strong bg-surface-elevated focus-visible:border-accent focus-visible:ring-focus-ring/25'
            } ${className}`.trim()}
            {...textareaProps}
        />
    );
});
