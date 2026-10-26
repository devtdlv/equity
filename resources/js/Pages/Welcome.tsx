import { Card } from '@/Components/Card';
import { Separator } from '@/Components/Separator';
import { PublicLayout } from '@/Layouts/PublicLayout';
import type { WelcomePageProps } from '@/Types';

const DEFAULT_PRODUCT_NAME = 'Equity';
const DEFAULT_TAGLINE = 'Personal finance, clearly understood.';
const DEFAULT_DESCRIPTION =
    'Track your accounts, understand your spending, and plan your financial future in one place.';
const DEFAULT_STATUS = 'Application foundation';

export default function Welcome({
    productName = DEFAULT_PRODUCT_NAME,
    tagline = DEFAULT_TAGLINE,
    description = DEFAULT_DESCRIPTION,
    status = DEFAULT_STATUS,
}: WelcomePageProps) {
    return (
        <PublicLayout title={productName} productName={productName} status={status}>
            <Card as="section" padding="lg" aria-labelledby="welcome-heading" className="w-full">
                <div className="max-w-2xl space-y-6">
                    <div className="inline-flex items-center gap-2 font-mono text-xs font-medium tracking-wider text-accent uppercase">
                        <span className="h-px w-6 bg-accent" aria-hidden="true" />
                        <span>Personal Finance Platform</span>
                    </div>

                    <div className="space-y-3">
                        <h1
                            id="welcome-heading"
                            className="text-4xl font-semibold tracking-tight text-ink sm:text-5xl"
                        >
                            {productName}
                        </h1>
                        <p className="text-xl font-medium tracking-tight text-ink-secondary sm:text-2xl">
                            {tagline}
                        </p>
                    </div>

                    <p className="text-base leading-relaxed text-ink-muted sm:text-lg">
                        {description}
                    </p>

                    <div className="pt-2">
                        <Separator className="mb-6" />
                        <dl className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div className="rounded-xl border border-border-subtle bg-surface-muted/60 px-4 py-3.5">
                                <dt className="font-mono text-xs text-ink-muted">Focus</dt>
                                <dd className="mt-1 text-sm font-medium text-ink">
                                    Accounts &amp; Cash Flow
                                </dd>
                            </div>
                            <div className="rounded-xl border border-border-subtle bg-surface-muted/60 px-4 py-3.5">
                                <dt className="font-mono text-xs text-ink-muted">Precision</dt>
                                <dd className="mt-1 text-sm font-medium text-ink">
                                    Exact Monetary Accounting
                                </dd>
                            </div>
                            <div className="rounded-xl border border-border-subtle bg-surface-muted/60 px-4 py-3.5">
                                <dt className="font-mono text-xs text-ink-muted">Stage</dt>
                                <dd className="mt-1 text-sm font-medium text-accent-strong dark:text-accent">
                                    {status}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </Card>
        </PublicLayout>
    );
}
