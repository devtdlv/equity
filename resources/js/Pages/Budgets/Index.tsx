import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { type FormEvent, useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { Alert } from '@/Components/Alert';
import { Badge } from '@/Components/Badge';
import { Button } from '@/Components/Button';
import { EmptyState } from '@/Components/EmptyState';
import { FormField } from '@/Components/FormField';
import { PageHeader } from '@/Components/PageHeader';
import { Select } from '@/Components/Select';
import { Skeleton } from '@/Components/Skeleton';
import { AuthenticatedLayout } from '@/Layouts/AuthenticatedLayout';
import type {
    BudgetRecord,
    BudgetSpendingSummaryRecord,
    BudgetsIndexPageProps,
    CategoryRecord,
    CurrencyOption,
    SharedPageProps,
} from '@/Types';
import {
    findBudgetForPeriod,
    formatMonthLabel,
    isPastUtcMonth,
    periodStartFor,
    shiftMonth,
} from '@/Utilities/budgetPeriod';
import {
    computeUtilization,
    progressBarPercent,
    remainingAllocatedMinor,
} from '@/Utilities/budgetProgress';
import { formatMinorUnits, minorUnitsToDecimalString } from '@/Utilities/money';

type BannerState = {
    variant: 'info' | 'positive' | 'warning' | 'negative';
    message: string;
} | null;

type LoadState = 'idle' | 'loading' | 'ready' | 'error';

interface AllocationDraftState {
    [categoryId: number]: string;
}

interface AllocationErrorState {
    [categoryId: number]: string | undefined;
}

interface AllocationSavingState {
    [categoryId: number]: boolean;
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

function tenantSafeApiMessage(
    status: number | undefined,
    fallback: string,
    serverMessage?: string,
): string {
    if (status === 401) {
        return 'Your session has expired. Please sign in again to continue.';
    }

    if (status === 403) {
        return 'You do not have permission to manage this budget.';
    }

    if (status === 404) {
        return 'That budget could not be found. Refreshing the overview.';
    }

    if (status === 422 && serverMessage) {
        return serverMessage;
    }

    if (status === 409 && serverMessage) {
        return serverMessage;
    }

    if (status !== undefined && status >= 500) {
        return 'The server could not complete this request. Please try again shortly.';
    }

    return fallback;
}

export default function BudgetsIndex() {
    const page = usePage<SharedPageProps & BudgetsIndexPageProps>();
    const currencies = useMemo(() => page.props.currencies ?? [], [page.props.currencies]);
    const categories = useMemo(() => page.props.categories ?? [], [page.props.categories]);
    const initialYear = page.props.initial_year ?? new Date().getUTCFullYear();
    const initialMonth = page.props.initial_month ?? new Date().getUTCMonth() + 1;

    const [year, setYear] = useState(initialYear);
    const [month, setMonth] = useState(initialMonth);
    const [currencyCode, setCurrencyCode] = useState(() => currencies[0]?.code ?? 'USD');
    const [loadState, setLoadState] = useState<LoadState>('loading');
    const [summary, setSummary] = useState<BudgetSpendingSummaryRecord | null>(null);
    const [budgetMissing, setBudgetMissing] = useState(false);
    const [banner, setBanner] = useState<BannerState>(null);
    const [creating, setCreating] = useState(false);
    const [createBlockedPendingReconcile, setCreateBlockedPendingReconcile] = useState(false);
    const [allocationDrafts, setAllocationDrafts] = useState<AllocationDraftState>({});
    const [allocationErrors, setAllocationErrors] = useState<AllocationErrorState>({});
    const [allocationSaving, setAllocationSaving] = useState<AllocationSavingState>({});

    const requestSeq = useRef(0);
    const monthLabelId = useId();
    const currencyLabelId = useId();

    const selectedCurrency: CurrencyOption | undefined = useMemo(
        () => currencies.find((currency) => currency.code === currencyCode) ?? currencies[0],
        [currencies, currencyCode],
    );

    const exponent = selectedCurrency?.minor_unit_exponent ?? 2;
    const symbol = selectedCurrency?.symbol ?? currencyCode;
    const readOnly = isPastUtcMonth(year, month);
    const monthLabel = formatMonthLabel(year, month);

    const expenseCategories = useMemo(
        () => categories.filter((category) => category.type === 'expense'),
        [categories],
    );

    const allocationByCategoryId = useMemo(() => {
        const map = new Map<number, BudgetSpendingSummaryRecord['allocations'][number]>();
        summary?.allocations.forEach((row) => {
            map.set(row.category_id, row);
        });
        return map;
    }, [summary]);

    const visibleCategoryRows = useMemo(() => {
        const rows: Array<{
            category: CategoryRecord;
            allocation: BudgetSpendingSummaryRecord['allocations'][number] | null;
        }> = [];

        expenseCategories.forEach((category) => {
            const allocation = allocationByCategoryId.get(category.id) ?? null;
            if (category.is_archived && allocation === null) {
                return;
            }
            rows.push({ category, allocation });
        });

        summary?.allocations.forEach((allocation) => {
            const alreadyListed = expenseCategories.some(
                (category) => category.id === allocation.category_id,
            );
            if (!alreadyListed) {
                rows.push({
                    category: {
                        id: allocation.category_id,
                        name: allocation.category_name,
                        type: 'expense',
                        type_label: 'Expense',
                        classification: 'expense',
                        is_archived: allocation.category_is_archived,
                        archived_at: null,
                    },
                    allocation,
                });
            }
        });

        return rows.sort((a, b) => a.category.name.localeCompare(b.category.name));
    }, [allocationByCategoryId, expenseCategories, summary]);

    const formatAmount = useCallback(
        (amountMinor: number | bigint) =>
            formatMinorUnits(amountMinor, currencyCode, exponent, symbol),
        [currencyCode, exponent, symbol],
    );

    const hydrateDraftsFromSummary = useCallback(
        (nextSummary: BudgetSpendingSummaryRecord) => {
            const drafts: AllocationDraftState = {};
            nextSummary.allocations.forEach((row) => {
                drafts[row.category_id] = minorUnitsToDecimalString(
                    row.allocated_minor,
                    nextSummary.budget.currency?.minor_unit_exponent ?? exponent,
                );
            });
            setAllocationDrafts(drafts);
            setAllocationErrors({});
        },
        [exponent],
    );

    const fetchBudgetList = useCallback(async (): Promise<BudgetRecord[]> => {
        const response = await axios.get<{ data: BudgetRecord[] }>('/budgets', {
            params: {
                currency_code: currencyCode,
                per_page: 100,
            },
            headers: {
                Accept: 'application/json',
            },
        });

        return response.data.data ?? [];
    }, [currencyCode]);

    const fetchSummary = useCallback(
        async (budgetId: number): Promise<BudgetSpendingSummaryRecord> => {
            const response = await axios.get<{ data: BudgetSpendingSummaryRecord }>(
                `/budgets/${budgetId}`,
                {
                    headers: {
                        Accept: 'application/json',
                    },
                },
            );

            return response.data.data;
        },
        [],
    );

    const reconcileExistingBudget = useCallback(async (): Promise<BudgetRecord | null> => {
        const budgets = await fetchBudgetList();
        return findBudgetForPeriod(budgets, year, month, currencyCode);
    }, [currencyCode, fetchBudgetList, month, year]);

    const loadSelectedPeriod = useCallback(async () => {
        const seq = ++requestSeq.current;
        setLoadState('loading');
        setBanner(null);

        try {
            const budgets = await fetchBudgetList();
            if (seq !== requestSeq.current) {
                return;
            }

            const match = findBudgetForPeriod(budgets, year, month, currencyCode);

            if (!match) {
                setSummary(null);
                setBudgetMissing(true);
                setAllocationDrafts({});
                setAllocationErrors({});
                setLoadState('ready');
                return;
            }

            const nextSummary = await fetchSummary(match.id);
            if (seq !== requestSeq.current) {
                return;
            }

            setSummary(nextSummary);
            setBudgetMissing(false);
            hydrateDraftsFromSummary(nextSummary);
            setLoadState('ready');
        } catch (error) {
            if (seq !== requestSeq.current) {
                return;
            }

            setSummary(null);
            setBudgetMissing(false);
            setLoadState('error');

            if (axios.isAxiosError(error)) {
                const status = error.response?.status;
                setBanner({
                    variant: 'negative',
                    message: tenantSafeApiMessage(
                        status,
                        'Unable to load budgets for this month and currency.',
                    ),
                });
                return;
            }

            setBanner({
                variant: 'negative',
                message: 'Unable to load budgets for this month and currency.',
            });
        }
    }, [currencyCode, fetchBudgetList, fetchSummary, hydrateDraftsFromSummary, month, year]);

    useEffect(() => {
        void loadSelectedPeriod();
    }, [loadSelectedPeriod]);

    useEffect(() => {
        if (currencies.length === 0) {
            return;
        }

        if (!currencies.some((currency) => currency.code === currencyCode)) {
            setCurrencyCode(currencies[0].code);
        }
    }, [currencies, currencyCode]);

    const handlePreviousMonth = () => {
        const next = shiftMonth(year, month, -1);
        setYear(next.year);
        setMonth(next.month);
    };

    const handleNextMonth = () => {
        const next = shiftMonth(year, month, 1);
        setYear(next.year);
        setMonth(next.month);
    };

    const handleCreateBudget = async () => {
        if (creating || readOnly) {
            return;
        }

        if (createBlockedPendingReconcile) {
            setBanner({
                variant: 'warning',
                message:
                    'A previous create request did not finish clearly. Checking for an existing budget before trying again.',
            });

            try {
                const existing = await reconcileExistingBudget();
                if (existing) {
                    const nextSummary = await fetchSummary(existing.id);
                    setSummary(nextSummary);
                    setBudgetMissing(false);
                    hydrateDraftsFromSummary(nextSummary);
                    setCreateBlockedPendingReconcile(false);
                    setBanner({
                        variant: 'info',
                        message:
                            'An existing budget for this month and currency was found and loaded.',
                    });
                    return;
                }

                setCreateBlockedPendingReconcile(false);
            } catch {
                setBanner({
                    variant: 'negative',
                    message:
                        'Could not confirm whether the budget already exists. Please try again when the connection is stable.',
                });
                return;
            }
        }

        setCreating(true);
        setBanner(null);

        try {
            const response = await axios.post<{
                data: BudgetRecord;
                meta?: { was_recently_created?: boolean };
            }>(
                '/budgets',
                {
                    currency_code: currencyCode,
                    year,
                    month,
                },
                {
                    headers: {
                        Accept: 'application/json',
                    },
                },
            );

            const created = response.data.data;
            const nextSummary = await fetchSummary(created.id);
            setSummary(nextSummary);
            setBudgetMissing(false);
            hydrateDraftsFromSummary(nextSummary);
            setCreateBlockedPendingReconcile(false);
            setBanner({
                variant: 'positive',
                message: `Budget created for ${monthLabel} (${currencyCode}).`,
            });
        } catch (error) {
            if (axios.isAxiosError(error)) {
                const status = error.response?.status;
                const data = error.response?.data as
                    | {
                          message?: string;
                          errors?: Record<string, string[] | string>;
                      }
                    | undefined;

                if (status === 409) {
                    try {
                        const existing = await reconcileExistingBudget();
                        if (existing) {
                            const nextSummary = await fetchSummary(existing.id);
                            setSummary(nextSummary);
                            setBudgetMissing(false);
                            hydrateDraftsFromSummary(nextSummary);
                            setCreateBlockedPendingReconcile(false);
                            setBanner({
                                variant: 'warning',
                                message:
                                    firstValidationMessage(data?.errors, [
                                        'budget',
                                        'currency_code',
                                    ]) ??
                                    data?.message ??
                                    'A budget already exists for this month and currency. Loaded the existing budget.',
                            });
                            return;
                        }
                    } catch {
                        // fall through to generic conflict message
                    }

                    setBanner({
                        variant: 'warning',
                        message:
                            data?.message ?? 'A budget already exists for this month and currency.',
                    });
                    return;
                }

                if (error.response === undefined) {
                    setCreateBlockedPendingReconcile(true);
                    setBanner({
                        variant: 'warning',
                        message:
                            'The create request did not complete clearly. Equity will confirm whether a budget already exists before allowing another create attempt.',
                    });
                    return;
                }

                const validationMessage = firstValidationMessage(data?.errors, [
                    'currency_code',
                    'year',
                    'month',
                    'budget',
                ]);

                setBanner({
                    variant: 'negative',
                    message: tenantSafeApiMessage(
                        status,
                        'Unable to create this budget.',
                        validationMessage ?? data?.message,
                    ),
                });
                return;
            }

            setCreateBlockedPendingReconcile(true);
            setBanner({
                variant: 'warning',
                message:
                    'The create request did not complete clearly. Equity will confirm whether a budget already exists before allowing another create attempt.',
            });
        } finally {
            setCreating(false);
        }
    };

    const handleSaveAllocation = async (category: CategoryRecord, event: FormEvent) => {
        event.preventDefault();

        if (!summary || readOnly || category.is_archived) {
            return;
        }

        const amount = (allocationDrafts[category.id] ?? '').trim();
        if (amount === '') {
            setAllocationErrors((current) => ({
                ...current,
                [category.id]: 'Enter an amount, including 0 for an explicit zero allocation.',
            }));
            return;
        }

        setAllocationSaving((current) => ({ ...current, [category.id]: true }));
        setAllocationErrors((current) => ({ ...current, [category.id]: undefined }));
        setBanner(null);

        try {
            const response = await axios.put<{
                data: {
                    summary: BudgetSpendingSummaryRecord;
                };
            }>(
                `/budgets/${summary.budget.id}/allocations/${category.id}`,
                { amount },
                {
                    headers: {
                        Accept: 'application/json',
                    },
                },
            );

            const nextSummary = response.data.data.summary;
            setSummary(nextSummary);
            hydrateDraftsFromSummary(nextSummary);
            setBanner({
                variant: 'positive',
                message: `Updated allocation for ${category.name}.`,
            });
        } catch (error) {
            if (axios.isAxiosError(error)) {
                const status = error.response?.status;
                const data = error.response?.data as
                    | {
                          message?: string;
                          errors?: Record<string, string[] | string>;
                      }
                    | undefined;

                const fieldMessage = firstValidationMessage(data?.errors, [
                    'amount',
                    'category_id',
                    'budget',
                    'allocation',
                ]);

                if (status === 422 && fieldMessage) {
                    setAllocationErrors((current) => ({
                        ...current,
                        [category.id]: fieldMessage,
                    }));
                }

                setBanner({
                    variant: 'negative',
                    message: tenantSafeApiMessage(
                        status,
                        `Unable to update the allocation for ${category.name}.`,
                        fieldMessage ?? data?.message,
                    ),
                });
                return;
            }

            setBanner({
                variant: 'negative',
                message: `Unable to update the allocation for ${category.name}.`,
            });
        } finally {
            setAllocationSaving((current) => ({ ...current, [category.id]: false }));
        }
    };

    const totals = summary?.totals;
    const remainingMinor = totals
        ? remainingAllocatedMinor(totals.allocated_minor, totals.allocated_actual_minor)
        : 0n;
    const overallUtilization = totals
        ? computeUtilization(totals.allocated_minor, totals.allocated_actual_minor)
        : null;

    const currencyOptions = currencies.map((currency) => ({
        value: currency.code,
        label: `${currency.code} (${currency.symbol})`,
    }));

    return (
        <AuthenticatedLayout title="Budgets">
            <div className="space-y-8">
                <PageHeader
                    eyebrow="Cash-Flow Planning"
                    title="Budgets"
                    description="Allocate monthly spending by category in a single currency. Totals come from the Equity budget API."
                    headingId="budgets-heading"
                    actions={
                        readOnly ? (
                            <Badge variant="warning">Past month · read-only</Badge>
                        ) : (
                            <Badge variant="accent">Current planning period</Badge>
                        )
                    }
                />

                <section
                    aria-labelledby="budget-controls-heading"
                    className="rounded-2xl border border-border-subtle bg-surface-elevated p-5 shadow-xs sm:p-6"
                >
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div className="space-y-3">
                            <h2
                                id="budget-controls-heading"
                                className="text-sm font-semibold tracking-tight text-ink"
                            >
                                Period and currency
                            </h2>
                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={handlePreviousMonth}
                                    aria-label="Previous month"
                                >
                                    Previous
                                </Button>
                                <p
                                    id={monthLabelId}
                                    className="min-w-[10rem] text-center text-base font-semibold text-ink"
                                    aria-live="polite"
                                >
                                    {monthLabel}
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={handleNextMonth}
                                    aria-label="Next month"
                                >
                                    Next
                                </Button>
                            </div>
                            <p className="font-mono text-xs text-ink-muted">
                                Period start {periodStartFor(year, month)} · UTC calendar month
                            </p>
                        </div>

                        <div className="w-full max-w-xs space-y-1.5">
                            <label
                                id={currencyLabelId}
                                htmlFor="budget-currency"
                                className="block text-sm font-medium text-ink"
                            >
                                Currency
                            </label>
                            <Select
                                id="budget-currency"
                                aria-labelledby={currencyLabelId}
                                value={currencyCode}
                                options={currencyOptions}
                                onChange={(event) => setCurrencyCode(event.target.value)}
                            />
                            <p className="text-xs text-ink-muted">
                                Budgets never convert or combine currencies.
                            </p>
                        </div>
                    </div>
                </section>

                {banner ? <Alert variant={banner.variant}>{banner.message}</Alert> : null}

                {loadState === 'loading' ? (
                    <div className="space-y-4" aria-busy="true">
                        <Skeleton className="h-28 w-full" label="Loading budget overview" />
                        <Skeleton className="h-40 w-full" label="Loading category allocations" />
                    </div>
                ) : null}

                {loadState === 'error' ? (
                    <EmptyState
                        eyebrow="Unavailable"
                        title="Budget overview could not be loaded."
                        description="Check your connection, then retry this month and currency."
                    >
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => void loadSelectedPeriod()}
                        >
                            Retry
                        </Button>
                    </EmptyState>
                ) : null}

                {loadState === 'ready' && budgetMissing ? (
                    <EmptyState
                        eyebrow={readOnly ? 'No budget' : 'Get started'}
                        title={`No ${currencyCode} budget for ${monthLabel}.`}
                        description={
                            readOnly
                                ? 'Past months are read-only. Create budgets for the current or a future month to plan allocations.'
                                : 'Create a monthly budget for this currency, then set category allocations. Creation is not automatically retried if the network response is uncertain.'
                        }
                    >
                        {!readOnly ? (
                            <Button
                                type="button"
                                loading={creating}
                                onClick={() => void handleCreateBudget()}
                            >
                                Create budget
                            </Button>
                        ) : null}
                    </EmptyState>
                ) : null}

                {loadState === 'ready' && summary && totals ? (
                    <>
                        <section aria-labelledby="budget-overview-heading" className="space-y-4">
                            <div className="flex flex-wrap items-end justify-between gap-3">
                                <h2
                                    id="budget-overview-heading"
                                    className="text-lg font-semibold tracking-tight text-ink"
                                >
                                    Overview
                                </h2>
                                <p className="font-mono text-xs text-ink-muted">
                                    {summary.budget.currency_code} · {summary.budget.period_start} →{' '}
                                    {summary.budget.period_end}
                                </p>
                            </div>

                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                <OverviewStat
                                    label="Total allocated"
                                    value={formatAmount(totals.allocated_minor)}
                                />
                                <OverviewStat
                                    label="Actual spending (allocated)"
                                    value={formatAmount(totals.allocated_actual_minor)}
                                />
                                <OverviewStat
                                    label="Remaining allocated"
                                    value={formatAmount(remainingMinor)}
                                    tone={remainingMinor < 0n ? 'negative' : 'default'}
                                />
                                <OverviewStat
                                    label="Unbudgeted spending"
                                    value={formatAmount(totals.unbudgeted_actual_minor)}
                                    tone={
                                        totals.unbudgeted_actual_minor > 0 ? 'warning' : 'default'
                                    }
                                />
                                <OverviewStat
                                    label="Total actual spending"
                                    value={formatAmount(totals.total_actual_minor)}
                                />
                                <OverviewStat
                                    label="Budget utilization"
                                    value={
                                        overallUtilization?.kind === 'ratio' &&
                                        overallUtilization.percent !== null
                                            ? `${overallUtilization.percent}%`
                                            : totals.allocated_minor === 0
                                              ? totals.allocated_actual_minor > 0
                                                  ? 'Over zero limit'
                                                  : 'No allocation'
                                              : '—'
                                    }
                                />
                            </div>

                            {totals.unbudgeted_actual_minor > 0 ? (
                                <Alert variant="warning" title="Unbudgeted">
                                    {formatAmount(totals.unbudgeted_actual_minor)} of expense
                                    spending in this currency and month is outside allocated
                                    categories. Explicit zero allocations are not treated as
                                    unbudgeted.
                                </Alert>
                            ) : null}
                        </section>

                        <section aria-labelledby="budget-allocations-heading" className="space-y-4">
                            <div className="space-y-1">
                                <h2
                                    id="budget-allocations-heading"
                                    className="text-lg font-semibold tracking-tight text-ink"
                                >
                                    Category allocations
                                </h2>
                                <p className="text-sm text-ink-muted">
                                    Set exact decimal amounts. Zero is an explicit allocation;
                                    leaving a category unset keeps it out of the budget.
                                </p>
                            </div>

                            {visibleCategoryRows.length === 0 ? (
                                <EmptyState
                                    eyebrow="Categories"
                                    title="No expense categories available."
                                    description="Create expense categories before allocating this budget."
                                />
                            ) : (
                                <ul className="space-y-3">
                                    {visibleCategoryRows.map(({ category, allocation }) => {
                                        const utilization = allocation
                                            ? computeUtilization(
                                                  allocation.allocated_minor,
                                                  allocation.actual_minor,
                                                  allocation.is_over_limit,
                                              )
                                            : null;
                                        const barPercent = utilization
                                            ? progressBarPercent(utilization)
                                            : 0;
                                        const draftValue =
                                            allocationDrafts[category.id] ??
                                            (allocation
                                                ? minorUnitsToDecimalString(
                                                      allocation.allocated_minor,
                                                      exponent,
                                                  )
                                                : '');
                                        const canEdit =
                                            !readOnly && !category.is_archived && summary !== null;
                                        const allocationStateLabel = allocation
                                            ? allocation.allocated_minor === 0
                                                ? 'Zero allocated'
                                                : 'Allocated'
                                            : 'Unset';

                                        return (
                                            <li
                                                key={category.id}
                                                className="rounded-2xl border border-border-subtle bg-surface-elevated p-4 shadow-xs sm:p-5"
                                            >
                                                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                                    <div className="min-w-0 flex-1 space-y-3">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <h3 className="text-base font-semibold text-ink">
                                                                {category.name}
                                                            </h3>
                                                            <Badge
                                                                variant={
                                                                    allocation
                                                                        ? allocation.allocated_minor ===
                                                                          0
                                                                            ? 'warning'
                                                                            : 'accent'
                                                                        : 'neutral'
                                                                }
                                                            >
                                                                {allocationStateLabel}
                                                            </Badge>
                                                            {category.is_archived ? (
                                                                <Badge variant="neutral">
                                                                    Archived
                                                                </Badge>
                                                            ) : null}
                                                            {allocation?.is_over_limit ? (
                                                                <Badge variant="negative">
                                                                    Over limit
                                                                </Badge>
                                                            ) : null}
                                                        </div>

                                                        {allocation ? (
                                                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                                                <MiniStat
                                                                    label="Allocated"
                                                                    value={formatAmount(
                                                                        allocation.allocated_minor,
                                                                    )}
                                                                />
                                                                <MiniStat
                                                                    label="Actual"
                                                                    value={formatAmount(
                                                                        allocation.actual_minor,
                                                                    )}
                                                                />
                                                                <MiniStat
                                                                    label="Remaining"
                                                                    value={formatAmount(
                                                                        allocation.remaining_minor,
                                                                    )}
                                                                />
                                                                <MiniStat
                                                                    label="Used"
                                                                    value={
                                                                        utilization?.kind ===
                                                                            'ratio' &&
                                                                        utilization.percent !== null
                                                                            ? `${utilization.percent}%`
                                                                            : allocation.allocated_minor ===
                                                                                0
                                                                              ? allocation.actual_minor >
                                                                                0
                                                                                  ? 'Over zero'
                                                                                  : '0 / 0'
                                                                              : '—'
                                                                    }
                                                                />
                                                            </div>
                                                        ) : (
                                                            <p className="text-sm text-ink-muted">
                                                                No allocation set for this category.
                                                            </p>
                                                        )}

                                                        {allocation ? (
                                                            <div className="space-y-1.5">
                                                                <div
                                                                    className="h-2 overflow-hidden rounded-full bg-surface-muted"
                                                                    role="progressbar"
                                                                    aria-valuemin={0}
                                                                    aria-valuemax={100}
                                                                    aria-valuenow={barPercent}
                                                                    aria-label={`${category.name} spending progress`}
                                                                >
                                                                    <div
                                                                        className={`h-full rounded-full motion-safe:transition-[width] motion-safe:duration-300 motion-reduce:transition-none ${
                                                                            allocation.is_over_limit
                                                                                ? 'bg-negative'
                                                                                : 'bg-accent'
                                                                        }`}
                                                                        style={{
                                                                            width: `${barPercent}%`,
                                                                        }}
                                                                    />
                                                                </div>
                                                            </div>
                                                        ) : null}
                                                    </div>

                                                    <form
                                                        className="w-full max-w-sm space-y-3"
                                                        onSubmit={(event) =>
                                                            void handleSaveAllocation(
                                                                category,
                                                                event,
                                                            )
                                                        }
                                                    >
                                                        <FormField
                                                            id={`allocation-${category.id}`}
                                                            name={`allocation-${category.id}`}
                                                            label={`Allocation (${currencyCode})`}
                                                            inputMode={
                                                                exponent === 0
                                                                    ? 'numeric'
                                                                    : 'decimal'
                                                            }
                                                            placeholder={
                                                                exponent === 0
                                                                    ? 'e.g. 15800'
                                                                    : 'e.g. 125.50'
                                                            }
                                                            hint={
                                                                category.is_archived
                                                                    ? 'Archived categories keep existing allocations but cannot receive new ones.'
                                                                    : readOnly
                                                                      ? 'Past months are read-only.'
                                                                      : exponent === 0
                                                                        ? `Whole-number ${currencyCode} amount. Explicit 0 is allowed.`
                                                                        : `Exact ${currencyCode} amount up to ${exponent} decimal places. Explicit 0 is allowed.`
                                                            }
                                                            value={draftValue}
                                                            disabled={!canEdit}
                                                            error={allocationErrors[category.id]}
                                                            onChange={(event) =>
                                                                setAllocationDrafts((current) => ({
                                                                    ...current,
                                                                    [category.id]:
                                                                        event.target.value,
                                                                }))
                                                            }
                                                        />
                                                        {canEdit ? (
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                variant="outline"
                                                                loading={Boolean(
                                                                    allocationSaving[category.id],
                                                                )}
                                                            >
                                                                {allocation
                                                                    ? 'Update allocation'
                                                                    : 'Set allocation'}
                                                            </Button>
                                                        ) : null}
                                                    </form>
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </section>
                    </>
                ) : null}
            </div>
        </AuthenticatedLayout>
    );
}

function OverviewStat({
    label,
    value,
    tone = 'default',
}: {
    label: string;
    value: string;
    tone?: 'default' | 'warning' | 'negative';
}) {
    const valueClass =
        tone === 'negative' ? 'text-negative' : tone === 'warning' ? 'text-warning' : 'text-ink';

    return (
        <div className="rounded-xl border border-border-subtle bg-surface-muted/40 px-4 py-3">
            <p className="font-mono text-[11px] font-medium tracking-wider text-ink-muted uppercase">
                {label}
            </p>
            <p className={`mt-1 font-mono text-lg font-semibold tabular-nums ${valueClass}`}>
                {value}
            </p>
        </div>
    );
}

function MiniStat({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="font-mono text-[10px] tracking-wider text-ink-muted uppercase">{label}</p>
            <p className="mt-0.5 font-mono text-sm font-medium tabular-nums text-ink">{value}</p>
        </div>
    );
}
