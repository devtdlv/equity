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
        vi.clearAllMocks();
        stubDefaultReads();
    });

    afterEach(() => {
        vi.clearAllMocks();
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
});
