import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { StatusBadge } from '@/Components/StatusBadge';
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
                <header className="border-b border-border-subtle bg-surface-elevated/80 backdrop-blur-xs">
                    <div className="mx-auto flex max-w-5xl items-center justify-between gap-4 px-6 py-4 sm:px-10">
                        <div className="flex items-center gap-3">
                            <Link
                                href="/"
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
                            <div className="hidden sm:block">
                                <StatusBadge label={status} />
                            </div>
                        </div>

                        <nav aria-label="Primary navigation" className="flex items-center gap-3">
                            {user ? (
                                <Link
                                    href="/dashboard"
                                    className="inline-flex items-center justify-center rounded-lg bg-ink px-3.5 py-2 text-xs font-medium text-white transition-colors hover:bg-ink-secondary focus:outline-none focus:ring-2 focus:ring-accent/30"
                                >
                                    Dashboard
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href="/login"
                                        className="inline-flex items-center justify-center rounded-lg px-3 py-2 text-xs font-medium text-ink-secondary transition-colors hover:bg-surface-muted hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent/30"
                                    >
                                        Sign in
                                    </Link>
                                    <Link
                                        href="/register"
                                        className="inline-flex items-center justify-center rounded-lg bg-accent px-3.5 py-2 text-xs font-medium text-white shadow-2xs transition-colors hover:bg-accent-strong focus:outline-none focus:ring-2 focus:ring-accent/30"
                                    >
                                        Create account
                                    </Link>
                                </>
                            )}
                        </nav>
                    </div>
                    <div className="border-t border-border-subtle px-6 py-2 sm:hidden">
                        <StatusBadge label={status} />
                    </div>
                </header>

                <main className="mx-auto flex w-full max-w-5xl flex-1 items-center px-6 py-16 sm:px-10 sm:py-24">
                    {children}
                </main>

                <footer className="border-t border-border-subtle bg-surface-elevated/60">
                    <div className="mx-auto flex max-w-5xl flex-col gap-2 px-6 py-5 text-xs text-ink-muted sm:flex-row sm:items-center sm:justify-between sm:px-10">
                        <span>{productName} personal finance and cash-flow management</span>
                        <span className="font-mono">Phase 2: Secure Session Authentication</span>
                    </div>
                </footer>
            </div>
        </>
    );
}
