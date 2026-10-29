import { Link } from '@inertiajs/react';
import axios from 'axios';
import { type FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { Alert } from '@/Components/Alert';
import { Badge } from '@/Components/Badge';
import { Button } from '@/Components/Button';
import { Card } from '@/Components/Card';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { EmptyState } from '@/Components/EmptyState';
import { FormField } from '@/Components/FormField';
import { PageHeader } from '@/Components/PageHeader';
import { Select } from '@/Components/Select';
import { Separator } from '@/Components/Separator';
import { Textarea } from '@/Components/Textarea';
import { AuthenticatedLayout } from '@/Layouts/AuthenticatedLayout';
import type {
    CategoryRecord,
    CategoryTypeValue,
    FinancialTransactionItem,
    PaginationMeta,
    TransactionAccountOption,
    TransactionFiltersState,
    TransactionsIndexPageProps,
    TransactionTypeValue,
} from '@/Types';
import { generateUuidV4 } from '@/Utilities/uuid';

interface TransactionDraftState {
    type: TransactionTypeValue;
    financial_account_id: string;
    transfer_financial_account_id: string;
    category_id: string;
    amount: string;
    entry_date: string;
    description: string;
    notes: string;
}

interface FilterDraftState {
    type: string;
    financial_account_id: string;
    category_id: string;
    start_date: string;
    end_date: string;
}

type SubmissionState = 'idle' | 'submitting' | 'uncertain_retry' | 'conflict_blocked';

function isTransactionReversible(transaction: FinancialTransactionItem): boolean {
    return !transaction.is_reversal && !transaction.is_reversed;
}

function accountLabelForTransaction(transaction: FinancialTransactionItem): string {
    if (transaction.type === 'transfer') {
        const from = transaction.financial_account?.name ?? 'Account';
        const to = transaction.transfer_financial_account?.name ?? 'Account';

        return `${from} → ${to}`;
    }

    return transaction.financial_account?.name ?? 'Account';
}

const DEFAULT_META: PaginationMeta = {
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
    from: null,
    to: null,
};

const DEFAULT_FILTERS: TransactionFiltersState = {
    type: null,
    financial_account_id: null,
    category_id: null,
    start_date: null,
    end_date: null,
};

function getTodayDateString(): string {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function serializeCanonicalDraft(draft: TransactionDraftState): string {
    return JSON.stringify({
        type: draft.type,
        financial_account_id: draft.financial_account_id,
        transfer_financial_account_id:
            draft.type === 'transfer' ? draft.transfer_financial_account_id : '',
        category_id: draft.type !== 'transfer' ? draft.category_id : '',
        amount: draft.amount.trim(),
        entry_date: draft.entry_date.trim(),
        description: draft.description.trim(),
        notes: draft.notes.trim(),
    });
}

function computeSynchronizedDraft(
    current: TransactionDraftState,
    activeAccounts: TransactionAccountOption[],
    categories: CategoryRecord[],
): TransactionDraftState {
    let nextAccountId = current.financial_account_id;
    if (!nextAccountId || !activeAccounts.some((account) => String(account.id) === nextAccountId)) {
        nextAccountId = activeAccounts[0] ? String(activeAccounts[0].id) : '';
    }

    const currentPrimary = activeAccounts.find((account) => String(account.id) === nextAccountId);

    let nextTransferId = current.transfer_financial_account_id;
    if (current.type === 'transfer') {
        const validDestinations = activeAccounts.filter(
            (account) =>
                currentPrimary !== undefined &&
                account.id !== currentPrimary.id &&
                account.currency_code === currentPrimary.currency_code,
        );
        if (
            !nextTransferId ||
            !validDestinations.some((account) => String(account.id) === nextTransferId)
        ) {
            nextTransferId = validDestinations[0] ? String(validDestinations[0].id) : '';
        }
    } else {
        nextTransferId = '';
    }

    let nextCategoryId = current.category_id;
    if (current.type === 'income' || current.type === 'expense') {
        const validCategories = categories.filter(
            (cat) => !cat.is_archived && cat.type === current.type,
        );
        if (!nextCategoryId || !validCategories.some((cat) => String(cat.id) === nextCategoryId)) {
            nextCategoryId = validCategories[0] ? String(validCategories[0].id) : '';
        }
    } else {
        nextCategoryId = '';
    }

    if (
        nextAccountId === current.financial_account_id &&
        nextTransferId === current.transfer_financial_account_id &&
        nextCategoryId === current.category_id
    ) {
        return current;
    }

    return {
        ...current,
        financial_account_id: nextAccountId,
        transfer_financial_account_id: nextTransferId,
        category_id: nextCategoryId,
    };
}

function PencilIcon() {
    return (
        <svg
            className="h-3.5 w-3.5"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M11.333 2.00004C11.5081 1.82494 11.716 1.68605 11.9447 1.59129C12.1735 1.49653 12.4187 1.44775 12.6663 1.44775C12.914 1.44775 13.1592 1.49653 13.3879 1.59129C13.6167 1.68605 13.8246 1.82494 13.9997 2.00004C14.1748 2.17513 14.3137 2.383 14.4084 2.61178C14.5032 2.84055 14.552 3.08575 14.552 3.33337C14.552 3.58099 14.5032 3.82619 14.4084 4.05497C14.3137 4.28374 14.1748 4.49161 13.9997 4.66671L5.00001 13.6667L1.33334 14.6667L2.33334 11L11.333 2.00004Z" />
        </svg>
    );
}

function ArchiveIcon() {
    return (
        <svg
            className="h-3.5 w-3.5"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <rect x="1.33334" y="2" width="13.3333" height="3.33333" rx="0.75" />
            <path d="M2.66666 5.33334V12.6667C2.66666 13.403 3.26362 14 4 14H12C12.7364 14 13.3333 13.403 13.3333 12.6667V5.33334" />
            <path d="M6.66666 8H9.33333" />
        </svg>
    );
}

function RestoreIcon() {
    return (
        <svg
            className="h-3.5 w-3.5"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M2 8C2 4.68629 4.68629 2 8 2C10.2208 2 12.1599 3.2066 13.1973 5" />
            <path d="M13.3333 2V5.33333H10" />
            <path d="M14 8C14 11.3137 11.3137 14 8 14C5.77915 14 3.84012 12.7934 2.80269 11" />
        </svg>
    );
}

function PlusIcon() {
    return (
        <svg
            className="h-3.5 w-3.5"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.75"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M8 3.33334V12.6667" />
            <path d="M3.33334 8H12.6667" />
        </svg>
    );
}

export default function TransactionsIndex({
    transactions: initialTransactions = [],
    meta: initialMeta = DEFAULT_META,
    filters: initialFilters = DEFAULT_FILTERS,
    accounts = [],
    categories: initialCategories = [],
}: TransactionsIndexPageProps) {
    const [transactions, setTransactions] =
        useState<FinancialTransactionItem[]>(initialTransactions);
    const [meta, setMeta] = useState<PaginationMeta>(initialMeta);
    const [categories, setCategories] = useState<CategoryRecord[]>(initialCategories);

    useEffect(() => {
        setTransactions(initialTransactions);
    }, [initialTransactions]);

    useEffect(() => {
        setMeta(initialMeta);
    }, [initialMeta]);

    useEffect(() => {
        setCategories(initialCategories);
    }, [initialCategories]);

    const activeAccounts = accounts.filter((account) => !account.is_archived);
    const defaultPrimaryAccountId = activeAccounts[0] ? String(activeAccounts[0].id) : '';

    const activeExpenseCategories = categories.filter(
        (category) => !category.is_archived && category.type === 'expense',
    );
    const activeIncomeCategories = categories.filter(
        (category) => !category.is_archived && category.type === 'income',
    );

    const [draft, setDraft] = useState<TransactionDraftState>(() => ({
        type: 'expense',
        financial_account_id: defaultPrimaryAccountId,
        transfer_financial_account_id: '',
        category_id: activeExpenseCategories[0] ? String(activeExpenseCategories[0].id) : '',
        amount: '',
        entry_date: getTodayDateString(),
        description: '',
        notes: '',
    }));

    const [idempotencyKey, setIdempotencyKey] = useState<string>(() => generateUuidV4());
    const [submissionState, setSubmissionState] = useState<SubmissionState>('idle');
    const [unavailableRetryTargetMessage, setUnavailableRetryTargetMessage] = useState<
        string | null
    >(null);

    const [txErrors, setTxErrors] = useState<Record<string, string>>({});
    const [txBanner, setTxBanner] = useState<{
        variant: 'info' | 'positive' | 'warning' | 'negative';
        message: string;
    } | null>(null);

    const isSubmittingTxRef = useRef(false);
    const activeAttemptKeyRef = useRef<string | null>(null);
    const draftRef = useRef<TransactionDraftState>(draft);
    draftRef.current = draft;

    // Filter & Pagination state
    const [appliedFilters, setAppliedFilters] = useState<FilterDraftState>(() => ({
        type: initialFilters.type ?? '',
        financial_account_id: initialFilters.financial_account_id
            ? String(initialFilters.financial_account_id)
            : '',
        category_id: initialFilters.category_id ? String(initialFilters.category_id) : '',
        start_date: initialFilters.start_date ?? '',
        end_date: initialFilters.end_date ?? '',
    }));
    const [filterDraft, setFilterDraft] = useState<FilterDraftState>(() => ({
        type: initialFilters.type ?? '',
        financial_account_id: initialFilters.financial_account_id
            ? String(initialFilters.financial_account_id)
            : '',
        category_id: initialFilters.category_id ? String(initialFilters.category_id) : '',
        start_date: initialFilters.start_date ?? '',
        end_date: initialFilters.end_date ?? '',
    }));
    const [isLoadingHistory, setIsLoadingHistory] = useState(false);
    const [historyError, setHistoryError] = useState<string | null>(null);

    // Category Management state
    const [showCategoryManager, setShowCategoryManager] = useState(false);
    const [newCategoryName, setNewCategoryName] = useState('');
    const [newCategoryType, setNewCategoryType] = useState<CategoryTypeValue>('expense');
    const [categoryErrors, setCategoryErrors] = useState<Record<string, string>>({});
    const [categoryStatusMessage, setCategoryStatusMessage] = useState<string | null>(null);
    const [isCreatingCategory, setIsCreatingCategory] = useState(false);
    const [editingCategory, setEditingCategory] = useState<CategoryRecord | null>(null);
    const [editingCategoryName, setEditingCategoryName] = useState('');
    const [isUpdatingCategory, setIsUpdatingCategory] = useState(false);
    const [pendingArchiveCategory, setPendingArchiveCategory] = useState<CategoryRecord | null>(
        null,
    );
    const [togglingCategoryId, setTogglingCategoryId] = useState<number | null>(null);

    // Reversal confirmation + submission safety state (separate from create-transaction flow)
    const [reverseTarget, setReverseTarget] = useState<FinancialTransactionItem | null>(null);
    const [reverseReason, setReverseReason] = useState('');
    const [reverseIdempotencyKey, setReverseIdempotencyKey] = useState<string>(() =>
        generateUuidV4(),
    );
    const [reverseSubmissionState, setReverseSubmissionState] = useState<SubmissionState>('idle');
    const [reverseErrors, setReverseErrors] = useState<Record<string, string>>({});
    const [reverseBanner, setReverseBanner] = useState<{
        variant: 'info' | 'positive' | 'warning' | 'negative';
        message: string;
    } | null>(null);
    const [historyStatusMessage, setHistoryStatusMessage] = useState<string | null>(null);

    const isSubmittingReverseRef = useRef(false);
    const activeReverseAttemptKeyRef = useRef<string | null>(null);
    const reverseReasonInputRef = useRef<HTMLElement | null>(null);
    const reverseReasonRef = useRef(reverseReason);
    reverseReasonRef.current = reverseReason;
    const reverseTargetRef = useRef(reverseTarget);
    reverseTargetRef.current = reverseTarget;

    const descriptionInputRef = useRef<HTMLInputElement | null>(null);

    const isReverseFormLocked =
        reverseSubmissionState === 'submitting' ||
        reverseSubmissionState === 'uncertain_retry' ||
        reverseSubmissionState === 'conflict_blocked';

    const trimmedReverseReason = reverseReason.trim();
    const canConfirmReverse =
        reverseTarget !== null &&
        trimmedReverseReason.length > 0 &&
        trimmedReverseReason.length <= 255 &&
        reverseSubmissionState !== 'conflict_blocked';

    const isFormLocked =
        submissionState === 'submitting' ||
        submissionState === 'uncertain_retry' ||
        submissionState === 'conflict_blocked';

    const selectedPrimaryAccount: TransactionAccountOption | undefined =
        activeAccounts.find((account) => String(account.id) === draft.financial_account_id) ??
        accounts.find((account) => String(account.id) === draft.financial_account_id);

    const eligibleTransferDestinations = activeAccounts.filter(
        (account) =>
            selectedPrimaryAccount !== undefined &&
            account.id !== selectedPrimaryAccount.id &&
            account.currency_code === selectedPrimaryAccount.currency_code,
    );

    const eligibleCategories =
        draft.type === 'income' ? activeIncomeCategories : activeExpenseCategories;

    // Synchronize default account/category selections ONLY when the form is idle.
    // Never mutate an in-flight ('submitting'), uncertain ('uncertain_retry'), or conflict-blocked draft.
    useEffect(() => {
        if (submissionState === 'submitting' || submissionState === 'conflict_blocked') {
            return;
        }

        const currentDraft = draftRef.current;

        if (submissionState === 'uncertain_retry') {
            // Check whether the account or category referenced by the uncertain submission became archived/unavailable.
            // Do NOT silently mutate the draft; warn the user if the target was archived while awaiting retry.
            const primaryStillActive = activeAccounts.some(
                (account) => String(account.id) === currentDraft.financial_account_id,
            );
            const transferStillActive =
                currentDraft.type !== 'transfer' ||
                activeAccounts.some(
                    (account) => String(account.id) === currentDraft.transfer_financial_account_id,
                );
            const categoryStillActive =
                currentDraft.type === 'transfer' ||
                categories.some(
                    (cat) =>
                        !cat.is_archived &&
                        cat.type === currentDraft.type &&
                        String(cat.id) === currentDraft.category_id,
                );

            if (!primaryStillActive || !transferStillActive || !categoryStillActive) {
                setUnavailableRetryTargetMessage(
                    'The selected account or category was archived while your submission was pending. You can still retry to check if the transaction was already recorded, or start a new attempt to choose an active option.',
                );
            } else {
                setUnavailableRetryTargetMessage(null);
            }

            return;
        }

        const synchronized = computeSynchronizedDraft(currentDraft, activeAccounts, categories);

        if (synchronized !== currentDraft) {
            const canonicalChanged =
                serializeCanonicalDraft(synchronized) !== serializeCanonicalDraft(currentDraft);

            setDraft(synchronized);

            if (canonicalChanged) {
                setIdempotencyKey(generateUuidV4());
            }
        }
    }, [activeAccounts, categories, submissionState]);

    const applyDraftUpdate = (nextDraft: TransactionDraftState) => {
        if (isSubmittingTxRef.current || isFormLocked) {
            return;
        }

        const previousCanonical = serializeCanonicalDraft(draftRef.current);
        const nextCanonical = serializeCanonicalDraft(nextDraft);

        draftRef.current = nextDraft;
        setDraft(nextDraft);

        if (previousCanonical !== nextCanonical) {
            setIdempotencyKey(generateUuidV4());
        }
    };

    const updateDraftField = <K extends keyof TransactionDraftState>(
        field: K,
        value: TransactionDraftState[K],
    ) => {
        if (isSubmittingTxRef.current || isFormLocked) {
            return;
        }

        const currentDraft = draftRef.current;
        if (currentDraft[field] === value) {
            return;
        }

        let nextDraft: TransactionDraftState = {
            ...currentDraft,
            [field]: value,
        };

        if (field === 'financial_account_id') {
            nextDraft = computeSynchronizedDraft(nextDraft, activeAccounts, categories);
        }

        applyDraftUpdate(nextDraft);

        setTxErrors((prev) => {
            if (!prev[field]) {
                return prev;
            }
            const next = { ...prev };
            delete next[field];

            return next;
        });
    };

    const handleTypeSwitch = (nextType: TransactionTypeValue) => {
        if (isSubmittingTxRef.current || isFormLocked || draftRef.current.type === nextType) {
            return;
        }

        const currentDraft = draftRef.current;
        const nextCategories = categories.filter(
            (cat) => !cat.is_archived && cat.type === nextType,
        );
        const nextDestinations = activeAccounts.filter(
            (account) =>
                selectedPrimaryAccount !== undefined &&
                account.id !== selectedPrimaryAccount.id &&
                account.currency_code === selectedPrimaryAccount.currency_code,
        );

        const nextDraft: TransactionDraftState = {
            ...currentDraft,
            type: nextType,
            category_id:
                nextType === 'transfer'
                    ? ''
                    : nextCategories[0]
                      ? String(nextCategories[0].id)
                      : '',
            transfer_financial_account_id:
                nextType === 'transfer' && nextDestinations[0]
                    ? String(nextDestinations[0].id)
                    : '',
        };

        applyDraftUpdate(nextDraft);
        setTxErrors({});
    };

    /**
     * Explicit user action to abandon an uncertain retry or an HTTP 409 idempotency conflict,
     * unlocking the form, synchronizing active account/category options, and issuing a fresh UUID.
     */
    const handleStartNewAttempt = () => {
        if (isSubmittingTxRef.current) {
            return;
        }

        const synchronized = computeSynchronizedDraft(draftRef.current, activeAccounts, categories);
        draftRef.current = synchronized;
        setDraft(synchronized);
        setIdempotencyKey(generateUuidV4());
        setSubmissionState('idle');
        setUnavailableRetryTargetMessage(null);
        setTxErrors({});
        setTxBanner(null);
    };

    const fetchTransactionsPage = useCallback(
        async (
            targetFilters: FilterDraftState,
            pageNumber: number = 1,
        ): Promise<FinancialTransactionItem[] | null> => {
            setIsLoadingHistory(true);
            setHistoryError(null);

            const params: Record<string, string | number> = {
                page: pageNumber,
            };

            if (targetFilters.type) {
                params.type = targetFilters.type;
            }
            if (targetFilters.financial_account_id) {
                params.financial_account_id = Number(targetFilters.financial_account_id);
            }
            if (targetFilters.category_id) {
                params.category_id = Number(targetFilters.category_id);
            }
            if (targetFilters.start_date) {
                params.start_date = targetFilters.start_date;
            }
            if (targetFilters.end_date) {
                params.end_date = targetFilters.end_date;
            }

            try {
                const response = await axios.get<{
                    data: FinancialTransactionItem[];
                    meta: PaginationMeta;
                }>('/transactions', {
                    params,
                    headers: {
                        Accept: 'application/json',
                    },
                });

                setTransactions(response.data.data);
                setMeta(response.data.meta);

                return response.data.data;
            } catch (error: unknown) {
                if (axios.isAxiosError(error) && error.response?.status === 401) {
                    setHistoryError('Your session has expired. Please sign in again.');
                } else {
                    setHistoryError(
                        'Unable to load transactions right now. Please try again in a moment.',
                    );
                }

                return null;
            } finally {
                setIsLoadingHistory(false);
            }
        },
        [],
    );

    const handleTransactionSubmit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (isSubmittingTxRef.current || submissionState === 'conflict_blocked') {
            return;
        }

        const submittedDraft = draftRef.current;
        const submittedKey = idempotencyKey;

        isSubmittingTxRef.current = true;
        activeAttemptKeyRef.current = submittedKey;
        setSubmissionState('submitting');
        setTxErrors({});
        setTxBanner(null);

        const payload: Record<string, unknown> = {
            type: submittedDraft.type,
            financial_account_id: submittedDraft.financial_account_id
                ? Number(submittedDraft.financial_account_id)
                : null,
            // Preserve the exact user-entered decimal string (only trimming outer whitespace)
            amount: submittedDraft.amount.trim(),
            entry_date: submittedDraft.entry_date.trim(),
            description: submittedDraft.description.trim(),
            notes: submittedDraft.notes.trim() !== '' ? submittedDraft.notes.trim() : null,
            idempotency_key: submittedKey,
        };

        if (submittedDraft.type === 'transfer') {
            payload.transfer_financial_account_id = submittedDraft.transfer_financial_account_id
                ? Number(submittedDraft.transfer_financial_account_id)
                : null;
        } else {
            payload.category_id = submittedDraft.category_id
                ? Number(submittedDraft.category_id)
                : null;
        }

        try {
            const response = await axios.post<{
                data: FinancialTransactionItem;
                meta: {
                    was_recently_created: boolean;
                    idempotent_replay: boolean;
                };
            }>('/transactions', payload, {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (activeAttemptKeyRef.current !== submittedKey) {
                return;
            }

            const wasReplay = Boolean(response.data.meta?.idempotent_replay);

            setTxBanner({
                variant: 'positive',
                message: wasReplay
                    ? 'Transaction was already recorded. Showing your saved record.'
                    : 'Transaction recorded.',
            });

            const clearedDraft = computeSynchronizedDraft(
                {
                    ...submittedDraft,
                    amount: '',
                    description: '',
                    notes: '',
                },
                activeAccounts,
                categories,
            );
            draftRef.current = clearedDraft;
            setDraft(clearedDraft);
            setIdempotencyKey(generateUuidV4());
            setSubmissionState('idle');
            setUnavailableRetryTargetMessage(null);

            await fetchTransactionsPage(appliedFilters, 1);
        } catch (error: unknown) {
            if (activeAttemptKeyRef.current !== submittedKey) {
                return;
            }

            if (axios.isAxiosError(error)) {
                const status = error.response?.status;
                const responseErrors = (
                    error.response?.data as { errors?: Record<string, string[]> } | undefined
                )?.errors;

                if (status === 422 && responseErrors) {
                    const mapped: Record<string, string> = {};
                    Object.entries(responseErrors).forEach(([key, messages]) => {
                        if (Array.isArray(messages) && messages.length > 0) {
                            mapped[key] = messages[0];
                        }
                    });
                    setTxErrors(mapped);
                    // Validation errors are deterministic rejections (nothing was committed).
                    // Return to 'idle' so the user can edit fields (which will rotate the UUID when changed).
                    setSubmissionState('idle');
                } else if (status === 409) {
                    // HTTP 409 Idempotency Conflict:
                    // Do NOT auto-rotate the UUID and do NOT enable immediate resubmission.
                    // Block retrying this conflicting key and require explicit user action to start a new attempt.
                    setSubmissionState('conflict_blocked');
                    setTxBanner({
                        variant: 'negative',
                        message:
                            'This submission conflicts with a previous request using the same idempotency key and was not recorded. To prevent duplicate postings, retrying this key is blocked. Click "Start new attempt" below to review your details and submit as a new transaction.',
                    });
                } else if (status === 401) {
                    setSubmissionState('idle');
                    setTxBanner({
                        variant: 'negative',
                        message: 'Your session has expired. Please sign in again to continue.',
                    });
                } else if (status === 403) {
                    setSubmissionState('idle');
                    setTxBanner({
                        variant: 'negative',
                        message: 'You do not have permission to record this transaction.',
                    });
                } else {
                    // Network error, timeout, or 5xx uncertain outcome:
                    // Lock the draft and preserve the exact submitted payload and UUID for a safe idempotent retry.
                    setSubmissionState('uncertain_retry');
                    setTxBanner({
                        variant: 'warning',
                        message:
                            'Connection interrupted while saving your transaction. Your form is locked so you can safely retry the exact same request without creating a duplicate, or click "Edit or start new attempt" to modify the entry.',
                    });
                }
            } else {
                setSubmissionState('uncertain_retry');
                setTxBanner({
                    variant: 'warning',
                    message:
                        'Connection interrupted while saving your transaction. Your form is locked so you can safely retry the exact same request without creating a duplicate, or click "Edit or start new attempt" to modify the entry.',
                });
            }
        } finally {
            isSubmittingTxRef.current = false;
        }
    };

    const handleApplyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setAppliedFilters(filterDraft);
        void fetchTransactionsPage(filterDraft, 1);
    };

    const handleClearFilters = () => {
        const cleared: FilterDraftState = {
            type: '',
            financial_account_id: '',
            category_id: '',
            start_date: '',
            end_date: '',
        };
        setFilterDraft(cleared);
        setAppliedFilters(cleared);
        void fetchTransactionsPage(cleared, 1);
    };

    const handlePageChange = (nextPage: number) => {
        if (nextPage < 1 || nextPage > meta.last_page || nextPage === meta.current_page) {
            return;
        }
        void fetchTransactionsPage(appliedFilters, nextPage);
    };

    const openReverseDialog = (transaction: FinancialTransactionItem) => {
        if (!isTransactionReversible(transaction) || isReverseFormLocked) {
            return;
        }

        setReverseTarget(transaction);
        setReverseReason('');
        setReverseIdempotencyKey(generateUuidV4());
        setReverseSubmissionState('idle');
        setReverseErrors({});
        setReverseBanner(null);
        setHistoryStatusMessage(null);
    };

    const closeReverseDialog = () => {
        if (reverseSubmissionState === 'submitting') {
            return;
        }

        setReverseTarget(null);
        setReverseReason('');
        setReverseIdempotencyKey(generateUuidV4());
        setReverseSubmissionState('idle');
        setReverseErrors({});
        setReverseBanner(null);
        isSubmittingReverseRef.current = false;
        activeReverseAttemptKeyRef.current = null;
    };

    const startNewReverseAttempt = () => {
        if (reverseSubmissionState === 'submitting') {
            return;
        }

        const target = reverseTargetRef.current;
        if (target === null || !isTransactionReversible(target)) {
            closeReverseDialog();

            return;
        }

        setReverseReason('');
        setReverseIdempotencyKey(generateUuidV4());
        setReverseSubmissionState('idle');
        setReverseErrors({});
        setReverseBanner(null);
        isSubmittingReverseRef.current = false;
        activeReverseAttemptKeyRef.current = null;
    };

    const submitReverseTransaction = async () => {
        const target = reverseTargetRef.current;
        if (
            target === null ||
            isSubmittingReverseRef.current ||
            reverseSubmissionState === 'conflict_blocked'
        ) {
            return;
        }

        const reason = reverseReasonRef.current.trim();
        if (reason === '' || reason.length > 255) {
            setReverseErrors({
                reason:
                    reason === ''
                        ? 'A reversal reason is required.'
                        : 'Reversal reason must not exceed 255 characters.',
            });

            return;
        }

        const submittedKey = reverseIdempotencyKey;
        const submittedTransactionId = target.id;
        const payload = {
            idempotency_key: submittedKey,
            reason,
        };

        isSubmittingReverseRef.current = true;
        activeReverseAttemptKeyRef.current = submittedKey;
        setReverseSubmissionState('submitting');
        setReverseErrors({});
        setReverseBanner(null);

        try {
            const response = await axios.post<{
                data: {
                    original: FinancialTransactionItem;
                    reversal: FinancialTransactionItem;
                };
                meta: {
                    was_recently_created: boolean;
                    idempotent_replay: boolean;
                };
            }>(`/transactions/${submittedTransactionId}/reverse`, payload, {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (activeReverseAttemptKeyRef.current !== submittedKey) {
                return;
            }

            const wasReplay = Boolean(response.data.meta?.idempotent_replay);
            setHistoryStatusMessage(
                wasReplay
                    ? 'Reversal was already recorded. Showing your saved compensating entry.'
                    : 'Transaction reversed. A compensating entry was posted; the original remains in history.',
            );

            setReverseTarget(null);
            setReverseReason('');
            setReverseIdempotencyKey(generateUuidV4());
            setReverseSubmissionState('idle');
            setReverseErrors({});
            setReverseBanner(null);

            await fetchTransactionsPage(appliedFilters, meta.current_page);
        } catch (error: unknown) {
            if (activeReverseAttemptKeyRef.current !== submittedKey) {
                return;
            }

            if (axios.isAxiosError(error)) {
                const status = error.response?.status;
                const responseErrors = (
                    error.response?.data as { errors?: Record<string, string[]> } | undefined
                )?.errors;
                const responseMessage = (error.response?.data as { message?: string } | undefined)
                    ?.message;

                if (status === 422 && responseErrors) {
                    const mapped: Record<string, string> = {};
                    Object.entries(responseErrors).forEach(([key, messages]) => {
                        if (Array.isArray(messages) && messages.length > 0) {
                            mapped[key] = messages[0];
                        }
                    });
                    setReverseErrors(mapped);
                    setReverseSubmissionState('idle');
                } else if (status === 409) {
                    setReverseSubmissionState('conflict_blocked');
                    const conflictMessage =
                        responseMessage ??
                        'This reversal conflicts with an existing request and was not recorded again.';
                    setReverseBanner({
                        variant: 'negative',
                        message: conflictMessage,
                    });
                    // Refresh so already-reversed originals update from authoritative server data.
                    const refreshedItems = await fetchTransactionsPage(
                        appliedFilters,
                        meta.current_page,
                    );
                    setReverseTarget((current) => {
                        if (current === null || refreshedItems === null) {
                            return current;
                        }

                        return refreshedItems.find((item) => item.id === current.id) ?? current;
                    });
                } else if (status === 401) {
                    setReverseSubmissionState('idle');
                    setReverseBanner({
                        variant: 'negative',
                        message: 'Your session has expired. Please sign in again to continue.',
                    });
                } else if (status === 403) {
                    setReverseSubmissionState('idle');
                    setReverseBanner({
                        variant: 'negative',
                        message: 'You do not have permission to reverse this transaction.',
                    });
                } else if (status === 404) {
                    setReverseSubmissionState('idle');
                    setReverseBanner({
                        variant: 'negative',
                        message: 'That transaction could not be found. Refreshing history.',
                    });
                    await fetchTransactionsPage(appliedFilters, meta.current_page);
                } else {
                    setReverseSubmissionState('uncertain_retry');
                    setReverseBanner({
                        variant: 'warning',
                        message:
                            'Connection interrupted while reversing. The reversal may already have been recorded. Retry the exact same request, or cancel and review history before starting a new attempt.',
                    });
                }
            } else {
                setReverseSubmissionState('uncertain_retry');
                setReverseBanner({
                    variant: 'warning',
                    message:
                        'Connection interrupted while reversing. The reversal may already have been recorded. Retry the exact same request, or cancel and review history before starting a new attempt.',
                });
            }
        } finally {
            isSubmittingReverseRef.current = false;
        }
    };

    // Category Management handlers
    const handleCreateCategory = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (isCreatingCategory) {
            return;
        }

        setIsCreatingCategory(true);
        setCategoryErrors({});
        setCategoryStatusMessage(null);

        try {
            const response = await axios.post<{ data: CategoryRecord }>(
                '/categories',
                {
                    name: newCategoryName.trim(),
                    type: newCategoryType,
                },
                {
                    headers: { Accept: 'application/json' },
                },
            );

            const created = response.data.data;
            setCategories((prev) =>
                [...prev, created].sort((a, b) => a.name.localeCompare(b.name)),
            );
            setNewCategoryName('');
            setCategoryStatusMessage(`Category "${created.name}" created.`);

            if (
                submissionState === 'idle' &&
                !isSubmittingTxRef.current &&
                draftRef.current.type === created.type &&
                !draftRef.current.category_id
            ) {
                updateDraftField('category_id', String(created.id));
            }
        } catch (error: unknown) {
            if (axios.isAxiosError(error) && error.response?.status === 422) {
                const responseErrors = (
                    error.response.data as { errors?: Record<string, string[]> } | undefined
                )?.errors;
                const mapped: Record<string, string> = {};
                if (responseErrors) {
                    Object.entries(responseErrors).forEach(([key, messages]) => {
                        if (Array.isArray(messages) && messages.length > 0) {
                            mapped[key] = messages[0];
                        }
                    });
                }
                setCategoryErrors(mapped);
            } else {
                setCategoryErrors({
                    name: 'Unable to create category right now. Please try again.',
                });
            }
        } finally {
            setIsCreatingCategory(false);
        }
    };

    const startEditingCategory = (category: CategoryRecord) => {
        setEditingCategory(category);
        setEditingCategoryName(category.name);
        setCategoryErrors({});
        setCategoryStatusMessage(null);
    };

    const handleUpdateCategory = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!editingCategory || isUpdatingCategory) {
            return;
        }

        setIsUpdatingCategory(true);
        setCategoryErrors({});
        setCategoryStatusMessage(null);

        try {
            const response = await axios.patch<{ data: CategoryRecord }>(
                `/categories/${editingCategory.id}`,
                {
                    name: editingCategoryName.trim(),
                },
                {
                    headers: { Accept: 'application/json' },
                },
            );

            const updated = response.data.data;
            setCategories((prev) => prev.map((item) => (item.id === updated.id ? updated : item)));
            setTransactions((prev) =>
                prev.map((tx) =>
                    tx.category && tx.category.id === updated.id
                        ? {
                              ...tx,
                              category: {
                                  ...tx.category,
                                  name: updated.name,
                              },
                          }
                        : tx,
                ),
            );
            setEditingCategory(null);
            setEditingCategoryName('');
            setCategoryStatusMessage(`Category renamed to "${updated.name}".`);
        } catch (error: unknown) {
            if (axios.isAxiosError(error) && error.response?.status === 422) {
                const responseErrors = (
                    error.response.data as { errors?: Record<string, string[]> } | undefined
                )?.errors;
                const mapped: Record<string, string> = {};
                if (responseErrors) {
                    Object.entries(responseErrors).forEach(([key, messages]) => {
                        if (Array.isArray(messages) && messages.length > 0) {
                            mapped[key] = messages[0];
                        }
                    });
                }
                setCategoryErrors(mapped);
            } else {
                setCategoryErrors({
                    edit_name: 'Unable to rename category right now.',
                });
            }
        } finally {
            setIsUpdatingCategory(false);
        }
    };

    const handleConfirmArchiveCategory = async () => {
        if (!pendingArchiveCategory || togglingCategoryId !== null) {
            return;
        }

        const target = pendingArchiveCategory;
        setTogglingCategoryId(target.id);
        setCategoryStatusMessage(null);

        try {
            const response = await axios.post<{ data: CategoryRecord }>(
                `/categories/${target.id}/archive`,
                {},
                { headers: { Accept: 'application/json' } },
            );

            const archived = response.data.data;
            setCategories((prev) =>
                prev.map((item) => (item.id === archived.id ? archived : item)),
            );
            setPendingArchiveCategory(null);
            setCategoryStatusMessage(`Category "${archived.name}" archived.`);
        } catch {
            setCategoryErrors({
                general: 'Unable to archive category right now.',
            });
        } finally {
            setTogglingCategoryId(null);
        }
    };

    const handleRestoreCategory = async (category: CategoryRecord) => {
        if (togglingCategoryId !== null) {
            return;
        }

        setTogglingCategoryId(category.id);
        setCategoryStatusMessage(null);

        try {
            const response = await axios.post<{ data: CategoryRecord }>(
                `/categories/${category.id}/restore`,
                {},
                { headers: { Accept: 'application/json' } },
            );

            const restored = response.data.data;
            setCategories((prev) =>
                prev.map((item) => (item.id === restored.id ? restored : item)),
            );
            setCategoryStatusMessage(`Category "${restored.name}" restored.`);
        } catch {
            setCategoryErrors({
                general: 'Unable to restore category right now.',
            });
        } finally {
            setTogglingCategoryId(null);
        }
    };

    const hasActiveFilters = Boolean(
        appliedFilters.type ||
        appliedFilters.financial_account_id ||
        appliedFilters.category_id ||
        appliedFilters.start_date ||
        appliedFilters.end_date,
    );

    const amountExponent = selectedPrimaryAccount?.minor_unit_exponent ?? 2;
    const amountCurrencyCode = selectedPrimaryAccount?.currency_code ?? 'USD';
    const amountCurrencySymbol = selectedPrimaryAccount?.currency_symbol ?? '$';
    const amountPlaceholder = amountExponent === 0 ? 'e.g. 15800' : 'e.g. 125.50';
    const amountHint =
        amountExponent === 0
            ? `Enter a whole-number amount in ${amountCurrencyCode} (${amountCurrencySymbol}). No decimal places.`
            : `Enter an exact amount in ${amountCurrencyCode} (${amountCurrencySymbol}), up to ${amountExponent} decimal places.`;

    // Ensure that if the form is locked in 'uncertain_retry' or 'submitting' and the selected option
    // was subsequently archived, the locked <Select> still includes the selected option label so its value never mutates.
    const primaryAccountSelectOptions = (() => {
        const list = [...activeAccounts];
        if (
            selectedPrimaryAccount &&
            !list.some((account) => account.id === selectedPrimaryAccount.id)
        ) {
            list.push(selectedPrimaryAccount);
        }

        return list.map((account) => ({
            value: String(account.id),
            label: `${account.name} (${account.currency_code} · ${account.type_label})${account.is_archived ? ' [Archived]' : ''}`,
        }));
    })();

    const transferDestinationSelectOptions = (() => {
        const list = [...eligibleTransferDestinations];
        if (draft.transfer_financial_account_id) {
            const selectedDest = accounts.find(
                (account) => String(account.id) === draft.transfer_financial_account_id,
            );
            if (selectedDest && !list.some((account) => account.id === selectedDest.id)) {
                list.push(selectedDest);
            }
        }

        return list.map((account) => ({
            value: String(account.id),
            label: `${account.name} (${account.currency_code} · ${account.type_label})${account.is_archived ? ' [Archived]' : ''}`,
        }));
    })();

    const categorySelectOptions = (() => {
        const list = [...eligibleCategories];
        if (draft.category_id) {
            const selectedCat = categories.find((cat) => String(cat.id) === draft.category_id);
            if (selectedCat && !list.some((cat) => cat.id === selectedCat.id)) {
                list.push(selectedCat);
            }
        }

        return list.map((category) => ({
            value: String(category.id),
            label: `${category.name}${category.is_archived ? ' [Archived]' : ''}`,
        }));
    })();

    return (
        <AuthenticatedLayout title="Transactions">
            <div className="space-y-8">
                <PageHeader
                    eyebrow="Cash Flow & Ledger Activity"
                    title="Transactions"
                    description="Record income, expenses, and same-currency account transfers backed by immutable double-entry journal postings."
                    actions={
                        <div className="flex flex-wrap items-center gap-2.5">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                aria-expanded={showCategoryManager}
                                aria-controls="category-management-panel"
                                onClick={() => setShowCategoryManager((prev) => !prev)}
                            >
                                {showCategoryManager ? 'Hide categories' : 'Manage categories'}
                            </Button>
                            <Button
                                type="button"
                                variant="primary"
                                size="sm"
                                icon={<PlusIcon />}
                                onClick={() => {
                                    descriptionInputRef.current?.focus();
                                }}
                            >
                                Add transaction
                            </Button>
                        </div>
                    }
                />

                {txBanner ? <Alert variant={txBanner.variant}>{txBanner.message}</Alert> : null}

                {/* Collapsible Category Management Panel */}
                {showCategoryManager ? (
                    <Card
                        as="section"
                        id="category-management-panel"
                        aria-labelledby="category-management-heading"
                        padding="md"
                    >
                        <div className="space-y-6">
                            <div className="flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
                                <div>
                                    <p className="font-mono text-xs font-medium tracking-wider text-accent uppercase">
                                        Categories
                                    </p>
                                    <h2
                                        id="category-management-heading"
                                        className="mt-1 text-lg font-semibold tracking-tight text-ink"
                                    >
                                        Manage income and expense categories
                                    </h2>
                                    <p className="mt-0.5 text-xs text-ink-muted">
                                        Category types are permanent once created. Archived
                                        categories remain visible on historical transactions.
                                    </p>
                                </div>
                            </div>

                            {categoryStatusMessage ? (
                                <Alert variant="positive">{categoryStatusMessage}</Alert>
                            ) : null}
                            {categoryErrors.general ? (
                                <Alert variant="negative">{categoryErrors.general}</Alert>
                            ) : null}

                            <form
                                onSubmit={handleCreateCategory}
                                className="grid grid-cols-1 gap-4 rounded-xl border border-border-subtle bg-surface-muted/40 p-4 sm:grid-cols-12 sm:items-start"
                                noValidate
                            >
                                <div className="sm:col-span-6">
                                    <FormField
                                        id="new-category-name"
                                        name="name"
                                        label="New category name"
                                        placeholder="e.g. Groceries, Salary, Utilities"
                                        required
                                        value={newCategoryName}
                                        onChange={(event) => setNewCategoryName(event.target.value)}
                                        error={categoryErrors.name}
                                    />
                                </div>

                                <div className="space-y-1.5 sm:col-span-4">
                                    <label
                                        htmlFor="new-category-type"
                                        className="block text-sm font-medium text-ink"
                                    >
                                        Category type
                                    </label>
                                    <Select
                                        id="new-category-type"
                                        name="type"
                                        value={newCategoryType}
                                        options={[
                                            { value: 'expense', label: 'Expense' },
                                            { value: 'income', label: 'Income' },
                                        ]}
                                        onChange={(event) =>
                                            setNewCategoryType(
                                                event.target.value as CategoryTypeValue,
                                            )
                                        }
                                    />
                                </div>

                                <div className="sm:col-span-2 sm:pt-6">
                                    <Button type="submit" fullWidth loading={isCreatingCategory}>
                                        Add category
                                    </Button>
                                </div>
                            </form>

                            {editingCategory ? (
                                <form
                                    onSubmit={handleUpdateCategory}
                                    className="flex flex-col gap-3 rounded-xl border border-accent-border bg-accent-subtle/40 p-4 sm:flex-row sm:items-end"
                                    noValidate
                                >
                                    <div className="flex-1">
                                        <FormField
                                            id="edit-category-name"
                                            name="edit_name"
                                            label={`Rename "${editingCategory.name}" (${editingCategory.type_label})`}
                                            required
                                            value={editingCategoryName}
                                            onChange={(event) =>
                                                setEditingCategoryName(event.target.value)
                                            }
                                            error={categoryErrors.name ?? categoryErrors.edit_name}
                                        />
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Button
                                            type="submit"
                                            size="sm"
                                            loading={isUpdatingCategory}
                                        >
                                            Save category name
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => {
                                                setEditingCategory(null);
                                                setEditingCategoryName('');
                                                setCategoryErrors({});
                                            }}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </form>
                            ) : null}

                            <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                                {(['expense', 'income'] as const).map((groupType) => {
                                    const groupItems = categories.filter(
                                        (cat) => cat.type === groupType,
                                    );
                                    const groupHeading =
                                        groupType === 'expense'
                                            ? 'Expense Categories'
                                            : 'Income Categories';

                                    return (
                                        <div key={groupType} className="space-y-3">
                                            <h3 className="text-sm font-semibold text-ink">
                                                {groupHeading} ({groupItems.length})
                                            </h3>

                                            {groupItems.length === 0 ? (
                                                <p className="rounded-xl border border-border-subtle bg-surface-muted/30 px-4 py-3 text-xs text-ink-muted">
                                                    No {groupType} categories created yet.
                                                </p>
                                            ) : (
                                                <ul className="space-y-2">
                                                    {groupItems.map((category) => (
                                                        <li
                                                            key={category.id}
                                                            className={`flex flex-wrap items-center justify-between gap-2 rounded-xl border px-3.5 py-2.5 ${
                                                                category.is_archived
                                                                    ? 'border-border-subtle bg-surface-muted/60'
                                                                    : 'border-border-subtle bg-surface-elevated'
                                                            }`}
                                                        >
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <span className="text-sm font-medium text-ink">
                                                                    {category.name}
                                                                </span>
                                                                <Badge
                                                                    variant={
                                                                        category.type === 'income'
                                                                            ? 'positive'
                                                                            : 'neutral'
                                                                    }
                                                                >
                                                                    {category.type_label}
                                                                </Badge>
                                                                {category.is_archived ? (
                                                                    <Badge variant="warning" dot>
                                                                        Archived
                                                                    </Badge>
                                                                ) : null}
                                                            </div>

                                                            <div className="flex items-center gap-1.5">
                                                                <Button
                                                                    type="button"
                                                                    variant="outline"
                                                                    size="sm"
                                                                    icon={<PencilIcon />}
                                                                    aria-label={`Rename category ${category.name}`}
                                                                    onClick={() =>
                                                                        startEditingCategory(
                                                                            category,
                                                                        )
                                                                    }
                                                                >
                                                                    Rename
                                                                </Button>

                                                                {category.is_archived ? (
                                                                    <Button
                                                                        type="button"
                                                                        variant="accent-outline"
                                                                        size="sm"
                                                                        icon={<RestoreIcon />}
                                                                        aria-label={`Restore category ${category.name}`}
                                                                        loading={
                                                                            togglingCategoryId ===
                                                                            category.id
                                                                        }
                                                                        onClick={() =>
                                                                            void handleRestoreCategory(
                                                                                category,
                                                                            )
                                                                        }
                                                                    >
                                                                        Restore
                                                                    </Button>
                                                                ) : (
                                                                    <Button
                                                                        type="button"
                                                                        variant="warning-outline"
                                                                        size="sm"
                                                                        icon={<ArchiveIcon />}
                                                                        aria-label={`Archive category ${category.name}`}
                                                                        loading={
                                                                            togglingCategoryId ===
                                                                            category.id
                                                                        }
                                                                        onClick={() =>
                                                                            setPendingArchiveCategory(
                                                                                category,
                                                                            )
                                                                        }
                                                                    >
                                                                        Archive
                                                                    </Button>
                                                                )}
                                                            </div>
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    </Card>
                ) : null}

                <div className="grid grid-cols-1 gap-8 lg:grid-cols-12">
                    {/* Left Column: Create Transaction Form */}
                    <div className="lg:col-span-5">
                        <Card
                            as="section"
                            aria-labelledby="record-transaction-heading"
                            padding="md"
                        >
                            <div className="mb-5">
                                <p className="font-mono text-xs font-medium tracking-wider text-accent uppercase">
                                    New Entry
                                </p>
                                <h2
                                    id="record-transaction-heading"
                                    className="mt-1 text-lg font-semibold tracking-tight text-ink"
                                >
                                    Record a transaction
                                </h2>
                                <p className="mt-1 text-xs leading-relaxed text-ink-muted">
                                    Every transaction posts a balanced, immutable double-entry
                                    journal record.
                                </p>
                            </div>

                            {/* Mode Switcher: Expense | Income | Transfer */}
                            <div
                                role="group"
                                aria-label="Transaction mode"
                                className="mb-5 grid grid-cols-3 gap-1 rounded-xl border border-border-subtle bg-surface-muted p-1"
                            >
                                {(
                                    [
                                        { value: 'expense', label: 'Expense' },
                                        { value: 'income', label: 'Income' },
                                        { value: 'transfer', label: 'Transfer' },
                                    ] as const
                                ).map((mode) => {
                                    const active = draft.type === mode.value;

                                    return (
                                        <button
                                            key={mode.value}
                                            type="button"
                                            disabled={isFormLocked}
                                            aria-pressed={active}
                                            onClick={() => handleTypeSwitch(mode.value)}
                                            className={`rounded-lg py-2 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring/40 disabled:pointer-events-none disabled:opacity-60 ${
                                                active
                                                    ? 'bg-surface-elevated text-ink shadow-2xs'
                                                    : 'text-ink-muted hover:text-ink'
                                            }`}
                                        >
                                            {mode.label}
                                        </button>
                                    );
                                })}
                            </div>

                            {activeAccounts.length === 0 && !isFormLocked ? (
                                <Alert variant="warning" title="Account Required">
                                    You need at least one active financial account before recording
                                    transactions.{' '}
                                    <Link
                                        href="/accounts"
                                        className="font-semibold underline hover:opacity-80"
                                    >
                                        Create an account
                                    </Link>
                                    .
                                </Alert>
                            ) : (
                                <form
                                    onSubmit={(event) => void handleTransactionSubmit(event)}
                                    className="space-y-4"
                                    noValidate
                                >
                                    <div className="space-y-1.5">
                                        <label
                                            htmlFor="tx-financial_account_id"
                                            className="block text-sm font-medium text-ink"
                                        >
                                            {draft.type === 'income'
                                                ? 'Destination account'
                                                : 'Source account'}
                                        </label>
                                        <Select
                                            id="tx-financial_account_id"
                                            name="financial_account_id"
                                            value={draft.financial_account_id}
                                            disabled={isFormLocked}
                                            invalid={Boolean(txErrors.financial_account_id)}
                                            options={primaryAccountSelectOptions}
                                            onChange={(event) =>
                                                updateDraftField(
                                                    'financial_account_id',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        {txErrors.financial_account_id ? (
                                            <p
                                                role="alert"
                                                className="text-xs font-medium text-negative"
                                            >
                                                {txErrors.financial_account_id}
                                            </p>
                                        ) : null}
                                    </div>

                                    {draft.type === 'transfer' ? (
                                        <div className="space-y-1.5">
                                            <label
                                                htmlFor="tx-transfer_financial_account_id"
                                                className="block text-sm font-medium text-ink"
                                            >
                                                Destination account
                                            </label>
                                            {transferDestinationSelectOptions.length === 0 ? (
                                                <p className="rounded-lg border border-warning-border bg-warning-subtle px-3 py-2 text-xs text-warning">
                                                    Transfers require a second active account in{' '}
                                                    {amountCurrencyCode}. Cross-currency transfers
                                                    are not supported yet.
                                                </p>
                                            ) : (
                                                <Select
                                                    id="tx-transfer_financial_account_id"
                                                    name="transfer_financial_account_id"
                                                    value={draft.transfer_financial_account_id}
                                                    disabled={isFormLocked}
                                                    invalid={Boolean(
                                                        txErrors.transfer_financial_account_id,
                                                    )}
                                                    options={transferDestinationSelectOptions}
                                                    onChange={(event) =>
                                                        updateDraftField(
                                                            'transfer_financial_account_id',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                            {txErrors.transfer_financial_account_id ? (
                                                <p
                                                    role="alert"
                                                    className="text-xs font-medium text-negative"
                                                >
                                                    {txErrors.transfer_financial_account_id}
                                                </p>
                                            ) : null}
                                        </div>
                                    ) : (
                                        <div className="space-y-1.5">
                                            <div className="flex items-center justify-between">
                                                <label
                                                    htmlFor="tx-category_id"
                                                    className="block text-sm font-medium text-ink"
                                                >
                                                    {draft.type === 'income'
                                                        ? 'Income category'
                                                        : 'Expense category'}
                                                </label>
                                                <button
                                                    type="button"
                                                    disabled={isFormLocked}
                                                    onClick={() => {
                                                        setNewCategoryType(
                                                            draft.type as CategoryTypeValue,
                                                        );
                                                        setShowCategoryManager(true);
                                                    }}
                                                    className="text-xs font-medium text-accent-strong hover:underline focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50 dark:text-accent"
                                                >
                                                    + New category
                                                </button>
                                            </div>

                                            {categorySelectOptions.length === 0 ? (
                                                <p className="rounded-lg border border-warning-border bg-warning-subtle px-3 py-2 text-xs text-warning">
                                                    No active {draft.type} categories available. Use{' '}
                                                    <span className="font-semibold">
                                                        Manage categories
                                                    </span>{' '}
                                                    to create one.
                                                </p>
                                            ) : (
                                                <Select
                                                    id="tx-category_id"
                                                    name="category_id"
                                                    value={draft.category_id}
                                                    disabled={isFormLocked}
                                                    invalid={Boolean(txErrors.category_id)}
                                                    options={categorySelectOptions}
                                                    onChange={(event) =>
                                                        updateDraftField(
                                                            'category_id',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                            {txErrors.category_id ? (
                                                <p
                                                    role="alert"
                                                    className="text-xs font-medium text-negative"
                                                >
                                                    {txErrors.category_id}
                                                </p>
                                            ) : null}
                                        </div>
                                    )}

                                    <FormField
                                        id="tx-amount"
                                        name="amount"
                                        type="text"
                                        inputMode={amountExponent === 0 ? 'numeric' : 'decimal'}
                                        label={`Amount (${amountCurrencyCode})`}
                                        placeholder={amountPlaceholder}
                                        hint={amountHint}
                                        required
                                        disabled={isFormLocked}
                                        value={draft.amount}
                                        onChange={(event) =>
                                            updateDraftField('amount', event.target.value)
                                        }
                                        error={txErrors.amount}
                                    />

                                    <FormField
                                        id="tx-entry_date"
                                        name="entry_date"
                                        type="date"
                                        label="Transaction date"
                                        required
                                        disabled={isFormLocked}
                                        value={draft.entry_date}
                                        onChange={(event) =>
                                            updateDraftField('entry_date', event.target.value)
                                        }
                                        error={txErrors.entry_date}
                                    />

                                    <div className="space-y-1.5">
                                        <label
                                            htmlFor="tx-description"
                                            className="block text-sm font-medium text-ink"
                                        >
                                            Description
                                        </label>
                                        <input
                                            ref={descriptionInputRef}
                                            id="tx-description"
                                            name="description"
                                            type="text"
                                            required
                                            disabled={isFormLocked}
                                            placeholder="e.g. Monthly payroll, Weekly groceries"
                                            aria-invalid={txErrors.description ? true : undefined}
                                            aria-describedby={
                                                txErrors.description
                                                    ? 'tx-description-error'
                                                    : undefined
                                            }
                                            value={draft.description}
                                            onChange={(event) =>
                                                updateDraftField('description', event.target.value)
                                            }
                                            className={`block min-h-10 w-full rounded-lg border px-3.5 py-2 text-sm text-ink shadow-2xs transition-colors placeholder:text-ink-muted/75 focus-visible:outline-none focus-visible:ring-2 disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-65 ${
                                                txErrors.description
                                                    ? 'border-negative bg-negative-subtle/40 focus-visible:border-negative focus-visible:ring-negative/25'
                                                    : 'border-border-strong bg-surface-elevated focus-visible:border-accent focus-visible:ring-focus-ring/25'
                                            }`}
                                        />
                                        {txErrors.description ? (
                                            <p
                                                id="tx-description-error"
                                                role="alert"
                                                className="text-xs font-medium text-negative"
                                            >
                                                {txErrors.description}
                                            </p>
                                        ) : null}
                                    </div>

                                    <div className="space-y-1.5">
                                        <label
                                            htmlFor="tx-notes"
                                            className="block text-sm font-medium text-ink"
                                        >
                                            Notes (optional)
                                        </label>
                                        <Textarea
                                            id="tx-notes"
                                            name="notes"
                                            rows={2}
                                            disabled={isFormLocked}
                                            placeholder="Optional memo or reference details."
                                            value={draft.notes}
                                            invalid={Boolean(txErrors.notes)}
                                            onChange={(event) =>
                                                updateDraftField('notes', event.target.value)
                                            }
                                        />
                                        {txErrors.notes ? (
                                            <p
                                                role="alert"
                                                className="text-xs font-medium text-negative"
                                            >
                                                {txErrors.notes}
                                            </p>
                                        ) : null}
                                    </div>

                                    {txErrors.idempotency_key ? (
                                        <p
                                            role="alert"
                                            className="text-xs font-medium text-negative"
                                        >
                                            {txErrors.idempotency_key}
                                        </p>
                                    ) : null}

                                    {unavailableRetryTargetMessage ? (
                                        <Alert variant="warning">
                                            {unavailableRetryTargetMessage}
                                        </Alert>
                                    ) : null}

                                    {submissionState === 'conflict_blocked' ? (
                                        <div className="space-y-2.5 pt-1">
                                            <Button type="submit" fullWidth disabled>
                                                Submission blocked (conflict)
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                fullWidth
                                                onClick={handleStartNewAttempt}
                                            >
                                                Start new attempt
                                            </Button>
                                        </div>
                                    ) : submissionState === 'uncertain_retry' ? (
                                        <div className="space-y-2.5 pt-1">
                                            <Button type="submit" fullWidth>
                                                Retry submission
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                fullWidth
                                                onClick={handleStartNewAttempt}
                                            >
                                                Edit or start new attempt
                                            </Button>
                                        </div>
                                    ) : (
                                        <Button
                                            type="submit"
                                            fullWidth
                                            loading={submissionState === 'submitting'}
                                            disabled={
                                                submissionState === 'submitting' ||
                                                (draft.type === 'transfer' &&
                                                    eligibleTransferDestinations.length === 0) ||
                                                (draft.type !== 'transfer' &&
                                                    eligibleCategories.length === 0)
                                            }
                                        >
                                            {draft.type === 'income'
                                                ? 'Record income'
                                                : draft.type === 'transfer'
                                                  ? 'Record transfer'
                                                  : 'Record expense'}
                                        </Button>
                                    )}
                                </form>
                            )}
                        </Card>
                    </div>

                    {/* Right Column: Filters & Transaction History */}
                    <div className="space-y-6 lg:col-span-7">
                        <Card
                            as="section"
                            aria-labelledby="filter-transactions-heading"
                            padding="sm"
                        >
                            <form onSubmit={handleApplyFilters} className="space-y-4" noValidate>
                                <div className="flex items-center justify-between">
                                    <h2
                                        id="filter-transactions-heading"
                                        className="text-sm font-semibold tracking-tight text-ink"
                                    >
                                        Filter History
                                    </h2>
                                    {hasActiveFilters ? (
                                        <button
                                            type="button"
                                            onClick={handleClearFilters}
                                            className="text-xs font-medium text-accent-strong hover:underline focus-visible:outline-none dark:text-accent"
                                        >
                                            Clear filters
                                        </button>
                                    ) : null}
                                </div>

                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    <div className="space-y-1">
                                        <label
                                            htmlFor="filter-type"
                                            className="block text-xs font-medium text-ink-secondary"
                                        >
                                            Type
                                        </label>
                                        <Select
                                            id="filter-type"
                                            name="type"
                                            value={filterDraft.type}
                                            options={[
                                                { value: '', label: 'All types' },
                                                { value: 'expense', label: 'Expense' },
                                                { value: 'income', label: 'Income' },
                                                { value: 'transfer', label: 'Transfer' },
                                            ]}
                                            onChange={(event) =>
                                                setFilterDraft((prev) => ({
                                                    ...prev,
                                                    type: event.target.value,
                                                }))
                                            }
                                        />
                                    </div>

                                    <div className="space-y-1">
                                        <label
                                            htmlFor="filter-account"
                                            className="block text-xs font-medium text-ink-secondary"
                                        >
                                            Account
                                        </label>
                                        <Select
                                            id="filter-account"
                                            name="financial_account_id"
                                            value={filterDraft.financial_account_id}
                                            options={[
                                                { value: '', label: 'All accounts' },
                                                ...accounts.map((account) => ({
                                                    value: String(account.id),
                                                    label: `${account.name} (${account.currency_code})${account.is_archived ? ' [Archived]' : ''}`,
                                                })),
                                            ]}
                                            onChange={(event) =>
                                                setFilterDraft((prev) => ({
                                                    ...prev,
                                                    financial_account_id: event.target.value,
                                                }))
                                            }
                                        />
                                    </div>

                                    <div className="space-y-1">
                                        <label
                                            htmlFor="filter-category"
                                            className="block text-xs font-medium text-ink-secondary"
                                        >
                                            Category
                                        </label>
                                        <Select
                                            id="filter-category"
                                            name="category_id"
                                            value={filterDraft.category_id}
                                            options={[
                                                { value: '', label: 'All categories' },
                                                ...categories.map((category) => ({
                                                    value: String(category.id),
                                                    label: `${category.name} (${category.type_label})${category.is_archived ? ' [Archived]' : ''}`,
                                                })),
                                            ]}
                                            onChange={(event) =>
                                                setFilterDraft((prev) => ({
                                                    ...prev,
                                                    category_id: event.target.value,
                                                }))
                                            }
                                        />
                                    </div>

                                    <div className="space-y-1">
                                        <label
                                            htmlFor="filter-start_date"
                                            className="block text-xs font-medium text-ink-secondary"
                                        >
                                            Start date
                                        </label>
                                        <input
                                            id="filter-start_date"
                                            name="start_date"
                                            type="date"
                                            value={filterDraft.start_date}
                                            onChange={(event) =>
                                                setFilterDraft((prev) => ({
                                                    ...prev,
                                                    start_date: event.target.value,
                                                }))
                                            }
                                            className="block min-h-10 w-full rounded-lg border border-border-strong bg-surface-elevated px-3 py-2 text-sm text-ink shadow-2xs focus-visible:border-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring/25"
                                        />
                                    </div>

                                    <div className="space-y-1">
                                        <label
                                            htmlFor="filter-end_date"
                                            className="block text-xs font-medium text-ink-secondary"
                                        >
                                            End date
                                        </label>
                                        <input
                                            id="filter-end_date"
                                            name="end_date"
                                            type="date"
                                            value={filterDraft.end_date}
                                            onChange={(event) =>
                                                setFilterDraft((prev) => ({
                                                    ...prev,
                                                    end_date: event.target.value,
                                                }))
                                            }
                                            className="block min-h-10 w-full rounded-lg border border-border-strong bg-surface-elevated px-3 py-2 text-sm text-ink shadow-2xs focus-visible:border-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring/25"
                                        />
                                    </div>

                                    <div className="flex items-end gap-2">
                                        <Button
                                            type="submit"
                                            variant="secondary"
                                            size="sm"
                                            fullWidth
                                            loading={isLoadingHistory}
                                        >
                                            Apply filters
                                        </Button>
                                    </div>
                                </div>
                            </form>
                        </Card>

                        {historyError ? <Alert variant="negative">{historyError}</Alert> : null}
                        {historyStatusMessage ? (
                            <Alert variant="positive">{historyStatusMessage}</Alert>
                        ) : null}

                        <section
                            aria-labelledby="transaction-history-heading"
                            className="space-y-4"
                        >
                            <div className="flex items-center justify-between">
                                <h2
                                    id="transaction-history-heading"
                                    className="text-base font-semibold tracking-tight text-ink"
                                >
                                    Transaction History ({meta.total})
                                </h2>
                                {meta.total > 0 && meta.from !== null && meta.to !== null ? (
                                    <span className="font-mono text-xs text-ink-muted">
                                        Showing {meta.from}-{meta.to} of {meta.total}
                                    </span>
                                ) : null}
                            </div>

                            {transactions.length === 0 ? (
                                <EmptyState
                                    eyebrow={
                                        hasActiveFilters ? 'No Matching Records' : 'No Activity Yet'
                                    }
                                    title={
                                        hasActiveFilters
                                            ? 'No transactions match your current filters.'
                                            : 'You have not recorded any transactions yet.'
                                    }
                                    description={
                                        hasActiveFilters
                                            ? 'Try adjusting or clearing your filter criteria to see more ledger activity.'
                                            : 'Use the form to record your first income, expense, or account transfer.'
                                    }
                                >
                                    {hasActiveFilters ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={handleClearFilters}
                                        >
                                            Clear filters
                                        </Button>
                                    ) : null}
                                </EmptyState>
                            ) : (
                                <div className="space-y-3">
                                    {transactions.map((tx) => {
                                        const isReversal = tx.is_reversal;
                                        const isReversed = tx.is_reversed;
                                        const canReverse = isTransactionReversible(tx);

                                        const badgeVariant = isReversal
                                            ? 'warning'
                                            : tx.type === 'income'
                                              ? 'positive'
                                              : tx.type === 'expense'
                                                ? 'negative'
                                                : 'accent';

                                        const amountPrefix = isReversal
                                            ? ''
                                            : tx.type === 'income'
                                              ? '+'
                                              : tx.type === 'expense'
                                                ? '-'
                                                : '';

                                        const amountClass = isReversal
                                            ? 'text-ink-secondary'
                                            : tx.type === 'income'
                                              ? 'text-positive'
                                              : tx.type === 'expense'
                                                ? 'text-ink'
                                                : 'text-accent-strong dark:text-accent';

                                        return (
                                            <Card key={tx.id} padding="sm">
                                                <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                                    <div className="min-w-0 flex-1 space-y-1.5">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-mono text-xs text-ink-muted">
                                                                {tx.entry_date}
                                                            </span>
                                                            {isReversal ? (
                                                                <Badge variant="warning">
                                                                    Reversal
                                                                </Badge>
                                                            ) : (
                                                                <Badge variant={badgeVariant}>
                                                                    {tx.type_label}
                                                                </Badge>
                                                            )}
                                                            {isReversal ? (
                                                                <Badge variant="neutral">
                                                                    {tx.type_label} offset
                                                                </Badge>
                                                            ) : null}
                                                            {isReversed ? (
                                                                <Badge variant="neutral">
                                                                    Reversed
                                                                </Badge>
                                                            ) : null}
                                                            <Badge variant="neutral">
                                                                {tx.currency_code}
                                                            </Badge>
                                                        </div>

                                                        <h3 className="text-base font-semibold text-ink">
                                                            {tx.description}
                                                        </h3>

                                                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-secondary">
                                                            {tx.type === 'transfer' ? (
                                                                <span>
                                                                    <span className="text-ink-muted">
                                                                        From:
                                                                    </span>{' '}
                                                                    <strong className="font-medium text-ink">
                                                                        {tx.financial_account
                                                                            ?.name ?? 'Account'}
                                                                    </strong>
                                                                    {tx.financial_account
                                                                        ?.is_archived
                                                                        ? ' (Archived)'
                                                                        : ''}{' '}
                                                                    <span
                                                                        className="text-ink-muted"
                                                                        aria-hidden="true"
                                                                    >
                                                                        &rarr;
                                                                    </span>{' '}
                                                                    <span className="text-ink-muted">
                                                                        To:
                                                                    </span>{' '}
                                                                    <strong className="font-medium text-ink">
                                                                        {tx
                                                                            .transfer_financial_account
                                                                            ?.name ?? 'Account'}
                                                                    </strong>
                                                                    {tx.transfer_financial_account
                                                                        ?.is_archived
                                                                        ? ' (Archived)'
                                                                        : ''}
                                                                </span>
                                                            ) : (
                                                                <>
                                                                    <span>
                                                                        <span className="text-ink-muted">
                                                                            Account:
                                                                        </span>{' '}
                                                                        <strong className="font-medium text-ink">
                                                                            {tx.financial_account
                                                                                ?.name ?? 'Account'}
                                                                        </strong>
                                                                        {tx.financial_account
                                                                            ?.is_archived
                                                                            ? ' (Archived)'
                                                                            : ''}
                                                                    </span>
                                                                    {tx.category ? (
                                                                        <span>
                                                                            <span className="text-ink-muted">
                                                                                Category:
                                                                            </span>{' '}
                                                                            <strong className="font-medium text-ink">
                                                                                {tx.category.name}
                                                                            </strong>
                                                                            {tx.category.is_archived
                                                                                ? ' (Archived)'
                                                                                : ''}
                                                                        </span>
                                                                    ) : null}
                                                                </>
                                                            )}
                                                        </div>

                                                        {isReversal &&
                                                        tx.reversed_transaction_id !== null ? (
                                                            <p className="text-xs text-ink-secondary">
                                                                Compensating entry for transaction #
                                                                {tx.reversed_transaction_id}
                                                                {tx.reversal_reason
                                                                    ? ` · Reason: ${tx.reversal_reason}`
                                                                    : ''}
                                                            </p>
                                                        ) : null}

                                                        {isReversed &&
                                                        tx.reversal_transaction_id !== null ? (
                                                            <p className="text-xs text-ink-secondary">
                                                                Reversed by compensating entry #
                                                                {tx.reversal_transaction_id}
                                                            </p>
                                                        ) : null}

                                                        {tx.notes ? (
                                                            <p className="text-xs leading-relaxed text-ink-muted">
                                                                {tx.notes}
                                                            </p>
                                                        ) : null}
                                                    </div>

                                                    <div className="flex flex-row items-center justify-between gap-3 border-t border-border-subtle pt-2.5 sm:flex-col sm:items-end sm:border-t-0 sm:pt-0">
                                                        <div className="text-right">
                                                            <span className="font-mono text-xs text-ink-muted sm:hidden">
                                                                Amount
                                                            </span>
                                                            <p
                                                                data-testid={`transaction-amount-${tx.id}`}
                                                                className={`font-mono text-lg font-semibold tabular-nums ${amountClass}`}
                                                            >
                                                                {amountPrefix}
                                                                {tx.amount_formatted}
                                                            </p>
                                                            {isReversal ? (
                                                                <p className="mt-0.5 text-[11px] font-medium tracking-wide text-ink-muted uppercase">
                                                                    Compensating
                                                                </p>
                                                            ) : null}
                                                        </div>
                                                        {canReverse ? (
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                size="sm"
                                                                data-testid={`reverse-transaction-${tx.id}`}
                                                                disabled={
                                                                    isReverseFormLocked ||
                                                                    isLoadingHistory
                                                                }
                                                                onClick={() =>
                                                                    openReverseDialog(tx)
                                                                }
                                                            >
                                                                Reverse
                                                            </Button>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            </Card>
                                        );
                                    })}
                                </div>
                            )}

                            {meta.last_page > 1 ? (
                                <>
                                    <Separator />
                                    <nav
                                        aria-label="Transaction pagination"
                                        className="flex items-center justify-between gap-4 pt-2"
                                    >
                                        <span className="font-mono text-xs text-ink-muted">
                                            Page {meta.current_page} of {meta.last_page}
                                        </span>
                                        <div className="flex items-center gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    meta.current_page <= 1 || isLoadingHistory
                                                }
                                                onClick={() =>
                                                    handlePageChange(meta.current_page - 1)
                                                }
                                            >
                                                Previous
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    meta.current_page >= meta.last_page ||
                                                    isLoadingHistory
                                                }
                                                onClick={() =>
                                                    handlePageChange(meta.current_page + 1)
                                                }
                                            >
                                                Next
                                            </Button>
                                        </div>
                                    </nav>
                                </>
                            ) : null}
                        </section>
                    </div>
                </div>
            </div>

            <ConfirmDialog
                open={pendingArchiveCategory !== null}
                title={
                    pendingArchiveCategory
                        ? `Archive ${pendingArchiveCategory.name}?`
                        : 'Archive category?'
                }
                description={
                    <p>
                        Archiving{' '}
                        <span className="font-semibold text-ink">
                            {pendingArchiveCategory?.name}
                        </span>{' '}
                        hides it from new transaction selections while preserving it on all
                        historical transactions. You can restore this category at any time.
                    </p>
                }
                confirmLabel="Confirm archive"
                cancelLabel="Cancel"
                confirmVariant="warning-outline"
                confirmIcon={<ArchiveIcon />}
                loading={
                    pendingArchiveCategory !== null &&
                    togglingCategoryId === pendingArchiveCategory.id
                }
                onConfirm={() => void handleConfirmArchiveCategory()}
                onCancel={() => {
                    if (togglingCategoryId === null) {
                        setPendingArchiveCategory(null);
                    }
                }}
            />

            <ConfirmDialog
                open={reverseTarget !== null}
                title="Reverse this transaction?"
                description={
                    reverseTarget ? (
                        <div className="space-y-3">
                            <p>
                                This posts a new compensating transaction. The original entry is
                                never deleted or edited and remains in your immutable ledger
                                history.
                            </p>
                            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 rounded-lg border border-border-subtle bg-surface-muted/60 px-3 py-2.5 text-xs text-ink">
                                <dt className="text-ink-muted">Type</dt>
                                <dd className="font-medium">{reverseTarget.type_label}</dd>
                                <dt className="text-ink-muted">Account</dt>
                                <dd className="font-medium">
                                    {accountLabelForTransaction(reverseTarget)}
                                </dd>
                                <dt className="text-ink-muted">Amount</dt>
                                <dd className="font-mono font-medium">
                                    {reverseTarget.amount_formatted} ({reverseTarget.currency_code})
                                </dd>
                                <dt className="text-ink-muted">Date</dt>
                                <dd className="font-mono font-medium">
                                    {reverseTarget.entry_date}
                                </dd>
                            </dl>
                            {reverseBanner ? (
                                <Alert variant={reverseBanner.variant}>
                                    {reverseBanner.message}
                                </Alert>
                            ) : null}
                            {reverseSubmissionState === 'uncertain_retry' ? (
                                <p className="text-xs text-warning">
                                    Your reason and idempotency key are locked so a retry sends the
                                    exact same request.
                                </p>
                            ) : null}
                            {reverseSubmissionState === 'conflict_blocked' ? (
                                <p className="text-xs text-negative">
                                    Automatic retry is blocked for this conflict. Refresh history
                                    before considering another reversal attempt.
                                </p>
                            ) : null}
                        </div>
                    ) : (
                        <p>Confirm this reversal.</p>
                    )
                }
                confirmLabel={
                    reverseSubmissionState === 'uncertain_retry'
                        ? 'Retry exact reversal'
                        : reverseSubmissionState === 'conflict_blocked'
                          ? 'Close'
                          : 'Confirm reversal'
                }
                cancelLabel={
                    reverseSubmissionState === 'uncertain_retry'
                        ? 'Cancel and review'
                        : reverseSubmissionState === 'conflict_blocked'
                          ? 'Dismiss'
                          : 'Cancel'
                }
                confirmVariant="warning-outline"
                loading={reverseSubmissionState === 'submitting'}
                confirmDisabled={
                    reverseSubmissionState === 'conflict_blocked'
                        ? false
                        : !canConfirmReverse || reverseSubmissionState === 'submitting'
                }
                initialFocusRef={reverseReasonInputRef}
                onConfirm={() => {
                    if (reverseSubmissionState === 'conflict_blocked') {
                        closeReverseDialog();

                        return;
                    }

                    void submitReverseTransaction();
                }}
                onCancel={() => {
                    if (reverseSubmissionState === 'submitting') {
                        return;
                    }

                    if (reverseSubmissionState === 'uncertain_retry') {
                        closeReverseDialog();

                        return;
                    }

                    if (reverseSubmissionState === 'conflict_blocked') {
                        closeReverseDialog();

                        return;
                    }

                    closeReverseDialog();
                }}
            >
                {reverseTarget !== null && reverseSubmissionState !== 'conflict_blocked' ? (
                    <div className="space-y-1.5">
                        <label
                            htmlFor="reverse-reason"
                            className="block text-sm font-medium text-ink"
                        >
                            Reversal reason{' '}
                            <span className="font-normal text-ink-muted">(required)</span>
                        </label>
                        <Textarea
                            ref={(element) => {
                                reverseReasonInputRef.current = element;
                            }}
                            id="reverse-reason"
                            name="reason"
                            rows={3}
                            maxLength={255}
                            value={reverseReason}
                            invalid={Boolean(reverseErrors.reason)}
                            disabled={isReverseFormLocked}
                            aria-required="true"
                            aria-describedby={
                                reverseErrors.reason
                                    ? 'reverse-reason-error'
                                    : 'reverse-reason-hint'
                            }
                            placeholder="Why is this transaction being reversed?"
                            onChange={(event) => {
                                if (isReverseFormLocked) {
                                    return;
                                }
                                setReverseReason(event.target.value);
                                if (reverseErrors.reason) {
                                    setReverseErrors((prev) => {
                                        const next = { ...prev };
                                        delete next.reason;

                                        return next;
                                    });
                                }
                            }}
                        />
                        {reverseErrors.reason ? (
                            <p
                                id="reverse-reason-error"
                                role="alert"
                                className="text-xs font-medium text-negative"
                            >
                                {reverseErrors.reason}
                            </p>
                        ) : (
                            <p id="reverse-reason-hint" className="text-xs text-ink-muted">
                                Maximum 255 characters. This is stored with the compensating entry.
                            </p>
                        )}
                    </div>
                ) : null}
                {reverseSubmissionState === 'conflict_blocked' &&
                reverseTarget !== null &&
                isTransactionReversible(reverseTarget) ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={startNewReverseAttempt}
                    >
                        Start new reversal attempt
                    </Button>
                ) : null}
            </ConfirmDialog>
        </AuthenticatedLayout>
    );
}
