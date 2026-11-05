import { useForm, usePage } from '@inertiajs/react';
import { type FormEvent, useEffect, useState } from 'react';
import { Alert } from '@/Components/Alert';
import { Button } from '@/Components/Button';
import { Card } from '@/Components/Card';
import { FormField } from '@/Components/FormField';
import { PageHeader } from '@/Components/PageHeader';
import { AuthenticatedLayout } from '@/Layouts/AuthenticatedLayout';
import type { SharedPageProps } from '@/Types';

export interface SettingsProfileProps {
    name: string;
    email: string;
}

export interface SettingsIndexPageProps {
    profile: SettingsProfileProps;
    emailVerificationNote: string;
    status?: string | null;
}

interface ProfileFormData {
    name: string;
    email: string;
    current_password: string;
}

interface PasswordFormData {
    current_password: string;
    password: string;
    password_confirmation: string;
}

export default function SettingsIndex({
    profile,
    emailVerificationNote,
    status: pageStatus = null,
}: SettingsIndexPageProps) {
    const page = usePage<SharedPageProps>();
    const flashStatus = pageStatus ?? page.props?.flash?.status ?? null;
    const [emailChanging, setEmailChanging] = useState(false);

    const profileForm = useForm<ProfileFormData>({
        name: profile.name,
        email: profile.email,
        current_password: '',
    });

    const passwordForm = useForm<PasswordFormData>({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    useEffect(() => {
        const nextEmail = profileForm.data.email.trim().toLowerCase();
        const originalEmail = profile.email.trim().toLowerCase();
        setEmailChanging(nextEmail !== '' && nextEmail !== originalEmail);
    }, [profileForm.data.email, profile.email]);

    const handleProfileSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        profileForm.patch('/settings/profile', {
            preserveScroll: true,
            onSuccess: () => profileForm.reset('current_password'),
        });
    };

    const handlePasswordSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        passwordForm.put('/settings/password', {
            preserveScroll: true,
            onFinish: () =>
                passwordForm.reset('current_password', 'password', 'password_confirmation'),
        });
    };

    return (
        <AuthenticatedLayout title="Settings">
            <div className="space-y-8">
                <PageHeader
                    eyebrow="Account"
                    title="Settings"
                    description="Update your profile details and password. Sensitive changes require your current password."
                />

                {flashStatus ? (
                    <Alert variant="positive" title="Saved">
                        {flashStatus}
                    </Alert>
                ) : null}

                <Card as="section" aria-labelledby="settings-profile-heading" className="space-y-6">
                    <div className="space-y-1.5">
                        <h2
                            id="settings-profile-heading"
                            className="text-lg font-semibold tracking-tight text-ink"
                        >
                            Profile
                        </h2>
                        <p className="text-sm leading-relaxed text-ink-muted">
                            Your name appears in the workspace. Your email is used to sign in.
                        </p>
                    </div>

                    <Alert variant="info" title="Email verification">
                        {emailVerificationNote}
                    </Alert>

                    <form onSubmit={handleProfileSubmit} className="space-y-5" noValidate>
                        <FormField
                            id="profile-name"
                            name="name"
                            type="text"
                            label="Full name"
                            autoComplete="name"
                            required
                            value={profileForm.data.name}
                            onChange={(event) => profileForm.setData('name', event.target.value)}
                            error={profileForm.errors.name}
                        />

                        <FormField
                            id="profile-email"
                            name="email"
                            type="email"
                            label="Email address"
                            autoComplete="email"
                            required
                            value={profileForm.data.email}
                            onChange={(event) => profileForm.setData('email', event.target.value)}
                            error={profileForm.errors.email}
                        />

                        {emailChanging ? (
                            <FormField
                                id="profile-current-password"
                                name="current_password"
                                type="password"
                                label="Current password"
                                autoComplete="current-password"
                                required
                                hint="Required only when changing your email address."
                                value={profileForm.data.current_password}
                                onChange={(event) =>
                                    profileForm.setData('current_password', event.target.value)
                                }
                                error={profileForm.errors.current_password}
                            />
                        ) : null}

                        <div className="flex justify-end">
                            <Button type="submit" loading={profileForm.processing}>
                                {profileForm.processing ? 'Saving profile...' : 'Save profile'}
                            </Button>
                        </div>
                    </form>
                </Card>

                <Card as="section" aria-labelledby="settings-password-heading" className="space-y-6">
                    <div className="space-y-1.5">
                        <h2
                            id="settings-password-heading"
                            className="text-lg font-semibold tracking-tight text-ink"
                        >
                            Password
                        </h2>
                        <p className="text-sm leading-relaxed text-ink-muted">
                            Choose a strong password. Other signed-in sessions will be signed out
                            when you save a new password.
                        </p>
                    </div>

                    <form onSubmit={handlePasswordSubmit} className="space-y-5" noValidate>
                        <FormField
                            id="password-current"
                            name="current_password"
                            type="password"
                            label="Current password"
                            autoComplete="current-password"
                            required
                            value={passwordForm.data.current_password}
                            onChange={(event) =>
                                passwordForm.setData('current_password', event.target.value)
                            }
                            error={passwordForm.errors.current_password}
                        />

                        <FormField
                            id="password-new"
                            name="password"
                            type="password"
                            label="New password"
                            autoComplete="new-password"
                            required
                            hint="Use at least 12 characters with uppercase, lowercase, numbers, and symbols."
                            value={passwordForm.data.password}
                            onChange={(event) =>
                                passwordForm.setData('password', event.target.value)
                            }
                            error={passwordForm.errors.password}
                        />

                        <FormField
                            id="password-confirmation"
                            name="password_confirmation"
                            type="password"
                            label="Confirm new password"
                            autoComplete="new-password"
                            required
                            value={passwordForm.data.password_confirmation}
                            onChange={(event) =>
                                passwordForm.setData('password_confirmation', event.target.value)
                            }
                            error={passwordForm.errors.password_confirmation}
                        />

                        <div className="flex justify-end">
                            <Button type="submit" loading={passwordForm.processing}>
                                {passwordForm.processing ? 'Updating password...' : 'Update password'}
                            </Button>
                        </div>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
