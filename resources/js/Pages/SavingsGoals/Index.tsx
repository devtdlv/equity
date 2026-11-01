import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Alert } from '@/Components/Alert';
import { Badge } from '@/Components/Badge';
import { Button } from '@/Components/Button';
import { Card } from '@/Components/Card';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { EmptyState } from '@/Components/EmptyState';
import { GoalAllocationDialog, type GoalAllocationMode, type PendingAllocationAttempt } from '@/Components/SavingsGoals/GoalAllocationDialog';
import { GoalCard } from '@/Components/SavingsGoals/GoalCard';
import { GoalEditorDialog } from '@/Components/SavingsGoals/GoalEditorDialog';
import { SavingsProgressBar } from '@/Components/SavingsGoals/SavingsProgressBar';
import { PageHeader } from '@/Components/PageHeader';
import { Skeleton } from '@/Components/Skeleton';
import { AuthenticatedLayout } from '@/Layouts/AuthenticatedLayout';
import type {
    CurrencyOption,
    PaginationMeta,
    SavingsGoalEventRecord,
    SavingsGoalPortfolioRecord,
    SavingsGoalProgressRecord,
    SavingsGoalStatusFilter,
    SavingsGoalsIndexPageProps,
    SharedPageProps,
} from '@/Types';
import { formatSavingsAmount, isNonZeroMinorString } from '@/Utilities/savingsMoney';

type LoadState = 'idle' | 'loading' | 'ready' | 'error';

type BannerState = {
    variant: 'info' | 'positive' | 'warning' | 'negative';
    message: string;
} | null;

function tenantSafeApiMessage(
    status: number | undefined,
    fallback: string,
): string {
    if (status === 401) {
        return 'Your session has expired. Please sign in again to continue.';
    }

    if (status === 403) {
        return 'That savings goal is not available.';
    }

    if (status === 422) {
        return 'Those filters could not be applied. Please try again.';
    }

    if (status !== undefined && status >= 500) {
        return 'The server could not complete this request. Please try again shortly.';
    }

    return fallback;
}

function formatTimestamp(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    // Prefer ISO date portion when present; fall back to raw string.
    if (/^\d{4}-\d{2}-\d{2}/.test(value)) {
        return value.slice(0, 10);
    }

    return value;
}

function eventTypeLabel(type: SavingsGoalEventRecord['type']): string {
    return type === 'contribution' ? 'Allocated' : 'Allocation reduced';
}

function emptyPaginationMeta(): PaginationMeta {
    return {
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: 0,
        from: null,
        to: null,
    };
}

