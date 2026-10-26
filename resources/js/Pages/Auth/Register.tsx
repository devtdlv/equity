import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormField } from '@/Components/FormField';
import { GuestLayout } from '@/Layouts/GuestLayout';

export interface RegisterFormData {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
}

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm<RegisterFormData>({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        post('/register', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout
            title="Create account"
            heading="Create your Equity account"
            subheading="Set up your personal finance workspace."
        >
            <form onSubmit={handleSubmit} className="space-y-5" noValidate>
                <FormField
                    id="name"
                    name="name"
                    type="text"
                    label="Full name"
                    autoComplete="name"
                    required
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    error={errors.name}
                />

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
                    label="Confirm password"
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
                    {processing ? 'Creating account...' : 'Create account'}
                </button>

                <p className="border-t border-border-subtle pt-4 text-center text-xs text-ink-muted">
                    Already have an account?{' '}
                    <Link
                        href="/login"
                        className="font-medium text-accent-strong hover:text-accent focus:outline-none focus:underline"
                    >
                        Sign in
                    </Link>
                </p>
            </form>
        </GuestLayout>
    );
}
