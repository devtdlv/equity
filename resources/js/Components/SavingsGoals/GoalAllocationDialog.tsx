import axios from 'axios';
import {
    type FormEvent,
    useEffect,
    useId,
    useRef,
    useState,
} from 'react';
import { Alert } from '@/Components/Alert';
import { Button } from '@/Components/Button';
import { FormField } from '@/Components/FormField';
import { Textarea } from '@/Components/Textarea';
import type {
    CurrencyOption,
    SavingsGoalEventRecord,
    SavingsGoalProgressRecord,
} from '@/Types';
import {
    decimalMajorToPositiveMinorUnitString,
    doesMinorAmountExceedAvailable,
    formatSavingsAmount,
    isNonZeroMinorString,
    parseMinorUnitString,
    savingsGoalAllocationDraftFingerprint,
    SavingsMoneyFormatError,
} from '@/Utilities/savingsMoney';
import { generateUuidV4 } from '@/Utilities/uuid';

export type GoalAllocationMode = 'contribute' | 'withdraw';

type FieldErrors = Record<string, string | undefined>;

type SubmissionState = 'idle' | 'submitting' | 'conflict_blocked' | 'uncertain';

export type PendingAllocationAttempt = {
    goalId: number;
    mode: GoalAllocationMode;
    amount: string;
    note: string;
    idempotencyKey: string;
    lockedPayload: string;
    state: 'conflict_blocked' | 'uncertain';
    banner: {
        variant: 'info' | 'positive' | 'warning' | 'negative';
        message: string;
    };
};

export interface GoalAllocationDialogProps {
    open: boolean;
    mode: GoalAllocationMode;
    goal: SavingsGoalProgressRecord;
    currency: CurrencyOption | null | undefined;
    pendingAttempt: PendingAllocationAttempt | null;
    onClose: () => void;
    onPendingChange: (pending: PendingAllocationAttempt | null) => void;
    onSuccess: (result: {
        mode: GoalAllocationMode;
        goal: SavingsGoalProgressRecord;
        event: SavingsGoalEventRecord;
        wasRecentlyCreated?: boolean;
        idempotentReplay?: boolean;
    }) => void;
    onAuthoritativeGoalRefresh?: (goalId: number) => void;
}

type DraftState = {
    amount: string;
    note: string;
};

const FOCUSABLE_SELECTOR = [
    'button:not(:disabled)',
    '[href]',
    'input:not(:disabled)',
    'select:not(:disabled)',
    'textarea:not(:disabled)',
    '[tabindex]:not([tabindex="-1"])',
].join(', ');

function mapFieldErrors(errors: Record<string, string[] | string> | undefined): FieldErrors {
    if (!errors) {
        return {};
    }

    const mapped: FieldErrors = {};
    for (const [key, value] of Object.entries(errors)) {
        if (typeof value === 'string' && value.trim() !== '') {
            mapped[key] = value;
        } else if (Array.isArray(value) && value.length > 0 && typeof value[0] === 'string') {
            mapped[key] = value[0];
        }
    }

    return mapped;
}

function firstValidationMessage(
    errors: Record<string, string[] | string> | undefined,
    preferredKeys: string[],
): string | null {
    if (!errors) {
        return null;
    }

    for (const key of preferredKeys) {
        const value = errors[key];
        if (typeof value === 'string' && value.trim() !== '') {
            return value;
        }
        if (Array.isArray(value) && value.length > 0 && typeof value[0] === 'string') {
            return value[0];
        }
    }

    for (const value of Object.values(errors)) {
        if (typeof value === 'string' && value.trim() !== '') {
            return value;
        }
        if (Array.isArray(value) && value.length > 0 && typeof value[0] === 'string') {
            return value[0];
        }
    }

    return null;
}

function tenantSafeMutationMessage(status: number | undefined, fallback: string): string {
    if (status === 401) {
        return 'Your session has expired. Please sign in again to continue.';
    }
    if (status === 403) {
        return 'You do not have permission to change allocations for this savings goal.';
    }
    if (status !== undefined && status >= 500) {
        return 'The server could not confirm this request. Your details were preserved for a safe retry.';
    }
    return fallback;
}