export default function SavingsGoalsIndex() {
    const page = usePage<SharedPageProps & SavingsGoalsIndexPageProps>();
    const currencies = useMemo(() => page.props.currencies ?? [], [page.props.currencies]);

    const currencyByCode = useMemo(() => {
        const map = new Map<string, CurrencyOption>();
        currencies.forEach((currency) => map.set(currency.code, currency));
        return map;
    }, [currencies]);

    const [portfolioState, setPortfolioState] = useState<LoadState>('loading');
    const [portfolio, setPortfolio] = useState<SavingsGoalPortfolioRecord | null>(null);
    const [portfolioError, setPortfolioError] = useState<string | null>(null);

    const [statusFilter, setStatusFilter] = useState<SavingsGoalStatusFilter>('active');
    const [goalsState, setGoalsState] = useState<LoadState>('loading');
    const [goals, setGoals] = useState<SavingsGoalProgressRecord[]>([]);
    const [goalsMeta, setGoalsMeta] = useState<PaginationMeta>(emptyPaginationMeta);
    const [goalsPage, setGoalsPage] = useState(1);
    const [goalsError, setGoalsError] = useState<string | null>(null);

    const [selectedGoalId, setSelectedGoalId] = useState<number | null>(null);
    const [detailState, setDetailState] = useState<LoadState>('idle');
    const [detail, setDetail] = useState<SavingsGoalProgressRecord | null>(null);
    const [detailError, setDetailError] = useState<string | null>(null);

    const [eventsState, setEventsState] = useState<LoadState>('idle');
    const [events, setEvents] = useState<SavingsGoalEventRecord[]>([]);
    const [eventsMeta, setEventsMeta] = useState<PaginationMeta>(emptyPaginationMeta);
    const [eventsPage, setEventsPage] = useState(1);
    const [eventsError, setEventsError] = useState<string | null>(null);

    const [banner, setBanner] = useState<BannerState>(null);

    const [editorOpen, setEditorOpen] = useState(false);
    const [editorMode, setEditorMode] = useState<'create' | 'edit'>('create');
    const [editingGoal, setEditingGoal] = useState<SavingsGoalProgressRecord | null>(null);
    const [lifecycleAction, setLifecycleAction] = useState<'archive' | 'restore' | null>(null);
    const [lifecycleBusy, setLifecycleBusy] = useState(false);

    const [allocationOpen, setAllocationOpen] = useState(false);
    const [allocationMode, setAllocationMode] = useState<GoalAllocationMode>('contribute');
    const [allocationGoal, setAllocationGoal] = useState<SavingsGoalProgressRecord | null>(null);
    const [pendingAllocation, setPendingAllocation] = useState<PendingAllocationAttempt | null>(
        null,
    );

    const portfolioSeq = useRef(0);
    const goalsSeq = useRef(0);
    const detailSeq = useRef(0);
    const eventsSeq = useRef(0);
    const selectedGoalIdRef = useRef<number | null>(null);

    selectedGoalIdRef.current = selectedGoalId;

    const formatAmount = useCallback(
        (amountMinor: string, currencyCode: string) => {
            return formatSavingsAmount(
                amountMinor,
                currencyCode,
                currencyByCode.get(currencyCode),
            );
        },
        [currencyByCode],
    );

    const loadPortfolio = useCallback(async () => {
        const seq = ++portfolioSeq.current;
        setPortfolioState('loading');
        setPortfolioError(null);

        try {
            const response = await axios.get<{ data: SavingsGoalPortfolioRecord }>(
                '/savings-goals/portfolio',
                { headers: { Accept: 'application/json' } },
            );

            if (seq !== portfolioSeq.current) {
                return;
            }

            setPortfolio(response.data.data);
            setPortfolioState('ready');
        } catch (error: unknown) {
            if (seq !== portfolioSeq.current) {
                return;
            }

            const status = axios.isAxiosError(error) ? error.response?.status : undefined;
            setPortfolio(null);
            setPortfolioError(
                tenantSafeApiMessage(status, 'Unable to load the savings portfolio right now.'),
            );
            setPortfolioState('error');
        }
    }, []);

    const loadGoals = useCallback(async (filter: SavingsGoalStatusFilter, pageNumber: number) => {
        const seq = ++goalsSeq.current;
        setGoalsState('loading');
        setGoalsError(null);

        const params: Record<string, string | number> = {
            page: pageNumber,
            per_page: 25,
        };

        if (filter !== 'all') {
            params.status = filter;
        }

        try {
            const response = await axios.get<{
                data: SavingsGoalProgressRecord[];
                meta: PaginationMeta;
                filters: { status: string | null };
            }>('/savings-goals', {
                params,
                headers: { Accept: 'application/json' },
            });

            if (seq !== goalsSeq.current) {
                return;
            }

            setGoals(response.data.data);
            setGoalsMeta(response.data.meta);
            setGoalsState('ready');
        } catch (error: unknown) {
            if (seq !== goalsSeq.current) {
                return;
            }

            const status = axios.isAxiosError(error) ? error.response?.status : undefined;
            setGoals([]);
            setGoalsMeta(emptyPaginationMeta());
            setGoalsError(
                tenantSafeApiMessage(status, 'Unable to load savings goals right now.'),
            );
            setGoalsState('error');
        }
    }, []);

    const loadDetail = useCallback(async (goalId: number) => {
        const seq = ++detailSeq.current;
        setDetail(null);
        setDetailState('loading');
        setDetailError(null);

        try {
            const response = await axios.get<{ data: SavingsGoalProgressRecord }>(
                `/savings-goals/${goalId}`,
                { headers: { Accept: 'application/json' } },
            );

            if (seq !== detailSeq.current || selectedGoalIdRef.current !== goalId) {
                return;
            }

            setDetail(response.data.data);
            setDetailState('ready');
        } catch (error: unknown) {
            if (seq !== detailSeq.current || selectedGoalIdRef.current !== goalId) {
                return;
            }

            const status = axios.isAxiosError(error) ? error.response?.status : undefined;
            setDetail(null);
            setDetailError(
                tenantSafeApiMessage(status, 'Unable to load that savings goal right now.'),
            );
            setDetailState('error');

            if (status === 403) {
                setSelectedGoalId(null);
                setBanner({
                    variant: 'warning',
                    message: 'That savings goal is not available.',
                });
            }
        }
    }, []);

    const loadEvents = useCallback(async (goalId: number, pageNumber: number) => {
        const seq = ++eventsSeq.current;
        setEvents([]);
        setEventsMeta(emptyPaginationMeta());
        setEventsState('loading');
        setEventsError(null);

        try {
            const response = await axios.get<{
                data: SavingsGoalEventRecord[];
                meta: PaginationMeta;
            }>(`/savings-goals/${goalId}/events`, {
                params: { page: pageNumber, per_page: 25 },
                headers: { Accept: 'application/json' },
            });

            if (seq !== eventsSeq.current || selectedGoalIdRef.current !== goalId) {
                return;
            }

            setEvents(response.data.data);
            setEventsMeta(response.data.meta);
            setEventsState('ready');
        } catch (error: unknown) {
            if (seq !== eventsSeq.current || selectedGoalIdRef.current !== goalId) {
                return;
            }

            const status = axios.isAxiosError(error) ? error.response?.status : undefined;
            setEvents([]);
            setEventsMeta(emptyPaginationMeta());
            setEventsError(
                tenantSafeApiMessage(status, 'Unable to load allocation history right now.'),
            );
            setEventsState('error');

            if (status === 403) {
                setSelectedGoalId(null);
                setDetail(null);
                setDetailState('idle');
                setBanner({
                    variant: 'warning',
                    message: 'That savings goal is not available.',
                });
            }
        }
    }, []);

    useEffect(() => {
        void loadPortfolio();
    }, [loadPortfolio]);

    useEffect(() => {
        void loadGoals(statusFilter, goalsPage);
    }, [loadGoals, statusFilter, goalsPage]);

    useEffect(() => {
        if (selectedGoalId === null) {
            detailSeq.current += 1;
            eventsSeq.current += 1;
            setDetail(null);
            setDetailState('idle');
            setDetailError(null);
            setEvents([]);
            setEventsMeta(emptyPaginationMeta());
            setEventsState('idle');
            setEventsError(null);
            setEventsPage(1);
            return;
        }

        void loadDetail(selectedGoalId);
    }, [loadDetail, selectedGoalId]);

    useEffect(() => {
        if (selectedGoalId === null) {
            return;
        }

        void loadEvents(selectedGoalId, eventsPage);
    }, [loadEvents, selectedGoalId, eventsPage]);

    const handleFilterChange = (next: SavingsGoalStatusFilter) => {
        setStatusFilter(next);
        setGoalsPage(1);
        setSelectedGoalId(null);
        setBanner(null);
    };

    const handleSelectGoal = (goalId: number) => {
        setBanner(null);

        if (goalId !== selectedGoalId) {
            // Invalidate in-flight detail/events before the selection effect runs.
            detailSeq.current += 1;
            eventsSeq.current += 1;
            setDetail(null);
            setDetailState('loading');
            setDetailError(null);
            setEvents([]);
            setEventsMeta(emptyPaginationMeta());
            setEventsState('loading');
            setEventsError(null);
            setEventsPage(1);
        }

        setSelectedGoalId(goalId);
    };

    const refreshAfterMutation = useCallback(
        async (options?: {
            selectGoalId?: number | null;
            preferActiveFilter?: boolean;
            resetEventsToFirstPage?: boolean;
        }) => {
            if (options?.preferActiveFilter && statusFilter !== 'active') {
                setStatusFilter('active');
                setGoalsPage(1);
            } else {
                await loadGoals(statusFilter, goalsPage);
            }

            await loadPortfolio();

            if (options?.selectGoalId !== undefined) {
                if (options.selectGoalId === null) {
                    setSelectedGoalId(null);
                    return;
                }

                const nextId = options.selectGoalId;
                if (nextId !== selectedGoalIdRef.current) {
                    detailSeq.current += 1;
                    eventsSeq.current += 1;
                    setDetail(null);
                    setDetailState('loading');
                    setDetailError(null);
                    setEvents([]);
                    setEventsMeta(emptyPaginationMeta());
                    setEventsState('loading');
                    setEventsError(null);
                    setEventsPage(1);
                } else if (options.resetEventsToFirstPage) {
                    setEventsPage(1);
                }

                setSelectedGoalId(nextId);
                await loadDetail(nextId);
                await loadEvents(
                    nextId,
                    options.resetEventsToFirstPage ? 1 : eventsPage,
                );
            } else if (selectedGoalIdRef.current !== null) {
                const goalId = selectedGoalIdRef.current;
                await loadDetail(goalId);
                if (options?.resetEventsToFirstPage) {
                    setEventsPage(1);
                    await loadEvents(goalId, 1);
                }
            }
        },
        [eventsPage, goalsPage, loadDetail, loadEvents, loadGoals, loadPortfolio, statusFilter],
    );

    const openCreateEditor = () => {
        setEditorMode('create');
        setEditingGoal(null);
        setEditorOpen(true);
        setBanner(null);
    };

    const openEditEditor = (goalRecord: SavingsGoalProgressRecord) => {
        if (goalRecord.status !== 'active') {
            setBanner({
                variant: 'warning',
                message: 'Archived savings goals cannot be edited until restored.',
            });
            return;
        }
        setEditorMode('edit');
        setEditingGoal(goalRecord);
        setEditorOpen(true);
        setBanner(null);
    };

    const closeEditor = () => {
        setEditorOpen(false);
        setEditingGoal(null);
    };

    const openAllocationDialog = (
        goalRecord: SavingsGoalProgressRecord,
        mode: GoalAllocationMode,
    ) => {
        if (goalRecord.status !== 'active') {
            setBanner({
                variant: 'warning',
                message: 'Archived savings goals cannot accept allocation changes.',
            });
            return;
        }

        setAllocationMode(mode);
        setAllocationGoal(goalRecord);
        setAllocationOpen(true);
        setBanner(null);
    };

    const closeAllocationDialog = () => {
        setAllocationOpen(false);
        setAllocationGoal(null);
    };

    const handleAllocationSuccess = async (result: {
        mode: GoalAllocationMode;
        goal: SavingsGoalProgressRecord;
        wasRecentlyCreated?: boolean;
        idempotentReplay?: boolean;
    }) => {
        closeAllocationDialog();
        setPendingAllocation(null);

        const stillViewingMutatedGoal = selectedGoalIdRef.current === result.goal.id;

        if (!stillViewingMutatedGoal) {
            // Confirmed for a goal the user is no longer viewing — refresh lists only.
            await loadPortfolio();
            await loadGoals(statusFilter, goalsPage);
            return;
        }

        setBanner({
            variant: 'positive',
            message: result.idempotentReplay
                ? result.mode === 'contribute'
                    ? 'Existing allocation returned for this request.'
                    : 'Existing allocation reduction returned for this request.'
                : result.mode === 'contribute'
                  ? 'Amount allocated to the goal.'
                  : 'Allocation reduced.',
        });

        // New events are newest-first; always return history to page 1 so the
        // mutation is discoverable after success.
        await refreshAfterMutation({
            selectGoalId: result.goal.id,
            resetEventsToFirstPage: true,
        });
    };

    const handleEditorSuccess = async (result: {
        mode: 'create' | 'edit';
        goal: SavingsGoalProgressRecord;
        wasRecentlyCreated?: boolean;
        idempotentReplay?: boolean;
    }) => {
        closeEditor();

        if (result.mode === 'create') {
            setBanner({
                variant: 'positive',
                message: result.idempotentReplay
                    ? 'Existing savings goal returned for this create request.'
                    : 'Savings goal created.',
            });
            await refreshAfterMutation({
                preferActiveFilter: true,
                selectGoalId: result.goal.id,
            });
            return;
        }

        setBanner({
            variant: 'positive',
            message: 'Savings goal updated.',
        });
        await refreshAfterMutation({ selectGoalId: result.goal.id });
    };

    const runLifecycleAction = async () => {
        if (!detail || lifecycleAction === null || lifecycleBusy) {
            return;
        }

        const goalId = detail.id;
        const action = lifecycleAction;
        setLifecycleBusy(true);
        setBanner(null);

        try {
            await axios.post(
                `/savings-goals/${goalId}/${action}`,
                {},
                { headers: { Accept: 'application/json' } },
            );

            setLifecycleAction(null);
            setBanner({
                variant: 'positive',
                message:
                    action === 'archive'
                        ? 'Savings goal archived. Existing allocation history is preserved.'
                        : 'Savings goal restored to active status.',
            });

            await refreshAfterMutation({ selectGoalId: goalId });
        } catch (error: unknown) {
            if (axios.isAxiosError(error)) {
                const status = error.response?.status;
                const errors = error.response?.data?.errors as
                    | Record<string, string[] | string>
                    | undefined;
                const fieldMessage =
                    typeof errors?.goal === 'string'
                        ? errors.goal
                        : Array.isArray(errors?.goal)
                          ? errors.goal[0]
                          : undefined;

                setBanner({
                    variant: 'negative',
                    message:
                        fieldMessage ??
                        tenantSafeApiMessage(
                            status,
                            action === 'archive'
                                ? 'Unable to archive this savings goal.'
                                : 'Unable to restore this savings goal.',
                        ),
                });

                if (status === undefined || (status !== undefined && status >= 500) || !error.response) {
                    await refreshAfterMutation({ selectGoalId: goalId });
                }
            } else {
                setBanner({
                    variant: 'negative',
                    message:
                        action === 'archive'
                            ? 'Unable to archive this savings goal.'
                            : 'Unable to restore this savings goal.',
                });
            }
        } finally {
            setLifecycleBusy(false);
        }
    };

    const hasPortfolioTotals = (portfolio?.currencies.length ?? 0) > 0;
    const detailCurrency = detail
        ? currencyByCode.get(detail.currency_code)
        : undefined;

    return (
        <AuthenticatedLayout title="Savings Goals">
            <div className="space-y-8">
                <PageHeader
                    eyebrow="Workspace"
                    title="Savings Goals"
                    description="Earmark money toward targets. Allocations track intent — they do not move funds between financial accounts."
                    actions={
                        <Button type="button" size="sm" onClick={openCreateEditor}>
                            Create goal
                        </Button>
                    }
                />

                {banner ? (
                    <Alert variant={banner.variant} title="Notice">
                        {banner.message}
                    </Alert>
                ) : null}

                <section aria-labelledby="savings-portfolio-heading" className="space-y-4">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <h2
                            id="savings-portfolio-heading"
                            className="text-lg font-semibold tracking-tight text-ink"
                        >
                            Portfolio
                        </h2>
                        {portfolioState === 'error' ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => void loadPortfolio()}
                            >
                                Retry
                            </Button>
                        ) : null}
                    </div>

                    {portfolioState === 'loading' ? (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-busy="true">
                            <Skeleton className="h-24 w-full" label="Loading portfolio" />
                            <Skeleton className="h-24 w-full" label="Loading portfolio" />
                            <Skeleton className="h-24 w-full" label="Loading portfolio" />
                        </div>
                    ) : null}

                    {portfolioState === 'error' && portfolioError ? (
                        <Alert variant="negative">{portfolioError}</Alert>
                    ) : null}

                    {portfolioState === 'ready' && portfolio ? (
                        <>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
                                <PortfolioStat label="Total goals" value={String(portfolio.total_goal_count)} />
                                <PortfolioStat label="Active" value={String(portfolio.active_goal_count)} />
                                <PortfolioStat
                                    label="Archived"
                                    value={String(portfolio.archived_goal_count)}
                                />
                                <PortfolioStat
                                    label="Active at target"
                                    value={String(portfolio.active_at_target_count)}
                                />
                                <PortfolioStat
                                    label="Active below target"
                                    value={String(portfolio.active_below_target_count)}
                                />
                            </div>

                            {!hasPortfolioTotals ? (
                                <EmptyState
                                    eyebrow="Portfolio"
                                    title="No savings goals yet."
                                    description="When you create goals, per-currency allocated and target totals will appear here. Currency totals are never combined."
                                />
                            ) : (
                                <ul className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                                    {portfolio.currencies.map((row) => (
                                        <li key={row.currency_code}>
                                            <Card padding="sm" className="shadow-none">
                                                <div className="flex items-center justify-between gap-2">
                                                    <h3 className="text-sm font-semibold text-ink">
                                                        {row.currency_code}
                                                    </h3>
                                                    <Badge variant="neutral">
                                                        {row.active_goal_count} active
                                                    </Badge>
                                                </div>
                                                <dl className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                                    <div>
                                                        <dt className="font-mono text-[10px] tracking-wider text-ink-muted uppercase">
                                                            Allocated
                                                        </dt>
                                                        <dd className="mt-0.5 text-sm font-medium tabular-nums text-ink">
                                                            {formatAmount(
                                                                row.total_allocated_minor,
                                                                row.currency_code,
                                                            )}
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt className="font-mono text-[10px] tracking-wider text-ink-muted uppercase">
                                                            Target
                                                        </dt>
                                                        <dd className="mt-0.5 text-sm font-medium tabular-nums text-ink">
                                                            {formatAmount(
                                                                row.total_target_minor,
                                                                row.currency_code,
                                                            )}
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt className="font-mono text-[10px] tracking-wider text-ink-muted uppercase">
                                                            Remaining
                                                        </dt>
                                                        <dd className="mt-0.5 text-sm font-medium tabular-nums text-ink">
                                                            {formatAmount(
                                                                row.total_remaining_minor,
                                                                row.currency_code,
                                                            )}
                                                        </dd>
                                                    </div>
                                                    {isNonZeroMinorString(row.total_overfunded_minor) ? (
                                                        <div>
                                                            <dt className="font-mono text-[10px] tracking-wider text-ink-muted uppercase">
                                                                Overfunded
                                                            </dt>
                                                            <dd className="mt-0.5 text-sm font-medium tabular-nums text-warning">
                                                                {formatAmount(
                                                                    row.total_overfunded_minor,
                                                                    row.currency_code,
                                                                )}
                                                            </dd>
                                                        </div>
                                                    ) : null}
                                                </dl>
                                            </Card>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </>
                    ) : null}
                </section>

                <section aria-labelledby="savings-goals-heading" className="space-y-4">
                    <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
                        <div className="space-y-1">
                            <h2
                                id="savings-goals-heading"
                                className="text-lg font-semibold tracking-tight text-ink"
                            >
                                Goals
                            </h2>
                            <p className="text-sm text-ink-muted">
                                Select a goal to view details and allocation history.
                            </p>
                        </div>
                        <div
                            className="flex flex-wrap gap-2"
                            role="group"
                            aria-label="Goal status filter"
                        >
                            {(
                                [
                                    ['active', 'Active'],
                                    ['archived', 'Archived'],
                                    ['all', 'All'],
                                ] as const
                            ).map(([value, label]) => (
                                <Button
                                    key={value}
                                    type="button"
                                    size="sm"
                                    variant={statusFilter === value ? 'primary' : 'outline'}
                                    aria-pressed={statusFilter === value}
                                    onClick={() => handleFilterChange(value)}
                                >
                                    {label}
                                </Button>
                            ))}
                        </div>
                    </div>

                    {goalsState === 'loading' ? (
                        <div className="space-y-3" aria-busy="true">
                            <Skeleton className="h-36 w-full" label="Loading goals" />
                            <Skeleton className="h-36 w-full" label="Loading goals" />
                        </div>
                    ) : null}

                    {goalsState === 'error' && goalsError ? (
                        <div className="space-y-3">
                            <Alert variant="negative">{goalsError}</Alert>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => void loadGoals(statusFilter, goalsPage)}
                            >
                                Retry
                            </Button>
                        </div>
                    ) : null}

                    {goalsState === 'ready' && goals.length === 0 ? (
                        <EmptyState
                            eyebrow="Goals"
                            title={
                                statusFilter === 'all'
                                    ? 'No savings goals yet.'
                                    : `No ${statusFilter} savings goals.`
                            }
                            description="Create a goal to earmark amounts toward a target. Allocations track intent and do not move funds between accounts."
                        />
                    ) : null}

                    {goalsState === 'ready' && goals.length > 0 ? (
                        <ul className="space-y-3">
                            {goals.map((goal) => (
                                <li key={goal.id}>
                                    <GoalCard
                                        goal={goal}
                                        currency={currencyByCode.get(goal.currency_code)}
                                        selected={selectedGoalId === goal.id}
                                        onSelect={handleSelectGoal}
                                    />
                                </li>
                            ))}
                        </ul>
                    ) : null}

                    {goalsMeta.last_page > 1 ? (
                        <PaginationControls
                            label="Goals pagination"
                            meta={goalsMeta}
                            currentPage={goalsPage}
                            loading={goalsState === 'loading'}
                            onPrevious={() => setGoalsPage((current) => Math.max(1, current - 1))}
                            onNext={() =>
                                setGoalsPage((current) =>
                                    Math.min(goalsMeta.last_page, current + 1),
                                )
                            }
                        />
                    ) : null}
                </section>

                <section aria-labelledby="savings-detail-heading" className="space-y-4">
                    <h2
                        id="savings-detail-heading"
                        className="text-lg font-semibold tracking-tight text-ink"
                    >
                        Goal detail
                    </h2>

                    {selectedGoalId === null ? (
                        <EmptyState
                            eyebrow="Detail"
                            title="No goal selected."
                            description="Choose a goal from the list to inspect targets, allocated amounts, and allocation history."
                        />
                    ) : null}

                    {selectedGoalId !== null && detailState === 'loading' ? (
                        <Skeleton className="h-48 w-full" label="Loading goal detail" />
                    ) : null}

                    {selectedGoalId !== null && detailState === 'error' && detailError ? (
                        <div className="space-y-3">
                            <Alert variant="negative">{detailError}</Alert>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => void loadDetail(selectedGoalId)}
                            >
                                Retry
                            </Button>
                        </div>
                    ) : null}

                    {selectedGoalId !== null && detailState === 'ready' && detail ? (
                        <Card padding="md" className="space-y-5 shadow-none">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="space-y-1">
                                    <h3 className="text-xl font-semibold tracking-tight text-ink">
                                        {detail.name}
                                    </h3>
                                    {detail.description ? (
                                        <p className="max-w-2xl text-sm leading-relaxed text-ink-muted">
                                            {detail.description}
                                        </p>
                                    ) : (
                                        <p className="text-sm text-ink-muted">No description.</p>
                                    )}
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant={detail.status === 'active' ? 'accent' : 'neutral'}>
                                        {detail.status === 'active' ? 'Active' : 'Archived'}
                                    </Badge>
                                    {detail.status === 'active' ? (
                                        <>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => openEditEditor(detail)}
                                            >
                                                Edit
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="primary"
                                                onClick={() =>
                                                    openAllocationDialog(detail, 'contribute')
                                                }
                                            >
                                                Allocate to goal
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    openAllocationDialog(detail, 'withdraw')
                                                }
                                            >
                                                Reduce allocation
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setLifecycleAction('archive')}
                                            >
                                                Archive
                                            </Button>
                                        </>
                                    ) : (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setLifecycleAction('restore')}
                                        >
                                            Restore
                                        </Button>
                                    )}
                                </div>
                            </div>

                            <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                <DetailStat label="Currency" value={detail.currency_code} />
                                <DetailStat
                                    label="Target"
                                    value={formatAmount(
                                        detail.target_amount_minor,
                                        detail.currency_code,
                                    )}
                                />
                                <DetailStat
                                    label="Allocated"
                                    value={formatAmount(
                                        detail.allocated_amount_minor,
                                        detail.currency_code,
                                    )}
                                />
                                <DetailStat
                                    label="Remaining to target"
                                    value={formatAmount(
                                        detail.remaining_amount_minor,
                                        detail.currency_code,
                                    )}
                                />
                                {isNonZeroMinorString(detail.overfunded_amount_minor) ? (
                                    <DetailStat
                                        label="Overfunded"
                                        value={formatAmount(
                                            detail.overfunded_amount_minor,
                                            detail.currency_code,
                                        )}
                                    />
                                ) : null}
                                <DetailStat
                                    label="Target date"
                                    value={detail.target_date ?? 'None'}
                                />
                                <DetailStat
                                    label="Created"
                                    value={formatTimestamp(detail.created_at)}
                                />
                                <DetailStat
                                    label="Updated"
                                    value={formatTimestamp(detail.updated_at)}
                                />
                                {detail.archived_at ? (
                                    <DetailStat
                                        label="Archived"
                                        value={formatTimestamp(detail.archived_at)}
                                    />
                                ) : null}
                            </dl>

                            <SavingsProgressBar
                                progressBasisPoints={detail.progress_basis_points}
                            />

                            <p className="text-xs text-ink-muted">
                                {detailCurrency
                                    ? `Display currency: ${detailCurrency.name} · ${detailCurrency.minor_unit_exponent} decimal place${
                                          detailCurrency.minor_unit_exponent === 1 ? '' : 's'
                                      }.`
                                    : 'Currency metadata unavailable — amounts shown as exact minor units.'}
                            </p>
                        </Card>
                    ) : null}
                </section>

                {selectedGoalId !== null ? (
                    <section aria-labelledby="savings-events-heading" className="space-y-4">
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <div className="space-y-1">
                                <h2
                                    id="savings-events-heading"
                                    className="text-lg font-semibold tracking-tight text-ink"
                                >
                                    Allocation history
                                </h2>
                                <p className="text-sm text-ink-muted">
                                    Chronological earmark changes for the selected goal.
                                </p>
                            </div>
                            {eventsState === 'error' ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => void loadEvents(selectedGoalId, eventsPage)}
                                >
                                    Retry
                                </Button>
                            ) : null}
                        </div>

                        {eventsState === 'loading' ? (
                            <div className="space-y-2" aria-busy="true">
                                <Skeleton className="h-16 w-full" label="Loading allocation history" />
                                <Skeleton className="h-16 w-full" label="Loading allocation history" />
                            </div>
                        ) : null}

                        {eventsState === 'error' && eventsError ? (
                            <Alert variant="negative">{eventsError}</Alert>
                        ) : null}

                        {eventsState === 'ready' && events.length === 0 ? (
                            <EmptyState
                                eyebrow="History"
                                title="No allocation events yet."
                                description="Contributions and reductions will appear here after allocations are recorded."
                            />
                        ) : null}

                        {eventsState === 'ready' && events.length > 0 ? (
                            <ul className="divide-y divide-border-subtle rounded-2xl border border-border-subtle bg-surface-elevated">
                                {events.map((event) => (
                                    <li
                                        key={event.id}
                                        className="flex flex-col gap-2 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div className="space-y-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge
                                                    variant={
                                                        event.type === 'contribution'
                                                            ? 'positive'
                                                            : 'warning'
                                                    }
                                                >
                                                    {eventTypeLabel(event.type)}
                                                </Badge>
                                                <span className="font-mono text-[11px] text-ink-muted">
                                                    {formatTimestamp(event.created_at)}
                                                </span>
                                            </div>
                                            {event.note ? (
                                                <p className="text-sm text-ink-secondary">
                                                    {event.note}
                                                </p>
                                            ) : null}
                                        </div>
                                        <p className="text-sm font-semibold tabular-nums text-ink">
                                            {formatAmount(event.amount_minor, event.currency_code)}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        ) : null}

                        {eventsMeta.last_page > 1 ? (
                            <PaginationControls
                                label="Events pagination"
                                meta={eventsMeta}
                                currentPage={eventsPage}
                                loading={eventsState === 'loading'}
                                onPrevious={() =>
                                    setEventsPage((current) => Math.max(1, current - 1))
                                }
                                onNext={() =>
                                    setEventsPage((current) =>
                                        Math.min(eventsMeta.last_page, current + 1),
                                    )
                                }
                            />
                        ) : null}
                    </section>
                ) : null}
            </div>

            <GoalEditorDialog
                open={editorOpen}
                mode={editorMode}
                currencies={currencies}
                goal={editingGoal}
                onClose={closeEditor}
                onSuccess={(result) => {
                    void handleEditorSuccess(result);
                }}
            />

            {allocationGoal ? (
                <GoalAllocationDialog
                    open={allocationOpen}
                    mode={allocationMode}
                    goal={allocationGoal}
                    currency={currencyByCode.get(allocationGoal.currency_code)}
                    pendingAttempt={pendingAllocation}
                    onClose={closeAllocationDialog}
                    onPendingChange={setPendingAllocation}
                    onSuccess={(result) => {
                        void handleAllocationSuccess(result);
                    }}
                    onAuthoritativeGoalRefresh={(goalId) => {
                        void loadDetail(goalId);
                    }}
                />
            ) : null}

            <ConfirmDialog
                open={lifecycleAction === 'archive'}
                title="Archive savings goal"
                description={
                    detail
                        ? `Archive “${detail.name}”? Archiving changes status only — existing allocation history is preserved, and new allocations are blocked while archived. Funds are not moved between accounts.`
                        : 'Archive this savings goal?'
                }
                confirmLabel="Archive goal"
                cancelLabel="Cancel"
                confirmVariant="danger"
                loading={lifecycleBusy}
                onConfirm={() => {
                    void runLifecycleAction();
                }}
                onCancel={() => {
                    if (!lifecycleBusy) {
                        setLifecycleAction(null);
                    }
                }}
            />

            <ConfirmDialog
                open={lifecycleAction === 'restore'}
                title="Restore savings goal"
                description={
                    detail
                        ? `Restore “${detail.name}” to active status? You will be able to edit the goal and allocate toward it again.`
                        : 'Restore this savings goal?'
                }
                confirmLabel="Restore goal"
                cancelLabel="Cancel"
                confirmVariant="primary"
                loading={lifecycleBusy}
                onConfirm={() => {
                    void runLifecycleAction();
                }}
                onCancel={() => {
                    if (!lifecycleBusy) {
                        setLifecycleAction(null);
                    }
                }}
            />
        </AuthenticatedLayout>
    );
}

