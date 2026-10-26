import { Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Alert } from '@/Components/Alert';
import { Button } from '@/Components/Button';
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
                <Alert variant="info" className="mb-5">
                    {statusMessage}
                </Alert>
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

                <Button type="submit" fullWidth loading={processing}>
                    {processing ? 'Sending reset link...' : 'Send password reset link'}
                </Button>

                <p className="border-t border-border-subtle pt-4 text-center text-xs text-ink-muted">
                    Remembered your password?{' '}
                    <Link
                        href="/login"
                        className="font-medium text-accent-strong hover:text-accent focus-visible:outline-none focus-visible:underline dark:text-accent"
                    >
                        Return to sign in
                    </Link>
                </p>
            </form>
        </GuestLayout>
    );
}
