import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent, type ReactNode, useEffect, useState } from 'react';
import { Badge } from '@/Components/Badge';
import { Button } from '@/Components/Button';
import { Separator } from '@/Components/Separator';
import { ThemeToggle } from '@/Components/ThemeToggle';
import type { AuthenticatedUser, SharedPageProps } from '@/Types';

export interface AuthenticatedLayoutProps {
    title?: string;
    user?: AuthenticatedUser | null;
    children: ReactNode;
}

const NAVIGATION_ITEMS: ReadonlyArray<{
    label: string;
    href: string;
    matchPrefix: string;
}> = [
    { label: 'Dashboard', href: '/dashboard', matchPrefix: '/dashboard' },
    { label: 'Accounts', href: '/accounts', matchPrefix: '/accounts' },
    { label: 'Transactions', href: '/transactions', matchPrefix: '/transactions' },
    { label: 'Budgets', href: '/budgets', matchPrefix: '/budgets' },
    { label: 'Bills & Subscriptions', href: '/bills', matchPrefix: '/bills' },
];

const UPCOMING_MODULES: ReadonlyArray<string> = ['Ledger & Reports'];

export function AuthenticatedLayout({ title, user, children }: AuthenticatedLayoutProps) {
    const page = usePage<SharedPageProps>();
    const activeUser = user ?? page.props?.auth?.user ?? null;
    const productName = page.props?.app?.name ?? 'Equity';
    const currentUrl = typeof page.url === 'string' ? page.url : '/dashboard';
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const { post, processing } = useForm({});

    useEffect(() => {
        setMobileMenuOpen(false);
    }, [currentUrl]);

    useEffect(() => {
        if (!mobileMenuOpen) {
            return;
        }

        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setMobileMenuOpen(false);
            }
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [mobileMenuOpen]);

    const handleLogout = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setMobileMenuOpen(false);
        post('/logout');
    };

    const isRouteActive = (matchPrefix: string): boolean => {
        return (
            currentUrl === matchPrefix ||
            currentUrl.startsWith(`${matchPrefix}?`) ||
            currentUrl.startsWith(`${matchPrefix}/`)
        );
    };

    const userInitial = activeUser?.name ? activeUser.name.charAt(0).toUpperCase() : 'U';

    return (
        <>
            {title ? <Head title={title} /> : null}
            <div className="flex min-h-screen flex-col bg-surface text-ink lg:flex-row">
                {/* Desktop Fixed-Width Sidebar */}
                <aside
                    aria-label="Workspace sidebar"
                    className="hidden lg:flex lg:w-72 lg:shrink-0 lg:flex-col lg:justify-between lg:border-r lg:border-border-subtle lg:bg-surface-elevated"
                >
                    <div className="flex flex-col gap-6 p-6">
                        <div className="flex items-center justify-between">
                            <Link
                                href="/dashboard"
                                className="flex items-center gap-2.5 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring/40"
                            >
                                <span
                                    className="flex h-8 w-8 items-center justify-center rounded-lg bg-ink font-mono text-xs font-semibold tracking-tight text-ink-inverse"
                                    aria-hidden="true"
                                >
                                    EQ
                                </span>
                                <span className="text-base font-semibold tracking-tight text-ink">
                                    {productName}
                                </span>
                            </Link>
                            <Badge variant="neutral">Workspace</Badge>
                        </div>

                        <nav aria-label="Application navigation" className="space-y-1">
                            <p className="px-3 pb-1.5 font-mono text-[11px] font-medium tracking-wider text-ink-muted uppercase">
                                Workspace
                            </p>
                            {NAVIGATION_ITEMS.map((item) => {
                                const active = isRouteActive(item.matchPrefix);

                                return (
                                    <Link
                                        key={item.href}
                                        href={item.href}
                                        aria-current={active ? 'page' : undefined}
                                        className={`flex items-center justify-between rounded-xl border px-3.5 py-2.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring/40 ${
                                            active
                                                ? 'border-accent-border bg-accent-subtle text-accent-strong dark:text-accent'
                                                : 'border-transparent text-ink-secondary hover:bg-surface-muted hover:text-ink'
                                        }`}
                                    >
                                        <span>{item.label}</span>
                                        {active ? (
                                            <span
                                                className="h-1.5 w-1.5 rounded-full bg-accent"
                                                aria-hidden="true"
                                            />
                                        ) : null}
                                    </Link>
                                );
                            })}
                        </nav>

                        <Separator />

                        <div aria-label="Upcoming financial modules" className="space-y-2">
                            <div className="flex items-center justify-between px-3">
                                <span className="font-mono text-[11px] font-medium tracking-wider text-ink-muted uppercase">
                                    Planned Modules
                                </span>
                                <span className="font-mono text-[11px] text-ink-muted">
                                    Upcoming
                                </span>
                            </div>
                            <ul className="space-y-1 px-3 text-xs text-ink-muted">
                                {UPCOMING_MODULES.map((moduleName) => (
                                    <li
                                        key={moduleName}
                                        className="flex items-center justify-between py-1.5"
                                    >
                                        <span>{moduleName}</span>
                                        <span className="rounded bg-surface-muted px-1.5 py-0.5 font-mono text-[10px] text-ink-muted">
                                            Planned
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>

                    <div className="space-y-4 border-t border-border-subtle bg-surface-muted/40 p-6">
                        <div className="space-y-2">
                            <span className="block font-mono text-[11px] font-medium tracking-wider text-ink-muted uppercase">
                                Appearance
                            </span>
                            <ThemeToggle className="w-full justify-between" />
                        </div>

                        {activeUser ? (
                            <div className="flex items-center gap-3 rounded-xl border border-border-subtle bg-surface-elevated p-3">
                                <span
                                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-accent-border bg-accent-subtle font-mono text-xs font-semibold text-accent-strong dark:text-accent"
                                    aria-hidden="true"
                                >
                                    {userInitial}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p
                                        className="truncate text-sm font-medium text-ink"
                                        data-testid="authenticated-user-name"
                                    >
                                        {activeUser.name}
                                    </p>
                                    <p className="truncate text-xs text-ink-muted">
                                        {activeUser.email}
                                    </p>
                                </div>
                            </div>
                        ) : null}

                        <form onSubmit={handleLogout}>
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                fullWidth
                                loading={processing}
                            >
                                Log out
                            </Button>
                        </form>
                    </div>
                </aside>

                {/* Mobile & Tablet Header */}
                <div className="flex min-w-0 flex-1 flex-col justify-between">
                    <header className="border-b border-border-subtle bg-surface-elevated lg:hidden">
                        <div className="flex items-center justify-between px-5 py-3.5 sm:px-8">
                            <Link
                                href="/dashboard"
                                className="flex items-center gap-2.5 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring/40"
                            >
                                <span
                                    className="flex h-8 w-8 items-center justify-center rounded-lg bg-ink font-mono text-xs font-semibold tracking-tight text-ink-inverse"
                                    aria-hidden="true"
                                >
                                    EQ
                                </span>
                                <span className="text-base font-semibold tracking-tight text-ink">
                                    {productName}
                                </span>
                            </Link>

                            <button
                                type="button"
                                aria-expanded={mobileMenuOpen}
                                aria-controls="mobile-navigation-menu"
                                aria-label="Toggle navigation menu"
                                onClick={() => setMobileMenuOpen((open) => !open)}
                                className="inline-flex min-h-9 items-center justify-center rounded-lg border border-border-strong bg-surface-elevated px-3 py-1.5 text-ink-secondary transition-colors hover:bg-surface-muted hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring/40"
                            >
                                <span className="font-mono text-xs font-medium">
                                    {mobileMenuOpen ? 'Close' : 'Menu'}
                                </span>
                            </button>
                        </div>

                        {mobileMenuOpen ? (
                            <div
                                id="mobile-navigation-menu"
                                className="border-t border-border-subtle bg-surface-elevated px-5 py-5 sm:px-8"
                            >
                                <nav
                                    aria-label="Mobile navigation"
                                    className="flex flex-col space-y-4"
                                >
                                    <div className="space-y-1.5">
                                        {NAVIGATION_ITEMS.map((item) => {
                                            const active = isRouteActive(item.matchPrefix);

                                            return (
                                                <Link
                                                    key={item.href}
                                                    href={item.href}
                                                    aria-current={active ? 'page' : undefined}
                                                    onClick={() => setMobileMenuOpen(false)}
                                                    className={`flex items-center justify-between rounded-xl border px-3.5 py-2.5 text-sm font-medium ${
                                                        active
                                                            ? 'border-accent-border bg-accent-subtle text-accent-strong dark:text-accent'
                                                            : 'border-transparent text-ink-secondary hover:bg-surface-muted hover:text-ink'
                                                    }`}
                                                >
                                                    <span>{item.label}</span>
                                                    {active ? (
                                                        <span
                                                            className="h-1.5 w-1.5 rounded-full bg-accent"
                                                            aria-hidden="true"
                                                        />
                                                    ) : null}
                                                </Link>
                                            );
                                        })}
                                    </div>

                                    <div className="space-y-2 border-t border-border-subtle pt-4">
                                        <span className="block font-mono text-[11px] font-medium tracking-wider text-ink-muted uppercase">
                                            Appearance
                                        </span>
                                        <ThemeToggle />
                                    </div>

                                    {activeUser ? (
                                        <div className="border-t border-border-subtle pt-4 text-sm">
                                            <p className="font-medium text-ink">
                                                {activeUser.name}
                                            </p>
                                            <p className="text-xs text-ink-muted">
                                                {activeUser.email}
                                            </p>
                                        </div>
                                    ) : null}

                                    <form onSubmit={handleLogout} className="pt-1">
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            size="sm"
                                            fullWidth
                                            loading={processing}
                                        >
                                            Log out
                                        </Button>
                                    </form>
                                </nav>
                            </div>
                        ) : null}
                    </header>

                    <main className="mx-auto w-full max-w-5xl flex-1 px-5 py-8 sm:px-8 sm:py-10 lg:px-12 lg:py-12">
                        {children}
                    </main>

                    <footer className="border-t border-border-subtle bg-surface-elevated/60">
                        <div className="mx-auto flex max-w-5xl flex-col gap-2 px-5 py-4 text-xs text-ink-muted sm:flex-row sm:items-center sm:justify-between sm:px-8 lg:px-12">
                            <span>{productName} personal finance and cash-flow management</span>
                            <span className="font-mono">Authenticated Workspace</span>
                        </div>
                    </footer>
                </div>
            </div>
        </>
    );
}
