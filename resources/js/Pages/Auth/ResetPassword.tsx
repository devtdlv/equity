import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormField } from '@/Components/FormField';
import { GuestLayout } from '@/Layouts/GuestLayout';
import type { ResetPasswordPageProps } from '@/Types';

export interface ResetPasswordFormData {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
}

export default function ResetPassword({ token, email = '' }: ResetPasswordPageProps) {
    const { data, setData, post, processing, errors, reset } = useForm<ResetPasswordFormData>({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        post('/reset-password', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout
            title="Choose a new password"
            heading="Choose a new password"
            subheading="Enter and confirm your new password below."
        >
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
                    label="New password"
                    autoComplete="new-password"
                    required
                    hint="Use at least 12 characters with uppercase, lowercase, numbers, and symbols."
                    value={data.password}
                    onChange={(event) => setData('password', event.target.value)}
                    error={errors.password}
                />

                <FormField
                    id="password_confirmation"
                    name="password_confirmation"
                    type="password"
                    label="Confirm new password"
                    autoComplete="new-password"
                    required
                    value={data.password_confirmation}
                    onChange={(event) => setData('password_confirmation', event.target.value)}
                    error={errors.password_confirmation}
                />

                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex w-full items-center justify-center rounded-lg bg-accent px-4 py-2.5 text-sm font-medium text-white shadow-2xs transition-colors hover:bg-accent-strong focus:outline-none focus:ring-2 focus:ring-accent/30 disabled:opacity-60"
                >
                    {processing ? 'Updating password...' : 'Reset password'}
                </button>
            </form>
        </GuestLayout>
    );
}