export function GoalAllocationDialog({
    open,
    mode,
    goal,
    currency,
    pendingAttempt,
    onClose,
    onPendingChange,
    onSuccess,
    onAuthoritativeGoalRefresh,
}: GoalAllocationDialogProps) {
    const titleId = useId();
    const descriptionId = useId();
    const panelRef = useRef<HTMLDivElement | null>(null);
    const amountInputRef = useRef<HTMLInputElement | null>(null);
    const previousFocusRef = useRef<HTMLElement | null>(null);
    const fingerprintRef = useRef('');
    const lockedPayloadRef = useRef<string | null>(null);
    const savingRef = useRef(false);
    const fieldsLockedRef = useRef(false);

    const [draft, setDraft] = useState<DraftState>({ amount: '', note: '' });
    const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
    const [formBanner, setFormBanner] = useState<{
        variant: 'info' | 'positive' | 'warning' | 'negative';
        message: string;
    } | null>(null);
    const [idempotencyKey, setIdempotencyKey] = useState(() => generateUuidV4());
    const [submissionState, setSubmissionState] = useState<SubmissionState>('idle');
    const [saving, setSaving] = useState(false);

    const isContribute = mode === 'contribute';
    const fieldsLocked =
        submissionState === 'conflict_blocked' || submissionState === 'uncertain';
    fieldsLockedRef.current = fieldsLocked;

    const allocatedDisplay = formatSavingsAmount(
        goal.allocated_amount_minor,
        goal.currency_code,
        currency,
    );

    const hasAllocation = (() => {
        try {
            return parseMinorUnitString(goal.allocated_amount_minor) > 0n;
        } catch {
            return isNonZeroMinorString(goal.allocated_amount_minor);
        }
    })();

    const usableExponent =
        currency &&
        Number.isInteger(currency.minor_unit_exponent) &&
        currency.minor_unit_exponent >= 0 &&
        currency.minor_unit_exponent <= 6
            ? currency.minor_unit_exponent
            : null;

    useEffect(() => {
        savingRef.current = saving;
    }, [saving]);

    useEffect(() => {
        if (!open) {
            return;
        }

        previousFocusRef.current =
            document.activeElement instanceof HTMLElement ? document.activeElement : null;

        const restorePending =
            pendingAttempt !== null &&
            pendingAttempt.goalId === goal.id &&
            pendingAttempt.mode === mode;

        if (restorePending) {
            fieldsLockedRef.current = true;
            setDraft({ amount: pendingAttempt.amount, note: pendingAttempt.note });
            setIdempotencyKey(pendingAttempt.idempotencyKey);
            lockedPayloadRef.current = pendingAttempt.lockedPayload;
            fingerprintRef.current = savingsGoalAllocationDraftFingerprint({
                amount: pendingAttempt.amount,
                note: pendingAttempt.note,
            });
            setSubmissionState(pendingAttempt.state);
            setFormBanner(pendingAttempt.banner);
            setFieldErrors({});
            setSaving(false);
        } else {
            fieldsLockedRef.current = false;
            setDraft({ amount: '', note: '' });
            fingerprintRef.current = savingsGoalAllocationDraftFingerprint({
                amount: '',
                note: '',
            });
            setIdempotencyKey(generateUuidV4());
            lockedPayloadRef.current = null;
            setSubmissionState('idle');
            setFormBanner(null);
            setFieldErrors({});
            setSaving(false);
        }

        const focusTimer = window.setTimeout(() => {
            amountInputRef.current?.focus();
        }, 0);

        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                onClose();
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
            window.clearTimeout(focusTimer);
            document.removeEventListener('keydown', handleKeyDown);
            const previousElement = previousFocusRef.current;
            if (previousElement && document.contains(previousElement)) {
                previousElement.focus();
            }
        };
        // Restore pending / reset only when the dialog opens for a goal+mode pair.
        // eslint-disable-next-line react-hooks/exhaustive-deps -- intentional open-gated init
    }, [open, goal.id, mode]);

    useEffect(() => {
        if (!open || fieldsLockedRef.current) {
            return;
        }

        const fingerprint = savingsGoalAllocationDraftFingerprint(draft);
        if (fingerprintRef.current === '') {
            fingerprintRef.current = fingerprint;
            return;
        }

        if (fingerprint !== fingerprintRef.current) {
            fingerprintRef.current = fingerprint;
            setIdempotencyKey(generateUuidV4());
        }
    }, [draft, open, submissionState]);

    if (!open) {
        return null;
    }

    const updateDraft = <K extends keyof DraftState>(key: K, value: DraftState[K]) => {
        if (fieldsLocked) {
            return;
        }
        setDraft((current) => ({ ...current, [key]: value }));
    };

    const persistPending = (
        state: 'conflict_blocked' | 'uncertain',
        banner: NonNullable<typeof formBanner>,
        key: string,
        payload: string,
        nextDraft: DraftState,
    ) => {
        onPendingChange({
            goalId: goal.id,
            mode,
            amount: nextDraft.amount,
            note: nextDraft.note,
            idempotencyKey: key,
            lockedPayload: payload,
            state,
            banner,
        });
    };

    const startNewAttempt = () => {
        fieldsLockedRef.current = false;
        setIdempotencyKey(generateUuidV4());
        setSubmissionState('idle');
        lockedPayloadRef.current = null;
        fingerprintRef.current = savingsGoalAllocationDraftFingerprint(draft);
        onPendingChange(null);
        setFormBanner({
            variant: 'info',
            message:
                'Started a new allocation attempt with a fresh idempotency key. If a previous request already succeeded, check the goal history before submitting again.',
        });
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        if (saving || submissionState === 'conflict_blocked') {
            return;
        }

        if (usableExponent === null) {
            setFieldErrors({
                amount: 'Currency metadata is unavailable, so this amount cannot be submitted safely.',
            });
            return;
        }

        if (!isContribute && !hasAllocation) {
            setFormBanner({
                variant: 'warning',
                message: 'There is no allocated amount to reduce on this goal.',
            });
            return;
        }

        let amountMinor: string;
        try {
            amountMinor = decimalMajorToPositiveMinorUnitString(draft.amount, usableExponent);
        } catch (error) {
            const message =
                error instanceof SavingsMoneyFormatError
                    ? error.message
                    : 'Enter a valid amount.';
            setFieldErrors({ amount: message, amount_minor: message });
            return;
        }

        if (!isContribute) {
            try {
                if (doesMinorAmountExceedAvailable(amountMinor, goal.allocated_amount_minor)) {
                    const message =
                        'Amount exceeds the currently allocated balance. Enter a smaller amount or refresh the goal.';
                    setFieldErrors({ amount: message, amount_minor: message });
                    return;
                }
            } catch {
                setFieldErrors({
                    amount: 'Allocated balance could not be verified for this withdrawal.',
                });
                return;
            }
        }

        const payload = {
            idempotency_key: idempotencyKey,
            amount_minor: amountMinor,
            note: draft.note.trim() === '' ? null : draft.note.trim(),
        };

        if (submissionState === 'uncertain' && lockedPayloadRef.current !== null) {
            const currentFingerprint = JSON.stringify({
                amount_minor: payload.amount_minor,
                note: payload.note,
            });
            if (currentFingerprint !== lockedPayloadRef.current) {
                setFormBanner({
                    variant: 'warning',
                    message:
                        'This allocation attempt is locked to the original payload after an uncertain response. Click “Start new attempt” before changing details.',
                });
                return;
            }
        }

        setSaving(true);
        setFieldErrors({});
        setFormBanner(null);
        setSubmissionState('submitting');

        const lockedPayload = JSON.stringify({
            amount_minor: payload.amount_minor,
            note: payload.note,
        });
        lockedPayloadRef.current = lockedPayload;

        const endpoint = isContribute
            ? `/savings-goals/${goal.id}/contributions`
            : `/savings-goals/${goal.id}/withdrawals`;

        try {
            const response = await axios.post<{
                data: {
                    event: SavingsGoalEventRecord;
                    goal: SavingsGoalProgressRecord;
                };
                meta?: {
                    was_recently_created?: boolean;
                    idempotent_replay?: boolean;
                };
            }>(endpoint, payload, { headers: { Accept: 'application/json' } });

            setSubmissionState('idle');
            lockedPayloadRef.current = null;
            onPendingChange(null);
            onSuccess({
                mode,
                goal: response.data.data.goal,
                event: response.data.data.event,
                wasRecentlyCreated: Boolean(response.data.meta?.was_recently_created),
                idempotentReplay: Boolean(response.data.meta?.idempotent_replay),
            });
        } catch (error: unknown) {
            if (axios.isAxiosError(error)) {
                const status = error.response?.status;
                const errors = error.response?.data?.errors as
                    | Record<string, string[] | string>
                    | undefined;

                if (status === 409) {
                    const banner = {
                        variant: 'warning' as const,
                        message:
                            firstValidationMessage(errors, ['idempotency_key']) ??
                            'This allocation key was already used with different details. Click “Start new attempt” to continue with a fresh key.',
                    };
                    setSubmissionState('conflict_blocked');
                    setFieldErrors(mapFieldErrors(errors));
                    setFormBanner(banner);
                    persistPending('conflict_blocked', banner, idempotencyKey, lockedPayload, draft);
                } else if (status === 422) {
                    setSubmissionState('idle');
                    lockedPayloadRef.current = null;
                    onPendingChange(null);
                    const mapped = mapFieldErrors(errors);
                    if (mapped.amount_minor && !mapped.amount) {
                        mapped.amount = mapped.amount_minor;
                    }
                    setFieldErrors(mapped);
                    setFormBanner({
                        variant: 'negative',
                        message:
                            firstValidationMessage(errors, [
                                'amount_minor',
                                'amount',
                                'note',
                                'goal',
                                'idempotency_key',
                            ]) ?? 'Please correct the highlighted fields.',
                    });
                    if (!isContribute) {
                        onAuthoritativeGoalRefresh?.(goal.id);
                    }
                } else if (status === undefined || status >= 500 || !error.response) {
                    const banner = {
                        variant: 'warning' as const,
                        message:
                            'The allocation request did not complete clearly. Equity preserved the original details and idempotency key. Retry the same request, or start a new attempt if you need different details (the original allocation may already exist).',
                    };
                    setSubmissionState('uncertain');
                    setFormBanner(banner);
                    persistPending('uncertain', banner, idempotencyKey, lockedPayload, draft);
                } else {
                    setSubmissionState('idle');
                    lockedPayloadRef.current = null;
                    onPendingChange(null);
                    setFormBanner({
                        variant: 'negative',
                        message: tenantSafeMutationMessage(
                            status,
                            isContribute
                                ? 'Unable to allocate to this savings goal.'
                                : 'Unable to reduce the allocation for this savings goal.',
                        ),
                    });
                }
            } else {
                const banner = {
                    variant: 'warning' as const,
                    message:
                        'The allocation request did not complete clearly. Equity preserved the original details and idempotency key for a safe retry.',
                };
                setSubmissionState('uncertain');
                setFormBanner(banner);
                persistPending('uncertain', banner, idempotencyKey, lockedPayload, draft);
            }
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6" role="presentation">
            <div
                className="fixed inset-0 bg-ink/50 backdrop-blur-[1px]"
                aria-hidden="true"
                onClick={() => {
                    // Closing while a request is in flight is allowed so the user can
                    // navigate away; the in-flight attempt still resolves to onSuccess/onPendingChange.
                    onClose();
                }}
            />

            <div
                ref={panelRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-describedby={descriptionId}
                className="relative z-10 max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl border border-border-subtle bg-surface-elevated p-6 text-ink shadow-xl sm:p-7"
            >
                <div className="space-y-2">
                    <p className="font-mono text-xs font-medium tracking-wider text-accent uppercase">
                        {isContribute ? 'Allocate' : 'Reduce'}
                    </p>
                    <h2 id={titleId} className="text-lg font-semibold tracking-tight text-ink">
                        {isContribute ? 'Allocate to goal' : 'Reduce allocation'}
                    </h2>
                    <p id={descriptionId} className="text-sm leading-relaxed text-ink-muted">
                        {isContribute
                            ? 'Earmark an amount toward this goal. Allocations track intent inside Equity — they do not transfer bank funds or change account balances.'
                            : 'Reduce the earmarked amount on this goal. This does not withdraw money from a bank account or change ledger balances.'}
                    </p>
                </div>

                <dl className="mt-4 grid grid-cols-1 gap-2 rounded-xl border border-border-subtle bg-surface-muted/60 px-3.5 py-3 sm:grid-cols-3">
                    <div>
                        <dt className="font-mono text-[10px] tracking-wider text-ink-muted uppercase">
                            Goal
                        </dt>
                        <dd className="mt-0.5 text-sm font-medium text-ink">{goal.name}</dd>
                    </div>
                    <div>
                        <dt className="font-mono text-[10px] tracking-wider text-ink-muted uppercase">
                            Currency
                        </dt>
                        <dd className="mt-0.5 font-mono text-sm text-ink">{goal.currency_code}</dd>
                    </div>
                    <div>
                        <dt className="font-mono text-[10px] tracking-wider text-ink-muted uppercase">
                            Allocated
                        </dt>
                        <dd className="mt-0.5 text-sm font-medium tabular-nums text-ink">
                            {allocatedDisplay}
                        </dd>
                    </div>
                </dl>

                {formBanner ? (
                    <div className="mt-4">
                        <Alert variant={formBanner.variant}>{formBanner.message}</Alert>
                    </div>
                ) : null}

                <form className="mt-5 space-y-4" onSubmit={(event) => void submit(event)}>
                    <FormField
                        ref={amountInputRef}
                        id="savings-allocation-amount"
                        label="Amount"
                        inputMode="decimal"
                        value={draft.amount}
                        onChange={(event) => updateDraft('amount', event.target.value)}
                        error={fieldErrors.amount ?? fieldErrors.amount_minor}
                        hint={
                            usableExponent === null
                                ? 'Currency metadata is required before an amount can be submitted.'
                                : isContribute
                                  ? `Enter a major-unit amount using up to ${usableExponent} decimal place${
                                        usableExponent === 1 ? '' : 's'
                                    }. Overfunding above the target is allowed.`
                                  : `Enter up to the currently allocated amount (${allocatedDisplay}), using at most ${usableExponent} decimal place${
                                        usableExponent === 1 ? '' : 's'
                                    }.`
                        }
                        required
                        disabled={fieldsLocked || saving || usableExponent === null}
                        autoComplete="off"
                    />

                    <div className="space-y-1.5">
                        <label
                            htmlFor="savings-allocation-note"
                            className="block text-sm font-medium text-ink"
                        >
                            Note
                        </label>
                        <Textarea
                            id="savings-allocation-note"
                            value={draft.note}
                            rows={3}
                            maxLength={500}
                            invalid={Boolean(fieldErrors.note)}
                            disabled={fieldsLocked || saving}
                            onChange={(event) => updateDraft('note', event.target.value)}
                        />
                        {fieldErrors.note ? (
                            <p role="alert" className="text-xs font-medium text-negative">
                                {fieldErrors.note}
                            </p>
                        ) : (
                            <p className="text-xs text-ink-muted">Optional. Maximum 500 characters.</p>
                        )}
                    </div>

                    {fieldErrors.goal ? <Alert variant="negative">{fieldErrors.goal}</Alert> : null}

                    {submissionState === 'conflict_blocked' || submissionState === 'uncertain' ? (
                        <div className="flex flex-wrap gap-2">
                            {submissionState === 'uncertain' ? (
                                <Button
                                    type="submit"
                                    variant="primary"
                                    size="sm"
                                    loading={saving}
                                    disabled={saving}
                                >
                                    Retry same request
                                </Button>
                            ) : null}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={saving}
                                onClick={startNewAttempt}
                            >
                                Start new attempt
                            </Button>
                        </div>
                    ) : null}

                    <div className="flex flex-col-reverse justify-end gap-2 pt-2 sm:flex-row">
                        <Button type="button" variant="outline" size="sm" onClick={onClose}>
                            Cancel
                        </Button>
                        {submissionState !== 'conflict_blocked' ? (
                            <Button
                                type="submit"
                                variant="primary"
                                size="sm"
                                loading={saving}
                                disabled={
                                    saving ||
                                    submissionState === 'uncertain' ||
                                    usableExponent === null ||
                                    (!isContribute && !hasAllocation)
                                }
                            >
                                {submissionState === 'uncertain'
                                    ? 'Use retry above'
                                    : isContribute
                                      ? 'Allocate to goal'
                                      : 'Reduce allocation'}
                            </Button>
                        ) : null}
                    </div>
                </form>
            </div>
        </div>
    );
}
