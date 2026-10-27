import { act, fireEvent, render, screen } from '@testing-library/react';
import { type AnchorHTMLAttributes, type ReactNode, useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AccountsIndex from '@/Pages/Accounts/Index';
import type { AccountTypeOption, CurrencyOption, FinancialAccountRecord } from '@/Types';

const mockPost = vi.fn();
const mockPatch = vi.fn();
const mockRouterPost = vi.fn();

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
    router: {
        post: (...args: unknown[]) => mockRouterPost(...args),
    },
    usePage: () => ({
        url: '/accounts',
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
    useForm: <TForm extends Record<string, unknown>>(initialValues: TForm) => {
        const [data, setDataState] = useState<TForm>(initialValues);

        const setData = (keyOrObject: keyof TForm | Partial<TForm>, value?: TForm[keyof TForm]) => {
            if (typeof keyOrObject === 'object' && keyOrObject !== null) {
                setDataState((prev) => ({ ...prev, ...keyOrObject }));
            } else {
                setDataState((prev) => ({ ...prev, [keyOrObject]: value }));
            }
        };

        return {
            data,
            setData,
            post: mockPost,
            patch: mockPatch,
            processing: false,
            errors: {},
            reset: vi.fn(),
            clearErrors: vi.fn(),
        };
    },
}));

const SAMPLE_CURRENCIES: CurrencyOption[] = [
    { code: 'PHP', name: 'Philippine Peso', symbol: '₱', minor_unit_exponent: 2 },
    { code: 'USD', name: 'US Dollar', symbol: '$', minor_unit_exponent: 2 },
    { code: 'JPY', name: 'Japanese Yen', symbol: '¥', minor_unit_exponent: 0 },
];

const SAMPLE_TYPES: AccountTypeOption[] = [
    { value: 'checking', label: 'Checking', classification: 'asset' },
    { value: 'savings', label: 'Savings', classification: 'asset' },
    { value: 'credit_card', label: 'Credit Card', classification: 'liability' },
];

const SAMPLE_ACCOUNTS: FinancialAccountRecord[] = [
    {
        id: 10,
        name: 'Primary Checking',
        type: 'checking',
        type_label: 'Checking',
        classification: 'asset',
        currency_code: 'PHP',
        institution: 'BPI',
        description: 'Everyday operating account',
        is_archived: false,
        archived_at: null,
        has_posted_activity: false,
        balance: {
            amount_minor: 250000,
            currency_code: 'PHP',
            exponent: 2,
            symbol: '₱',
            formatted: '₱2,500.00',
            is_liability: false,
        },
    },
    {
        id: 11,
        name: 'Rewards Credit Card',
        type: 'credit_card',
        type_label: 'Credit Card',
        classification: 'liability',
        currency_code: 'USD',
        institution: 'Chase',
        description: null,
        is_archived: false,
        archived_at: null,
        has_posted_activity: true,
        balance: {
            amount_minor: 12550,
            currency_code: 'USD',
            exponent: 2,
            symbol: '$',
            formatted: '$125.50',
            is_liability: true,
        },
    },
    {
        id: 12,
        name: 'Legacy Savings',
        type: 'savings',
        type_label: 'Savings',
        classification: 'asset',
        currency_code: 'USD',
        institution: 'Ally Bank',
        description: 'Previous emergency reserve',
        is_archived: true,
        archived_at: '2026-10-27T00:00:00Z',
        has_posted_activity: false,
        balance: {
            amount_minor: 0,
            currency_code: 'USD',
            exponent: 2,
            symbol: '$',
            formatted: '$0.00',
            is_liability: false,
        },
    },
];

