import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { StatusBadge } from '@/Components/StatusBadge';

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
    return (
        <>
            {title ? <Head title={title} /> : null}
            <div className="flex min-h-screen flex-col justify-between bg-surface text-ink">
                <header className="border-b border-border-subtle bg-surface-elevated/80 backdrop-blur-xs">
                    <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4 sm:px-10">
                        <div className="flex items-center gap-2.5">
                            <span
                                className="flex h-7 w-7 items-center justify-center rounded-md bg-ink font-mono text-xs font-semibold tracking-tight text-white"
                                aria-hidden="true"
                            >
                                EQ
                            </span>
                            <span className="text-sm font-semibold tracking-tight text-ink">
                                {productName}
                            </span>
                        </div>
                        <StatusBadge label={status} />
                    </div>
                </header>

                <main className="mx-auto flex w-full max-w-5xl flex-1 items-center px-6 py-16 sm:px-10 sm:py-24">
                    {children}
                </main>

                <footer className="border-t border-border-subtle bg-surface-elevated/60">
                    <div className="mx-auto flex max-w-5xl flex-col gap-2 px-6 py-5 text-xs text-ink-muted sm:flex-row sm:items-center sm:justify-between sm:px-10">
                        <span>{productName} personal finance and cash-flow management</span>
                        <span className="font-mono">Phase 1: {status}</span>
                    </div>
                </footer>
            </div>
        </>
    );
}
