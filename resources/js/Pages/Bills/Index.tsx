import { usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    type FormEvent,
    type ReactNode,
    useCallback,
    useEffect,
    useId,
    useMemo,
    useRef,
    useState,
} from 'react';
import { Alert } from '@/Components/Alert';
import { Badge } from '@/Components/Badge';
import { Button } from '@/Components/Button';
import { Checkbox } from '@/Components/Checkbox';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { EmptyState } from '@/Components/EmptyState';
import { FormField } from '@/Components/FormField';
import { PageHeader } from '@/Components/PageHeader';
import { Select } from '@/Components/Select';
import { Skeleton } from '@/Components/Skeleton';
import { Textarea } from '@/Components/Textarea';
import { AuthenticatedLayout } from '@/Layouts/AuthenticatedLayout';
import type {
    BillsIndexPageProps,
    CategoryRecord,
    CurrencyOption,
    FinancialTransactionItem,
    PaginationMeta,
    RecurringFrequencyValue,
    RecurringOccurrenceDisplayStatusValue,
    RecurringOccurrenceRecord,
    RecurringObligationRecord,
    RecurringPaymentLinkRecord,
    SharedPageProps,
    TransactionAccountOption,
} from '@/Types';
import {
    buildBillsOverviewSummary,
    displayStatusLabel,
    frequencyLabel,
    obligationDraftFingerprint,
    obligationStatusLabel,
} from '@/Utilities/billsSummary';
import { formatMinorUnits, minorUnitsToDecimalString } from '@/Utilities/money';
import { generateUuidV4 } from '@/Utilities/uuid';

type BannerState = {
    variant: 'info' | 'positive' | 'warning' | 'negative';
    message: string;
} | null;

type LoadState = 'idle' | 'loading' | 'ready' | 'error';

type ObligationDraft = {
    name: string;
    description: string;
    amount: string;
    currency_code: string;
    category_id: string;
    financial_account_id: string;
    start_date: string;
    frequency: RecurringFrequencyValue;
    anchor_day: string;
    ends_of_month: boolean;
};

type FieldErrors = Record<string, string | undefined>;

const FREQUENCY_OPTIONS: Array<{ value: RecurringFrequencyValue; label: string }> = [
    { value: 'weekly', label: 'Weekly' },
    { value: 'biweekly', label: 'Biweekly' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'quarterly', label: 'Quarterly' },
    { value: 'yearly', label: 'Yearly' },
];

function emptyDraft(currencyCode: string, today: string): ObligationDraft {
    const day = Number(today.slice(8, 10));

    return {
        name: '',
        description: '',
        amount: '',
        currency_code: currencyCode,
        category_id: '',
        financial_account_id: '',
        start_date: today,
        frequency: 'monthly',
        anchor_day: String(Number.isFinite(day) && day >= 1 ? day : 1),
        ends_of_month: false,
    };
}

