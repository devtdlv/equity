import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { StatusBadge } from '@/Components/StatusBadge';
import { ThemeToggle } from '@/Components/ThemeToggle';
import type { SharedPageProps } from '@/Types';

export interface PublicLayoutProps {
    title?: string;
    productName?: string;
    status?: string;
    children: ReactNode;
}

export function PublicLayout({
    title,
    productName = 'Equity',
    status = 'Application foundation',
    children,
}: PublicLayoutProps) {
    const page = usePage<SharedPageProps>();
    const user = page.props?.auth?.user ?? null;

    return (
        <>
            {title ? <Head title={title} /> : null}
            <div className="flex min-h-screen flex-col justify-between bg-surface text-ink">
                <header className="border-b border-border-subtle bg-surface-elevated/90 backdrop-blur-xs">
                    <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-5 py-4 sm:px-10">
                        <div className="flex items-center gap-3">
                            <Link
                                href="/"
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
                            <div className="hidden md:block">
                                <StatusBadge label={status} />
                            </div>
                        </div>

                        <div className="flex items-center gap-3">
                            <div className="hidden sm:block">
                                <ThemeToggle />
                            </div>

                            <nav
                                aria-label="Primary navigation"
                                className="flex items-center gap-2.5"
                            >
                                {user ? (
                                    <Link
                                        href="/dashboard"
                                        className="inline-flex min-h-9 items-center justify-center rounded-lg bg-ink px-3.5 py-2 text-xs font-medium text-ink-inverse transition-colors hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring/40"
                                    >
                                        Dashboard
                                    </Link>
                                ) : (
                                    <>
                                        <Link
                                            href="/login"
                                            className="inline-flex min-h-9 items-center justify-center rounded-lg px-3 py-2 text-xs font-medium text-ink-secondary transition-colors hover:bg-surface-muted hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring/40"
                                        >
                                            Sign in
                                        </Link>
                                        <Link
                                            href="/register"
                                            className="inline-flex min-h-9 items-center justify-center rounded-lg bg-accent px-3.5 py-2 text-xs font-medium text-accent-foreground shadow-2xs transition-colors hover:bg-accent-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring/40"
                                        >
                                            Create account
                                        </Link>
                                    </>
                                )}
                            </nav>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border-subtle px-5 py-2.5 md:hidden">
                        <StatusBadge label={status} />
                        <div className="sm:hidden">
                            <ThemeToggle />
                        </div>
                    </div>
                </header>

                <main className="mx-auto flex w-full max-w-6xl flex-1 items-center px-5 py-12 sm:px-10 sm:py-20">
                    {children}
                </main>

                <footer className="border-t border-border-subtle bg-surface-elevated/70">
                    <div className="mx-auto flex max-w-6xl flex-col gap-2 px-5 py-5 text-xs text-ink-muted sm:flex-row sm:items-center sm:justify-between sm:px-10">
                        <span>{productName} personal finance and cash-flow management</span>
                        <span className="font-mono">Phase 3: Design System &amp; Shell</span>
                    </div>
                </footer>
            </div>
        </>
    );
}
