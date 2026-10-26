import { fireEvent, render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Dashboard from '@/Pages/Dashboard';

const mockPost = vi.fn();
let mockUrl = '/dashboard';

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
        mockUrl = '/dashboard';
    });

    it('renders the user-facing dashboard heading, description, capability cards, and signed-in user name without a development milestone badge', () => {
        render(<Dashboard />);

        expect(screen.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeInTheDocument();
        expect(
            screen.getByRole('heading', {
                level: 2,
                name: 'Your financial overview starts here.',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Equity will bring your accounts, transactions, budgets, and cash-flow planning into one organized workspace. Financial features are currently being developed.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Keep your checking, savings, and credit card balances organized in one clear view.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Follow your income, everyday spending, and transfers with a reliable, easy-to-read history.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Plan monthly spending targets and stay ahead of upcoming bills and savings goals.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByRole('status')).toBeNull();
        expect(screen.getByTestId('authenticated-user-name')).toHaveTextContent('Morgan Lee');
        expect(screen.getByRole('link', { name: 'Dashboard' })).toHaveAttribute(
            'href',
            '/dashboard',
        );
    });

    it('does not render clickable navigation links to unimplemented financial routes', () => {
        render(<Dashboard />);

        const links = screen.getAllByRole('link');
        const hrefs = links.map((link) => link.getAttribute('href'));

        expect(hrefs.every((href) => href === '/dashboard')).toBe(true);
    });

    it('submits a POST request to /logout when the logout button is clicked', () => {
        render(<Dashboard />);

        const logoutButton = screen.getByRole('button', { name: 'Log out' });
        fireEvent.submit(logoutButton);

        expect(mockPost).toHaveBeenCalledWith('/logout');
    });

    it('toggles the responsive mobile navigation menu and closes on Escape or route change', () => {
        const { rerender } = render(<Dashboard />);

        const toggleButton = screen.getByRole('button', { name: 'Toggle navigation menu' });
        expect(toggleButton).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByRole('navigation', { name: 'Mobile navigation' })).toBeNull();

        fireEvent.click(toggleButton);

        expect(toggleButton).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByRole('navigation', { name: 'Mobile navigation' })).toBeInTheDocument();

        fireEvent.keyDown(window, { key: 'Escape' });
        expect(toggleButton).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByRole('navigation', { name: 'Mobile navigation' })).toBeNull();

        fireEvent.click(toggleButton);
        expect(toggleButton).toHaveAttribute('aria-expanded', 'true');

        mockUrl = '/dashboard?tab=overview';
        rerender(<Dashboard />);
        expect(toggleButton).toHaveAttribute('aria-expanded', 'false');
    });
});
