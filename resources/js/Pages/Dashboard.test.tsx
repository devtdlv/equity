import { fireEvent, render, screen, within } from '@testing-library/react';
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

    it('renders the dashboard heading, authenticated greeting, and five workspace module links', () => {
        render(<Dashboard />);

        expect(screen.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeInTheDocument();
        expect(
            screen.getByText('Welcome back, Morgan Lee. Your workspace is ready.'),
        ).toBeInTheDocument();
        expect(screen.getByTestId('authenticated-user-name')).toHaveTextContent('Morgan Lee');

        const workspace = screen.getByRole('region', { name: 'Workspace' });
        const moduleLinks = within(workspace).getAllByRole('link');
        const moduleHrefs = moduleLinks.map((link) => link.getAttribute('href'));

        expect(moduleHrefs).toEqual([
            '/accounts',
            '/transactions',
            '/budgets',
            '/bills',
            '/savings',
        ]);
        expect(within(workspace).getByRole('link', { name: 'Accounts' })).toHaveAttribute(
            'href',
            '/accounts',
        );
        expect(within(workspace).getByRole('link', { name: 'Transactions' })).toHaveAttribute(
            'href',
            '/transactions',
        );
        expect(within(workspace).getByRole('link', { name: 'Budgets' })).toHaveAttribute(
            'href',
            '/budgets',
        );
        expect(
            within(workspace).getByRole('link', { name: 'Bills & Subscriptions' }),
        ).toHaveAttribute('href', '/bills');
        expect(within(workspace).getByRole('link', { name: 'Savings Goals' })).toHaveAttribute(
            'href',
            '/savings',
        );

        expect(within(workspace).queryByText('Upcoming')).not.toBeInTheDocument();
        expect(screen.queryByText(/currently being developed/i)).not.toBeInTheDocument();
        expect(
            screen.getByText(/Linking does not create a transaction/i),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/do not transfer money or change account balances/i),
        ).toBeInTheDocument();
    });

    it('shows Ledger & Reports as planned and not as a destination', () => {
        render(<Dashboard />);

        const planned = screen.getByRole('region', { name: 'Planned' });
        expect(
            within(planned).getByRole('heading', { name: 'Ledger & Reports' }),
        ).toBeInTheDocument();
        expect(
            within(planned).getByText(/Charts, reports, cash-flow forecasting/i),
        ).toBeInTheDocument();
        expect(within(planned).queryByRole('link')).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Ledger & Reports' })).not.toBeInTheDocument();
    });

    it('only renders clickable navigation links for implemented routes (/dashboard, /accounts, /transactions, /budgets, /savings, and /bills)', () => {
        render(<Dashboard />);

        const links = screen.getAllByRole('link');
        const hrefs = links.map((link) => link.getAttribute('href'));

        expect(
            hrefs.every(
                (href) =>
                    href === '/dashboard' ||
                    href === '/accounts' ||
                    href === '/transactions' ||
                    href === '/budgets' ||
                    href === '/savings' ||
                    href === '/bills',
            ),
        ).toBe(true);
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
