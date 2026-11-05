import { fireEvent, render, screen, within } from '@testing-library/react';
import {
    type AnchorHTMLAttributes,
    type ReactNode,
    useState,
} from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SettingsIndex from '@/Pages/Settings/Index';

const mockPatch = vi.fn();
const mockPut = vi.fn();
const mockReset = vi.fn();
let mockErrors: Record<string, string> = {};
let mockFlashStatus: string | null = null;
let mockUrl = '/settings';

vi.mock('@inertiajs/react', () => ({
    Head: ({ title, children }: { title?: string; children?: ReactNode }) => (
        <>
            {title ? <title>{title}</title> : null}
            {children}
        </>
    ),
    Link: ({
        href,
        children,
        ...props
    }: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({
        url: mockUrl,
        props: {
            app: { name: 'Equity' },
            auth: {
                user: {
                    id: 1,
                    name: 'Morgan Lee',
                    email: 'morgan@example.com',
                },
            },
            flash: { status: mockFlashStatus },
        },
    }),
    useForm: <TForm extends Record<string, unknown>>(initialValues: TForm) => {
        const [data, setDataState] = useState<TForm>(initialValues);

        const setData = (key: keyof TForm, value: TForm[keyof TForm]) => {
            setDataState((prev) => ({ ...prev, [key]: value }));
        };

        return {
            data,
            setData,
            patch: mockPatch,
            put: mockPut,
            post: vi.fn(),
            processing: false,
            errors: mockErrors,
            reset: mockReset,
        };
    },
}));

describe('Settings page', () => {
    beforeEach(() => {
        mockPatch.mockClear();
        mockPut.mockClear();
        mockReset.mockClear();
        mockErrors = {};
        mockFlashStatus = null;
        mockUrl = '/settings';
    });

    it('renders profile and password sections with the verification notice', () => {
        render(
            <SettingsIndex
                profile={{ name: 'Morgan Lee', email: 'morgan@example.com' }}
                emailVerificationNote="Email addresses are not verified in this MVP."
            />,
        );

        const profileSection = screen.getByRole('region', { name: 'Profile' });
        const passwordSection = screen.getByRole('region', { name: 'Password' });

        expect(screen.getByRole('heading', { name: 'Settings' })).toBeInTheDocument();
        expect(
            screen.getByText('Email addresses are not verified in this MVP.'),
        ).toBeInTheDocument();
        expect(within(profileSection).getByLabelText('Full name')).toHaveValue('Morgan Lee');
        expect(within(profileSection).getByLabelText('Email address')).toHaveValue(
            'morgan@example.com',
        );
        expect(within(profileSection).queryByLabelText('Current password')).not.toBeInTheDocument();
        expect(within(passwordSection).getByLabelText('Current password')).toBeInTheDocument();
    });

    it('shows profile current password only when the email is changing and submits profile updates', () => {
        render(
            <SettingsIndex
                profile={{ name: 'Morgan Lee', email: 'morgan@example.com' }}
                emailVerificationNote="Email addresses are not verified in this MVP."
            />,
        );

        const profileSection = screen.getByRole('region', { name: 'Profile' });

        fireEvent.change(within(profileSection).getByLabelText('Email address'), {
            target: { value: 'new.morgan@example.com' },
        });

        expect(within(profileSection).getByLabelText('Current password')).toBeInTheDocument();

        fireEvent.change(within(profileSection).getByLabelText('Current password'), {
            target: { value: 'CorrectHorse!99' },
        });
        fireEvent.change(within(profileSection).getByLabelText('Full name'), {
            target: { value: 'Morgan Updated' },
        });

        fireEvent.submit(within(profileSection).getByRole('button', { name: 'Save profile' }));

        expect(mockPatch).toHaveBeenCalledWith(
            '/settings/profile',
            expect.objectContaining({
                preserveScroll: true,
                onSuccess: expect.any(Function),
            }),
        );
    });

    it('submits password updates to /settings/password', () => {
        render(
            <SettingsIndex
                profile={{ name: 'Morgan Lee', email: 'morgan@example.com' }}
                emailVerificationNote="Email addresses are not verified in this MVP."
            />,
        );

        const passwordSection = screen.getByRole('region', { name: 'Password' });

        fireEvent.change(within(passwordSection).getByLabelText('Current password'), {
            target: { value: 'CorrectHorse!99' },
        });
        fireEvent.change(within(passwordSection).getByLabelText('New password'), {
            target: { value: 'CorrectHorse!100' },
        });
        fireEvent.change(within(passwordSection).getByLabelText('Confirm new password'), {
            target: { value: 'CorrectHorse!100' },
        });

        fireEvent.submit(
            within(passwordSection).getByRole('button', { name: 'Update password' }),
        );

        expect(mockPut).toHaveBeenCalledWith(
            '/settings/password',
            expect.objectContaining({
                preserveScroll: true,
                onFinish: expect.any(Function),
            }),
        );

        const putOptions = mockPut.mock.calls[0]?.[1] as { onFinish?: () => void };
        putOptions.onFinish?.();

        expect(mockReset).toHaveBeenCalledWith(
            'current_password',
            'password',
            'password_confirmation',
        );
    });

    it('displays validation errors and flash success status', () => {
        mockErrors = {
            email: 'Unable to update your profile with the provided email address.',
            current_password: 'The provided password does not match your current password.',
            password: 'The password field must be at least 12 characters.',
        };
        mockFlashStatus = 'Profile updated.';

        render(
            <SettingsIndex
                profile={{ name: 'Morgan Lee', email: 'morgan@example.com' }}
                emailVerificationNote="Email addresses are not verified in this MVP."
                status={null}
            />,
        );

        const profileSection = screen.getByRole('region', { name: 'Profile' });
        const passwordSection = screen.getByRole('region', { name: 'Password' });

        fireEvent.change(within(profileSection).getByLabelText('Email address'), {
            target: { value: 'taken@example.com' },
        });

        expect(
            within(profileSection).getByText(
                'Unable to update your profile with the provided email address.',
            ),
        ).toBeInTheDocument();
        expect(
            within(profileSection).getByText(
                'The provided password does not match your current password.',
            ),
        ).toBeInTheDocument();
        expect(
            within(passwordSection).getByText(
                'The password field must be at least 12 characters.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('Profile updated.')).toBeInTheDocument();
    });

    it('includes a Settings link in authenticated navigation', () => {
        render(
            <SettingsIndex
                profile={{ name: 'Morgan Lee', email: 'morgan@example.com' }}
                emailVerificationNote="Email addresses are not verified in this MVP."
            />,
        );

        expect(
            screen.getAllByRole('link', { name: 'Settings' }).every(
                (link) => link.getAttribute('href') === '/settings',
            ),
        ).toBe(true);
    });
});
