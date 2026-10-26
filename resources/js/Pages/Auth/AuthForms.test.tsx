import { fireEvent, render, screen } from '@testing-library/react';
import { type AnchorHTMLAttributes, type ReactNode, useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ForgotPassword from '@/Pages/Auth/ForgotPassword';
import Login from '@/Pages/Auth/Login';
import Register from '@/Pages/Auth/Register';
import ResetPassword from '@/Pages/Auth/ResetPassword';

const mockPost = vi.fn();
const mockReset = vi.fn();
let mockErrors: Record<string, string> = {};
let mockFlashStatus: string | null = null;

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
        props: {
            app: { name: 'Equity' },
            auth: { user: null },
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
            post: mockPost,
            processing: false,
            errors: mockErrors,
            reset: mockReset,
        };
    },
}));

describe('Authentication forms', () => {
    beforeEach(() => {
        mockPost.mockClear();
        mockReset.mockClear();
        mockErrors = {};
        mockFlashStatus = null;
    });

    it('renders the registration form and submits to /register', () => {
        render(<Register />);

        const nameInput = screen.getByLabelText('Full name');
        const emailInput = screen.getByLabelText('Email address');
        const passwordInput = screen.getByLabelText('Password');
        const confirmInput = screen.getByLabelText('Confirm password');

        fireEvent.change(nameInput, { target: { value: 'Jordan Taylor' } });
        fireEvent.change(emailInput, { target: { value: 'jordan@example.com' } });
        fireEvent.change(passwordInput, { target: { value: 'CorrectHorse!99' } });
        fireEvent.change(confirmInput, { target: { value: 'CorrectHorse!99' } });

        fireEvent.submit(screen.getByRole('button', { name: 'Create account' }));

        expect(mockPost).toHaveBeenCalledWith(
            '/register',
            expect.objectContaining({
                onFinish: expect.any(Function),
            }),
        );
    });

    it('displays validation error states on the registration form', () => {
        mockErrors = {
            email: 'Unable to complete registration with the provided details.',
            password: 'The password field must be at least 12 characters.',
        };

        render(<Register />);

        expect(
            screen.getByText('Unable to complete registration with the provided details.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('The password field must be at least 12 characters.'),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('Email address')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByLabelText('Password')).toHaveAttribute('aria-invalid', 'true');
    });

    it('renders the login form with email, password, and remember me and submits to /login', () => {
        render(<Login />);

        fireEvent.change(screen.getByLabelText('Email address'), {
            target: { value: 'alex@example.com' },
        });
        fireEvent.change(screen.getByLabelText('Password'), {
            target: { value: 'CorrectHorse!99' },
        });
        fireEvent.click(screen.getByLabelText('Remember me'));

        fireEvent.submit(screen.getByRole('button', { name: 'Sign in' }));

        expect(mockPost).toHaveBeenCalledWith(
            '/login',
            expect.objectContaining({
                onFinish: expect.any(Function),
            }),
        );
    });

    it('renders the forgot-password form and status message', () => {
        mockFlashStatus =
            'If an account matches that email address, we have sent a password reset link.';

        render(<ForgotPassword />);

        expect(screen.getByRole('status')).toHaveTextContent(
            'If an account matches that email address, we have sent a password reset link.',
        );

        fireEvent.change(screen.getByLabelText('Email address'), {
            target: { value: 'member@example.com' },
        });
        fireEvent.submit(screen.getByRole('button', { name: 'Send password reset link' }));

        expect(mockPost).toHaveBeenCalledWith('/forgot-password');
    });

    it('renders the reset-password form with prefilled email and submits to /reset-password', () => {
        render(<ResetPassword token="reset-token-123" email="member@example.com" />);

        expect(screen.getByLabelText('Email address')).toHaveValue('member@example.com');

        fireEvent.change(screen.getByLabelText('New password'), {
            target: { value: 'NewStrongPass!456' },
        });
        fireEvent.change(screen.getByLabelText('Confirm new password'), {
            target: { value: 'NewStrongPass!456' },
        });

        fireEvent.submit(screen.getByRole('button', { name: 'Reset password' }));

        expect(mockPost).toHaveBeenCalledWith(
            '/reset-password',
            expect.objectContaining({
                onFinish: expect.any(Function),
            }),
        );
    });
});
