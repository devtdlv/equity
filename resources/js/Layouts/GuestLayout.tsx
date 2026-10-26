import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

export interface GuestLayoutProps {
    title: string;
    heading: string;
    subheading: string;
    children: ReactNode;
}

export function GuestLayout({ title, heading, subheading, children }: GuestLayoutProps) {
    return (
        <>
            <Head title={title} />
            <div className="flex min-h-screen flex-col justify-between bg-surface text-ink">
                <header className="border-b border-border-subtle bg-surface-elevated/80 backdrop-blur-xs">
                    <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4 sm:px-10">
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
                                Equity
                            </span>
                        </Link>
                        <span className="font-mono text-xs text-ink-muted">Secure Session</span>
                    </div>
                </header>

                <main className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center px-6 py-12 sm:px-0">
                    <div className="rounded-2xl border border-border-subtle bg-surface-elevated p-6 shadow-xs sm:p-8">
                        <div className="mb-6 space-y-1.5">
                            <h1 className="text-2xl font-semibold tracking-tight text-ink">
                                {heading}
                            </h1>
                            <p className="text-sm text-ink-muted">{subheading}</p>
                        </div>

                        {children}
                    </div>
                </main>

                <footer className="border-t border-border-subtle bg-surface-elevated/60">
                    <div className="mx-auto flex max-w-5xl flex-col gap-2 px-6 py-4 text-xs text-ink-muted sm:flex-row sm:items-center sm:justify-between sm:px-10">
                        <span>Equity personal finance and cash-flow management</span>
                        <span className="font-mono">Personal finance, clearly understood.</span>
                    </div>
                </footer>
            </div>
        </>
    );
}
