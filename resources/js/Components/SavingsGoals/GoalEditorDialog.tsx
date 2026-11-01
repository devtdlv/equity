import axios from 'axios';
import {
    type FormEvent,
    useEffect,
    useId,
    useMemo,
    useRef,
    useState,
} from 'react';
import { Alert } from '@/Components/Alert';
import { Button } from '@/Components/Button';
import { FormField } from '@/Components/FormField';
import { Select } from '@/Components/Select';
import { Textarea } from '@/Components/Textarea';
import type { CurrencyOption, SavingsGoalProgressRecord } from '@/Types';
import {
    decimalMajorToPositiveMinorUnitString,
    minorUnitsToMajorDecimalString,
    savingsGoalCreateDraftFingerprint,
    SavingsMoneyFormatError,
} from '@/Utilities/savingsMoney';
import { generateUuidV4 } from '@/Utilities/uuid';

export type GoalEditorMode = 'create' | 'edit';

type FieldErrors = Record<string, string | undefined>;

type CreateSubmissionState = 'idle' | 'submitting' | 'conflict_blocked' | 'uncertain';

export type PendingCreateGoalAttempt = {
    draft: {
        name: string;
        currency_code: string;
        target_amount: string;
        description: string;
        target_date: string;
    };
    idempotencyKey: string;
    lockedPayload: string;
    state: 'conflict_blocked' | 'uncertain';
    banner: {
        variant: 'info' | 'positive' | 'warning' | 'negative';
        message: string;
    };
};

export interface GoalEditorDialogProps {
    open: boolean;
    mode: GoalEditorMode;
    currencies: CurrencyOption[];
    goal?: SavingsGoalProgressRecord | null;
    pendingCreateAttempt?: PendingCreateGoalAttempt | null;
    onClose: () => void;
    onPendingCreateChange?: (pending: PendingCreateGoalAttempt | null) => void;
    onSuccess: (result: {
        mode: GoalEditorMode;
        goal: SavingsGoalProgressRecord;
        wasRecentlyCreated?: boolean;
        idempotentReplay?: boolean;
    }) => void;
}

type DraftState = {
    name: string;
    currency_code: string;
    target_amount: string;
    description: string;
    target_date: string;
};

const FOCUSABLE_SELECTOR = [
    'button:not(:disabled)',
    '[href]',
    'input:not(:disabled)',
    'select:not(:disabled)',
    'textarea:not(:disabled)',
    '[tabindex]:not([tabindex="-1"])',
].join(', ');

function emptyDraft(currencyCode: string): DraftState {
    return {
        name: '',
        currency_code: currencyCode,
        target_amount: '',
        description: '',
        target_date: '',
    };
}

function draftFromGoal(goal: SavingsGoalProgressRecord, currencies: CurrencyOption[]): DraftState {
    const currency = currencies.find((item) => item.code === goal.currency_code);
    const exponent = currency?.minor_unit_exponent;

    let targetAmount = '';
    if (typeof exponent === 'number' && Number.isInteger(exponent) && exponent >= 0 && exponent <= 6) {
        try {
            targetAmount = minorUnitsToMajorDecimalString(goal.target_amount_minor, exponent);
        } catch {
            targetAmount = '';
        }
    }

    return {
        name: goal.name,
        currency_code: goal.currency_code,
        target_amount: targetAmount,
        description: goal.description ?? '',
        target_date: goal.target_date ?? '',
    };
}

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
        return 'You do not have permission to manage this savings goal.';
    }
    if (status !== undefined && status >= 500) {
        return 'The server could not confirm this request. Your details were preserved for a safe retry.';
    }
    return fallback;
}

