import { render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Welcome from '@/Pages/Welcome';

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
            flash: { status: null },
        },
    }),
}));

describe('Welcome page', () => {
    it('renders the Equity product name, tagline, and description', () => {
        render(<Welcome />);

        expect(screen.getByRole('heading', { level: 1, name: 'Equity' })).toBeInTheDocument();
        expect(screen.getByText('Personal finance, clearly understood.')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Track your accounts, understand your spending, and plan your financial future in one place.',
            ),
        ).toBeInTheDocument();
    });

    it('renders the development status indicator', () => {
        render(<Welcome />);

        const statusBadges = screen.getAllByRole('status', {
            name: 'Development status: Application foundation',
        });

        expect(statusBadges.length).toBeGreaterThanOrEqual(1);
        expect(statusBadges[0]).toHaveTextContent('Application foundation');
    });

    it('renders sign in and registration navigation links for guests', () => {
        render(<Welcome />);

        expect(screen.getByRole('link', { name: 'Sign in' })).toHaveAttribute('href', '/login');
        expect(screen.getByRole('link', { name: 'Create account' })).toHaveAttribute(
            'href',
            '/register',
        );
    });
});
