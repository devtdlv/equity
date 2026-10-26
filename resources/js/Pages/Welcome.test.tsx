import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Welcome from '@/Pages/Welcome';

vi.mock('@inertiajs/react', () => ({
    Head: ({ title, children }: { title?: string; children?: ReactNode }) => (
        <>
            {title ? <title>{title}</title> : null}
            {children}
        </>
    ),
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

        const statusBadge = screen.getByRole('status', {
            name: 'Development status: Application foundation',
        });

        expect(statusBadge).toBeInTheDocument();
        expect(statusBadge).toHaveTextContent('Application foundation');
    });

    it('does not render nonfunctional navigation links or buttons', () => {
        render(<Welcome />);

        expect(screen.queryAllByRole('link')).toHaveLength(0);
        expect(screen.queryAllByRole('button')).toHaveLength(0);
    });
});
