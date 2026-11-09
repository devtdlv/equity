import { act, fireEvent, render, screen } from '@testing-library/react';
import { type AnchorHTMLAttributes, type ReactNode, useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AccountsIndex from '@/Pages/Accounts/Index';
import type { AccountTypeOption, CurrencyOption, FinancialAccountRecord } from '@/Types';

const mockPost = vi.fn();
const mockPatch = vi.fn();
const mockRouterPost = vi.fn();
let mockInitialErrors: Partial<Record<string, string>> = {};

const UUID_V4_PATTERN =
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

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
        const [errors, setErrors] = useState<Partial<Record<keyof TForm, string>>>(
            () => ({ ...(mockInitialErrors as Partial<Record<keyof TForm, string>>) }),
        );
        let transformFn: (formData: TForm) => TForm = (formData) => formData;

        const setData = (keyOrObject: keyof TForm | Partial<TForm>, value?: TForm[keyof TForm]) => {
            if (typeof keyOrObject === 'object' && keyOrObject !== null) {
                setDataState((prev) => ({ ...prev, ...keyOrObject }));
            } else {
                setDataState((prev) => ({ ...prev, [keyOrObject]: value }));
            }
        };

        const formApi = {
            data,
            setData,
            transform: (callback: (formData: TForm) => TForm) => {
                transformFn = callback;
            },
            post: (url: string, options?: unknown) => {
                const payload = transformFn({ ...data });

                return mockPost(url, payload, options);
            },
            patch: (url: string, options?: unknown) => mockPatch(url, { ...data }, options),
            processing: false,
            errors,
            reset: vi.fn(),
            clearErrors: (field?: keyof TForm) => {
                if (field === undefined) {
                    setErrors({});

                    return;
                }

                setErrors((prev) => {
                    const next = { ...prev };
                    delete next[field];

                    return next;
                });
            },
            setError: (field: keyof TForm, message: string) => {
                setErrors((prev) => ({ ...prev, [field]: message }));
            },
        };

        return formApi;
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
        has_opening_balance: false,
        can_post_opening_balance: true,
        balance: {
            amount_minor: 0,
            currency_code: 'PHP',
            exponent: 2,
            symbol: '₱',
            formatted: '₱0.00',
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
        has_opening_balance: true,
        can_post_opening_balance: false,
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
        has_opening_balance: false,
        can_post_opening_balance: false,
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
        mockInitialErrors = {};
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
        expect(screen.getByLabelText('Currency')).toHaveValue('JPY');
    });

    it('defaults create-form currency to the alphabetically first active account currency', () => {
        render(
            <AccountsIndex
                accounts={SAMPLE_ACCOUNTS}
                currencies={SAMPLE_CURRENCIES}
                accountTypes={SAMPLE_TYPES}
            />,
        );

        expect(screen.getByLabelText('Currency')).toHaveValue('PHP');
    });

    it('falls back to the first catalog currency when only archived accounts exist', () => {
        render(
            <AccountsIndex
                accounts={[SAMPLE_ACCOUNTS[2]]}
                currencies={SAMPLE_CURRENCIES}
                accountTypes={SAMPLE_TYPES}
            />,
        );

        expect(screen.getByLabelText('Currency')).toHaveValue('JPY');
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
        expect(screen.getByTestId('account-balance-10')).toHaveTextContent('₱0.00');
        expect(screen.getByText('Opening not set')).toBeInTheDocument();
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

        expect(screen.getByLabelText('Opening Balance')).toHaveValue('');
        expect(
            screen.getByText(/Optional\. Enter an exact amount in PHP/, { exact: false }),
        ).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Account name'), {
            target: { value: 'Travel Yen Cash' },
        });
        fireEvent.change(screen.getByLabelText('Currency'), {
            target: { value: 'JPY' },
        });

        expect(
            screen.getByText(/Optional\. Enter a whole-number amount in JPY/, { exact: false }),
        ).toBeInTheDocument();

        fireEvent.submit(screen.getByRole('button', { name: 'Create account' }));

        expect(mockPost).toHaveBeenCalledTimes(1);
        expect(mockPost).toHaveBeenCalledWith(
            '/accounts',
            expect.objectContaining({
                name: 'Travel Yen Cash',
                currency_code: 'JPY',
                starting_balance: '',
                idempotency_key: expect.stringMatching(UUID_V4_PATTERN),
            }),
            expect.objectContaining({
                preserveScroll: true,
            }),
        );
        expect(typeof mockPost.mock.calls[0][1].starting_balance).toBe('string');
        expect(typeof mockPost.mock.calls[0][1].idempotency_key).toBe('string');
    });

    it('submits positive, negative, and zero opening balances as decimal strings without Number conversion', () => {
        render(
            <AccountsIndex
                accounts={SAMPLE_ACCOUNTS}
                currencies={SAMPLE_CURRENCIES}
                accountTypes={SAMPLE_TYPES}
            />,
        );

        const nameInput = screen.getByLabelText('Account name');
        const openingInput = screen.getByLabelText('Opening Balance');

        fireEvent.change(nameInput, { target: { value: 'PHP Opening Asset' } });
        fireEvent.change(openingInput, { target: { value: '1000.50' } });
        fireEvent.submit(screen.getByRole('button', { name: 'Create account' }));

        expect(mockPost).toHaveBeenCalledWith(
            '/accounts',
            expect.objectContaining({
                starting_balance: '1000.50',
                currency_code: 'PHP',
            }),
            expect.any(Object),
        );
        expect(typeof mockPost.mock.calls[0][1].starting_balance).toBe('string');

        mockPost.mockClear();
        fireEvent.change(openingInput, { target: { value: '-500.25' } });
        fireEvent.submit(screen.getByRole('button', { name: 'Create account' }));

        expect(mockPost).toHaveBeenCalledWith(
            '/accounts',
            expect.objectContaining({ starting_balance: '-500.25' }),
            expect.any(Object),
        );

        mockPost.mockClear();
        fireEvent.change(openingInput, { target: { value: '0' } });
        fireEvent.submit(screen.getByRole('button', { name: 'Create account' }));

        expect(mockPost).toHaveBeenCalledWith(
            '/accounts',
            expect.objectContaining({ starting_balance: '0' }),
            expect.any(Object),
        );
        expect(mockPost).toHaveBeenCalledTimes(1);
    });

    it('blocks excess precision, preserves the amount on currency change, and does not POST until valid', () => {
        render(
            <AccountsIndex
                accounts={SAMPLE_ACCOUNTS}
                currencies={SAMPLE_CURRENCIES}
                accountTypes={SAMPLE_TYPES}
            />,
        );

        const openingInput = screen.getByLabelText('Opening Balance');

        fireEvent.change(screen.getByLabelText('Account name'), {
            target: { value: 'Precision Guard' },
        });
        fireEvent.change(openingInput, { target: { value: '12.345' } });
        fireEvent.submit(screen.getByRole('button', { name: 'Create account' }));

        expect(mockPost).not.toHaveBeenCalled();
        expect(
            screen.getByText('Monetary amount cannot have more than 2 decimal places.'),
        ).toBeInTheDocument();
        expect(openingInput).toHaveValue('12.345');

        fireEvent.change(openingInput, { target: { value: '1000.50' } });
        expect(
            screen.queryByText('Monetary amount cannot have more than 2 decimal places.'),
        ).not.toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Currency'), {
            target: { value: 'JPY' },
        });

        expect(openingInput).toHaveValue('1000.50');
        expect(
            screen.getByText('Currency does not support fractional minor units.'),
        ).toBeInTheDocument();
        expect(mockPost).not.toHaveBeenCalled();

        fireEvent.submit(screen.getByRole('button', { name: 'Create account' }));
        expect(mockPost).not.toHaveBeenCalled();
        expect(openingInput).toHaveValue('1000.50');
    });

    it('keeps the same create idempotency key across retries until a new attempt is started', () => {
        mockInitialErrors = {
            idempotency_key: 'The idempotency key was already used with different request data.',
        };

        render(
            <AccountsIndex
                accounts={SAMPLE_ACCOUNTS}
                currencies={SAMPLE_CURRENCIES}
                accountTypes={SAMPLE_TYPES}
            />,
        );

        expect(
            screen.getByText('The idempotency key was already used with different request data.'),
        ).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Account name'), {
            target: { value: 'Conflict Retry' },
        });
        fireEvent.submit(screen.getByRole('button', { name: 'Create account' }));

        const conflictKey = String(mockPost.mock.calls[0][1].idempotency_key);
        expect(conflictKey).toMatch(UUID_V4_PATTERN);

        fireEvent.click(screen.getByRole('button', { name: 'Start new attempt' }));
        fireEvent.submit(screen.getByRole('button', { name: 'Create account' }));

        expect(mockPost).toHaveBeenCalledTimes(2);
        expect(String(mockPost.mock.calls[1][1].idempotency_key)).toMatch(UUID_V4_PATTERN);
        expect(String(mockPost.mock.calls[1][1].idempotency_key)).not.toBe(conflictKey);
    });

    it('preserves the create idempotency key through client validation failures and rotates only after success', () => {
        mockPost.mockImplementation((_url: string, _data: unknown, options?: { onSuccess?: () => void }) => {
            options?.onSuccess?.();
        });

        render(
            <AccountsIndex
                accounts={SAMPLE_ACCOUNTS}
                currencies={SAMPLE_CURRENCIES}
                accountTypes={SAMPLE_TYPES}
            />,
        );

        fireEvent.change(screen.getByLabelText('Account name'), {
            target: { value: 'UUID Persistence' },
        });
        fireEvent.change(screen.getByLabelText('Opening Balance'), {
            target: { value: '12.345' },
        });
        fireEvent.submit(screen.getByRole('button', { name: 'Create account' }));

        expect(mockPost).not.toHaveBeenCalled();

        fireEvent.change(screen.getByLabelText('Opening Balance'), {
            target: { value: '12.34' },
        });
        fireEvent.submit(screen.getByRole('button', { name: 'Create account' }));

        expect(mockPost).toHaveBeenCalledTimes(1);
        const firstKey = String(mockPost.mock.calls[0][1].idempotency_key);
        expect(firstKey).toMatch(UUID_V4_PATTERN);

        fireEvent.change(screen.getByLabelText('Account name'), {
            target: { value: 'UUID Persistence Next' },
        });
        fireEvent.submit(screen.getByRole('button', { name: 'Create account' }));

        expect(mockPost).toHaveBeenCalledTimes(2);
        expect(String(mockPost.mock.calls[1][1].idempotency_key)).toMatch(UUID_V4_PATTERN);
        expect(String(mockPost.mock.calls[1][1].idempotency_key)).not.toBe(firstKey);
    });

    it('submits whitespace-only opening balance as an empty string so optional posting is skipped', () => {
        render(
            <AccountsIndex
                accounts={SAMPLE_ACCOUNTS}
                currencies={SAMPLE_CURRENCIES}
                accountTypes={SAMPLE_TYPES}
            />,
        );

        fireEvent.change(screen.getByLabelText('Account name'), {
            target: { value: 'Whitespace Opening' },
        });
        fireEvent.change(screen.getByLabelText('Opening Balance'), {
            target: { value: '   ' },
        });
        fireEvent.submit(screen.getByRole('button', { name: 'Create account' }));

        expect(mockPost).toHaveBeenCalledTimes(1);
        expect(mockPost).toHaveBeenCalledWith(
            '/accounts',
            expect.objectContaining({
                name: 'Whitespace Opening',
                starting_balance: '',
            }),
            expect.any(Object),
        );
    });

    it('trims surrounding whitespace on opening balance before POST without Number conversion', () => {
        render(
            <AccountsIndex
                accounts={SAMPLE_ACCOUNTS}
                currencies={SAMPLE_CURRENCIES}
                accountTypes={SAMPLE_TYPES}
            />,
        );

        fireEvent.change(screen.getByLabelText('Account name'), {
            target: { value: 'Trimmed Opening' },
        });
        fireEvent.change(screen.getByLabelText('Opening Balance'), {
            target: { value: '  -500.25  ' },
        });
        fireEvent.submit(screen.getByRole('button', { name: 'Create account' }));

        expect(mockPost).toHaveBeenCalledWith(
            '/accounts',
            expect.objectContaining({
                starting_balance: '-500.25',
            }),
            expect.any(Object),
        );
        expect(typeof mockPost.mock.calls[0][1].starting_balance).toBe('string');
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
                name: 'Updated Rewards Card',
            }),
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

    it('posts a one-time opening balance for an eligible existing account from the edit panel', () => {
        render(
            <AccountsIndex
                accounts={SAMPLE_ACCOUNTS}
                currencies={SAMPLE_CURRENCIES}
                accountTypes={SAMPLE_TYPES}
            />,
        );

        fireEvent.click(screen.getAllByRole('button', { name: 'Edit' })[0]);

        expect(screen.getByRole('heading', { name: 'Set opening balance' })).toBeInTheDocument();
        const openingField = screen.getByLabelText('Opening Balance');
        fireEvent.change(openingField, { target: { value: '100.50' } });
        fireEvent.click(screen.getByRole('button', { name: 'Record opening balance' }));

        expect(mockPost).toHaveBeenCalledWith(
            '/accounts/10/opening-balance',
            { opening_balance: '100.50' },
            expect.objectContaining({
                preserveScroll: true,
                onFinish: expect.any(Function),
                onSuccess: expect.any(Function),
            }),
        );
    });

    it('does not offer opening balance posting for accounts with ledger activity', () => {
        render(
            <AccountsIndex
                accounts={SAMPLE_ACCOUNTS}
                currencies={SAMPLE_CURRENCIES}
                accountTypes={SAMPLE_TYPES}
            />,
        );

        fireEvent.click(screen.getAllByRole('button', { name: 'Edit' })[1]);

        expect(screen.queryByRole('heading', { name: 'Set opening balance' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Record opening balance' })).not.toBeInTheDocument();
    });
});