function PortfolioStat({ label, value }: { label: string; value: string }) {
    return (
        <Card padding="sm" className="shadow-none">
            <p className="font-mono text-[10px] tracking-wider text-ink-muted uppercase">{label}</p>
            <p className="mt-1.5 text-lg font-semibold tabular-nums text-ink">{value}</p>
        </Card>
    );
}

function DetailStat({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="font-mono text-[10px] tracking-wider text-ink-muted uppercase">
                {label}
            </dt>
            <dd className="mt-0.5 text-sm font-medium tabular-nums text-ink">{value}</dd>
        </div>
    );
}

function PaginationControls({
    label,
    meta,
    currentPage,
    loading,
    onPrevious,
    onNext,
}: {
    label: string;
    meta: PaginationMeta;
    currentPage: number;
    loading: boolean;
    onPrevious: () => void;
    onNext: () => void;
}) {
    if (meta.last_page <= 1) {
        return null;
    }

    return (
        <div
            className="flex flex-wrap items-center justify-between gap-3"
            role="navigation"
            aria-label={label}
            aria-busy={loading || undefined}
        >
            <p className="font-mono text-xs text-ink-muted">
                Page {currentPage} of {meta.last_page}
                {meta.total > 0 ? ` · ${meta.total} total` : ''}
            </p>
            <div className="flex gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={currentPage <= 1}
                    onClick={onPrevious}
                >
                    Previous
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={currentPage >= meta.last_page}
                    onClick={onNext}
                >
                    Next
                </Button>
            </div>
        </div>
    );
}