export function GoalEditorDialog({
    open,
    mode,
    currencies,
    goal = null,
    pendingCreateAttempt = null,
    onClose,
    onPendingCreateChange,
    onSuccess,
}: GoalEditorDialogProps) {
    const titleId = useId();
    const descriptionId = useId();
    const panelRef = useRef<HTMLDivElement | null>(null);
    const nameInputRef = useRef<HTMLInputElement | null>(null);
    const previousFocusRef = useRef<HTMLElement | null>(null);
    const fingerprintRef = useRef('');
    const lockedPayloadRef = useRef<string | null>(null);
    const fieldsLockedRef = useRef(false);

    const defaultCurrency = currencies[0]?.code ?? '';
    const [draft, setDraft] = useState<DraftState>(() => emptyDraft(defaultCurrency));
    const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
    const [formBanner, setFormBanner] = useState<{
        variant: 'info' | 'positive' | 'warning' | 'negative';
        message: string;
    } | null>(null);
    const [idempotencyKey, setIdempotencyKey] = useState(() => generateUuidV4());
    const [createState, setCreateState] = useState<CreateSubmissionState>('idle');
    const [saving, setSaving] = useState(false);

    const currencyOptions = useMemo(
        () =>
            currencies.map((currency) => ({
                value: currency.code,
                label: `${currency.code} — ${currency.name}`,
            })),
        [currencies],
    );

    const selectedCurrency = useMemo(
        () => currencies.find((currency) => currency.code === draft.currency_code),
        [currencies, draft.currency_code],
    );

    const isCreate = mode === 'create';
    const fieldsLocked = isCreate && (createState === 'conflict_blocked' || createState === 'uncertain');
    fieldsLockedRef.current = fieldsLocked;

    useEffect(() => {
        if (!open) {
            return;
        }

        previousFocusRef.current =
            document.activeElement instanceof HTMLElement ? document.activeElement : null;

        const restorePending = isCreate && pendingCreateAttempt !== null;

        if (mode === 'edit' && goal) {
            fieldsLockedRef.current = false;
            setDraft(draftFromGoal(goal, currencies));
            setFieldErrors({});
            setFormBanner(null);
            setCreateState('idle');
            setSaving(false);
            lockedPayloadRef.current = null;
        } else if (restorePending && pendingCreateAttempt) {
            fieldsLockedRef.current = true;
            setDraft({ ...pendingCreateAttempt.draft });
            setIdempotencyKey(pendingCreateAttempt.idempotencyKey);
            lockedPayloadRef.current = pendingCreateAttempt.lockedPayload;
            fingerprintRef.current = savingsGoalCreateDraftFingerprint(pendingCreateAttempt.draft);
            setCreateState(pendingCreateAttempt.state);
            setFormBanner(pendingCreateAttempt.banner);
            setFieldErrors({});
            setSaving(false);
        } else {
            fieldsLockedRef.current = false;
            const next = emptyDraft(currencies[0]?.code ?? '');
            setDraft(next);
            fingerprintRef.current = savingsGoalCreateDraftFingerprint(next);
            setIdempotencyKey(generateUuidV4());
            lockedPayloadRef.current = null;
            setFieldErrors({});
            setFormBanner(null);
            setCreateState('idle');
            setSaving(false);
        }

        const focusTimer = window.setTimeout(() => {
            nameInputRef.current?.focus();
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
        // Open-gated initialization only; pending restore intentionally reads latest props here.
        // eslint-disable-next-line react-hooks/exhaustive-deps -- intentional open-gated init
    }, [open, mode, goal?.id, isCreate]);

    useEffect(() => {
        if (!open || !isCreate || fieldsLockedRef.current) {
            return;
        }

        const fingerprint = savingsGoalCreateDraftFingerprint(draft);
        if (fingerprintRef.current === '') {
            fingerprintRef.current = fingerprint;
            return;
        }

        if (fingerprint !== fingerprintRef.current) {
            fingerprintRef.current = fingerprint;
            setIdempotencyKey(generateUuidV4());
        }
    }, [draft, open, isCreate, createState]);

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
        onPendingCreateChange?.({
            draft: { ...nextDraft },
            idempotencyKey: key,
            lockedPayload: payload,
            state,
            banner,
        });
    };

    const startNewCreateAttempt = () => {
        fieldsLockedRef.current = false;
        setIdempotencyKey(generateUuidV4());
        setCreateState('idle');
        lockedPayloadRef.current = null;
        fingerprintRef.current = savingsGoalCreateDraftFingerprint(draft);
        onPendingCreateChange?.(null);
        setFormBanner({
            variant: 'info',
            message:
                'Started a new create attempt with a fresh idempotency key. The original request may already have succeeded — check your goals before submitting again.',
        });
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        if (saving || createState === 'conflict_blocked') {
            return;
        }

        if (!selectedCurrency) {
            setFieldErrors({ currency_code: 'Select a supported currency.' });
            return;
        }

        let targetAmountMinor: string;
        try {
            targetAmountMinor = decimalMajorToPositiveMinorUnitString(
                draft.target_amount,
                selectedCurrency.minor_unit_exponent,
            );
        } catch (error) {
            const message =
                error instanceof SavingsMoneyFormatError
                    ? error.message
                    : 'Enter a valid target amount.';
            setFieldErrors({ target_amount_minor: message, target_amount: message });
            return;
        }

        const payloadBase = {
            name: draft.name.trim(),
            description: draft.description.trim() === '' ? null : draft.description.trim(),
            target_amount_minor: targetAmountMinor,
            target_date: draft.target_date.trim() === '' ? null : draft.target_date.trim(),
        };

        if (isCreate && createState === 'uncertain' && lockedPayloadRef.current !== null) {
            const currentFingerprint = JSON.stringify({
                ...payloadBase,
                currency_code: draft.currency_code,
            });
            if (currentFingerprint !== lockedPayloadRef.current) {
                setFormBanner({
                    variant: 'warning',
                    message:
                        'This create attempt is locked to the original payload after an uncertain response. Click “Start new attempt” before changing details.',
                });
                return;
            }
        }

        setSaving(true);
        setFieldErrors({});
        setFormBanner(null);
        if (isCreate) {
            setCreateState('submitting');
        }

        try {
            if (isCreate) {
                const createPayload = {
                    ...payloadBase,
                    currency_code: draft.currency_code,
                    idempotency_key: idempotencyKey,
                };
                const lockedPayload = JSON.stringify({
                    ...payloadBase,
                    currency_code: draft.currency_code,
                });
                lockedPayloadRef.current = lockedPayload;

                const response = await axios.post<{
                    data: SavingsGoalProgressRecord;
                    meta?: {
                        was_recently_created?: boolean;
                        idempotent_replay?: boolean;
                    };
                }>('/savings-goals', createPayload, {
                    headers: { Accept: 'application/json' },
                });

                const wasRecentlyCreated = Boolean(response.data.meta?.was_recently_created);
                const idempotentReplay = Boolean(response.data.meta?.idempotent_replay);

                setCreateState('idle');
                lockedPayloadRef.current = null;
                onPendingCreateChange?.(null);
                onSuccess({
                    mode: 'create',
                    goal: response.data.data,
                    wasRecentlyCreated,
                    idempotentReplay,
                });
            } else if (goal) {
                const response = await axios.patch<{ data: SavingsGoalProgressRecord }>(
                    `/savings-goals/${goal.id}`,
                    payloadBase,
                    { headers: { Accept: 'application/json' } },
                );

                onSuccess({
                    mode: 'edit',
                    goal: response.data.data,
                });
            }
        } catch (error: unknown) {
            if (axios.isAxiosError(error)) {
                const status = error.response?.status;
                const errors = error.response?.data?.errors as
                    | Record<string, string[] | string>
                    | undefined;

                if (status === 409 && isCreate) {
                    const banner = {
                        variant: 'warning' as const,
                        message:
                            firstValidationMessage(errors, ['idempotency_key']) ??
                            'This create key was already used with different details. Click “Start new attempt” to continue with a fresh key.',
                    };
                    const lockedPayload =
                        lockedPayloadRef.current ??
                        JSON.stringify({
                            ...payloadBase,
                            currency_code: draft.currency_code,
                        });
                    setCreateState('conflict_blocked');
                    setFieldErrors(mapFieldErrors(errors));
                    setFormBanner(banner);
                    persistPending('conflict_blocked', banner, idempotencyKey, lockedPayload, draft);
                } else if (status === 422) {
                    if (isCreate) {
                        setCreateState('idle');
                        lockedPayloadRef.current = null;
                        onPendingCreateChange?.(null);
                    }
                    const mapped = mapFieldErrors(errors);
                    if (mapped.target_amount_minor && !mapped.target_amount) {
                        mapped.target_amount = mapped.target_amount_minor;
                    }
                    setFieldErrors(mapped);
                    setFormBanner({
                        variant: 'negative',
                        message:
                            firstValidationMessage(errors, [
                                'name',
                                'currency_code',
                                'target_amount_minor',
                                'target_date',
                                'description',
                                'goal',
                                'idempotency_key',
                            ]) ?? 'Please correct the highlighted fields.',
                    });
                } else if (status === undefined || status >= 500 || !error.response) {
                    if (isCreate) {
                        const banner = {
                            variant: 'warning' as const,
                            message:
                                'The create request did not complete clearly. Equity preserved the original details and idempotency key. Retry the same request, or start a new attempt if you need different details (the original goal may already exist).',
                        };
                        const lockedPayload =
                            lockedPayloadRef.current ??
                            JSON.stringify({
                                ...payloadBase,
                                currency_code: draft.currency_code,
                            });
                        setCreateState('uncertain');
                        setFormBanner(banner);
                        persistPending('uncertain', banner, idempotencyKey, lockedPayload, draft);
                    } else {
                        setFormBanner({
                            variant: 'warning',
                            message: tenantSafeMutationMessage(
                                status,
                                'The update could not be confirmed. Refresh the goal before editing again.',
                            ),
                        });
                    }
                } else {
                    if (isCreate) {
                        setCreateState('idle');
                        lockedPayloadRef.current = null;
                        onPendingCreateChange?.(null);
                    }
                    setFormBanner({
                        variant: 'negative',
                        message: tenantSafeMutationMessage(
                            status,
                            'Unable to save this savings goal.',
                        ),
                    });
                }
            } else if (isCreate) {
                const banner = {
                    variant: 'warning' as const,
                    message:
                        'The create request did not complete clearly. Equity preserved the original details and idempotency key for a safe retry.',
                };
                const lockedPayload =
                    lockedPayloadRef.current ??
                    JSON.stringify({
                        ...payloadBase,
                        currency_code: draft.currency_code,
                    });
                setCreateState('uncertain');
                setFormBanner(banner);
                persistPending('uncertain', banner, idempotencyKey, lockedPayload, draft);
            } else {
                setFormBanner({
                    variant: 'negative',
                    message: 'Unable to save this savings goal.',
                });
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
                        {isCreate ? 'Create' : 'Edit'}
                    </p>
                    <h2 id={titleId} className="text-lg font-semibold tracking-tight text-ink">
                        {isCreate ? 'Create savings goal' : 'Edit savings goal'}
                    </h2>
                    <p id={descriptionId} className="text-sm leading-relaxed text-ink-muted">
                        {isCreate
                            ? 'Earmark a target amount. Creating a goal does not move money between accounts.'
                            : 'Update the target and details. Currency cannot be changed after creation.'}
                    </p>
                </div>

                {formBanner ? (
                    <div className="mt-4">
                        <Alert variant={formBanner.variant}>{formBanner.message}</Alert>
                    </div>
                ) : null}

                <form className="mt-5 space-y-4" onSubmit={(event) => void submit(event)}>
                    <FormField
                        ref={nameInputRef}
                        id="savings-goal-name"
                        label="Name"
                        value={draft.name}
                        onChange={(event) => updateDraft('name', event.target.value)}
                        error={fieldErrors.name}
                        maxLength={120}
                        required
                        disabled={fieldsLocked || saving}
                        autoComplete="off"
                    />

                    {isCreate ? (
                        <div className="space-y-1.5">
                            <label
                                htmlFor="savings-goal-currency"
                                className="block text-sm font-medium text-ink"
                            >
                                Currency
                            </label>
                            <Select
                                id="savings-goal-currency"
                                value={draft.currency_code}
                                options={currencyOptions}
                                invalid={Boolean(fieldErrors.currency_code)}
                                disabled={fieldsLocked || saving || currencyOptions.length === 0}
                                onChange={(event) => updateDraft('currency_code', event.target.value)}
                            />
                            {fieldErrors.currency_code ? (
                                <p role="alert" className="text-xs font-medium text-negative">
                                    {fieldErrors.currency_code}
                                </p>
                            ) : null}
                        </div>
                    ) : (
                        <div className="space-y-1.5">
                            <p className="block text-sm font-medium text-ink">Currency</p>
                            <p className="rounded-lg border border-border-subtle bg-surface-muted px-3.5 py-2 font-mono text-sm text-ink">
                                {draft.currency_code}
                            </p>
                            <p className="text-xs text-ink-muted">Currency is fixed after creation.</p>
                        </div>
                    )}

                    <FormField
                        id="savings-goal-target"
                        label="Target amount"
                        inputMode="decimal"
                        value={draft.target_amount}
                        onChange={(event) => updateDraft('target_amount', event.target.value)}
                        error={fieldErrors.target_amount ?? fieldErrors.target_amount_minor}
                        hint={
                            selectedCurrency
                                ? `Enter a major-unit amount using up to ${selectedCurrency.minor_unit_exponent} decimal place${
                                      selectedCurrency.minor_unit_exponent === 1 ? '' : 's'
                                  }.`
                                : 'Select a currency to enter a target amount.'
                        }
                        required
                        disabled={fieldsLocked || saving || !selectedCurrency}
                        autoComplete="off"
                    />

                    <div className="space-y-1.5">
                        <label
                            htmlFor="savings-goal-description"
                            className="block text-sm font-medium text-ink"
                        >
                            Description
                        </label>
                        <Textarea
                            id="savings-goal-description"
                            value={draft.description}
                            rows={3}
                            maxLength={500}
                            invalid={Boolean(fieldErrors.description)}
                            disabled={fieldsLocked || saving}
                            onChange={(event) => updateDraft('description', event.target.value)}
                        />
                        {fieldErrors.description ? (
                            <p role="alert" className="text-xs font-medium text-negative">
                                {fieldErrors.description}
                            </p>
                        ) : (
                            <p className="text-xs text-ink-muted">Optional. Maximum 500 characters.</p>
                        )}
                    </div>

                    <FormField
                        id="savings-goal-target-date"
                        label="Target date"
                        type="date"
                        value={draft.target_date}
                        onChange={(event) => updateDraft('target_date', event.target.value)}
                        error={fieldErrors.target_date}
                        hint="Optional."
                        disabled={fieldsLocked || saving}
                    />

                    {fieldErrors.goal ? <Alert variant="negative">{fieldErrors.goal}</Alert> : null}

                    {isCreate && (createState === 'conflict_blocked' || createState === 'uncertain') ? (
                        <div className="flex flex-wrap gap-2">
                            {createState === 'uncertain' ? (
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
                                onClick={startNewCreateAttempt}
                            >
                                Start new attempt
                            </Button>
                        </div>
                    ) : null}

                    <div className="flex flex-col-reverse justify-end gap-2 pt-2 sm:flex-row">
                        <Button type="button" variant="outline" size="sm" onClick={onClose}>
                            Cancel
                        </Button>
                        {createState !== 'conflict_blocked' ? (
                            <Button
                                type="submit"
                                variant="primary"
                                size="sm"
                                loading={saving}
                                disabled={saving || (isCreate && createState === 'uncertain')}
                            >
                                {isCreate
                                    ? createState === 'uncertain'
                                        ? 'Use retry above'
                                        : 'Create goal'
                                    : 'Save changes'}
                            </Button>
                        ) : null}
                    </div>
                </form>
            </div>
        </div>
    );
}
