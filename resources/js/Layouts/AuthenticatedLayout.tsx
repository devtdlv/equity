import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent, type ReactNode, useState } from 'react';
import type { AuthenticatedUser, SharedPageProps } from '@/Types';

export interface AuthenticatedLayoutProps {
    title?: string;
    user?: AuthenticatedUser | null;
    children: ReactNode;
}

export function AuthenticatedLayout({ title, user, children }: AuthenticatedLayoutProps) {
    const page = usePage<SharedPageProps>();
    const activeUser = user ?? page.props?.auth?.user ?? null;
    const productName = page.props?.app?.name ?? 'Equity';
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const { post, processing } = useForm({});

    const handleLogout = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        post('/logout');
    };

    return (
        <>
            {title ? <Head title={title} /> : null}
            <div className="flex min-h-screen flex-col justify-between bg-surface text-ink">
                <header className="border-b border-border-subtle bg-surface-elevated">
                    <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4 sm:px-10">
                        <div className="flex items-center gap-8">
                            <Link
                                href="/dashboard"
                                className="flex items-center gap-2.5 rounded-md focus:outline-none focus:ring-2 focus:ring-accent/30"
                            >
                                <span
                                    className="flex h-7 w-7 items-center justify-center rounded-md bg-ink font-mono text-xs font-semibold tracking-tight text-white"
                                    aria-hidden="true"
                                >
                                    EQ
                                </span>
                                <span className="text-sm font-semibold tracking-tight text-ink">
                                    {productName}
                                </span>
                            </Link>

                            <nav
                                aria-label="Application navigation"
                                className="hidden items-center gap-1 sm:flex"
                            >
                                <Link
                                    href="/dashboard"
                                    aria-current="page"
                                    className="rounded-lg bg-accent-subtle px-3 py-1.5 text-sm font-medium text-accent-strong transition-colors"
                                >
                                    Dashboard
                                </Link>
                            </nav>
                        </div>

                        <div className="hidden items-center gap-4 sm:flex">
                            {activeUser ? (
                                <div className="flex items-center gap-2.5 text-sm">
                                    <span
                                        className="flex h-7 w-7 items-center justify-center rounded-full border border-accent-border bg-accent-subtle font-mono text-xs font-semibold text-accent-strong"
                                        aria-hidden="true"
                                    >
                                        {activeUser.name.charAt(0).toUpperCase()}
                                    </span>
                                    <span
                                        className="font-medium text-ink"
                                        data-testid="authenticated-user-name"
                                    >
                                        {activeUser.name}
                                    </span>
                                </div>
                            ) : null}

                            <form onSubmit={handleLogout}>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="inline-flex items-center justify-center rounded-lg border border-border-strong bg-surface-elevated px-3 py-1.5 text-xs font-medium text-ink-secondary transition-colors hover:bg-surface-muted hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent/30 disabled:opacity-60"
                                >
                                    Log out
                                </button>
                            </form>
                        </div>

                        <div className="flex items-center sm:hidden">
                            <button
                                type="button"
                                aria-expanded={mobileMenuOpen}
                                aria-controls="mobile-navigation-menu"
                                aria-label="Toggle navigation menu"
                                onClick={() => setMobileMenuOpen((open) => !open)}
                                className="inline-flex items-center justify-center rounded-lg border border-border-subtle p-2 text-ink-secondary hover:bg-surface-muted hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent/30"
                            >
                                <span className="font-mono text-xs font-medium">
                                    {mobileMenuOpen ? 'Close' : 'Menu'}
                                </span>
                            </button>
                        </div>
                    </div>

                    {mobileMenuOpen ? (
                        <div
                            id="mobile-navigation-menu"
                            className="border-t border-border-subtle bg-surface-elevated px-6 py-4 sm:hidden"
                        >
                            <nav aria-label="Mobile navigation" className="flex flex-col space-y-3">
                                <Link
                                    href="/dashboard"
                                    aria-current="page"
                                    className="rounded-lg bg-accent-subtle px-3 py-2 text-sm font-medium text-accent-strong"
                                >
                                    Dashboard
                                </Link>

                                {activeUser ? (
                                    <div className="border-t border-border-subtle pt-3 text-sm">
                                        <p className="font-medium text-ink">{activeUser.name}</p>
                                        <p className="text-xs text-ink-muted">{activeUser.email}</p>
                                    </div>
                                ) : null}

                                <form onSubmit={handleLogout} className="pt-1">
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="w-full rounded-lg border border-border-strong bg-surface-elevated px-3 py-2 text-left text-xs font-medium text-ink-secondary hover:bg-surface-muted hover:text-ink disabled:opacity-60"
                                    >
                                        Log out
                                    </button>
                                </form>
                            </nav>
                        </div>
                    ) : null}
                </header>

                <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-10 sm:px-10 sm:py-14">
                    {children}
                </main>

                <footer className="border-t border-border-subtle bg-surface-elevated/60">
                    <div className="mx-auto flex max-w-6xl flex-col gap-2 px-6 py-4 text-xs text-ink-muted sm:flex-row sm:items-center sm:justify-between sm:px-10">
                        <span>{productName} personal finance and cash-flow management</span>
                        <span className="font-mono">Authenticated Session</span>
                    </div>
                </footer>
            </div>
        </>
    );
}
