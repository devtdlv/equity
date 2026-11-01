import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import axios from 'axios';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SavingsGoalsIndex from '@/Pages/SavingsGoals/Index';
import type {
    CurrencyOption,
    PaginationMeta,
    SavingsGoalEventRecord,
    SavingsGoalPortfolioRecord,
    SavingsGoalProgressRecord,
} from '@/Types';

let uuidCounter = 0;

vi.mock('@/Utilities/uuid', () => ({
    generateUuidV4: () => {
        uuidCounter += 1;
        return `00000000-0000-4000-8000-${String(uuidCounter).padStart(12, '0')}`;
    },
}));

vi.mock('axios', () => {
    const get = vi.fn();
    const post = vi.fn();
    const patch = vi.fn();
    const put = vi.fn();
    const del = vi.fn();
    const isAxiosError = (err: unknown): boolean =>
        typeof err === 'object' && err !== null && 'isAxiosError' in err;

    return {
        default: {
            get,
            post,
            patch,
            put,
            delete: del,
            isAxiosError,
        },
    };
});

vi.mock('@inertiajs/react', () => ({
    Head: ({ title, children }: { title?: string; children?: ReactNode }) => (
        <>
            {title ? <title>{title}</title> : null}
            {children}
        </>
    ),
    Link: ({
        href,
        children,
        ...props
    }: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({
        url: '/savings',
        props: {
            app: { name: 'Equity' },
            auth: {
                user: {
                    id: 1,
                    name: 'Morgan Lee',
                    email: 'morgan@example.com',
                },
            },
            flash: { status: null },
            currencies: SAMPLE_CURRENCIES,
        },
    }),
    useForm: () => ({
        data: {},
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
    }),
}));

const SAMPLE_CURRENCIES: CurrencyOption[] = [
    { code: 'USD', name: 'US Dollar', symbol: '$', minor_unit_exponent: 2 },
    { code: 'JPY', name: 'Japanese Yen', symbol: '¥', minor_unit_exponent: 0 },
    { code: 'PHP', name: 'Philippine Peso', symbol: '₱', minor_unit_exponent: 2 },
    { code: 'KWD', name: 'Kuwaiti Dinar', symbol: 'KD ', minor_unit_exponent: 3 },
];

function paginationMeta(overrides: Partial<PaginationMeta> = {}): PaginationMeta {
    return {
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: 0,
        from: null,
        to: null,
        ...overrides,
    };
}

function goal(overrides: Partial<SavingsGoalProgressRecord> = {}): SavingsGoalProgressRecord {
    return {
        id: 10,
        name: 'Emergency Fund',
        description: 'Primary buffer',
        currency_code: 'PHP',
        status: 'active',
        target_amount_minor: '100000',
        allocated_amount_minor: '25000',
        remaining_amount_minor: '75000',
        overfunded_amount_minor: '0',
        progress_basis_points: 2500,
        target_reached: false,
        target_date: '2027-06-01',
        archived_at: null,
        created_at: '2026-11-01T10:00:00+00:00',
        updated_at: '2026-11-01T10:00:00+00:00',
        ...overrides,
    };
}

function portfolio(
    overrides: Partial<SavingsGoalPortfolioRecord> = {},
): SavingsGoalPortfolioRecord {
    return {
        total_goal_count: 2,
        active_goal_count: 1,
        archived_goal_count: 1,
        active_at_target_count: 0,
        active_below_target_count: 1,
        currencies: [
            {
                currency_code: 'PHP',
                active_goal_count: 1,
                total_target_minor: '100000',
                total_allocated_minor: '25000',
                total_remaining_minor: '75000',
                total_overfunded_minor: '0',
            },
            {
                currency_code: 'USD',
                active_goal_count: 0,
                total_target_minor: '50000',
                total_allocated_minor: '50000',
                total_remaining_minor: '0',
                total_overfunded_minor: '0',
            },
        ],
        ...overrides,
    };
}

function event(overrides: Partial<SavingsGoalEventRecord> = {}): SavingsGoalEventRecord {
    return {
        id: 1,
        savings_goal_id: 10,
        type: 'contribution',
        amount_minor: '25000',
        currency_code: 'PHP',
        note: 'Seed',
        created_at: '2026-11-01T10:00:00+00:00',
        updated_at: '2026-11-01T10:00:00+00:00',
        ...overrides,
    };
}

function mockAxiosError(status: number, data: unknown = {}) {
    return {
        isAxiosError: true,
        response: { status, data },
    };
}

function createDeferred<T>() {
    let resolve!: (value: T | PromiseLike<T>) => void;
    let reject!: (reason?: unknown) => void;
    const promise = new Promise<T>((res, rej) => {
        resolve = res;
        reject = rej;
    });
    return { promise, resolve, reject };
}

function openCreateDialog() {
    fireEvent.click(screen.getByRole('button', { name: 'Create goal' }));
    return screen.getByRole('dialog', { name: 'Create savings goal' });
}

function submitCreateDialog(dialog: HTMLElement = screen.getByRole('dialog', { name: 'Create savings goal' })) {
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create goal' }));
}

function postedCreateBody(
    mockedPost: ReturnType<typeof vi.mocked<typeof axios.post>>,
    callIndex: number,
): Record<string, unknown> {
    return mockedPost.mock.calls[callIndex][1] as Record<string, unknown>;
}

function stubDefaultReads(options?: {
    portfolio?: SavingsGoalPortfolioRecord;
    goals?: SavingsGoalProgressRecord[];
    goalsMeta?: PaginationMeta;
}) {
    const mockedGet = vi.mocked(axios.get);
    mockedGet.mockImplementation(async (url: string) => {
        if (url === '/savings-goals/portfolio') {
            return { data: { data: options?.portfolio ?? portfolio() } };
        }

        if (url === '/savings-goals') {
            const goals = options?.goals ?? [goal()];
            return {
                data: {
                    data: goals,
                    meta: options?.goalsMeta ?? paginationMeta({ total: goals.length, from: 1, to: goals.length }),
                    filters: { status: 'active' },
                },
            };
        }

        if (url === '/savings-goals/10') {
            return { data: { data: goal() } };
        }

        if (url === '/savings-goals/10/events') {
            return {
                data: {
                    data: [event()],
                    meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                },
            };
        }

        throw new Error(`Unexpected GET ${url}`);
    });
}

describe('SavingsGoalsIndex', () => {
    beforeEach(() => {
        uuidCounter = 0;
        vi.mocked(axios.get).mockReset();
        vi.mocked(axios.post).mockReset();
        vi.mocked(axios.patch).mockReset();
        vi.mocked(axios.put).mockReset();
        vi.mocked(axios.delete).mockReset();
        stubDefaultReads();
    });

    afterEach(() => {
        vi.mocked(axios.get).mockReset();
        vi.mocked(axios.post).mockReset();
        vi.mocked(axios.patch).mockReset();
        vi.mocked(axios.put).mockReset();
        vi.mocked(axios.delete).mockReset();
    });

    it('renders the page heading and active Savings Goals navigation', async () => {
        render(<SavingsGoalsIndex />);

        expect(screen.getByRole('heading', { name: 'Savings Goals', level: 1 })).toBeInTheDocument();
        const navLinks = screen.getAllByRole('link', { name: 'Savings Goals' });
        expect(navLinks.length).toBeGreaterThan(0);
        expect(navLinks[0]).toHaveAttribute('href', '/savings');
        expect(navLinks[0]).toHaveAttribute('aria-current', 'page');

        await waitFor(() => {
            expect(screen.getByText('Emergency Fund')).toBeInTheDocument();
        });
    });

    it('renders portfolio counts and keeps currencies separate', async () => {
        render(<SavingsGoalsIndex />);

        await waitFor(() => {
            expect(screen.getByText('Portfolio')).toBeInTheDocument();
        });

        expect(screen.getByText('Total goals').parentElement).toHaveTextContent('2');
        expect(screen.getAllByText('₱250.00').length).toBeGreaterThan(0);
        expect(screen.getAllByText('$500.00').length).toBeGreaterThan(0);
        expect(screen.getAllByText('PHP').length).toBeGreaterThan(0);
        expect(screen.getAllByText('USD').length).toBeGreaterThan(0);
    });

    it('shows empty portfolio currency guidance when there are no currency groups', async () => {
        stubDefaultReads({
            portfolio: portfolio({
                total_goal_count: 0,
                active_goal_count: 0,
                archived_goal_count: 0,
                currencies: [],
            }),
            goals: [],
        });

        render(<SavingsGoalsIndex />);

        await waitFor(() => {
            expect(screen.getByText('No savings goals yet.')).toBeInTheDocument();
        });
    });

    it('shows portfolio loading then error with retry', async () => {
        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                throw mockAxiosError(500);
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [],
                        meta: paginationMeta(),
                        filters: { status: 'active' },
                    },
                };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        render(<SavingsGoalsIndex />);

        await waitFor(() => {
            expect(
                screen.getByText(/server could not complete this request/i),
            ).toBeInTheDocument();
        });

        stubDefaultReads({ goals: [] });
        fireEvent.click(screen.getByRole('button', { name: 'Retry' }));

        await waitFor(() => {
            expect(screen.getByText('Total goals')).toBeInTheDocument();
        });
    });

    it('formats large minor strings without Number coercion and shows progress', async () => {
        stubDefaultReads({
            goals: [
                goal({
                    allocated_amount_minor: '9007199254740993',
                    remaining_amount_minor: '0',
                    overfunded_amount_minor: '9007199254740993',
                    progress_basis_points: 12500,
                    target_reached: true,
                }),
            ],
        });

        render(<SavingsGoalsIndex />);

        await waitFor(() => {
            expect(screen.getByText('₱90,071,992,547,409.93')).toBeInTheDocument();
        });

        const progress = screen.getByRole('progressbar', { name: /125 percent, overfunded/i });
        expect(progress).toHaveAttribute('aria-valuenow', '100');
        expect(screen.getByText('125%')).toBeInTheDocument();
        expect(screen.getAllByText('Overfunded').length).toBeGreaterThan(0);
    });

    it('filters goals and resets selection', async () => {
        const mockedGet = vi.mocked(axios.get);
        stubDefaultReads();

        render(<SavingsGoalsIndex />);

        await waitFor(() => {
            expect(screen.getByText('Emergency Fund')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: /Emergency Fund/i }));

        await waitFor(() => {
            expect(screen.getByRole('heading', { name: 'Emergency Fund', level: 3 })).toBeInTheDocument();
        });

        mockedGet.mockImplementation(async (url: string, config?: unknown) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                const params =
                    typeof config === 'object' &&
                    config !== null &&
                    'params' in config &&
                    typeof (config as { params?: unknown }).params === 'object' &&
                    (config as { params?: unknown }).params !== null
                        ? ((config as { params: Record<string, unknown> }).params)
                        : {};
                expect(params.status).toBe('archived');
                expect(params.page).toBe(1);
                return {
                    data: {
                        data: [],
                        meta: paginationMeta(),
                        filters: { status: 'archived' },
                    },
                };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        fireEvent.click(screen.getByRole('button', { name: 'Archived' }));

        await waitFor(() => {
            expect(screen.getByText('No archived savings goals.')).toBeInTheDocument();
        });

        expect(screen.getByText('No goal selected.')).toBeInTheDocument();
    });

    it('paginates goals with next and previous', async () => {
        const mockedGet = vi.mocked(axios.get);
        stubDefaultReads({
            goals: [goal()],
            goalsMeta: paginationMeta({
                current_page: 1,
                last_page: 2,
                total: 26,
                from: 1,
                to: 25,
            }),
        });

        render(<SavingsGoalsIndex />);

        await waitFor(() => {
            expect(screen.getByLabelText('Goals pagination')).toBeInTheDocument();
        });

        mockedGet.mockImplementation(async (url: string, config?: unknown) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                const params =
                    typeof config === 'object' &&
                    config !== null &&
                    'params' in config &&
                    typeof (config as { params?: unknown }).params === 'object' &&
                    (config as { params?: unknown }).params !== null
                        ? ((config as { params: Record<string, unknown> }).params)
                        : {};
                expect(params.page).toBe(2);
                return {
                    data: {
                        data: [goal({ id: 11, name: 'Travel Fund' })],
                        meta: paginationMeta({
                            current_page: 2,
                            last_page: 2,
                            total: 26,
                            from: 26,
                            to: 26,
                        }),
                        filters: { status: 'active' },
                    },
                };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        fireEvent.click(
            within(screen.getByLabelText('Goals pagination')).getByRole('button', {
                name: 'Next',
            }),
        );

        await waitFor(() => {
            expect(screen.getByText('Travel Fund')).toBeInTheDocument();
        });
    });

    it('loads detail and events, including withdrawal labels', async () => {
        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [goal()],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/10') {
                return { data: { data: goal() } };
            }
            if (url === '/savings-goals/10/events') {
                return {
                    data: {
                        data: [
                            event(),
                            event({
                                id: 2,
                                type: 'withdrawal',
                                amount_minor: '5000',
                                note: null,
                            }),
                        ],
                        meta: paginationMeta({ total: 2, from: 1, to: 2 }),
                    },
                };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        render(<SavingsGoalsIndex />);

        await waitFor(() => {
            expect(screen.getByText('Emergency Fund')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: /Emergency Fund/i }));

        await waitFor(() => {
            expect(screen.getAllByText('Allocated').length).toBeGreaterThan(0);
            expect(screen.getByText('Allocation reduced')).toBeInTheDocument();
            expect(screen.getByText('Primary buffer')).toBeInTheDocument();
            expect(screen.getByText('Seed')).toBeInTheDocument();
        });
    });

    it('clears detail safely on unauthorized goal responses', async () => {
        const mockedGet = vi.mocked(axios.get);
        stubDefaultReads();

        render(<SavingsGoalsIndex />);

        await waitFor(() => {
            expect(screen.getByText('Emergency Fund')).toBeInTheDocument();
        });

        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [goal()],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/10' || url === '/savings-goals/10/events') {
                throw mockAxiosError(403, { message: 'Forbidden internals' });
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        fireEvent.click(screen.getByRole('button', { name: /Emergency Fund/i }));

        await waitFor(() => {
            expect(screen.getByText('That savings goal is not available.')).toBeInTheDocument();
        });

        expect(screen.queryByText('Forbidden internals')).not.toBeInTheDocument();
        expect(screen.getByText('No goal selected.')).toBeInTheDocument();
    });

    it('issues only GET requests for savings data (no mutations)', async () => {
        render(<SavingsGoalsIndex />);

        await waitFor(() => {
            expect(screen.getByText('Emergency Fund')).toBeInTheDocument();
        });

        expect(vi.mocked(axios.post)).not.toHaveBeenCalled();
        expect(vi.mocked(axios.patch)).not.toHaveBeenCalled();
        expect(vi.mocked(axios.put)).not.toHaveBeenCalled();
        expect(vi.mocked(axios.delete)).not.toHaveBeenCalled();

        const getUrls = vi.mocked(axios.get).mock.calls.map((call) => call[0]);
        expect(getUrls.every((url) => String(url).startsWith('/savings-goals'))).toBe(true);
    });

    it('omits status when All filter is selected', async () => {
        const mockedGet = vi.mocked(axios.get);
        stubDefaultReads();

        render(<SavingsGoalsIndex />);

        await waitFor(() => {
            expect(screen.getByText('Emergency Fund')).toBeInTheDocument();
        });

        mockedGet.mockImplementation(async (url: string, config?: unknown) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                const params =
                    typeof config === 'object' &&
                    config !== null &&
                    'params' in config &&
                    typeof (config as { params?: unknown }).params === 'object' &&
                    (config as { params?: unknown }).params !== null
                        ? ((config as { params: Record<string, unknown> }).params)
                        : {};
                expect(params.status).toBeUndefined();
                return {
                    data: {
                        data: [goal(), goal({ id: 12, name: 'Archived Nest', status: 'archived' })],
                        meta: paginationMeta({ total: 2, from: 1, to: 2 }),
                        filters: { status: null },
                    },
                };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        fireEvent.click(screen.getByRole('button', { name: 'All' }));

        await waitFor(() => {
            expect(screen.getByText('Archived Nest')).toBeInTheDocument();
        });
    });

    it('shows exact minor-unit fallback for unknown currency metadata', async () => {
        stubDefaultReads({
            goals: [
                goal({
                    id: 44,
                    name: 'Mystery Goal',
                    currency_code: 'XYZ',
                    target_amount_minor: '12345',
                    allocated_amount_minor: '12345',
                    remaining_amount_minor: '0',
                }),
            ],
        });

        const mockedGet = vi.mocked(axios.get);
        const original = mockedGet.getMockImplementation();
        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/44') {
                return {
                    data: {
                        data: goal({
                            id: 44,
                            name: 'Mystery Goal',
                            currency_code: 'XYZ',
                            target_amount_minor: '12345',
                            allocated_amount_minor: '12345',
                            remaining_amount_minor: '0',
                        }),
                    },
                };
            }
            if (url === '/savings-goals/44/events') {
                return { data: { data: [], meta: paginationMeta() } };
            }
            if (original) {
                return original(url);
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        render(<SavingsGoalsIndex />);

        await waitFor(() => {
            expect(screen.getByText('Mystery Goal')).toBeInTheDocument();
        });

        expect(screen.getAllByText('XYZ 12345 minor units').length).toBeGreaterThan(0);
        expect(screen.queryByText('XYZ 123.45')).not.toBeInTheDocument();
    });

    it('ignores stale detail success after a newer goal is selected', async () => {
        const mockedGet = vi.mocked(axios.get);
        const detailA = createDeferred<{ data: { data: SavingsGoalProgressRecord } }>();
        const detailB = createDeferred<{ data: { data: SavingsGoalProgressRecord } }>();

        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [
                            goal({ id: 10, name: 'Goal A' }),
                            goal({ id: 20, name: 'Goal B', allocated_amount_minor: '5000' }),
                        ],
                        meta: paginationMeta({ total: 2, from: 1, to: 2 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/10') {
                return detailA.promise;
            }
            if (url === '/savings-goals/20') {
                return detailB.promise;
            }
            if (url === '/savings-goals/10/events' || url === '/savings-goals/20/events') {
                return { data: { data: [], meta: paginationMeta() } };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        render(<SavingsGoalsIndex />);

        await waitFor(() => {
            expect(screen.getByText('Goal A')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: /Goal A/i }));
        fireEvent.click(screen.getByRole('button', { name: /Goal B/i }));

        detailA.resolve({
            data: {
                data: goal({ id: 10, name: 'Goal A', description: 'Stale A detail' }),
            },
        });
        detailB.resolve({
            data: {
                data: goal({ id: 20, name: 'Goal B', description: 'Fresh B detail' }),
            },
        });

        await waitFor(() => {
            expect(screen.getByText('Fresh B detail')).toBeInTheDocument();
        });
        expect(screen.queryByText('Stale A detail')).not.toBeInTheDocument();
    });

    it('ignores stale events after switching goals', async () => {
        const mockedGet = vi.mocked(axios.get);
        const eventsA = createDeferred<{
            data: { data: SavingsGoalEventRecord[]; meta: PaginationMeta };
        }>();

        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [
                            goal({ id: 10, name: 'Goal A' }),
                            goal({ id: 20, name: 'Goal B' }),
                        ],
                        meta: paginationMeta({ total: 2, from: 1, to: 2 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/10' || url === '/savings-goals/20') {
                const id = url.endsWith('/10') ? 10 : 20;
                return {
                    data: {
                        data: goal({ id, name: id === 10 ? 'Goal A' : 'Goal B' }),
                    },
                };
            }
            if (url === '/savings-goals/10/events') {
                return eventsA.promise;
            }
            if (url === '/savings-goals/20/events') {
                return {
                    data: {
                        data: [
                            event({
                                id: 99,
                                savings_goal_id: 20,
                                note: 'B only event',
                                amount_minor: '111',
                            }),
                        ],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                    },
                };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        render(<SavingsGoalsIndex />);

        await waitFor(() => {
            expect(screen.getByText('Goal A')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: /Goal A/i }));
        fireEvent.click(screen.getByRole('button', { name: /Goal B/i }));

        await waitFor(() => {
            expect(screen.getByText('B only event')).toBeInTheDocument();
        });

        eventsA.resolve({
            data: {
                data: [
                    event({
                        id: 1,
                        savings_goal_id: 10,
                        note: 'Stale A event',
                        amount_minor: '999',
                    }),
                ],
                meta: paginationMeta({ total: 1, from: 1, to: 1 }),
            },
        });

        await waitFor(() => {
            expect(screen.getByText('B only event')).toBeInTheDocument();
        });
        expect(screen.queryByText('Stale A event')).not.toBeInTheDocument();
    });

    it('ignores stale filter list results after a newer filter is applied', async () => {
        const mockedGet = vi.mocked(axios.get);
        const activeList = createDeferred<{
            data: {
                data: SavingsGoalProgressRecord[];
                meta: PaginationMeta;
                filters: { status: string | null };
            };
        }>();

        mockedGet.mockImplementation(async (url: string, config?: unknown) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                const params =
                    typeof config === 'object' &&
                    config !== null &&
                    'params' in config &&
                    typeof (config as { params?: unknown }).params === 'object' &&
                    (config as { params?: unknown }).params !== null
                        ? (config as { params: Record<string, unknown> }).params
                        : {};

                if (params.status === 'active') {
                    return activeList.promise;
                }

                if (params.status === 'archived') {
                    return {
                        data: {
                            data: [goal({ id: 30, name: 'Archived Only', status: 'archived' })],
                            meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                            filters: { status: 'archived' },
                        },
                    };
                }
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        render(<SavingsGoalsIndex />);

        fireEvent.click(screen.getByRole('button', { name: 'Archived' }));

        await waitFor(() => {
            expect(screen.getByText('Archived Only')).toBeInTheDocument();
        });

        activeList.resolve({
            data: {
                data: [goal({ id: 10, name: 'Stale Active Goal' })],
                meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                filters: { status: 'active' },
            },
        });

        await waitFor(() => {
            expect(screen.getByText('Archived Only')).toBeInTheDocument();
        });
        expect(screen.queryByText('Stale Active Goal')).not.toBeInTheDocument();
    });

    it('ignores stale pagination results when a newer page is requested', async () => {
        const mockedGet = vi.mocked(axios.get);
        const latePage1 = createDeferred<{
            data: {
                data: SavingsGoalProgressRecord[];
                meta: PaginationMeta;
                filters: { status: string | null };
            };
        }>();
        let page1Calls = 0;

        mockedGet.mockImplementation(async (url: string, config?: unknown) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                const params =
                    typeof config === 'object' &&
                    config !== null &&
                    'params' in config &&
                    typeof (config as { params?: unknown }).params === 'object' &&
                    (config as { params?: unknown }).params !== null
                        ? (config as { params: Record<string, unknown> }).params
                        : {};

                if (params.page === 1) {
                    page1Calls += 1;
                    if (page1Calls === 1) {
                        return {
                            data: {
                                data: [goal({ id: 10, name: 'Page One Goal' })],
                                meta: paginationMeta({
                                    current_page: 1,
                                    last_page: 2,
                                    total: 26,
                                    from: 1,
                                    to: 25,
                                }),
                                filters: { status: 'active' },
                            },
                        };
                    }
                    return latePage1.promise;
                }

                if (params.page === 2) {
                    return {
                        data: {
                            data: [goal({ id: 11, name: 'Page Two Goal' })],
                            meta: paginationMeta({
                                current_page: 2,
                                last_page: 2,
                                total: 26,
                                from: 26,
                                to: 26,
                            }),
                            filters: { status: 'active' },
                        },
                    };
                }
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        render(<SavingsGoalsIndex />);

        await waitFor(() => {
            expect(screen.getByText('Page One Goal')).toBeInTheDocument();
        });

        fireEvent.click(
            within(screen.getByLabelText('Goals pagination')).getByRole('button', {
                name: 'Next',
            }),
        );

        await waitFor(() => {
            expect(screen.getByText('Page Two Goal')).toBeInTheDocument();
        });

        fireEvent.click(
            within(screen.getByLabelText('Goals pagination')).getByRole('button', {
                name: 'Previous',
            }),
        );

        fireEvent.click(
            within(screen.getByLabelText('Goals pagination')).getByRole('button', {
                name: 'Next',
            }),
        );

        await waitFor(() => {
            expect(screen.getByText('Page Two Goal')).toBeInTheDocument();
        });

        latePage1.resolve({
            data: {
                data: [goal({ id: 10, name: 'Stale Page One' })],
                meta: paginationMeta({
                    current_page: 1,
                    last_page: 2,
                    total: 26,
                    from: 1,
                    to: 25,
                }),
                filters: { status: 'active' },
            },
        });

        await waitFor(() => {
            expect(screen.getByText('Page Two Goal')).toBeInTheDocument();
        });
        expect(screen.queryByText('Stale Page One')).not.toBeInTheDocument();
    });

    it('does not apply a stale 403 from a previously selected goal', async () => {
        const mockedGet = vi.mocked(axios.get);
        const detailA = createDeferred<{ data: { data: SavingsGoalProgressRecord } }>();

        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [
                            goal({ id: 10, name: 'Goal A' }),
                            goal({ id: 20, name: 'Goal B' }),
                        ],
                        meta: paginationMeta({ total: 2, from: 1, to: 2 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/10') {
                return detailA.promise;
            }
            if (url === '/savings-goals/20') {
                return { data: { data: goal({ id: 20, name: 'Goal B', description: 'Keep B' }) } };
            }
            if (url.endsWith('/events')) {
                return { data: { data: [], meta: paginationMeta() } };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        render(<SavingsGoalsIndex />);

        await waitFor(() => {
            expect(screen.getByText('Goal A')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: /Goal A/i }));
        fireEvent.click(screen.getByRole('button', { name: /Goal B/i }));

        await waitFor(() => {
            expect(screen.getByText('Keep B')).toBeInTheDocument();
        });

        detailA.reject(mockAxiosError(403, { message: 'stale forbidden' }));

        await waitFor(() => {
            expect(screen.getByText('Keep B')).toBeInTheDocument();
        });
        expect(screen.queryByText('That savings goal is not available.')).not.toBeInTheDocument();
        expect(screen.queryByText('No goal selected.')).not.toBeInTheDocument();
    });

    it('opens create form, posts exact minor-unit payload with UUID, and refreshes views on 201', async () => {
        const mockedPost = vi.mocked(axios.post);
        const mockedGet = vi.mocked(axios.get);
        const created = goal({
            id: 44,
            name: 'Vacation',
            currency_code: 'PHP',
            target_amount_minor: '20000000',
            description: null,
            target_date: null,
        });

        mockedPost.mockResolvedValue({
            status: 201,
            data: {
                data: created,
                meta: { was_recently_created: true, idempotent_replay: false },
            },
        });

        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return {
                    data: {
                        data: portfolio({
                            total_goal_count: 3,
                            active_goal_count: 2,
                        }),
                    },
                };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [goal(), created],
                        meta: paginationMeta({ total: 2, from: 1, to: 2 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/44') {
                return { data: { data: created } };
            }
            if (url === '/savings-goals/44/events') {
                return { data: { data: [], meta: paginationMeta() } };
            }
            if (url === '/savings-goals/10') {
                return { data: { data: goal() } };
            }
            if (url === '/savings-goals/10/events') {
                return { data: { data: [event()], meta: paginationMeta({ total: 1, from: 1, to: 1 }) } };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        render(<SavingsGoalsIndex />);

        await waitFor(() => {
            expect(screen.getByText('Emergency Fund')).toBeInTheDocument();
        });

        const dialog = openCreateDialog();

        fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Vacation' } });
        fireEvent.change(within(dialog).getByLabelText('Currency'), { target: { value: 'PHP' } });
        fireEvent.change(within(dialog).getByLabelText('Target amount'), {
            target: { value: '200000.00' },
        });

        submitCreateDialog(dialog);

        await waitFor(() => {
            expect(mockedPost).toHaveBeenCalledTimes(1);
        });
        expect(mockedPost.mock.calls[0][0]).toBe('/savings-goals');
        expect(postedCreateBody(mockedPost, 0)).toMatchObject({
            name: 'Vacation',
            description: null,
            currency_code: 'PHP',
            target_amount_minor: '20000000',
            target_date: null,
        });
        expect(postedCreateBody(mockedPost, 0).idempotency_key).toMatch(
            /^00000000-0000-4000-8000-\d{12}$/,
        );
        expect(mockedPost.mock.calls[0][2]).toEqual({ headers: { Accept: 'application/json' } });

        await waitFor(() => {
            expect(screen.getByText('Savings goal created.')).toBeInTheDocument();
        });
        expect(screen.queryByRole('dialog', { name: 'Create savings goal' })).not.toBeInTheDocument();
        expect(screen.getAllByRole('heading', { name: 'Vacation', level: 3 }).length).toBeGreaterThan(0);
    });

    it('treats create 200 idempotent replay as success and prevents double submit', async () => {
        const mockedPost = vi.mocked(axios.post);
        const deferred = createDeferred<{
            status: number;
            data: {
                data: SavingsGoalProgressRecord;
                meta: { was_recently_created: boolean; idempotent_replay: boolean };
            };
        }>();
        const existing = goal({ id: 55, name: 'Replay Goal', target_amount_minor: '50000' });

        render(<SavingsGoalsIndex />);
        await waitFor(() => expect(screen.getByText('Emergency Fund')).toBeInTheDocument());

        mockedPost.mockReturnValueOnce(deferred.promise);

        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [existing],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/55') {
                return { data: { data: existing } };
            }
            if (url.endsWith('/events')) {
                return { data: { data: [], meta: paginationMeta() } };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        const dialog = openCreateDialog();
        fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Replay Goal' } });
        fireEvent.change(within(dialog).getByLabelText('Currency'), { target: { value: 'PHP' } });
        fireEvent.change(within(dialog).getByLabelText('Target amount'), {
            target: { value: '500.00' },
        });

        const submit = within(dialog).getByRole('button', { name: 'Create goal' });
        fireEvent.click(submit);
        fireEvent.click(submit);

        await waitFor(() => expect(mockedPost).toHaveBeenCalledTimes(1));

        deferred.resolve({
            status: 200,
            data: {
                data: existing,
                meta: { was_recently_created: false, idempotent_replay: true },
            },
        });

        await waitFor(() => {
            expect(
                screen.getByText('Existing savings goal returned for this create request.'),
            ).toBeInTheDocument();
        });
    });

    it('maps create 422 field errors and preserves UUID across ambiguous network failure retry', async () => {
        const mockedPost = vi.mocked(axios.post);

        render(<SavingsGoalsIndex />);
        await waitFor(() => expect(screen.getByText('Emergency Fund')).toBeInTheDocument());

        const dialog = openCreateDialog();
        fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Bad' } });
        fireEvent.change(within(dialog).getByLabelText('Currency'), { target: { value: 'PHP' } });
        fireEvent.change(within(dialog).getByLabelText('Target amount'), {
            target: { value: '10.00' },
        });

        mockedPost.mockRejectedValueOnce(
            mockAxiosError(422, {
                message: 'The given data was invalid.',
                errors: { name: ['The name field must be at least something.'] },
            }),
        );

        submitCreateDialog(dialog);

        await waitFor(() => {
            expect(
                screen.getAllByText('The name field must be at least something.').length,
            ).toBeGreaterThan(0);
        });

        fireEvent.change(within(dialog).getByLabelText('Name'), {
            target: { value: 'Network Goal' },
        });
        fireEvent.change(within(dialog).getByLabelText('Target amount'), {
            target: { value: '25.00' },
        });

        mockedPost.mockRejectedValueOnce({ isAxiosError: true, message: 'Network Error' });

        submitCreateDialog(dialog);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Retry same request' })).toBeInTheDocument();
        });

        const uncertainKey = String(postedCreateBody(mockedPost, 1).idempotency_key);
        expect(uncertainKey).toMatch(/^00000000-0000-4000-8000-/);

        const created = goal({
            id: 66,
            name: 'Network Goal',
            target_amount_minor: '2500',
        });
        mockedPost.mockResolvedValueOnce({
            status: 201,
            data: {
                data: created,
                meta: { was_recently_created: true, idempotent_replay: false },
            },
        });

        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [created],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === `/savings-goals/${created.id}`) {
                return { data: { data: created } };
            }
            if (url.endsWith('/events')) {
                return { data: { data: [], meta: paginationMeta() } };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        fireEvent.click(screen.getByRole('button', { name: 'Retry same request' }));

        await waitFor(() => expect(mockedPost).toHaveBeenCalledTimes(3));
        expect(postedCreateBody(mockedPost, 2).idempotency_key).toBe(uncertainKey);
        expect(postedCreateBody(mockedPost, 2)).toMatchObject({
            name: 'Network Goal',
            target_amount_minor: '2500',
            currency_code: 'PHP',
        });
    });

    it('preserves create UUID/payload across uncertain close/reopen and warns on new attempt', async () => {
        const mockedPost = vi.mocked(axios.post);

        render(<SavingsGoalsIndex />);
        await waitFor(() => expect(screen.getByText('Emergency Fund')).toBeInTheDocument());

        let dialog = openCreateDialog();
        fireEvent.change(within(dialog).getByLabelText('Name'), {
            target: { value: 'Pending Goal' },
        });
        fireEvent.change(within(dialog).getByLabelText('Currency'), { target: { value: 'PHP' } });
        fireEvent.change(within(dialog).getByLabelText('Target amount'), {
            target: { value: '75.00' },
        });

        mockedPost.mockRejectedValueOnce({ isAxiosError: true, message: 'Network Error' });
        submitCreateDialog(dialog);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Retry same request' })).toBeInTheDocument();
        });
        const uncertainKey = String(postedCreateBody(mockedPost, 0).idempotency_key);

        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        await waitFor(() => {
            expect(screen.queryByRole('dialog', { name: 'Create savings goal' })).not.toBeInTheDocument();
        });

        dialog = openCreateDialog();
        expect(within(dialog).getByLabelText('Name')).toHaveValue('Pending Goal');
        expect(within(dialog).getByLabelText('Currency')).toHaveValue('PHP');
        expect(within(dialog).getByLabelText('Target amount')).toHaveValue('75.00');
        expect(within(dialog).getByRole('button', { name: 'Retry same request' })).toBeInTheDocument();

        mockedPost.mockResolvedValueOnce({
            status: 201,
            data: {
                data: goal({ id: 91, name: 'Pending Goal', target_amount_minor: '7500' }),
                meta: { was_recently_created: true, idempotent_replay: false },
            },
        });
        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [goal({ id: 91, name: 'Pending Goal', target_amount_minor: '7500' })],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/91') {
                return {
                    data: {
                        data: goal({ id: 91, name: 'Pending Goal', target_amount_minor: '7500' }),
                    },
                };
            }
            if (url.endsWith('/events')) {
                return { data: { data: [], meta: paginationMeta() } };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        fireEvent.click(within(dialog).getByRole('button', { name: 'Retry same request' }));
        await waitFor(() => expect(mockedPost).toHaveBeenCalledTimes(2));
        expect(postedCreateBody(mockedPost, 1).idempotency_key).toBe(uncertainKey);
        expect(postedCreateBody(mockedPost, 1)).toMatchObject({
            name: 'Pending Goal',
            currency_code: 'PHP',
            target_amount_minor: '7500',
        });
    });

    it('focuses the create dialog and keeps Tab focus contained', async () => {
        render(<SavingsGoalsIndex />);
        await waitFor(() => expect(screen.getByText('Emergency Fund')).toBeInTheDocument());

        const createTrigger = screen.getByRole('button', { name: 'Create goal' });
        createTrigger.focus();
        expect(document.activeElement).toBe(createTrigger);

        const dialog = openCreateDialog();
        await waitFor(() => {
            expect(document.activeElement).toBe(within(dialog).getByLabelText('Name'));
        });

        // Move focus to the last control, then Tab should wrap to the first focusable.
        within(dialog).getByRole('button', { name: 'Create goal' }).focus();
        fireEvent.keyDown(document, { key: 'Tab', bubbles: true });
        expect(dialog.contains(document.activeElement)).toBe(true);
        expect(document.activeElement).toBe(within(dialog).getByLabelText('Name'));

        // Shift+Tab from the first control should wrap to the last focusable.
        fireEvent.keyDown(document, { key: 'Tab', shiftKey: true, bubbles: true });
        expect(dialog.contains(document.activeElement)).toBe(true);

        fireEvent.keyDown(document, { key: 'Escape', bubbles: true });
        await waitFor(() => {
            expect(screen.queryByRole('dialog', { name: 'Create savings goal' })).not.toBeInTheDocument();
        });
        expect(document.activeElement).toBe(createTrigger);
    });

    it('blocks automatic resubmit on 409 until Start new attempt', async () => {
        const mockedPost = vi.mocked(axios.post);
        mockedPost.mockRejectedValueOnce(
            mockAxiosError(409, {
                message: 'The idempotency key was already used with different request data.',
                errors: {
                    idempotency_key: [
                        'The idempotency key was already used with different request data.',
                    ],
                },
            }),
        );

        render(<SavingsGoalsIndex />);
        await waitFor(() => expect(screen.getByText('Emergency Fund')).toBeInTheDocument());

        const dialog = openCreateDialog();
        fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Conflict' } });
        fireEvent.change(within(dialog).getByLabelText('Currency'), { target: { value: 'USD' } });
        fireEvent.change(within(dialog).getByLabelText('Target amount'), {
            target: { value: '40.00' },
        });
        submitCreateDialog(dialog);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Start new attempt' })).toBeInTheDocument();
        });
        expect(within(dialog).queryByRole('button', { name: 'Create goal' })).not.toBeInTheDocument();

        const conflictKey = String(postedCreateBody(mockedPost, 0).idempotency_key);

        fireEvent.click(screen.getByRole('button', { name: 'Start new attempt' }));

        mockedPost.mockResolvedValueOnce({
            status: 201,
            data: {
                data: goal({ id: 77, name: 'Conflict', currency_code: 'USD', target_amount_minor: '4000' }),
                meta: { was_recently_created: true, idempotent_replay: false },
            },
        });

        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [goal({ id: 77, name: 'Conflict', currency_code: 'USD' })],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/77') {
                return {
                    data: {
                        data: goal({
                            id: 77,
                            name: 'Conflict',
                            currency_code: 'USD',
                            target_amount_minor: '4000',
                        }),
                    },
                };
            }
            if (url.endsWith('/events')) {
                return { data: { data: [], meta: paginationMeta() } };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        submitCreateDialog(dialog);

        await waitFor(() => expect(mockedPost).toHaveBeenCalledTimes(2));
        expect(postedCreateBody(mockedPost, 1).idempotency_key).not.toBe(conflictKey);
    });

    it('edits an active goal with immutable currency and refreshes detail', async () => {
        const mockedPatch = vi.mocked(axios.patch);
        const updated = goal({
            name: 'Emergency Fund Plus',
            target_amount_minor: '150000',
            description: 'Updated buffer',
            target_date: '2028-01-01',
        });

        render(<SavingsGoalsIndex />);
        await waitFor(() => expect(screen.getByText('Emergency Fund')).toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', { name: /Emergency Fund/i }));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument();
        });

        mockedPatch.mockResolvedValue({ status: 200, data: { data: updated } });

        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [updated],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/10') {
                return { data: { data: updated } };
            }
            if (url === '/savings-goals/10/events') {
                return {
                    data: {
                        data: [event()],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                    },
                };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        fireEvent.click(screen.getByRole('button', { name: 'Edit' }));

        const dialog = screen.getByRole('dialog', { name: 'Edit savings goal' });
        expect(within(dialog).getByText('PHP')).toBeInTheDocument();
        expect(within(dialog).queryByLabelText('Currency')).not.toBeInTheDocument();
        expect(within(dialog).getByLabelText('Target amount')).toHaveValue('1000.00');

        fireEvent.change(within(dialog).getByLabelText('Name'), {
            target: { value: 'Emergency Fund Plus' },
        });
        fireEvent.change(within(dialog).getByLabelText('Target amount'), {
            target: { value: '1500.00' },
        });
        fireEvent.change(within(dialog).getByLabelText('Description'), {
            target: { value: 'Updated buffer' },
        });
        fireEvent.change(within(dialog).getByLabelText('Target date'), {
            target: { value: '2028-01-01' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }));

        await waitFor(() => {
            expect(mockedPatch).toHaveBeenCalledWith(
                '/savings-goals/10',
                {
                    name: 'Emergency Fund Plus',
                    description: 'Updated buffer',
                    target_amount_minor: '150000',
                    target_date: '2028-01-01',
                },
                { headers: { Accept: 'application/json' } },
            );
        });

        await waitFor(() => {
            expect(screen.getByText('Savings goal updated.')).toBeInTheDocument();
        });
        expect(
            screen.getAllByRole('heading', { name: 'Emergency Fund Plus', level: 3 }).length,
        ).toBeGreaterThan(0);
    });

    it('surfaces archived edit restriction from 422', async () => {
        const mockedPatch = vi.mocked(axios.patch);
        mockedPatch.mockRejectedValue(
            mockAxiosError(422, {
                errors: { goal: ['Archived savings goals cannot be updated.'] },
            }),
        );

        render(<SavingsGoalsIndex />);
        await waitFor(() => expect(screen.getByText('Emergency Fund')).toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', { name: /Emergency Fund/i }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', { name: 'Edit' }));

        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => {
            expect(
                screen.getAllByText('Archived savings goals cannot be updated.').length,
            ).toBeGreaterThan(0);
        });
    });

    it('archives an active goal after confirmation and refreshes', async () => {
        const mockedPost = vi.mocked(axios.post);
        const archived = goal({
            status: 'archived',
            archived_at: '2026-11-01T12:00:00+00:00',
        });

        render(<SavingsGoalsIndex />);
        await waitFor(() => expect(screen.getByText('Emergency Fund')).toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', { name: /Emergency Fund/i }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Archive' })).toBeInTheDocument());

        mockedPost.mockResolvedValue({ status: 200, data: { data: archived } });

        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return {
                    data: {
                        data: portfolio({
                            active_goal_count: 0,
                            archived_goal_count: 2,
                        }),
                    },
                };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [],
                        meta: paginationMeta(),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/10') {
                return { data: { data: archived } };
            }
            if (url === '/savings-goals/10/events') {
                return {
                    data: {
                        data: [event()],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                    },
                };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        fireEvent.click(screen.getByRole('button', { name: 'Archive' }));

        const confirm = screen.getByRole('dialog', { name: 'Archive savings goal' });
        expect(confirm).toHaveTextContent(/Funds are not moved/i);

        fireEvent.click(within(confirm).getByRole('button', { name: 'Archive goal' }));

        await waitFor(() => {
            expect(mockedPost).toHaveBeenCalledWith(
                '/savings-goals/10/archive',
                {},
                { headers: { Accept: 'application/json' } },
            );
        });

        await waitFor(() => {
            expect(
                screen.getByText(/Savings goal archived. Existing allocation history is preserved./i),
            ).toBeInTheDocument();
        });
        expect(screen.getAllByText('Archived').length).toBeGreaterThan(0);
        expect(screen.getByRole('button', { name: 'Restore' })).toBeInTheDocument();
    });

    it('restores an archived goal after confirmation', async () => {
        const archived = goal({
            status: 'archived',
            archived_at: '2026-11-01T12:00:00+00:00',
        });
        const restored = goal({ status: 'active', archived_at: null });
        let detailStatus: 'archived' | 'active' = 'archived';

        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockImplementation(async (url: string, config?: unknown) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                const params =
                    typeof config === 'object' &&
                    config !== null &&
                    'params' in config &&
                    typeof (config as { params?: unknown }).params === 'object' &&
                    (config as { params?: unknown }).params !== null
                        ? (config as { params: Record<string, unknown> }).params
                        : {};

                if (params.status === 'archived') {
                    return {
                        data: {
                            data: detailStatus === 'archived' ? [archived] : [],
                            meta: paginationMeta({
                                total: detailStatus === 'archived' ? 1 : 0,
                                from: detailStatus === 'archived' ? 1 : null,
                                to: detailStatus === 'archived' ? 1 : null,
                            }),
                            filters: { status: 'archived' },
                        },
                    };
                }

                return {
                    data: {
                        data: detailStatus === 'active' ? [restored] : [goal()],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/10') {
                return {
                    data: {
                        data: detailStatus === 'archived' ? archived : restored,
                    },
                };
            }
            if (url.endsWith('/events')) {
                return { data: { data: [], meta: paginationMeta() } };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        const mockedPost = vi.mocked(axios.post);
        mockedPost.mockImplementation(async () => {
            detailStatus = 'active';
            return { status: 200, data: { data: restored } };
        });

        render(<SavingsGoalsIndex />);
        await waitFor(() => expect(screen.getByText('Emergency Fund')).toBeInTheDocument());

        fireEvent.click(screen.getByRole('button', { name: 'Archived' }));
        await waitFor(() => expect(screen.getByText('Emergency Fund')).toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', { name: /Emergency Fund/i }));

        await waitFor(() => expect(screen.getByRole('button', { name: 'Restore' })).toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', { name: 'Restore' }));

        const confirm = screen.getByRole('dialog', { name: 'Restore savings goal' });
        fireEvent.click(within(confirm).getByRole('button', { name: 'Restore goal' }));

        await waitFor(() => {
            expect(mockedPost).toHaveBeenCalledWith(
                '/savings-goals/10/restore',
                {},
                { headers: { Accept: 'application/json' } },
            );
        });

        await waitFor(() => {
            expect(screen.getByText('Savings goal restored to active status.')).toBeInTheDocument();
        });
        expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument();
    });

    it('does not let a stale goals response overwrite a newer post-mutation refresh', async () => {
        const mockedGet = vi.mocked(axios.get);
        const lateGoals = createDeferred<{
            data: {
                data: SavingsGoalProgressRecord[];
                meta: PaginationMeta;
                filters: { status: string | null };
            };
        }>();
        let goalsCalls = 0;

        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                goalsCalls += 1;
                if (goalsCalls === 1) {
                    return {
                        data: {
                            data: [goal({ name: 'Initial Goal' })],
                            meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                            filters: { status: 'active' },
                        },
                    };
                }
                if (goalsCalls === 2) {
                    return lateGoals.promise;
                }
                return {
                    data: {
                        data: [goal({ id: 88, name: 'Fresh After Mutation' })],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/10') {
                return { data: { data: goal({ name: 'Initial Goal' }) } };
            }
            if (url === '/savings-goals/88') {
                return { data: { data: goal({ id: 88, name: 'Fresh After Mutation' }) } };
            }
            if (url.endsWith('/events')) {
                return { data: { data: [], meta: paginationMeta() } };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        render(<SavingsGoalsIndex />);
        await waitFor(() => expect(screen.getByText('Initial Goal')).toBeInTheDocument());

        // Start a second goals load (All filter) that will resolve late after create refresh.
        fireEvent.click(screen.getByRole('button', { name: 'All' }));

        const mockedPost = vi.mocked(axios.post);
        mockedPost.mockResolvedValue({
            status: 201,
            data: {
                data: goal({ id: 88, name: 'Fresh After Mutation', target_amount_minor: '1000' }),
                meta: { was_recently_created: true, idempotent_replay: false },
            },
        });

        const dialog = openCreateDialog();
        fireEvent.change(within(dialog).getByLabelText('Name'), {
            target: { value: 'Fresh After Mutation' },
        });
        fireEvent.change(within(dialog).getByLabelText('Currency'), { target: { value: 'PHP' } });
        fireEvent.change(within(dialog).getByLabelText('Target amount'), {
            target: { value: '10.00' },
        });
        submitCreateDialog(dialog);

        await waitFor(() => {
            expect(screen.getAllByText('Fresh After Mutation').length).toBeGreaterThan(0);
        });

        lateGoals.resolve({
            data: {
                data: [goal({ name: 'Stale List' })],
                meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                filters: { status: 'active' },
            },
        });

        await waitFor(() => {
            expect(screen.getAllByText('Fresh After Mutation').length).toBeGreaterThan(0);
        });
        expect(screen.queryByText('Stale List')).not.toBeInTheDocument();
    });

    async function selectEmergencyFund() {
        await waitFor(() => expect(screen.getByText('Emergency Fund')).toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', { name: /Emergency Fund/i }));
        await waitFor(() => {
            expect(screen.getByRole('heading', { name: 'Emergency Fund', level: 3 })).toBeInTheDocument();
        });
    }

    it('opens allocate dialog, posts exact minor payload, and refreshes on 201', async () => {
        const mockedPost = vi.mocked(axios.post);
        const updated = goal({
            allocated_amount_minor: '37500',
            remaining_amount_minor: '62500',
            progress_basis_points: 3750,
        });
        const createdEvent = event({
            id: 99,
            amount_minor: '12500',
            note: 'Payday earmark',
        });

        render(<SavingsGoalsIndex />);
        await selectEmergencyFund();

        mockedPost.mockResolvedValue({
            status: 201,
            data: {
                data: { event: createdEvent, goal: updated },
                meta: { was_recently_created: true, idempotent_replay: false },
            },
        });

        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [updated],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/10') {
                return { data: { data: updated } };
            }
            if (url === '/savings-goals/10/events') {
                return {
                    data: {
                        data: [createdEvent, event()],
                        meta: paginationMeta({ total: 2, from: 1, to: 2 }),
                    },
                };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        fireEvent.click(screen.getByRole('button', { name: 'Allocate to goal' }));
        const dialog = screen.getByRole('dialog', { name: 'Allocate to goal' });
        expect(dialog).toHaveTextContent(/do not transfer bank funds/i);
        expect(within(dialog).getByText('Emergency Fund')).toBeInTheDocument();
        expect(within(dialog).getByText('PHP')).toBeInTheDocument();

        fireEvent.change(within(dialog).getByLabelText('Amount'), {
            target: { value: '125.00' },
        });
        fireEvent.change(within(dialog).getByLabelText('Note'), {
            target: { value: 'Payday earmark' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Allocate to goal' }));

        await waitFor(() => expect(mockedPost).toHaveBeenCalledTimes(1));
        expect(mockedPost.mock.calls[0][0]).toBe('/savings-goals/10/contributions');
        expect(postedCreateBody(mockedPost, 0)).toMatchObject({
            amount_minor: '12500',
            note: 'Payday earmark',
        });
        expect(postedCreateBody(mockedPost, 0).idempotency_key).toMatch(
            /^00000000-0000-4000-8000-\d{12}$/,
        );

        await waitFor(() => {
            expect(screen.getByText('Amount allocated to the goal.')).toBeInTheDocument();
        });
        expect(screen.getByText('Payday earmark')).toBeInTheDocument();
    });

    it('opens reduce dialog, blocks over-withdrawal locally, and posts withdrawals endpoint', async () => {
        const mockedPost = vi.mocked(axios.post);
        const updated = goal({
            allocated_amount_minor: '15000',
            remaining_amount_minor: '85000',
            progress_basis_points: 1500,
        });

        render(<SavingsGoalsIndex />);
        await selectEmergencyFund();

        fireEvent.click(screen.getByRole('button', { name: 'Reduce allocation' }));
        const dialog = screen.getByRole('dialog', { name: 'Reduce allocation' });
        expect(dialog).toHaveTextContent(/does not withdraw money from a bank/i);

        fireEvent.change(within(dialog).getByLabelText('Amount'), {
            target: { value: '250.01' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Reduce allocation' }));

        await waitFor(() => {
            expect(
                screen.getByText(/exceeds the currently allocated balance/i),
            ).toBeInTheDocument();
        });
        expect(mockedPost).not.toHaveBeenCalled();

        mockedPost.mockResolvedValue({
            status: 201,
            data: {
                data: {
                    event: event({
                        id: 100,
                        type: 'withdrawal',
                        amount_minor: '10000',
                        note: null,
                    }),
                    goal: updated,
                },
                meta: { was_recently_created: true, idempotent_replay: false },
            },
        });

        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [updated],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/10') {
                return { data: { data: updated } };
            }
            if (url.endsWith('/events')) {
                return {
                    data: {
                        data: [event({ type: 'withdrawal', amount_minor: '10000' })],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                    },
                };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        fireEvent.change(within(dialog).getByLabelText('Amount'), {
            target: { value: '100.00' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Reduce allocation' }));

        await waitFor(() => {
            expect(mockedPost).toHaveBeenCalledWith(
                '/savings-goals/10/withdrawals',
                expect.objectContaining({
                    amount_minor: '10000',
                    note: null,
                }),
                { headers: { Accept: 'application/json' } },
            );
        });
        await waitFor(() => {
            expect(screen.getByText('Allocation reduced.')).toBeInTheDocument();
        });
    });

    it('hides allocation actions for archived goals', async () => {
        const archived = goal({
            status: 'archived',
            archived_at: '2026-11-01T12:00:00+00:00',
        });
        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockImplementation(async (url: string, config?: unknown) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                const params =
                    typeof config === 'object' &&
                    config !== null &&
                    'params' in config &&
                    typeof (config as { params?: unknown }).params === 'object' &&
                    (config as { params?: unknown }).params !== null
                        ? (config as { params: Record<string, unknown> }).params
                        : {};
                if (params.status === 'archived') {
                    return {
                        data: {
                            data: [archived],
                            meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                            filters: { status: 'archived' },
                        },
                    };
                }
                return {
                    data: {
                        data: [goal()],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/10') {
                return { data: { data: archived } };
            }
            if (url.endsWith('/events')) {
                return { data: { data: [], meta: paginationMeta() } };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        render(<SavingsGoalsIndex />);
        await waitFor(() => expect(screen.getByText('Emergency Fund')).toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', { name: 'Archived' }));
        await waitFor(() => expect(screen.getByText('Emergency Fund')).toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', { name: /Emergency Fund/i }));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Restore' })).toBeInTheDocument();
        });
        expect(screen.queryByRole('button', { name: 'Allocate to goal' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Reduce allocation' })).not.toBeInTheDocument();
    });

    it('treats allocation 200 replay as success and prevents double submit', async () => {
        const mockedPost = vi.mocked(axios.post);
        const deferred = createDeferred<{
            status: number;
            data: {
                data: { event: SavingsGoalEventRecord; goal: SavingsGoalProgressRecord };
                meta: { was_recently_created: boolean; idempotent_replay: boolean };
            };
        }>();
        const updated = goal({ allocated_amount_minor: '35000' });

        render(<SavingsGoalsIndex />);
        await selectEmergencyFund();

        mockedPost.mockReturnValueOnce(deferred.promise);
        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [updated],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/10') {
                return { data: { data: updated } };
            }
            if (url.endsWith('/events')) {
                return { data: { data: [event()], meta: paginationMeta({ total: 1, from: 1, to: 1 }) } };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        fireEvent.click(screen.getByRole('button', { name: 'Allocate to goal' }));
        const dialog = screen.getByRole('dialog', { name: 'Allocate to goal' });
        fireEvent.change(within(dialog).getByLabelText('Amount'), {
            target: { value: '100.00' },
        });
        const submit = within(dialog).getByRole('button', { name: 'Allocate to goal' });
        fireEvent.click(submit);
        fireEvent.click(submit);

        await waitFor(() => expect(mockedPost).toHaveBeenCalledTimes(1));

        deferred.resolve({
            status: 200,
            data: {
                data: { event: event({ amount_minor: '10000' }), goal: updated },
                meta: { was_recently_created: false, idempotent_replay: true },
            },
        });

        await waitFor(() => {
            expect(
                screen.getByText('Existing allocation returned for this request.'),
            ).toBeInTheDocument();
        });
    });

    it('preserves UUID across uncertain allocation outcome and close/reopen recovery', async () => {
        const mockedPost = vi.mocked(axios.post);

        render(<SavingsGoalsIndex />);
        await selectEmergencyFund();

        fireEvent.click(screen.getByRole('button', { name: 'Allocate to goal' }));
        let dialog = screen.getByRole('dialog', { name: 'Allocate to goal' });
        fireEvent.change(within(dialog).getByLabelText('Amount'), {
            target: { value: '50.00' },
        });
        fireEvent.change(within(dialog).getByLabelText('Note'), {
            target: { value: 'Hold' },
        });

        mockedPost.mockRejectedValueOnce({ isAxiosError: true, message: 'Network Error' });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Allocate to goal' }));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Retry same request' })).toBeInTheDocument();
        });
        const uncertainKey = String(postedCreateBody(mockedPost, 0).idempotency_key);

        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        await waitFor(() => {
            expect(screen.queryByRole('dialog', { name: 'Allocate to goal' })).not.toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Allocate to goal' }));
        dialog = screen.getByRole('dialog', { name: 'Allocate to goal' });
        expect(within(dialog).getByLabelText('Amount')).toHaveValue('50.00');
        expect(within(dialog).getByLabelText('Note')).toHaveValue('Hold');
        expect(within(dialog).getByRole('button', { name: 'Retry same request' })).toBeInTheDocument();

        mockedPost.mockResolvedValueOnce({
            status: 201,
            data: {
                data: {
                    event: event({ amount_minor: '5000', note: 'Hold' }),
                    goal: goal({ allocated_amount_minor: '30000' }),
                },
                meta: { was_recently_created: true, idempotent_replay: false },
            },
        });
        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [goal({ allocated_amount_minor: '30000' })],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/10') {
                return { data: { data: goal({ allocated_amount_minor: '30000' }) } };
            }
            if (url.endsWith('/events')) {
                return { data: { data: [event()], meta: paginationMeta({ total: 1, from: 1, to: 1 }) } };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        fireEvent.click(within(dialog).getByRole('button', { name: 'Retry same request' }));
        await waitFor(() => expect(mockedPost).toHaveBeenCalledTimes(2));
        expect(postedCreateBody(mockedPost, 1).idempotency_key).toBe(uncertainKey);
        expect(postedCreateBody(mockedPost, 1)).toMatchObject({
            amount_minor: '5000',
            note: 'Hold',
        });
    });

    it('blocks automatic allocation retry on 409 until Start new attempt', async () => {
        const mockedPost = vi.mocked(axios.post);
        mockedPost.mockRejectedValueOnce(
            mockAxiosError(409, {
                errors: {
                    idempotency_key: [
                        'The idempotency key was already used with different request data.',
                    ],
                },
            }),
        );

        render(<SavingsGoalsIndex />);
        await selectEmergencyFund();

        fireEvent.click(screen.getByRole('button', { name: 'Allocate to goal' }));
        const dialog = screen.getByRole('dialog', { name: 'Allocate to goal' });
        fireEvent.change(within(dialog).getByLabelText('Amount'), {
            target: { value: '20.00' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Allocate to goal' }));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Start new attempt' })).toBeInTheDocument();
        });
        expect(within(dialog).queryByRole('button', { name: 'Allocate to goal' })).not.toBeInTheDocument();
        const conflictKey = String(postedCreateBody(mockedPost, 0).idempotency_key);

        fireEvent.click(screen.getByRole('button', { name: 'Start new attempt' }));
        mockedPost.mockResolvedValueOnce({
            status: 201,
            data: {
                data: {
                    event: event({ amount_minor: '2000' }),
                    goal: goal({ allocated_amount_minor: '27000' }),
                },
                meta: { was_recently_created: true, idempotent_replay: false },
            },
        });
        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [goal({ allocated_amount_minor: '27000' })],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/10') {
                return { data: { data: goal({ allocated_amount_minor: '27000' }) } };
            }
            if (url.endsWith('/events')) {
                return { data: { data: [event()], meta: paginationMeta({ total: 1, from: 1, to: 1 }) } };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        fireEvent.click(within(dialog).getByRole('button', { name: 'Allocate to goal' }));
        await waitFor(() => expect(mockedPost).toHaveBeenCalledTimes(2));
        expect(postedCreateBody(mockedPost, 1).idempotency_key).not.toBe(conflictKey);
    });

    it('maps allocation 422/403 and refreshes goal after withdrawal validation failure', async () => {
        const mockedPost = vi.mocked(axios.post);
        const mockedGet = vi.mocked(axios.get);
        let detailAllocated = '25000';

        render(<SavingsGoalsIndex />);
        await selectEmergencyFund();

        fireEvent.click(screen.getByRole('button', { name: 'Reduce allocation' }));
        const dialog = screen.getByRole('dialog', { name: 'Reduce allocation' });
        fireEvent.change(within(dialog).getByLabelText('Amount'), {
            target: { value: '100.00' },
        });

        mockedPost.mockRejectedValueOnce(
            mockAxiosError(422, {
                errors: {
                    amount_minor: ['Withdrawal exceeds the current net allocated amount.'],
                },
            }),
        );
        detailAllocated = '5000';
        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [goal({ allocated_amount_minor: detailAllocated })],
                        meta: paginationMeta({ total: 1, from: 1, to: 1 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/10') {
                return {
                    data: {
                        data: goal({ allocated_amount_minor: detailAllocated }),
                    },
                };
            }
            if (url.endsWith('/events')) {
                return { data: { data: [event()], meta: paginationMeta({ total: 1, from: 1, to: 1 }) } };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        fireEvent.click(within(dialog).getByRole('button', { name: 'Reduce allocation' }));

        await waitFor(() => {
            expect(
                screen.getAllByText('Withdrawal exceeds the current net allocated amount.').length,
            ).toBeGreaterThan(0);
        });
        await waitFor(() => {
            expect(mockedGet.mock.calls.some((call) => call[0] === '/savings-goals/10')).toBe(true);
        });

        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        fireEvent.click(screen.getByRole('button', { name: 'Allocate to goal' }));
        const contributeDialog = screen.getByRole('dialog', { name: 'Allocate to goal' });
        fireEvent.change(within(contributeDialog).getByLabelText('Amount'), {
            target: { value: '10.00' },
        });
        mockedPost.mockRejectedValueOnce(mockAxiosError(403, { message: 'Forbidden' }));
        fireEvent.click(within(contributeDialog).getByRole('button', { name: 'Allocate to goal' }));

        await waitFor(() => {
            expect(
                screen.getByText(
                    /do not have permission to change allocations for this savings goal/i,
                ),
            ).toBeInTheDocument();
        });
    });

    it('ignores a stale allocation success after the selected goal changes', async () => {
        const mockedPost = vi.mocked(axios.post);
        const deferred = createDeferred<{
            status: number;
            data: {
                data: { event: SavingsGoalEventRecord; goal: SavingsGoalProgressRecord };
                meta: { was_recently_created: boolean; idempotent_replay: boolean };
            };
        }>();

        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [
                            goal({ id: 10, name: 'Goal A' }),
                            goal({ id: 20, name: 'Goal B', currency_code: 'USD' }),
                        ],
                        meta: paginationMeta({ total: 2, from: 1, to: 2 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/10') {
                return { data: { data: goal({ id: 10, name: 'Goal A' }) } };
            }
            if (url === '/savings-goals/20') {
                return {
                    data: {
                        data: goal({
                            id: 20,
                            name: 'Goal B',
                            currency_code: 'USD',
                            description: 'Keep B',
                        }),
                    },
                };
            }
            if (url.endsWith('/events')) {
                return { data: { data: [], meta: paginationMeta() } };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        render(<SavingsGoalsIndex />);
        await waitFor(() => expect(screen.getByText('Goal A')).toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', { name: /Goal A/i }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Allocate to goal' })).toBeInTheDocument());

        mockedPost.mockReturnValueOnce(deferred.promise);
        fireEvent.click(screen.getByRole('button', { name: 'Allocate to goal' }));
        const dialog = screen.getByRole('dialog', { name: 'Allocate to goal' });
        fireEvent.change(within(dialog).getByLabelText('Amount'), {
            target: { value: '10.00' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Allocate to goal' }));

        await waitFor(() => expect(mockedPost).toHaveBeenCalledTimes(1));

        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        fireEvent.click(screen.getByRole('button', { name: /Goal B/i }));
        await waitFor(() => expect(screen.getByText('Keep B')).toBeInTheDocument());

        deferred.resolve({
            status: 201,
            data: {
                data: {
                    event: event({ savings_goal_id: 10, amount_minor: '1000', note: 'Stale A' }),
                    goal: goal({ id: 10, name: 'Goal A', allocated_amount_minor: '26000' }),
                },
                meta: { was_recently_created: true, idempotent_replay: false },
            },
        });

        await waitFor(() => {
            expect(screen.getByText('Keep B')).toBeInTheDocument();
        });
        expect(screen.queryByText('Stale A')).not.toBeInTheDocument();
        expect(screen.queryByText('Amount allocated to the goal.')).not.toBeInTheDocument();
    });

    it('keeps uncertain allocation pending bound to the original goal id across selection changes', async () => {
        const mockedPost = vi.mocked(axios.post);
        const mockedGet = vi.mocked(axios.get);
        mockedGet.mockImplementation(async (url: string) => {
            if (url === '/savings-goals/portfolio') {
                return { data: { data: portfolio() } };
            }
            if (url === '/savings-goals') {
                return {
                    data: {
                        data: [
                            goal({ id: 10, name: 'Goal A', allocated_amount_minor: '25000' }),
                            goal({
                                id: 20,
                                name: 'Goal B',
                                currency_code: 'USD',
                                allocated_amount_minor: '5000',
                            }),
                        ],
                        meta: paginationMeta({ total: 2, from: 1, to: 2 }),
                        filters: { status: 'active' },
                    },
                };
            }
            if (url === '/savings-goals/10') {
                return {
                    data: {
                        data: goal({ id: 10, name: 'Goal A', allocated_amount_minor: '25000' }),
                    },
                };
            }
            if (url === '/savings-goals/20') {
                return {
                    data: {
                        data: goal({
                            id: 20,
                            name: 'Goal B',
                            currency_code: 'USD',
                            allocated_amount_minor: '5000',
                            description: 'Keep B',
                        }),
                    },
                };
            }
            if (url.endsWith('/events')) {
                return { data: { data: [], meta: paginationMeta() } };
            }
            throw new Error(`Unexpected GET ${url}`);
        });

        render(<SavingsGoalsIndex />);
        await waitFor(() => expect(screen.getByText('Goal A')).toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', { name: /Goal A/i }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Allocate to goal' })).toBeInTheDocument());

        fireEvent.click(screen.getByRole('button', { name: 'Allocate to goal' }));
        let dialog = screen.getByRole('dialog', { name: 'Allocate to goal' });
        fireEvent.change(within(dialog).getByLabelText('Amount'), {
            target: { value: '12.00' },
        });
        mockedPost.mockRejectedValueOnce({ isAxiosError: true, message: 'Network Error' });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Allocate to goal' }));
        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Retry same request' })).toBeInTheDocument();
        });
        const pendingKey = String(postedCreateBody(mockedPost, 0).idempotency_key);

        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        fireEvent.click(screen.getByRole('button', { name: /Goal B/i }));
        await waitFor(() => expect(screen.getByText('Keep B')).toBeInTheDocument());

        fireEvent.click(screen.getByRole('button', { name: 'Allocate to goal' }));
        dialog = screen.getByRole('dialog', { name: 'Allocate to goal' });
        // Fresh attempt for Goal B — must not restore Goal A's pending payload.
        expect(within(dialog).getByLabelText('Amount')).toHaveValue('');
        expect(within(dialog).queryByRole('button', { name: 'Retry same request' })).not.toBeInTheDocument();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));

        fireEvent.click(screen.getByRole('button', { name: /Goal A/i }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Allocate to goal' })).toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', { name: 'Allocate to goal' }));
        dialog = screen.getByRole('dialog', { name: 'Allocate to goal' });
        expect(within(dialog).getByLabelText('Amount')).toHaveValue('12.00');
        expect(within(dialog).getByRole('button', { name: 'Retry same request' })).toBeInTheDocument();

        mockedPost.mockResolvedValueOnce({
            status: 201,
            data: {
                data: {
                    event: event({ savings_goal_id: 10, amount_minor: '1200' }),
                    goal: goal({ id: 10, name: 'Goal A', allocated_amount_minor: '26200' }),
                },
                meta: { was_recently_created: true, idempotent_replay: false },
            },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Retry same request' }));
        await waitFor(() => expect(mockedPost).toHaveBeenCalledTimes(2));
        expect(mockedPost.mock.calls[1][0]).toBe('/savings-goals/10/contributions');
        expect(postedCreateBody(mockedPost, 1).idempotency_key).toBe(pendingKey);
        expect(postedCreateBody(mockedPost, 1)).toMatchObject({ amount_minor: '1200' });
    });
});