function draftFromObligation(obligation: RecurringObligationRecord): ObligationDraft {
    const exponent = obligation.currency?.minor_unit_exponent ?? 2;

    return {
        name: obligation.name,
        description: obligation.description ?? '',
        amount: minorUnitsToDecimalString(obligation.amount_minor, exponent),
        currency_code: obligation.currency_code,
        category_id: String(obligation.category_id),
        financial_account_id:
            obligation.financial_account_id !== null ? String(obligation.financial_account_id) : '',
        start_date: obligation.start_date,
        frequency: obligation.frequency,
        anchor_day: String(obligation.anchor_day),
        ends_of_month: obligation.ends_of_month,
    };
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

function tenantSafeApiMessage(
    status: number | undefined,
    fallback: string,
    serverMessage?: string,
): string {
    if (status === 401) {
        return 'Your session has expired. Please sign in again to continue.';
    }
    if (status === 403) {
        return 'You do not have permission to manage this bill.';
    }
    if (status === 404) {
        return 'That bill or occurrence could not be found. Refreshing the list.';
    }
    if ((status === 422 || status === 409) && serverMessage) {
        return serverMessage;
    }
    if (status !== undefined && status >= 500) {
        return 'The server could not complete this request. Please try again shortly.';
    }

    return fallback;
}

function monthBounds(year: number, month: number): { from: string; to: string } {
    const lastDay = new Date(Date.UTC(year, month, 0)).getUTCDate();
    const mm = String(month).padStart(2, '0');

    return {
        from: `${year}-${mm}-01`,
        to: `${year}-${mm}-${String(lastDay).padStart(2, '0')}`,
    };
}

function displayStatusBadgeVariant(
    status: RecurringOccurrenceDisplayStatusValue,
): 'neutral' | 'accent' | 'positive' | 'warning' | 'negative' {
    switch (status) {
        case 'paid':
            return 'positive';
        case 'partially_paid':
            return 'warning';
        case 'overdue':
            return 'negative';
        case 'due':
            return 'accent';
        case 'skipped':
        case 'cancelled':
            return 'neutral';
        default:
            return 'neutral';
    }
}

function supportsEndsOfMonth(frequency: RecurringFrequencyValue): boolean {
    return frequency === 'monthly' || frequency === 'quarterly' || frequency === 'yearly';
}

export default function BillsIndex() {
    const page = usePage<SharedPageProps & BillsIndexPageProps>();
    const currencies = useMemo(() => page.props.currencies ?? [], [page.props.currencies]);
    const categories = useMemo(() => page.props.categories ?? [], [page.props.categories]);
    const accounts = useMemo(() => page.props.accounts ?? [], [page.props.accounts]);
    const today = page.props.today ?? new Date().toISOString().slice(0, 10);
    const initialYear = page.props.initial_year ?? Number(today.slice(0, 4));
    const initialMonth = page.props.initial_month ?? Number(today.slice(5, 7));

    const [loadState, setLoadState] = useState<LoadState>('loading');
    const [banner, setBanner] = useState<BannerState>(null);
    const [obligations, setObligations] = useState<RecurringObligationRecord[]>([]);
    const [overviewOccurrences, setOverviewOccurrences] = useState<RecurringOccurrenceRecord[]>(
        [],
    );
    const [occurrences, setOccurrences] = useState<RecurringOccurrenceRecord[]>([]);
    const [occurrenceMeta, setOccurrenceMeta] = useState<PaginationMeta | null>(null);

    const [filterFrom, setFilterFrom] = useState(() => monthBounds(initialYear, initialMonth).from);
    const [filterTo, setFilterTo] = useState(() => monthBounds(initialYear, initialMonth).to);
    const [filterCurrency, setFilterCurrency] = useState('');
    const [filterObligationId, setFilterObligationId] = useState('');
    const [filterStatus, setFilterStatus] = useState('');
    const [occurrencePage, setOccurrencePage] = useState(1);

    const [editorOpen, setEditorOpen] = useState(false);
    const [editingObligation, setEditingObligation] = useState<RecurringObligationRecord | null>(
        null,
    );
    const [draft, setDraft] = useState<ObligationDraft>(() =>
        emptyDraft(currencies[0]?.code ?? 'USD', today),
    );
    const [draftErrors, setDraftErrors] = useState<FieldErrors>({});
    const [savingDraft, setSavingDraft] = useState(false);
    const [idempotencyKey, setIdempotencyKey] = useState(() => generateUuidV4());
    const [idempotencyLocked, setIdempotencyLocked] = useState(false);
    const fingerprintRef = useRef('');

    const [detailOccurrence, setDetailOccurrence] = useState<RecurringOccurrenceRecord | null>(
        null,
    );
    const [linkOccurrence, setLinkOccurrence] = useState<RecurringOccurrenceRecord | null>(null);
    const [candidateTransactions, setCandidateTransactions] = useState<FinancialTransactionItem[]>(
        [],
    );
    const [loadingCandidates, setLoadingCandidates] = useState(false);
    const [selectedTransactionId, setSelectedTransactionId] = useState<number | null>(null);
    const [linking, setLinking] = useState(false);

    const [confirmSkip, setConfirmSkip] = useState<RecurringOccurrenceRecord | null>(null);
    const [confirmUnlink, setConfirmUnlink] = useState<{
        occurrence: RecurringOccurrenceRecord;
        link: RecurringPaymentLinkRecord;
    } | null>(null);
    const [confirmCancel, setConfirmCancel] = useState<RecurringObligationRecord | null>(null);
    const [actionBusy, setActionBusy] = useState(false);

    const requestSeq = useRef(0);
    const filtersId = useId();

    const expenseCategories = useMemo(
        () => categories.filter((category) => category.type === 'expense' && !category.is_archived),
        [categories],
    );

    const currencyByCode = useMemo(() => {
        const map = new Map<string, CurrencyOption>();
        currencies.forEach((currency) => map.set(currency.code, currency));
        return map;
    }, [currencies]);

    const overview = useMemo(
        () =>
            buildBillsOverviewSummary(
                obligations,
                overviewOccurrences,
                initialYear,
                initialMonth,
            ),
        [obligations, overviewOccurrences, initialYear, initialMonth],
    );

    const formatAmount = useCallback(
        (amountMinor: number, currencyCode: string) => {
            const currency = currencyByCode.get(currencyCode);
            return formatMinorUnits(
                amountMinor,
                currencyCode,
                currency?.minor_unit_exponent ?? 2,
                currency?.symbol ?? '',
            );
        },
        [currencyByCode],
    );

    const preferredAccounts = useMemo(() => {
        return accounts.filter(
            (account) =>
                !account.is_archived &&
                (draft.currency_code === '' || account.currency_code === draft.currency_code),
        );
    }, [accounts, draft.currency_code]);

    const refreshAll = useCallback(async () => {
        const seq = ++requestSeq.current;
        setLoadState('loading');
        setBanner(null);

        try {
            const month = monthBounds(initialYear, initialMonth);
            const [obligationsResponse, overviewResponse, occurrencesResponse] = await Promise.all([
                axios.get<{ data: RecurringObligationRecord[] }>('/recurring-obligations', {
                    params: { per_page: 100 },
                    headers: { Accept: 'application/json' },
                }),
                axios.get<{ data: RecurringOccurrenceRecord[] }>('/recurring-occurrences', {
                    params: {
                        from_date: month.from,
                        to_date: month.to,
                        per_page: 100,
                    },
                    headers: { Accept: 'application/json' },
                }),
                axios.get<{
                    data: RecurringOccurrenceRecord[];
                    meta: PaginationMeta;
                }>('/recurring-occurrences', {
                    params: {
                        from_date: filterFrom || undefined,
                        to_date: filterTo || undefined,
                        currency_code: filterCurrency || undefined,
                        obligation_id: filterObligationId || undefined,
                        status: filterStatus || undefined,
                        page: occurrencePage,
                        per_page: 15,
                    },
                    headers: { Accept: 'application/json' },
                }),
            ]);

            if (seq !== requestSeq.current) {
                return;
            }

            setObligations(obligationsResponse.data.data ?? []);
            setOverviewOccurrences(overviewResponse.data.data ?? []);
            setOccurrences(occurrencesResponse.data.data ?? []);
            setOccurrenceMeta(occurrencesResponse.data.meta ?? null);
            setLoadState('ready');
        } catch (error) {
            if (seq !== requestSeq.current) {
                return;
            }

            let status: number | undefined;
            let message: string | undefined;
            if (axios.isAxiosError(error)) {
                status = error.response?.status;
                message =
                    typeof error.response?.data?.message === 'string'
                        ? error.response.data.message
                        : undefined;
            }

            setLoadState('error');
            setBanner({
                variant: 'negative',
                message: tenantSafeApiMessage(
                    status,
                    'Unable to load bills and subscriptions. Please try again.',
                    message,
                ),
            });
        }
    }, [
        filterCurrency,
        filterFrom,
        filterObligationId,
        filterStatus,
        filterTo,
        initialMonth,
        initialYear,
        occurrencePage,
    ]);

    useEffect(() => {
        void refreshAll();
    }, [refreshAll]);

    useEffect(() => {
        if (!editorOpen || editingObligation !== null || idempotencyLocked) {
            return;
        }

        const fingerprint = obligationDraftFingerprint(draft);
        if (fingerprintRef.current === '') {
            fingerprintRef.current = fingerprint;
            return;
        }

        if (fingerprint !== fingerprintRef.current) {
            fingerprintRef.current = fingerprint;
            setIdempotencyKey(generateUuidV4());
        }
    }, [draft, editorOpen, editingObligation, idempotencyLocked]);

    const openCreateEditor = () => {
        const next = emptyDraft(currencies[0]?.code ?? 'USD', today);
        setEditingObligation(null);
        setDraft(next);
        setDraftErrors({});
        setIdempotencyKey(generateUuidV4());
        setIdempotencyLocked(false);
        fingerprintRef.current = obligationDraftFingerprint(next);
        setEditorOpen(true);
        setBanner(null);
    };

    const openEditEditor = (obligation: RecurringObligationRecord) => {
        const next = draftFromObligation(obligation);
        setEditingObligation(obligation);
        setDraft(next);
        setDraftErrors({});
        setIdempotencyLocked(false);
        fingerprintRef.current = obligationDraftFingerprint(next);
        setEditorOpen(true);
        setBanner(null);
    };

    const closeEditor = () => {
        if (savingDraft) {
            return;
        }
        setEditorOpen(false);
        setEditingObligation(null);
        setDraftErrors({});
        setIdempotencyLocked(false);
    };

    const submitObligation = async (event: FormEvent) => {
        event.preventDefault();
        if (savingDraft || idempotencyLocked) {
            return;
        }

        setSavingDraft(true);
        setDraftErrors({});
        setBanner(null);

        const payload = {
            name: draft.name.trim(),
            description: draft.description.trim() === '' ? null : draft.description.trim(),
            amount: draft.amount.trim(),
            currency_code: draft.currency_code,
            category_id: Number(draft.category_id),
            financial_account_id:
                draft.financial_account_id.trim() === ''
                    ? null
                    : Number(draft.financial_account_id),
            start_date: draft.start_date,
            frequency: draft.frequency,
            anchor_day: Number(draft.anchor_day),
            ends_of_month: supportsEndsOfMonth(draft.frequency) ? draft.ends_of_month : false,
        };

        try {
            if (editingObligation) {
                await axios.patch(`/recurring-obligations/${editingObligation.id}`, payload, {
                    headers: { Accept: 'application/json' },
                });
                setBanner({ variant: 'positive', message: 'Bill details updated.' });
            } else {
                const response = await axios.post(
                    '/recurring-obligations',
                    {
                        ...payload,
                        idempotency_key: idempotencyKey,
                    },
                    { headers: { Accept: 'application/json' } },
                );
                const replay = Boolean(response.data?.meta?.idempotent_replay);
                setBanner({
                    variant: 'positive',
                    message: replay
                        ? 'Existing bill returned for this idempotency key.'
                        : 'Bill created and upcoming occurrences generated.',
                });
            }

            setEditorOpen(false);
            setEditingObligation(null);
            await refreshAll();
        } catch (error) {
            if (axios.isAxiosError(error)) {
                const status = error.response?.status;
                const errors = error.response?.data?.errors as
                    | Record<string, string[] | string>
                    | undefined;
                const message =
                    typeof error.response?.data?.message === 'string'
                        ? error.response.data.message
                        : undefined;

                if (status === 409 && !editingObligation) {
                    setIdempotencyLocked(true);
                    setDraftErrors(mapFieldErrors(errors));
                    setBanner({
                        variant: 'warning',
                        message:
                            firstValidationMessage(errors, ['idempotency_key']) ??
                            'This submission conflicts with a previous request using the same idempotency key. Click “Start new attempt” to continue with a fresh key.',
                    });
                } else if (status === 422) {
                    setDraftErrors(mapFieldErrors(errors));
                    setBanner({
                        variant: 'negative',
                        message:
                            firstValidationMessage(errors, [
                                'amount',
                                'start_date',
                                'category_id',
                                'financial_account_id',
                                'frequency',
                                'name',
                            ]) ??
                            tenantSafeApiMessage(status, 'Please correct the highlighted fields.', message),
                    });
                } else {
                    setBanner({
                        variant: 'negative',
                        message: tenantSafeApiMessage(
                            status,
                            'Unable to save this bill. Your details were preserved for retry.',
                            message,
                        ),
                    });
                }
            } else {
                setBanner({
                    variant: 'negative',
                    message: 'Unable to save this bill. Your details were preserved for retry.',
                });
            }
        } finally {
            setSavingDraft(false);
        }
    };

    const startNewCreateAttempt = () => {
        setIdempotencyKey(generateUuidV4());
        setIdempotencyLocked(false);
        fingerprintRef.current = obligationDraftFingerprint(draft);
        setBanner({
            variant: 'info',
            message: 'Started a new create attempt with a fresh idempotency key.',
        });
    };

    const runObligationAction = async (
        obligation: RecurringObligationRecord,
        action: 'pause' | 'resume' | 'cancel',
    ) => {
        setActionBusy(true);
        setBanner(null);
        try {
            await axios.post(`/recurring-obligations/${obligation.id}/${action}`, null, {
                headers: { Accept: 'application/json' },
            });
            setBanner({
                variant: 'positive',
                message:
                    action === 'pause'
                        ? 'Bill paused. New occurrences will not be generated.'
                        : action === 'resume'
                          ? 'Bill resumed.'
                          : 'Bill cancelled.',
            });
            setConfirmCancel(null);
            await refreshAll();
        } catch (error) {
            let status: number | undefined;
            let message: string | undefined;
            let errors: Record<string, string[] | string> | undefined;
            if (axios.isAxiosError(error)) {
                status = error.response?.status;
                message =
                    typeof error.response?.data?.message === 'string'
                        ? error.response.data.message
                        : undefined;
                errors = error.response?.data?.errors;
            }
            setBanner({
                variant: 'negative',
                message:
                    firstValidationMessage(errors, ['obligation']) ??
                    tenantSafeApiMessage(status, `Unable to ${action} this bill.`, message),
            });
        } finally {
            setActionBusy(false);
        }
    };

    const openLinkDialog = async (occurrence: RecurringOccurrenceRecord) => {
        setLinkOccurrence(occurrence);
        setSelectedTransactionId(null);
        setCandidateTransactions([]);
        setLoadingCandidates(true);
        setBanner(null);

        try {
            const obligation = obligations.find((item) => item.id === occurrence.obligation_id);
            const response = await axios.get<{ data: FinancialTransactionItem[] }>(
                '/transactions',
                {
                    params: {
                        type: 'expense',
                        category_id: obligation?.category_id,
                        per_page: 50,
                    },
                    headers: { Accept: 'application/json' },
                },
            );

            const linked = new Set(occurrence.all_linked_transaction_ids);
            const candidates = (response.data.data ?? []).filter(
                (txn) =>
                    txn.currency_code === occurrence.currency_code &&
                    !txn.is_reversal &&
                    !txn.is_reversed &&
                    !linked.has(txn.id) &&
                    (obligation === undefined ||
                        txn.category?.id === undefined ||
                        txn.category.id === obligation.category_id),
            );
            setCandidateTransactions(candidates);
        } catch (error) {
            let status: number | undefined;
            let message: string | undefined;
            if (axios.isAxiosError(error)) {
                status = error.response?.status;
                message =
                    typeof error.response?.data?.message === 'string'
                        ? error.response.data.message
                        : undefined;
            }
            setBanner({
                variant: 'negative',
                message: tenantSafeApiMessage(
                    status,
                    'Unable to load expense candidates for linking.',
                    message,
                ),
            });
            setLinkOccurrence(null);
        } finally {
            setLoadingCandidates(false);
        }
    };

    const submitSkip = async () => {
        if (!confirmSkip || actionBusy) {
            return;
        }
        setActionBusy(true);
        setBanner(null);
        try {
            const response = await axios.post(
                `/recurring-occurrences/${confirmSkip.id}/skip`,
                null,
                { headers: { Accept: 'application/json' } },
            );
            setBanner({ variant: 'positive', message: 'Occurrence skipped for this due date.' });
            setConfirmSkip(null);
            setDetailOccurrence(response.data?.data ?? null);
            await refreshAll();
        } catch (error) {
            let status: number | undefined;
            let message: string | undefined;
            let errors: Record<string, string[] | string> | undefined;
            if (axios.isAxiosError(error)) {
                status = error.response?.status;
                message =
                    typeof error.response?.data?.message === 'string'
                        ? error.response.data.message
                        : undefined;
                errors = error.response?.data?.errors;
            }
            setBanner({
                variant: 'negative',
                message:
                    firstValidationMessage(errors, ['occurrence']) ??
                    tenantSafeApiMessage(status, 'Unable to skip this occurrence.', message),
            });
        } finally {
            setActionBusy(false);
        }
    };

    const openUnlink = (
        occurrence: RecurringOccurrenceRecord,
        link: RecurringPaymentLinkRecord,
    ) => {
        setConfirmUnlink({ occurrence, link });
    };

    const submitPaymentLink = async () => {
        if (!linkOccurrence || selectedTransactionId === null || linking) {
            return;
        }

        setLinking(true);
        setBanner(null);
        try {
            const response = await axios.post(
                `/recurring-occurrences/${linkOccurrence.id}/payment-links`,
                { financial_transaction_id: selectedTransactionId },
                { headers: { Accept: 'application/json' } },
            );
            const replay = Boolean(response.data?.meta?.idempotent_replay);
            setBanner({
                variant: 'positive',
                message: replay
                    ? 'Existing payment link returned for this transaction.'
                    : 'Expense linked to this bill occurrence.',
            });
            setLinkOccurrence(null);
            setDetailOccurrence(response.data?.data?.occurrence ?? null);
            await refreshAll();
        } catch (error) {
            let status: number | undefined;
            let message: string | undefined;
            let errors: Record<string, string[] | string> | undefined;
            if (axios.isAxiosError(error)) {
                status = error.response?.status;
                message =
                    typeof error.response?.data?.message === 'string'
                        ? error.response.data.message
                        : undefined;
                errors = error.response?.data?.errors;
            }
            setBanner({
                variant: status === 409 ? 'warning' : 'negative',
                message:
                    firstValidationMessage(errors, ['financial_transaction_id', 'occurrence']) ??
                    tenantSafeApiMessage(status, 'Unable to link this expense.', message),
            });
        } finally {
            setLinking(false);
        }
    };

    const submitUnlink = async () => {
        if (!confirmUnlink || actionBusy) {
            return;
        }

        setActionBusy(true);
        setBanner(null);
        try {
            const response = await axios.delete(
                `/recurring-occurrences/${confirmUnlink.occurrence.id}/payment-links/${confirmUnlink.link.id}`,
                { headers: { Accept: 'application/json' } },
            );
            setBanner({
                variant: 'positive',
                message:
                    'Payment unlinked. The original expense was not deleted or reversed.',
            });
            setConfirmUnlink(null);
            setDetailOccurrence(response.data?.data?.occurrence ?? null);
            await refreshAll();
        } catch (error) {
            let status: number | undefined;
            let message: string | undefined;
            let errors: Record<string, string[] | string> | undefined;
            if (axios.isAxiosError(error)) {
                status = error.response?.status;
                message =
                    typeof error.response?.data?.message === 'string'
                        ? error.response.data.message
                        : undefined;
                errors = error.response?.data?.errors;
            }
            setBanner({
                variant: 'negative',
                message:
                    firstValidationMessage(errors, ['link', 'payment_link']) ??
                    tenantSafeApiMessage(status, 'Unable to unlink this payment.', message),
            });
        } finally {
            setActionBusy(false);
        }
    };

    const hasAnyBills = obligations.length > 0;
    const filteredEmpty = loadState === 'ready' && occurrences.length === 0 && hasAnyBills;

    return (
        <AuthenticatedLayout title="Bills & Subscriptions">
            <div className="space-y-8">
                <PageHeader
                    eyebrow="Cash flow"
                    title="Bills & Subscriptions"
                    description="Track expected bills, link posted expenses, and keep paid progress accurate without touching the ledger."
                    actions={
                        <Button type="button" onClick={openCreateEditor}>
                            Add bill
                        </Button>
                    }
                />

                {banner ? (
                    <Alert variant={banner.variant} role="status">
                        {banner.message}
                    </Alert>
                ) : null}

                {loadState === 'loading' ? (
                    <div
                        className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                        aria-label="Loading bills overview"
                    >
                        <Skeleton className="h-28" />
                        <Skeleton className="h-28" />
                        <Skeleton className="h-28" />
                        <Skeleton className="h-28" />
                    </div>
                ) : null}

                {loadState === 'error' ? (
                    <EmptyState
                        eyebrow="Unavailable"
                        title="Bills could not be loaded"
                        description="Check your connection, then retry. No bill data was changed."
                    >
                        <Button type="button" variant="secondary" onClick={() => void refreshAll()}>
                            Retry
                        </Button>
                    </EmptyState>
                ) : null}

                {loadState === 'ready' && !hasAnyBills ? (
                    <EmptyState
                        eyebrow="First bill"
                        title="No bills or subscriptions yet"
                        description="Create a recurring obligation to generate upcoming occurrences. Expected amounts never post to your ledger until you record and link an expense."
                    >
                        <Button type="button" onClick={openCreateEditor}>
                            Create your first bill
                        </Button>
                    </EmptyState>
                ) : null}

                {loadState === 'ready' && hasAnyBills ? (
                    <>
                        <section aria-labelledby={`${filtersId}-overview`} className="space-y-4">
                            <h2
                                id={`${filtersId}-overview`}
                                className="font-display text-xl text-ink"
                            >
                                This month overview
                            </h2>
                            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                <OverviewStat label="Upcoming" value={String(overview.upcoming_count)} />
                                <OverviewStat label="Due today" value={String(overview.due_today_count)} />
                                <OverviewStat
                                    label="Overdue"
                                    value={String(overview.overdue_count)}
                                    tone={overview.overdue_count > 0 ? 'warning' : 'default'}
                                />
                                <OverviewStat
                                    label="Active bills"
                                    value={String(overview.active_obligation_count)}
                                />
                            </div>

                            <div className="space-y-3">
                                {overview.month_by_currency.length === 0 ? (
                                    <p className="text-sm text-ink-muted">
                                        No scheduled occurrences fall in the current UTC month.
                                    </p>
                                ) : (
                                    overview.month_by_currency.map((row) => (
                                        <div
                                            key={row.currency_code}
                                            className="rounded-2xl border border-border-subtle bg-surface-elevated p-5 shadow-xs"
                                        >
                                            <p className="font-mono text-xs tracking-wider text-ink-muted uppercase">
                                                {row.currency_code} this month
                                            </p>
                                            <div className="mt-3 grid gap-3 sm:grid-cols-3">
                                                <MoneyStat
                                                    label="Expected"
                                                    value={formatAmount(
                                                        row.expected_minor,
                                                        row.currency_code,
                                                    )}
                                                />
                                                <MoneyStat
                                                    label="Effectively paid"
                                                    value={formatAmount(
                                                        row.paid_minor,
                                                        row.currency_code,
                                                    )}
                                                />
                                                <MoneyStat
                                                    label="Remaining"
                                                    value={formatAmount(
                                                        row.remaining_minor,
                                                        row.currency_code,
                                                    )}
                                                />
                                            </div>
                                            <p className="mt-3 text-xs text-ink-muted">
                                                Currencies are never combined. Overpaid:{' '}
                                                {formatAmount(row.overpaid_minor, row.currency_code)}
                                            </p>
                                        </div>
                                    ))
                                )}
                            </div>
                        </section>

                        <section aria-labelledby={`${filtersId}-obligations`} className="space-y-4">
                            <div className="flex flex-wrap items-end justify-between gap-3">
                                <h2
                                    id={`${filtersId}-obligations`}
                                    className="font-display text-xl text-ink"
                                >
                                    Obligations
                                </h2>
                            </div>
                            <ul className="divide-y divide-border-subtle rounded-2xl border border-border-subtle bg-surface-elevated shadow-xs">
                                {obligations.map((obligation) => (
                                    <li
                                        key={obligation.id}
                                        className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div className="space-y-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="font-medium text-ink">
                                                    {obligation.name}
                                                </p>
                                                <Badge
                                                    variant={
                                                        obligation.status === 'active'
                                                            ? 'positive'
                                                            : obligation.status === 'paused'
                                                              ? 'warning'
                                                              : 'neutral'
                                                    }
                                                >
                                                    {obligationStatusLabel(obligation.status)}
                                                </Badge>
                                            </div>
                                            <p className="text-sm text-ink-muted">
                                                {frequencyLabel(obligation.frequency)} ·{' '}
                                                {formatAmount(
                                                    obligation.amount_minor,
                                                    obligation.currency_code,
                                                )}{' '}
                                                · starts {obligation.start_date}
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            {obligation.status !== 'cancelled' ? (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => openEditEditor(obligation)}
                                                >
                                                    Edit
                                                </Button>
                                            ) : null}
                                            {obligation.status === 'active' ? (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="secondary"
                                                    onClick={() =>
                                                        void runObligationAction(obligation, 'pause')
                                                    }
                                                    disabled={actionBusy}
                                                >
                                                    Pause
                                                </Button>
                                            ) : null}
                                            {obligation.status === 'paused' ? (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="secondary"
                                                    onClick={() =>
                                                        void runObligationAction(
                                                            obligation,
                                                            'resume',
                                                        )
                                                    }
                                                    disabled={actionBusy}
                                                >
                                                    Resume
                                                </Button>
                                            ) : null}
                                            {obligation.status !== 'cancelled' ? (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="danger"
                                                    onClick={() => setConfirmCancel(obligation)}
                                                    disabled={actionBusy}
                                                >
                                                    Cancel
                                                </Button>
                                            ) : null}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </section>

                        <section aria-labelledby={`${filtersId}-occurrences`} className="space-y-4">
                            <h2
                                id={`${filtersId}-occurrences`}
                                className="font-display text-xl text-ink"
                            >
                                Occurrences
                            </h2>

                            <div className="grid gap-3 rounded-2xl border border-border-subtle bg-surface-elevated p-4 shadow-xs md:grid-cols-2 xl:grid-cols-5">
                                <FormField
                                    id="occ-from"
                                    label="From date"
                                    type="date"
                                    value={filterFrom}
                                    onChange={(event) => {
                                        setOccurrencePage(1);
                                        setFilterFrom(event.target.value);
                                    }}
                                />
                                <FormField
                                    id="occ-to"
                                    label="To date"
                                    type="date"
                                    value={filterTo}
                                    onChange={(event) => {
                                        setOccurrencePage(1);
                                        setFilterTo(event.target.value);
                                    }}
                                />
                                <FieldGroup id="occ-currency" label="Currency">
                                    <Select
                                        id="occ-currency"
                                        value={filterCurrency}
                                        onChange={(event) => {
                                            setOccurrencePage(1);
                                            setFilterCurrency(event.target.value);
                                        }}
                                    >
                                        <option value="">All currencies</option>
                                        {currencies.map((currency) => (
                                            <option key={currency.code} value={currency.code}>
                                                {currency.code}
                                            </option>
                                        ))}
                                    </Select>
                                </FieldGroup>
                                <FieldGroup id="occ-obligation" label="Obligation">
                                    <Select
                                        id="occ-obligation"
                                        value={filterObligationId}
                                        onChange={(event) => {
                                            setOccurrencePage(1);
                                            setFilterObligationId(event.target.value);
                                        }}
                                    >
                                        <option value="">All bills</option>
                                        {obligations.map((obligation) => (
                                            <option key={obligation.id} value={obligation.id}>
                                                {obligation.name}
                                            </option>
                                        ))}
                                    </Select>
                                </FieldGroup>
                                <FieldGroup id="occ-status" label="Stored status">
                                    <Select
                                        id="occ-status"
                                        value={filterStatus}
                                        onChange={(event) => {
                                            setOccurrencePage(1);
                                            setFilterStatus(event.target.value);
                                        }}
                                    >
                                        <option value="">All statuses</option>
                                        <option value="scheduled">Scheduled</option>
                                        <option value="skipped">Skipped</option>
                                        <option value="cancelled">Cancelled</option>
                                    </Select>
                                </FieldGroup>
                            </div>

                            {filteredEmpty ? (
                                <EmptyState
                                    eyebrow="No matches"
                                    title="No occurrences for these filters"
                                    description="Adjust the date range, currency, or obligation filters to see more results."
                                />
                            ) : (
                                <ul className="space-y-3">
                                    {occurrences.map((occurrence) => (
                                        <li
                                            key={occurrence.id}
                                            className="rounded-2xl border border-border-subtle bg-surface-elevated p-4 shadow-xs"
                                        >
                                            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                                <div className="space-y-2">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <p className="font-medium text-ink">
                                                            {occurrence.obligation_name ??
                                                                'Obligation'}
                                                        </p>
                                                        <Badge
                                                            variant={displayStatusBadgeVariant(
                                                                occurrence.display_status,
                                                            )}
                                                        >
                                                            {displayStatusLabel(
                                                                occurrence.display_status,
                                                            )}
                                                        </Badge>
                                                        {occurrence.is_overdue &&
                                                        occurrence.display_status ===
                                                            'partially_paid' ? (
                                                            <Badge variant="negative">
                                                                Still overdue
                                                            </Badge>
                                                        ) : null}
                                                    </div>
                                                    <p className="text-sm text-ink-muted">
                                                        Due {occurrence.due_date} · Expected{' '}
                                                        {formatAmount(
                                                            occurrence.expected_amount_minor,
                                                            occurrence.currency_code,
                                                        )}
                                                    </p>
                                                    <PaymentProgress
                                                        expected={occurrence.expected_amount_minor}
                                                        paid={occurrence.paid_amount_minor}
                                                        remaining={
                                                            occurrence.remaining_amount_minor
                                                        }
                                                        overpaid={occurrence.overpaid_amount_minor}
                                                        currencyCode={occurrence.currency_code}
                                                        formatAmount={formatAmount}
                                                    />
                                                </div>
                                                <div className="flex flex-wrap gap-2">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            setDetailOccurrence(occurrence)
                                                        }
                                                    >
                                                        Details
                                                    </Button>
                                                    {occurrence.status === 'scheduled' &&
                                                    !occurrence.is_paid ? (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="secondary"
                                                            onClick={() =>
                                                                void openLinkDialog(occurrence)
                                                            }
                                                        >
                                                            Link payment
                                                        </Button>
                                                    ) : null}
                                                    {occurrence.status === 'scheduled' &&
                                                    occurrence.paid_amount_minor === 0 ? (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                setConfirmSkip(occurrence)
                                                            }
                                                        >
                                                            Skip
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {occurrenceMeta && occurrenceMeta.last_page > 1 ? (
                                <div className="flex items-center justify-between gap-3">
                                    <p className="text-sm text-ink-muted">
                                        Page {occurrenceMeta.current_page} of{' '}
                                        {occurrenceMeta.last_page}
                                    </p>
                                    <div className="flex gap-2">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            disabled={occurrencePage <= 1}
                                            onClick={() =>
                                                setOccurrencePage((current) =>
                                                    Math.max(1, current - 1),
                                                )
                                            }
                                        >
                                            Previous
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            disabled={
                                                occurrencePage >= (occurrenceMeta.last_page ?? 1)
                                            }
                                            onClick={() =>
                                                setOccurrencePage((current) => current + 1)
                                            }
                                        >
                                            Next
                                        </Button>
                                    </div>
                                </div>
                            ) : null}
                        </section>
                    </>
                ) : null}
            </div>

            {editorOpen ? (
                <div
                    className="fixed inset-0 z-40 flex items-end justify-center bg-ink/40 p-4 sm:items-center"
                    role="presentation"
                >
                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="bill-editor-title"
                        className="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl border border-border-subtle bg-surface-elevated p-5 shadow-lg"
                    >
                        <h2 id="bill-editor-title" className="font-display text-2xl text-ink">
                            {editingObligation ? 'Edit bill' : 'Add bill'}
                        </h2>
                        <p className="mt-1 text-sm text-ink-muted">
                            Expected amounts schedule cash-flow reminders. They do not post ledger
                            expenses.
                        </p>
                        <form className="mt-5 space-y-4" onSubmit={(event) => void submitObligation(event)}>
                            <FormField
                                id="bill-name"
                                label="Name"
                                error={draftErrors.name}
                                value={draft.name}
                                onChange={(event) =>
                                    setDraft((current) => ({
                                        ...current,
                                        name: event.target.value,
                                    }))
                                }
                                required
                                maxLength={120}
                            />
                            <FieldGroup
                                id="bill-description"
                                label="Description"
                                error={draftErrors.description}
                            >
                                <Textarea
                                    id="bill-description"
                                    value={draft.description}
                                    onChange={(event) =>
                                        setDraft((current) => ({
                                            ...current,
                                            description: event.target.value,
                                        }))
                                    }
                                    rows={3}
                                />
                            </FieldGroup>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <FormField
                                    id="bill-amount"
                                    label="Amount"
                                    error={draftErrors.amount}
                                    hint="Exact decimal string; no floating-point conversion."
                                    value={draft.amount}
                                    onChange={(event) =>
                                        setDraft((current) => ({
                                            ...current,
                                            amount: event.target.value,
                                        }))
                                    }
                                    inputMode="decimal"
                                    required
                                />
                                <FieldGroup
                                    id="bill-currency"
                                    label="Currency"
                                    error={draftErrors.currency_code}
                                >
                                    <Select
                                        id="bill-currency"
                                        value={draft.currency_code}
                                        disabled={editingObligation !== null}
                                        invalid={Boolean(draftErrors.currency_code)}
                                        onChange={(event) =>
                                            setDraft((current) => ({
                                                ...current,
                                                currency_code: event.target.value,
                                                financial_account_id: '',
                                            }))
                                        }
                                    >
                                        {currencies.map((currency) => (
                                            <option key={currency.code} value={currency.code}>
                                                {currency.code} · {currency.name}
                                            </option>
                                        ))}
                                    </Select>
                                </FieldGroup>
                            </div>
                            <FieldGroup
                                id="bill-category"
                                label="Expense category"
                                error={draftErrors.category_id}
                            >
                                <Select
                                    id="bill-category"
                                    value={draft.category_id}
                                    invalid={Boolean(draftErrors.category_id)}
                                    onChange={(event) =>
                                        setDraft((current) => ({
                                            ...current,
                                            category_id: event.target.value,
                                        }))
                                    }
                                    required
                                >
                                    <option value="">Select category</option>
                                    {expenseCategories.map((category: CategoryRecord) => (
                                        <option key={category.id} value={category.id}>
                                            {category.name}
                                        </option>
                                    ))}
                                </Select>
                            </FieldGroup>
                            <FieldGroup
                                id="bill-account"
                                label="Preferred account"
                                error={draftErrors.financial_account_id}
                                hint="Optional. Must match the bill currency."
                            >
                                <Select
                                    id="bill-account"
                                    value={draft.financial_account_id}
                                    invalid={Boolean(draftErrors.financial_account_id)}
                                    onChange={(event) =>
                                        setDraft((current) => ({
                                            ...current,
                                            financial_account_id: event.target.value,
                                        }))
                                    }
                                >
                                    <option value="">No preferred account</option>
                                    {preferredAccounts.map((account: TransactionAccountOption) => (
                                        <option key={account.id} value={account.id}>
                                            {account.name} ({account.currency_code})
                                        </option>
                                    ))}
                                </Select>
                            </FieldGroup>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <FormField
                                    id="bill-start"
                                    label="Start date"
                                    error={draftErrors.start_date}
                                    type="date"
                                    value={draft.start_date}
                                    onChange={(event) =>
                                        setDraft((current) => ({
                                            ...current,
                                            start_date: event.target.value,
                                            anchor_day: String(
                                                Number(event.target.value.slice(8, 10)) ||
                                                    current.anchor_day,
                                            ),
                                        }))
                                    }
                                    required
                                />
                                <FieldGroup
                                    id="bill-frequency"
                                    label="Frequency"
                                    error={draftErrors.frequency}
                                >
                                    <Select
                                        id="bill-frequency"
                                        value={draft.frequency}
                                        invalid={Boolean(draftErrors.frequency)}
                                        onChange={(event) =>
                                            setDraft((current) => ({
                                                ...current,
                                                frequency: event.target
                                                    .value as RecurringFrequencyValue,
                                                ends_of_month: supportsEndsOfMonth(
                                                    event.target.value as RecurringFrequencyValue,
                                                )
                                                    ? current.ends_of_month
                                                    : false,
                                            }))
                                        }
                                    >
                                        {FREQUENCY_OPTIONS.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </Select>
                                </FieldGroup>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <FormField
                                    id="bill-anchor"
                                    label="Anchor day"
                                    error={draftErrors.anchor_day}
                                    hint="Day of month preserved across short months."
                                    type="number"
                                    min={1}
                                    max={31}
                                    value={draft.anchor_day}
                                    onChange={(event) =>
                                        setDraft((current) => ({
                                            ...current,
                                            anchor_day: event.target.value,
                                        }))
                                    }
                                    required
                                />
                                {supportsEndsOfMonth(draft.frequency) ? (
                                    <div className="flex items-end pb-2">
                                        <Checkbox
                                            id="bill-eom"
                                            checked={draft.ends_of_month}
                                            onChange={(event) =>
                                                setDraft((current) => ({
                                                    ...current,
                                                    ends_of_month: event.target.checked,
                                                }))
                                            }
                                            label="End of each month"
                                        />
                                    </div>
                                ) : null}
                            </div>

                            {!editingObligation ? (
                                <p className="text-xs text-ink-muted">
                                    Idempotency key locked for retries of this unchanged form. Changing
                                    fields issues a new key automatically.
                                </p>
                            ) : null}

                            <div className="flex flex-wrap justify-end gap-2 pt-2">
                                {idempotencyLocked && !editingObligation ? (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={startNewCreateAttempt}
                                    >
                                        Start new attempt
                                    </Button>
                                ) : null}
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={closeEditor}
                                    disabled={savingDraft}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    loading={savingDraft}
                                    disabled={idempotencyLocked && !editingObligation}
                                >
                                    {editingObligation ? 'Save changes' : 'Create bill'}
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}

            {detailOccurrence ? (
                <div
                    className="fixed inset-0 z-40 flex items-end justify-center bg-ink/40 p-4 sm:items-center"
                    role="presentation"
                >
                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="occurrence-detail-title"
                        className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl border border-border-subtle bg-surface-elevated p-5 shadow-lg"
                    >
                        <h2 id="occurrence-detail-title" className="font-display text-2xl text-ink">
                            {detailOccurrence.obligation_name}
                        </h2>
                        <p className="mt-1 text-sm text-ink-muted">
                            Due {detailOccurrence.due_date}
                        </p>
                        <div className="mt-4 flex flex-wrap gap-2">
                            <Badge
                                variant={displayStatusBadgeVariant(detailOccurrence.display_status)}
                            >
                                {displayStatusLabel(detailOccurrence.display_status)}
                            </Badge>
                            {detailOccurrence.is_overdue ? (
                                <Badge variant="negative">Overdue</Badge>
                            ) : null}
                        </div>
                        <PaymentProgress
                            expected={detailOccurrence.expected_amount_minor}
                            paid={detailOccurrence.paid_amount_minor}
                            remaining={detailOccurrence.remaining_amount_minor}
                            overpaid={detailOccurrence.overpaid_amount_minor}
                            currencyCode={detailOccurrence.currency_code}
                            formatAmount={formatAmount}
                        />
                        <div className="mt-4 space-y-2">
                            <p className="text-sm font-medium text-ink">Linked payments</p>
                            {(detailOccurrence.payment_links ?? []).length === 0 ? (
                                <p className="text-sm text-ink-muted">
                                    No expenses linked yet. Linking never creates a new transaction.
                                </p>
                            ) : (
                                <ul className="space-y-2">
                                    {(detailOccurrence.payment_links ?? []).map((link) => (
                                        <li
                                            key={link.id}
                                            className="flex items-center justify-between gap-3 rounded-xl border border-border-subtle px-3 py-2"
                                        >
                                            <div>
                                                <p className="text-sm text-ink">
                                                    Transaction #{link.financial_transaction_id} ·{' '}
                                                    {formatAmount(
                                                        link.amount_minor,
                                                        link.currency_code,
                                                    )}
                                                </p>
                                                <p className="text-xs text-ink-muted">
                                                    {link.is_active
                                                        ? 'Counts toward paid total'
                                                        : link.is_reversed
                                                          ? 'Linked but not effective (reversed)'
                                                          : 'Linked but not effective'}
                                                </p>
                                            </div>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    openUnlink(detailOccurrence, link)
                                                }
                                            >
                                                Unlink
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                        <div className="mt-5 flex flex-wrap justify-end gap-2">
                            {detailOccurrence.status === 'scheduled' &&
                            !detailOccurrence.is_paid ? (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => {
                                        setDetailOccurrence(null);
                                        void openLinkDialog(detailOccurrence);
                                    }}
                                >
                                    Link payment
                                </Button>
                            ) : null}
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setDetailOccurrence(null)}
                            >
                                Close
                            </Button>
                        </div>
                    </div>
                </div>
            ) : null}

            {linkOccurrence ? (
                <div
                    className="fixed inset-0 z-40 flex items-end justify-center bg-ink/40 p-4 sm:items-center"
                    role="presentation"
                >
                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="link-payment-title"
                        className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl border border-border-subtle bg-surface-elevated p-5 shadow-lg"
                    >
                        <h2 id="link-payment-title" className="font-display text-2xl text-ink">
                            Link existing expense
                        </h2>
                        <p className="mt-1 text-sm text-ink-muted">
                            Choose a posted expense. This does not create, edit, or reverse
                            transactions.
                        </p>
                        {loadingCandidates ? (
                            <Skeleton className="mt-4 h-32" />
                        ) : candidateTransactions.length === 0 ? (
                            <p className="mt-4 text-sm text-ink-muted">
                                No eligible active expenses matched this occurrence’s currency and
                                category.
                            </p>
                        ) : (
                            <ul className="mt-4 space-y-2">
                                {candidateTransactions.map((txn) => (
                                    <li key={txn.id}>
                                        <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-border-subtle px-3 py-2 has-[:checked]:border-accent">
                                            <input
                                                type="radio"
                                                name="payment-candidate"
                                                className="mt-1"
                                                checked={selectedTransactionId === txn.id}
                                                onChange={() => setSelectedTransactionId(txn.id)}
                                            />
                                            <span>
                                                <span className="block text-sm font-medium text-ink">
                                                    {txn.description} · {txn.amount_formatted}
                                                </span>
                                                <span className="block text-xs text-ink-muted">
                                                    {txn.entry_date} · {txn.currency_code}
                                                </span>
                                            </span>
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <div className="mt-5 flex flex-wrap justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setLinkOccurrence(null)}
                                disabled={linking}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                onClick={() => void submitPaymentLink()}
                                loading={linking}
                                disabled={selectedTransactionId === null}
                            >
                                Link expense
                            </Button>
                        </div>
                    </div>
                </div>
            ) : null}

            <ConfirmDialog
                open={confirmSkip !== null}
                title="Skip this occurrence?"
                description="The due date stays occupied so generation will not recreate it. No expense is posted."
                confirmLabel="Skip occurrence"
                confirmVariant="warning-outline"
                loading={actionBusy}
                onCancel={() => setConfirmSkip(null)}
                onConfirm={() => void submitSkip()}
            />

            <ConfirmDialog
                open={confirmCancel !== null}
                title="Cancel this bill?"
                description="Cancellation is terminal. Future unlinked scheduled occurrences are released, but payment history is preserved."
                confirmLabel="Cancel bill"
                confirmVariant="danger"
                loading={actionBusy}
                onCancel={() => setConfirmCancel(null)}
                onConfirm={() => {
                    if (confirmCancel) {
                        void runObligationAction(confirmCancel, 'cancel');
                    }
                }}
            />

            <ConfirmDialog
                open={confirmUnlink !== null}
                title="Unlink this expense?"
                description="Unlinking removes the association only. The original expense stays on your ledger and is not reversed."
                confirmLabel="Unlink payment"
                confirmVariant="warning-outline"
                loading={actionBusy}
                onCancel={() => setConfirmUnlink(null)}
                onConfirm={() => void submitUnlink()}
            />
        </AuthenticatedLayout>
    );
}

function FieldGroup({
    id,
    label,
    error,
    hint,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    hint?: string;
    children: ReactNode;
}) {
    const errorId = `${id}-error`;
    const hintId = `${id}-hint`;

    return (
        <div className="space-y-1.5">
            <label htmlFor={id} className="block text-sm font-medium text-ink">
                {label}
            </label>
            {children}
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
}

function OverviewStat({
    label,
    value,
    tone = 'default',
}: {
    label: string;
    value: string;
    tone?: 'default' | 'warning';
}) {
    return (
        <div className="rounded-2xl border border-border-subtle bg-surface-elevated p-5 shadow-xs">
            <p className="font-mono text-xs tracking-wider text-ink-muted uppercase">{label}</p>
            <p
                className={`mt-2 font-display text-3xl tabular-nums ${
                    tone === 'warning' ? 'text-warning' : 'text-ink'
                }`}
            >
                {value}
            </p>
        </div>
    );
}

function MoneyStat({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="text-xs text-ink-muted">{label}</p>
            <p className="mt-1 font-mono text-base font-semibold tabular-nums text-ink">{value}</p>
        </div>
    );
}

function PaymentProgress({
    expected,
    paid,
    remaining,
    overpaid,
    currencyCode,
    formatAmount,
}: {
    expected: number;
    paid: number;
    remaining: number;
    overpaid: number;
    currencyCode: string;
    formatAmount: (amountMinor: number, currencyCode: string) => string;
}) {
    const percent =
        expected <= 0 ? 0 : Math.min(100, Math.floor((Math.max(0, paid) * 100) / expected));

    return (
        <div className="space-y-2">
            <div
                className="h-2 overflow-hidden rounded-full bg-surface-muted"
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent}
                aria-label="Payment progress"
            >
                <div
                    className="h-full rounded-full bg-accent transition-[width] duration-300 motion-reduce:transition-none"
                    style={{ width: `${percent}%` }}
                />
            </div>
            <p className="text-xs text-ink-muted">
                Paid {formatAmount(paid, currencyCode)} · Remaining{' '}
                {formatAmount(remaining, currencyCode)}
                {overpaid > 0 ? ` · Overpaid ${formatAmount(overpaid, currencyCode)}` : ''}
            </p>
        </div>
    );
}
