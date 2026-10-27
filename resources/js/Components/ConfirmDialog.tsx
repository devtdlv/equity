import { type ReactNode, useEffect, useId, useRef } from 'react';
import { Button, type ButtonVariant } from '@/Components/Button';

export interface ConfirmDialogProps {
    open: boolean;
    title: string;
    description: ReactNode;
    confirmLabel?: string;
    cancelLabel?: string;
    confirmVariant?: ButtonVariant;
    confirmIcon?: ReactNode;
    loading?: boolean;
    onConfirm: () => void;
    onCancel: () => void;
}

const FOCUSABLE_SELECTOR = [
    'button:not(:disabled)',
    '[href]',
    'input:not(:disabled)',
    'select:not(:disabled)',
    'textarea:not(:disabled)',
    '[tabindex]:not([tabindex="-1"])',
].join(', ');

export function ConfirmDialog({
    open,
    title,
    description,
    confirmLabel = 'Confirm',
    cancelLabel = 'Cancel',
    confirmVariant = 'warning-outline',
    confirmIcon,
    loading = false,
    onConfirm,
    onCancel,
}: ConfirmDialogProps) {
    const dialogId = useId();
    const titleId = `${dialogId}-title`;
    const descriptionId = `${dialogId}-description`;

    const panelRef = useRef<HTMLDivElement | null>(null);
    const cancelButtonRef = useRef<HTMLButtonElement | null>(null);
    const previousFocusRef = useRef<HTMLElement | null>(null);
    const onCancelRef = useRef(onCancel);
    const loadingRef = useRef(loading);

    useEffect(() => {
        onCancelRef.current = onCancel;
        loadingRef.current = loading;
    }, [onCancel, loading]);

    useEffect(() => {
        if (!open) {
            return;
        }

        previousFocusRef.current =
            document.activeElement instanceof HTMLElement ? document.activeElement : null;

        cancelButtonRef.current?.focus();

        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                if (!loadingRef.current) {
                    onCancelRef.current();
                }

                return;
            }

            if (event.key !== 'Tab' || !panelRef.current) {
                return;
            }

            const focusableElements = Array.from(
                panelRef.current.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR),
            );

            if (focusableElements.length === 0) {
                event.preventDefault();

                return;
            }

            const firstElement = focusableElements[0];
            const lastElement = focusableElements[focusableElements.length - 1];
            const activeElement = document.activeElement as HTMLElement | null;

            if (event.shiftKey) {
                if (activeElement === firstElement || !panelRef.current.contains(activeElement)) {
                    event.preventDefault();
                    lastElement.focus();
                }
            } else if (activeElement === lastElement || !panelRef.current.contains(activeElement)) {
                event.preventDefault();
                firstElement.focus();
            }
        };

        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('keydown', handleKeyDown);

            const previousElement = previousFocusRef.current;
            if (previousElement && document.contains(previousElement)) {
                previousElement.focus();
            }
        };
    }, [open]);

    if (!open) {
        return null;
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6"
            role="presentation"
        >
            <div
                className="fixed inset-0 bg-ink/50 backdrop-blur-[1px] transition-opacity"
                aria-hidden="true"
                onClick={() => {
                    if (!loading) {
                        onCancel();
                    }
                }}
            />

            <div
                ref={panelRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-describedby={descriptionId}
                className="relative z-10 w-full max-w-md rounded-2xl border border-border-subtle bg-surface-elevated p-6 text-ink shadow-xl sm:p-7"
            >
                <div className="space-y-3">
                    <p className="font-mono text-xs font-medium tracking-wider text-warning uppercase">
                        Confirm Action
                    </p>
                    <h2 id={titleId} className="text-lg font-semibold tracking-tight text-ink">
                        {title}
                    </h2>
                    <div
                        id={descriptionId}
                        className="space-y-2 text-sm leading-relaxed text-ink-muted"
                    >
                        {description}
                    </div>
                </div>

                <div className="mt-6 flex flex-col-reverse items-stretch justify-end gap-2.5 sm:flex-row sm:items-center">
                    <Button
                        ref={cancelButtonRef}
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={loading}
                        onClick={onCancel}
                    >
                        {cancelLabel}
                    </Button>
                    <Button
                        type="button"
                        variant={confirmVariant}
                        size="sm"
                        icon={confirmIcon}
                        loading={loading}
                        onClick={() => {
                            if (!loading) {
                                onConfirm();
                            }
                        }}
                    >
                        {confirmLabel}
                    </Button>
                </div>
            </div>
        </div>
    );
}