describe('AccountsIndex page', () => {
    beforeEach(() => {
        mockPost.mockClear();
        mockPatch.mockClear();
        mockRouterPost.mockClear();
    });

    it('renders the empty state when the user has no active accounts', () => {
        render(
            <AccountsIndex
                accounts={[]}
                currencies={SAMPLE_CURRENCIES}
                accountTypes={SAMPLE_TYPES}
            />,
        );

        expect(
            screen.getByRole('heading', {
                level: 2,
                name: 'You have not added any active accounts yet.',
            }),
        ).toBeInTheDocument();
    });

    it('renders active and archived accounts with consistent hierarchy, ledger-derived balances, and submits new account creation', () => {
        render(
            <AccountsIndex
                accounts={SAMPLE_ACCOUNTS}
                currencies={SAMPLE_CURRENCIES}
                accountTypes={SAMPLE_TYPES}
            />,
        );

        expect(screen.getByText('Primary Checking')).toBeInTheDocument();
        expect(screen.getByTestId('account-balance-10')).toHaveTextContent('₱2,500.00');
        expect(screen.getByText('Rewards Credit Card')).toBeInTheDocument();
        expect(screen.getByTestId('account-balance-11')).toHaveTextContent('$125.50');
        expect(screen.getByText('Liability')).toBeInTheDocument();

        // Archived account card details and Restore control
        expect(screen.getByText('Legacy Savings')).toBeInTheDocument();
        expect(screen.getByText('Ally Bank')).toBeInTheDocument();
        expect(screen.getByText('Previous emergency reserve')).toBeInTheDocument();
        expect(screen.getByText('Archived')).toBeInTheDocument();
        expect(screen.getByTestId('account-balance-12')).toHaveTextContent('$0.00');

        const editButtons = screen.getAllByRole('button', { name: 'Edit' });
        const archiveButtons = screen.getAllByRole('button', { name: 'Archive' });
        const restoreButton = screen.getByRole('button', { name: 'Restore' });

        expect(editButtons).toHaveLength(2);
        expect(archiveButtons).toHaveLength(2);
        expect(editButtons[0].querySelector('svg')).not.toBeNull();
        expect(archiveButtons[0].querySelector('svg')).not.toBeNull();
        expect(restoreButton.querySelector('svg')).not.toBeNull();
        expect(archiveButtons[0].className).toContain('border-warning-border');
        expect(restoreButton.className).toContain('border-accent-border');

        fireEvent.change(screen.getByLabelText('Account name'), {
            target: { value: 'Travel Yen Cash' },
        });
        fireEvent.change(screen.getByLabelText('Currency'), {
            target: { value: 'JPY' },
        });

        fireEvent.submit(screen.getByRole('button', { name: 'Create account' }));

        expect(mockPost).toHaveBeenCalledWith(
            '/accounts',
            expect.objectContaining({
                preserveScroll: true,
            }),
        );
    });

    it('supports editing account metadata, locks currency when posted activity exists, and dispatches restore action', () => {
        render(
            <AccountsIndex
                accounts={SAMPLE_ACCOUNTS}
                currencies={SAMPLE_CURRENCIES}
                accountTypes={SAMPLE_TYPES}
            />,
        );

        const editButtons = screen.getAllByRole('button', { name: 'Edit' });
        fireEvent.click(editButtons[1]); // Rewards Credit Card (has_posted_activity = true)

        expect(
            screen.getByText('Currency is locked because this account has posted ledger activity.'),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('Currency')).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Account name'), {
            target: { value: 'Updated Rewards Card' },
        });
        fireEvent.submit(screen.getByRole('button', { name: 'Save changes' }));

        expect(mockPatch).toHaveBeenCalledWith(
            '/accounts/11',
            expect.objectContaining({
                preserveScroll: true,
            }),
        );

        const restoreButton = screen.getByRole('button', { name: 'Restore' });
        fireEvent.click(restoreButton);
        expect(mockRouterPost).toHaveBeenCalledWith('/accounts/12/restore', {}, expect.any(Object));
    });

    it('opens the accessible archive confirmation dialog without immediate submission and supports Cancel, Escape, and focus containment', () => {
        render(
            <AccountsIndex
                accounts={SAMPLE_ACCOUNTS}
                currencies={SAMPLE_CURRENCIES}
                accountTypes={SAMPLE_TYPES}
            />,
        );

        const archiveButtons = screen.getAllByRole('button', { name: 'Archive' });
        const firstArchiveButton = archiveButtons[0];
        firstArchiveButton.focus();

        // Clicking Archive opens the dialog without immediately posting
        fireEvent.click(firstArchiveButton);
        expect(mockRouterPost).not.toHaveBeenCalled();

        const dialog = screen.getByRole('dialog', { name: 'Archive Primary Checking?' });
        expect(dialog).toBeInTheDocument();
        expect(dialog).toHaveAttribute('aria-modal', 'true');
        expect(dialog).toHaveTextContent(
            'Archiving Primary Checking hides it from your active accounts while preserving its complete ledger history and balance. You can restore this account at any time.',
        );

        const cancelButton = screen.getByRole('button', { name: 'Cancel' });
        const confirmButton = screen.getByRole('button', { name: 'Confirm archive' });

        // Initial focus lands on Cancel
        expect(document.activeElement).toBe(cancelButton);

        // Tab traps focus within the dialog
        fireEvent.keyDown(document, { key: 'Tab', shiftKey: true });
        expect(document.activeElement).toBe(confirmButton);

        fireEvent.keyDown(document, { key: 'Tab', shiftKey: false });
        expect(document.activeElement).toBe(cancelButton);

        // Cancel dismisses without submitting and restores focus to the trigger button
        fireEvent.click(cancelButton);
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(mockRouterPost).not.toHaveBeenCalled();
        expect(document.activeElement).toBe(firstArchiveButton);

        // Reopen and dismiss via Escape key
        fireEvent.click(firstArchiveButton);
        expect(
            screen.getByRole('dialog', { name: 'Archive Primary Checking?' }),
        ).toBeInTheDocument();

        fireEvent.keyDown(document, { key: 'Escape' });
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(mockRouterPost).not.toHaveBeenCalled();
        expect(document.activeElement).toBe(firstArchiveButton);
    });

    it('submits archive request exactly once on confirmation and prevents duplicate submissions while pending', () => {
        let finishRequest: (() => void) | undefined;

        mockRouterPost.mockImplementation(
            (_url: string, _data: unknown, options?: { onFinish?: () => void }) => {
                finishRequest = options?.onFinish;
            },
        );

        render(
            <AccountsIndex
                accounts={SAMPLE_ACCOUNTS}
                currencies={SAMPLE_CURRENCIES}
                accountTypes={SAMPLE_TYPES}
            />,
        );

        const archiveButtons = screen.getAllByRole('button', { name: 'Archive' });
        fireEvent.click(archiveButtons[0]);

        const confirmButton = screen.getByRole('button', { name: 'Confirm archive' });
        const cancelButton = screen.getByRole('button', { name: 'Cancel' });

        // Rapid double click on Confirm archive
        fireEvent.click(confirmButton);
        fireEvent.click(confirmButton);

        expect(mockRouterPost).toHaveBeenCalledTimes(1);
        expect(mockRouterPost).toHaveBeenCalledWith(
            '/accounts/10/archive',
            {},
            expect.objectContaining({
                preserveScroll: true,
                onFinish: expect.any(Function),
            }),
        );

        // While request is in flight, Confirm and Cancel are disabled and Escape does not close
        expect(confirmButton).toBeDisabled();
        expect(confirmButton).toHaveAttribute('aria-busy', 'true');
        expect(cancelButton).toBeDisabled();

        fireEvent.keyDown(document, { key: 'Escape' });
        expect(
            screen.getByRole('dialog', { name: 'Archive Primary Checking?' }),
        ).toBeInTheDocument();

        // Completing the request closes the dialog and resets loading state
        act(() => {
            finishRequest?.();
        });
        expect(screen.queryByRole('dialog')).toBeNull();
    });
});
