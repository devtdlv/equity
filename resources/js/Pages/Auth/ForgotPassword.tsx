import { Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormField } from '@/Components/FormField';
import { GuestLayout } from '@/Layouts/GuestLayout';
import type { SharedPageProps } from '@/Types';

export interface ForgotPasswordProps {
    status?: string | null;
}

export interface ForgotPasswordFormData {
    email: string;
}

export default function ForgotPassword({ status }: ForgotPasswordProps) {
    const page = usePage<SharedPageProps>();
    const statusMessage = status ?? page.props?.flash?.status ?? null;

    const { data, setData, post, processing, errors } = useForm<ForgotPasswordFormData>({
        email: '',
    });

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        post('/forgot-password');
    };

    return (
        <GuestLayout
            title="Reset password"
            heading="Reset your password"
            subheading="Enter the email address associated with your account and we will send a password reset link."
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

                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex w-full items-center justify-center rounded-lg bg-accent px-4 py-2.5 text-sm font-medium text-white shadow-2xs transition-colors hover:bg-accent-strong focus:outline-none focus:ring-2 focus:ring-accent/30 disabled:opacity-60"
                >
                    {processing ? 'Sending reset link...' : 'Send password reset link'}
                </button>

                <p className="border-t border-border-subtle pt-4 text-center text-xs text-ink-muted">
                    Remembered your password?{' '}
                    <Link
                        href="/login"
                        className="font-medium text-accent-strong hover:text-accent focus:outline-none focus:underline"
                    >
                        Return to sign in
                    </Link>
                </p>
            </form>
        </GuestLayout>
    );
}
