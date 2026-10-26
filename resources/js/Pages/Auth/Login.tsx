import { Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormField } from '@/Components/FormField';
import { GuestLayout } from '@/Layouts/GuestLayout';
import type { SharedPageProps } from '@/Types';

export interface LoginPageProps {
    status?: string | null;
}

export interface LoginFormData {
    email: string;
    password: string;
    remember: boolean;
}

export default function Login({ status }: LoginPageProps) {
    const page = usePage<SharedPageProps>();
    const statusMessage = status ?? page.props?.flash?.status ?? null;

    const { data, setData, post, processing, errors, reset } = useForm<LoginFormData>({
        email: '',
        password: '',
        remember: false,
    });

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        post('/login', {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout
            title="Sign in"
            heading="Sign in to Equity"
            subheading="Access your personal finance workspace."
        >
            {statusMessage ? (
                <div
                    role="status"
                    className="mb-5 rounded-lg border border-accent-border bg-accent-subtle px-3.5 py-2.5 text-xs font-medium text-accent-strong"
                >
                    {statusMessage}
                </div>
            ) : null}

            <form onSubmit={handleSubmit} className="space-y-5" noValidate>
                <FormField
                    id="email"
                    name="email"
                    type="email"
                    label="Email address"
                    autoComplete="email"
                    required
                    value={data.email}
                    onChange={(event) => setData('email', event.target.value)}
                    error={errors.email}
                />

                <FormField
                    id="password"
                    name="password"
                    type="password"
                    label="Password"
                    autoComplete="current-password"
                    required
                    value={data.password}
                    onChange={(event) => setData('password', event.target.value)}
                    error={errors.password}
                />

                <div className="flex items-center justify-between gap-4">
                    <label
                        htmlFor="remember"
                        className="inline-flex items-center gap-2 text-sm text-ink-secondary"
                    >
                        <input
                            id="remember"
                            name="remember"
                            type="checkbox"
                            checked={data.remember}
                            onChange={(event) => setData('remember', event.target.checked)}
                            className="h-4 w-4 rounded border-border-strong text-accent focus:ring-2 focus:ring-accent/30"
                        />
                        <span>Remember me</span>
                    </label>

                    <Link
                        href="/forgot-password"
                        className="text-xs font-medium text-accent-strong hover:text-accent focus:outline-none focus:underline"
                    >
                        Forgot password?
                    </Link>
                </div>

                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex w-full items-center justify-center rounded-lg bg-accent px-4 py-2.5 text-sm font-medium text-white shadow-2xs transition-colors hover:bg-accent-strong focus:outline-none focus:ring-2 focus:ring-accent/30 disabled:opacity-60"
                >
                    {processing ? 'Signing in...' : 'Sign in'}
                </button>

                <p className="border-t border-border-subtle pt-4 text-center text-xs text-ink-muted">
                    New to Equity?{' '}
                    <Link
                        href="/register"
                        className="font-medium text-accent-strong hover:text-accent focus:outline-none focus:underline"
                    >
                        Create an account
                    </Link>
                </p>
            </form>
        </GuestLayout>
    );
}
