import { Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Alert } from '@/Components/Alert';
import { Button } from '@/Components/Button';
import { Checkbox } from '@/Components/Checkbox';
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
                    <Checkbox
                        id="remember"
                        name="remember"
                        label="Remember me"
                        checked={data.remember}
                        onChange={(event) => setData('remember', event.target.checked)}
                    />

                    <Link
                        href="/forgot-password"
                        className="text-xs font-medium text-accent-strong hover:text-accent focus-visible:outline-none focus-visible:underline dark:text-accent"
                    >
                        Forgot password?
                    </Link>
                </div>

                <Button type="submit" fullWidth loading={processing}>
                    {processing ? 'Signing in...' : 'Sign in'}
                </Button>

                <p className="border-t border-border-subtle pt-4 text-center text-xs text-ink-muted">
                    New to Equity?{' '}
                    <Link
                        href="/register"
                        className="font-medium text-accent-strong hover:text-accent focus-visible:outline-none focus-visible:underline dark:text-accent"
                    >
                        Create an account
                    </Link>
                </p>
            </form>
        </GuestLayout>
    );
}
