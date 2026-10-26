import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Alert } from '@/Components/Alert';
import { Badge } from '@/Components/Badge';
import { Button } from '@/Components/Button';
import { Card } from '@/Components/Card';
import { Checkbox } from '@/Components/Checkbox';
import { EmptyState } from '@/Components/EmptyState';
import { FormField } from '@/Components/FormField';
import { Input } from '@/Components/Input';
import { PageHeader } from '@/Components/PageHeader';
import { Select } from '@/Components/Select';
import { Separator } from '@/Components/Separator';
import { Skeleton } from '@/Components/Skeleton';
import { Textarea } from '@/Components/Textarea';
import { ThemeToggle } from '@/Components/ThemeToggle';
import { THEME_STORAGE_KEY } from '@/Utilities/theme';

describe('Design System Primitives', () => {
    beforeEach(() => {
        window.localStorage.clear();
        document.documentElement.className = '';
    });

    it('renders Button variants, disabled state, and loading state with aria-busy', () => {
        const handleClick = vi.fn();
        const { rerender } = render(
            <Button variant="primary" size="md" onClick={handleClick}>
                Save changes
            </Button>,
        );

        const button = screen.getByRole('button', { name: 'Save changes' });
        expect(button).not.toBeDisabled();
        fireEvent.click(button);
        expect(handleClick).toHaveBeenCalledTimes(1);

        rerender(
            <Button variant="outline" disabled onClick={handleClick}>
                Disabled button
            </Button>,
        );
        expect(screen.getByRole('button', { name: 'Disabled button' })).toBeDisabled();

        rerender(
            <Button variant="danger" loading onClick={handleClick}>
                Processing
            </Button>,
        );
        const loadingButton = screen.getByRole('button', { name: 'Processing' });
        expect(loadingButton).toBeDisabled();
        expect(loadingButton).toHaveAttribute('aria-busy', 'true');
    });

    it('renders Input, Textarea, Select, Checkbox, and FormField with accessible labels and error states', () => {
        render(
            <div>
                <FormField
                    id="account-name"
                    label="Account name"
                    hint="Enter a descriptive name."
                    error="Account name is required."
                />
                <label htmlFor="notes">Notes</label>
                <Textarea id="notes" invalid />
                <label htmlFor="currency">Currency</label>
                <Select
                    id="currency"
                    options={[
                        { value: 'USD', label: 'US Dollar' },
                        { value: 'EUR', label: 'Euro' },
                    ]}
                />
                <Checkbox
                    id="terms"
                    label="Keep session active"
                    description="Applies to this browser only."
                />
                <Input aria-label="Standalone input" invalid />
            </div>,
        );

        const accountInput = screen.getByLabelText('Account name');
        expect(accountInput).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByRole('alert')).toHaveTextContent('Account name is required.');

        const notesTextarea = screen.getByLabelText('Notes');
        expect(notesTextarea).toHaveAttribute('aria-invalid', 'true');

        const currencySelect = screen.getByLabelText('Currency');
        expect(currencySelect).toHaveValue('USD');

        const checkbox = screen.getByLabelText('Keep session active');
        expect(checkbox).toHaveAttribute('type', 'checkbox');
        expect(screen.getByText('Applies to this browser only.')).toBeInTheDocument();
    });

    it('renders Card, Badge, Alert, Separator, EmptyState, PageHeader, and Skeleton', () => {
        render(
            <div>
                <PageHeader
                    eyebrow="Overview"
                    title="Financial Summary"
                    description="Track your progress."
                    actions={<Badge variant="positive">Active</Badge>}
                />
                <Alert variant="warning" title="Attention">
                    Review your settings.
                </Alert>
                <Separator />
                <Card>
                    <EmptyState
                        eyebrow="Getting Started"
                        title="No items yet"
                        description="Items will appear here."
                    />
                </Card>
                <Skeleton label="Loading placeholder" />
            </div>,
        );

        expect(
            screen.getByRole('heading', { level: 1, name: 'Financial Summary' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Active')).toBeInTheDocument();
        expect(screen.getByText('Review your settings.')).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 2, name: 'No items yet' })).toBeInTheDocument();
        expect(screen.getByRole('status', { name: 'Loading placeholder' })).toHaveAttribute(
            'aria-busy',
            'true',
        );
    });

    it('renders ThemeToggle with accessible group semantics and updates theme preference', () => {
        render(<ThemeToggle />);

        const group = screen.getByRole('group', { name: 'Theme preference' });
        expect(group).toBeInTheDocument();

        const lightButton = screen.getByRole('button', { name: 'Light' });
        const darkButton = screen.getByRole('button', { name: 'Dark' });
        const systemButton = screen.getByRole('button', { name: 'System' });

        expect(systemButton).toHaveAttribute('aria-pressed', 'true');

        fireEvent.click(darkButton);
        expect(darkButton).toHaveAttribute('aria-pressed', 'true');
        expect(lightButton).toHaveAttribute('aria-pressed', 'false');
        expect(window.localStorage.getItem(THEME_STORAGE_KEY)).toBe('dark');
        expect(document.documentElement.classList.contains('dark')).toBe(true);

        fireEvent.click(lightButton);
        expect(lightButton).toHaveAttribute('aria-pressed', 'true');
        expect(window.localStorage.getItem(THEME_STORAGE_KEY)).toBe('light');
        expect(document.documentElement.classList.contains('dark')).toBe(false);
    });
});
