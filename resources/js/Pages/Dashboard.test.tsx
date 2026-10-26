import { fireEvent, render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Dashboard from '@/Pages/Dashboard';

const mockPost = vi.fn();

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
            auth: {
                user: {
                    id: 1,
                    name: 'Morgan Lee',
                    email: 'morgan@example.com',
                },
            },
            flash: { status: null },
        },
    }),
    useForm: () => ({
        data: {},
        setData: vi.fn(),
        post: mockPost,
        processing: false,
        errors: {},
        reset: vi.fn(),
    }),
}));

describe('Dashboard and AuthenticatedLayout', () => {
    beforeEach(() => {
        mockPost.mockClear();
    });

    it('renders the dashboard placeholder message, Equity branding, navigation, and signed-in user name', () => {
        render(<Dashboard />);

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'Your financial overview is coming together.',
            }),
        ).toBeInTheDocument();
        expect(screen.getByTestId('authenticated-user-name')).toHaveTextContent('Morgan Lee');
        expect(screen.getByRole('link', { name: 'Dashboard' })).toHaveAttribute(
            'href',
            '/dashboard',
        );
    });

    it('submits a POST request to /logout when the logout button is clicked', () => {
        render(<Dashboard />);

        const logoutButton = screen.getByRole('button', { name: 'Log out' });
        fireEvent.submit(logoutButton);

        expect(mockPost).toHaveBeenCalledWith('/logout');
    });

    it('toggles the responsive mobile navigation menu', () => {
        render(<Dashboard />);

        const toggleButton = screen.getByRole('button', { name: 'Toggle navigation menu' });
        expect(toggleButton).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByRole('navigation', { name: 'Mobile navigation' })).toBeNull();

        fireEvent.click(toggleButton);

        expect(toggleButton).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByRole('navigation', { name: 'Mobile navigation' })).toBeInTheDocument();
        expect(screen.getByText('morgan@example.com')).toBeInTheDocument();
    });
});
