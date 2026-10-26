import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Card } from '@/Components/Card';
import { ThemeToggle } from '@/Components/ThemeToggle';

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
                <header className="border-b border-border-subtle bg-surface-elevated/90 backdrop-blur-xs">
                    <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-5 py-4 sm:px-10">
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
                                Equity
                            </span>
                        </Link>

                        <div className="flex items-center gap-3">
                            <span className="hidden font-mono text-xs text-ink-muted sm:inline">
                                Secure Session
                            </span>
                            <ThemeToggle />
                        </div>
                    </div>
                </header>

                <main className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center px-5 py-12 sm:px-0">
                    <Card padding="md">
                        <div className="mb-6 space-y-1.5">
                            <h1 className="text-2xl font-semibold tracking-tight text-ink">
                                {heading}
                            </h1>
                            <p className="text-sm leading-relaxed text-ink-muted">{subheading}</p>
                        </div>

                        {children}
                    </Card>
                </main>

                <footer className="border-t border-border-subtle bg-surface-elevated/70">
                    <div className="mx-auto flex max-w-6xl flex-col gap-2 px-5 py-4 text-xs text-ink-muted sm:flex-row sm:items-center sm:justify-between sm:px-10">
                        <span>Equity personal finance and cash-flow management</span>
                        <span className="font-mono">Personal finance, clearly understood.</span>
                    </div>
                </footer>
            </div>
        </>
    );
}
