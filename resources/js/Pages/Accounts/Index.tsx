import { router, useForm, usePage } from '@inertiajs/react';
import { type FormEvent, useRef, useState } from 'react';
import { Alert } from '@/Components/Alert';
import { Badge } from '@/Components/Badge';
import { Button } from '@/Components/Button';
import { Card } from '@/Components/Card';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { EmptyState } from '@/Components/EmptyState';
import { FormField } from '@/Components/FormField';
import { PageHeader } from '@/Components/PageHeader';
import { Select } from '@/Components/Select';
import { Separator } from '@/Components/Separator';
import { Textarea } from '@/Components/Textarea';
import { AuthenticatedLayout } from '@/Layouts/AuthenticatedLayout';
import type { AccountsIndexPageProps, FinancialAccountRecord, SharedPageProps } from '@/Types';

interface AccountFormData {
    name: string;
    type: string;
    currency_code: string;
    institution: string;
    description: string;
}

function PencilIcon() {
    return (
        <svg
            className="h-3.5 w-3.5"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M11.333 2.00004C11.5081 1.82494 11.716 1.68605 11.9447 1.59129C12.1735 1.49653 12.4187 1.44775 12.6663 1.44775C12.914 1.44775 13.1592 1.49653 13.3879 1.59129C13.6167 1.68605 13.8246 1.82494 13.9997 2.00004C14.1748 2.17513 14.3137 2.383 14.4084 2.61178C14.5032 2.84055 14.552 3.08575 14.552 3.33337C14.552 3.58099 14.5032 3.82619 14.4084 4.05497C14.3137 4.28374 14.1748 4.49161 13.9997 4.66671L5.00001 13.6667L1.33334 14.6667L2.33334 11L11.333 2.00004Z" />
        </svg>
    );
}

function ArchiveIcon() {
    return (
        <svg
            className="h-3.5 w-3.5"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <rect x="1.33334" y="2" width="13.3333" height="3.33333" rx="0.75" />
            <path d="M2.66666 5.33334V12.6667C2.66666 13.403 3.26362 14 4 14H12C12.7364 14 13.3333 13.403 13.3333 12.6667V5.33334" />
            <path d="M6.66666 8H9.33333" />
        </svg>
    );
}

function RestoreIcon() {
    return (
        <svg
            className="h-3.5 w-3.5"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M2 8C2 4.68629 4.68629 2 8 2C10.2208 2 12.1599 3.2066 13.1973 5" />
            <path d="M13.3333 2V5.33333H10" />
            <path d="M14 8C14 11.3137 11.3137 14 8 14C5.77915 14 3.84012 12.7934 2.80269 11" />
        </svg>
    );
}

export default function AccountsIndex({
    accounts = [],
    currencies = [],
    accountTypes = [],
}: AccountsIndexPageProps) {
    const page = usePage<SharedPageProps>();
    const flashStatus = page.props?.flash?.status ?? null;

    const defaultCurrencyCode = currencies[0]?.code ?? 'USD';
    const defaultAccountType = accountTypes[0]?.value ?? 'checking';

    const [editingAccount, setEditingAccount] = useState<FinancialAccountRecord | null>(null);
    const [pendingArchiveAccount, setPendingArchiveAccount] =
        useState<FinancialAccountRecord | null>(null);
    const [submittingAccountId, setSubmittingAccountId] = useState<number | null>(null);
    const isSubmittingStatusRef = useRef(false);

    const createForm = useForm<AccountFormData>({
        name: '',
        type: defaultAccountType,
        currency_code: defaultCurrencyCode,
        institution: '',
        description: '',
    });

    const editForm = useForm<AccountFormData>({
        name: '',
        type: defaultAccountType,
        currency_code: defaultCurrencyCode,
        institution: '',
        description: '',
    });

    const activeAccounts = accounts.filter((account) => !account.is_archived);
    const archivedAccounts = accounts.filter((account) => account.is_archived);

    const handleCreateSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        createForm.post('/accounts', {
            preserveScroll: true,
            onSuccess: () => {
                createForm.reset('name', 'institution', 'description');
            },
        });
    };

    const startEditing = (account: FinancialAccountRecord) => {
        setEditingAccount(account);
        editForm.clearErrors();
        editForm.setData({
            name: account.name,
            type: account.type,
            currency_code: account.currency_code,
            institution: account.institution ?? '',
            description: account.description ?? '',
        });
    };

    const cancelEditing = () => {
        setEditingAccount(null);
        editForm.reset();
        editForm.clearErrors();
    };

    const handleEditSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!editingAccount) {
            return;
        }

        editForm.patch(`/accounts/${editingAccount.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingAccount(null);
            },
        });
    };

    const openArchiveDialog = (account: FinancialAccountRecord) => {
        if (isSubmittingStatusRef.current) {
            return;
        }

        setPendingArchiveAccount(account);
    };

    const closeArchiveDialog = () => {
        if (isSubmittingStatusRef.current) {
            return;
        }

        setPendingArchiveAccount(null);
    };

    const handleConfirmArchive = () => {
        if (!pendingArchiveAccount || isSubmittingStatusRef.current) {
            return;
        }

        const targetAccount = pendingArchiveAccount;
        isSubmittingStatusRef.current = true;
        setSubmittingAccountId(targetAccount.id);

        router.post(
            `/accounts/${targetAccount.id}/archive`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    isSubmittingStatusRef.current = false;
                    setSubmittingAccountId(null);
                    setPendingArchiveAccount(null);
                    if (editingAccount?.id === targetAccount.id) {
                        setEditingAccount(null);
                    }
                },
            },
        );
    };

    const handleRestore = (account: FinancialAccountRecord) => {
        if (isSubmittingStatusRef.current) {
            return;
        }

        isSubmittingStatusRef.current = true;
        setSubmittingAccountId(account.id);

        router.post(
            `/accounts/${account.id}/restore`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    isSubmittingStatusRef.current = false;
                    setSubmittingAccountId(null);
                },
            },
        );
    };

    const typeSelectOptions = accountTypes.map((option) => ({
        value: option.value,
        label: option.label,
    }));

    const currencySelectOptions = currencies.map((currency) => ({
        value: currency.code,
        label: `${currency.code} - ${currency.name} (${currency.symbol})`,
    }));

    return (
        <AuthenticatedLayout title="Accounts">
            <div className="space-y-8">
                <PageHeader
                    eyebrow="Financial Foundation"
                    title="Accounts"
                    description="Manage your financial accounts. Balances are derived directly from posted double-entry ledger activity in each account's native currency."
                />

                {flashStatus ? <Alert variant="positive">{flashStatus}</Alert> : null}

                <div className="grid grid-cols-1 gap-8 lg:grid-cols-12">
                    {/* Create or Edit Account Form */}
                    <div className="lg:col-span-5">
                        {editingAccount ? (
                            <Card as="section" aria-labelledby="edit-account-heading" padding="md">
                                <div className="mb-5 flex items-center justify-between gap-2">
                                    <div>
                                        <p className="font-mono text-xs font-medium tracking-wider text-accent uppercase">
                                            Edit Account
                                        </p>
                                        <h2
                                            id="edit-account-heading"
                                            className="mt-1 text-lg font-semibold tracking-tight text-ink"
                                        >
                                            {editingAccount.name}
                                        </h2>
                                    </div>
                                    <Badge variant="neutral">{editingAccount.currency_code}</Badge>
                                </div>

                                <form onSubmit={handleEditSubmit} className="space-y-4" noValidate>
                                    <FormField
                                        id="edit-name"
                                        name="name"
                                        label="Account name"
                                        required
                                        value={editForm.data.name}
                                        onChange={(event) =>
                                            editForm.setData('name', event.target.value)
                                        }
                                        error={editForm.errors.name}
                                    />

                                    <div className="space-y-1.5">
                                        <label
                                            htmlFor="edit-type"
                                            className="block text-sm font-medium text-ink"
                                        >
                                            Account type
                                        </label>
                                        <Select
                                            id="edit-type"
                                            name="type"
                                            value={editForm.data.type}
                                            options={typeSelectOptions}
                                            invalid={Boolean(editForm.errors.type)}
                                            onChange={(event) =>
                                                editForm.setData('type', event.target.value)
                                            }
                                        />
                                        {editForm.errors.type ? (
                                            <p
                                                role="alert"
                                                className="text-xs font-medium text-negative"
                                            >
                                                {editForm.errors.type}
                                            </p>
                                        ) : null}
                                    </div>

                                    <div className="space-y-1.5">
                                        <label
                                            htmlFor="edit-currency_code"
                                            className="block text-sm font-medium text-ink"
                                        >
                                            Currency
                                        </label>
                                        <Select
                                            id="edit-currency_code"
                                            name="currency_code"
                                            value={editForm.data.currency_code}
                                            options={currencySelectOptions}
                                            disabled={editingAccount.has_posted_activity}
                                            invalid={Boolean(editForm.errors.currency_code)}
                                            onChange={(event) =>
                                                editForm.setData(
                                                    'currency_code',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        {editingAccount.has_posted_activity ? (
                                            <p className="text-xs text-ink-muted">
                                                Currency is locked because this account has posted
                                                ledger activity.
                                            </p>
                                        ) : null}
                                        {editForm.errors.currency_code ? (
                                            <p
                                                role="alert"
                                                className="text-xs font-medium text-negative"
                                            >
                                                {editForm.errors.currency_code}
                                            </p>
                                        ) : null}
                                    </div>

                                    <FormField
                                        id="edit-institution"
                                        name="institution"
                                        label="Institution (optional)"
                                        value={editForm.data.institution}
                                        onChange={(event) =>
                                            editForm.setData('institution', event.target.value)
                                        }
                                        error={editForm.errors.institution}
                                    />

                                    <div className="space-y-1.5">
                                        <label
                                            htmlFor="edit-description"
                                            className="block text-sm font-medium text-ink"
                                        >
                                            Description (optional)
                                        </label>
                                        <Textarea
                                            id="edit-description"
                                            name="description"
                                            rows={3}
                                            value={editForm.data.description}
                                            invalid={Boolean(editForm.errors.description)}
                                            onChange={(event) =>
                                                editForm.setData('description', event.target.value)
                                            }
                                        />
                                        {editForm.errors.description ? (
                                            <p
                                                role="alert"
                                                className="text-xs font-medium text-negative"
                                            >
                                                {editForm.errors.description}
                                            </p>
                                        ) : null}
                                    </div>

                                    <div className="flex items-center gap-3 pt-2">
                                        <Button
                                            type="submit"
                                            loading={editForm.processing}
                                            className="flex-1"
                                        >
                                            Save changes
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={cancelEditing}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </form>
                            </Card>
                        ) : (
                            <Card
                                as="section"
                                aria-labelledby="create-account-heading"
                                padding="md"
                            >
                                <div className="mb-5">
                                    <p className="font-mono text-xs font-medium tracking-wider text-accent uppercase">
                                        New Account
                                    </p>
                                    <h2
                                        id="create-account-heading"
                                        className="mt-1 text-lg font-semibold tracking-tight text-ink"
                                    >
                                        Add a financial account
                                    </h2>
                                    <p className="mt-1 text-xs leading-relaxed text-ink-muted">
                                        New accounts start with a zero ledger-derived balance.
                                    </p>
                                </div>

                                <form
                                    onSubmit={handleCreateSubmit}
                                    className="space-y-4"
                                    noValidate
                                >
                                    <FormField
                                        id="create-name"
                                        name="name"
                                        label="Account name"
                                        placeholder="e.g. Primary Checking"
                                        required
                                        value={createForm.data.name}
                                        onChange={(event) =>
                                            createForm.setData('name', event.target.value)
                                        }
                                        error={createForm.errors.name}
                                    />

                                    <div className="space-y-1.5">
                                        <label
                                            htmlFor="create-type"
                                            className="block text-sm font-medium text-ink"
                                        >
                                            Account type
                                        </label>
                                        <Select
                                            id="create-type"
                                            name="type"
                                            value={createForm.data.type}
                                            options={typeSelectOptions}
                                            invalid={Boolean(createForm.errors.type)}
                                            onChange={(event) =>
                                                createForm.setData('type', event.target.value)
                                            }
                                        />
                                        {createForm.errors.type ? (
                                            <p
                                                role="alert"
                                                className="text-xs font-medium text-negative"
                                            >
                                                {createForm.errors.type}
                                            </p>
                                        ) : null}
                                    </div>

                                    <div className="space-y-1.5">
                                        <label
                                            htmlFor="create-currency_code"
                                            className="block text-sm font-medium text-ink"
                                        >
                                            Currency
                                        </label>
                                        <Select
                                            id="create-currency_code"
                                            name="currency_code"
                                            value={createForm.data.currency_code}
                                            options={currencySelectOptions}
                                            invalid={Boolean(createForm.errors.currency_code)}
                                            onChange={(event) =>
                                                createForm.setData(
                                                    'currency_code',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        {createForm.errors.currency_code ? (
                                            <p
                                                role="alert"
                                                className="text-xs font-medium text-negative"
                                            >
                                                {createForm.errors.currency_code}
                                            </p>
                                        ) : null}
                                    </div>

                                    <FormField
                                        id="create-institution"
                                        name="institution"
                                        label="Institution (optional)"
                                        placeholder="e.g. BPI, Chase, Wise"
                                        value={createForm.data.institution}
                                        onChange={(event) =>
                                            createForm.setData('institution', event.target.value)
                                        }
                                        error={createForm.errors.institution}
                                    />

                                    <div className="space-y-1.5">
                                        <label
                                            htmlFor="create-description"
                                            className="block text-sm font-medium text-ink"
                                        >
                                            Description (optional)
                                        </label>
                                        <Textarea
                                            id="create-description"
                                            name="description"
                                            rows={3}
                                            placeholder="Optional notes about how this account is used."
                                            value={createForm.data.description}
                                            invalid={Boolean(createForm.errors.description)}
                                            onChange={(event) =>
                                                createForm.setData(
                                                    'description',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        {createForm.errors.description ? (
                                            <p
                                                role="alert"
                                                className="text-xs font-medium text-negative"
                                            >
                                                {createForm.errors.description}
                                            </p>
                                        ) : null}
                                    </div>

                                    <Button type="submit" fullWidth loading={createForm.processing}>
                                        Create account
                                    </Button>
                                </form>
                            </Card>
                        )}
                    </div>

                    {/* Account Listing */}
                    <div className="space-y-6 lg:col-span-7">
                        <section aria-labelledby="active-accounts-heading" className="space-y-4">
                            <div className="flex items-center justify-between">
                                <h2
                                    id="active-accounts-heading"
                                    className="text-base font-semibold tracking-tight text-ink"
                                >
                                    Active Accounts ({activeAccounts.length})
                                </h2>
                            </div>

                            {activeAccounts.length === 0 ? (
                                <EmptyState
                                    eyebrow="No Active Accounts"
                                    title="You have not added any active accounts yet."
                                    description="Create your first checking, savings, cash, or credit card account using the form to establish its corresponding ledger account."
                                />
                            ) : (
                                <div className="space-y-3">
                                    {activeAccounts.map((account) => (
                                        <Card key={account.id} padding="sm">
                                            <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                                                <div className="min-w-0 flex-1 space-y-1.5">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <h3 className="text-base font-semibold text-ink">
                                                            {account.name}
                                                        </h3>
                                                        <Badge variant="accent">
                                                            {account.type_label}
                                                        </Badge>
                                                        <Badge variant="neutral">
                                                            {account.currency_code}
                                                        </Badge>
                                                        {account.balance.is_liability ? (
                                                            <Badge variant="warning">
                                                                Liability
                                                            </Badge>
                                                        ) : null}
                                                    </div>

                                                    {account.institution ? (
                                                        <p className="text-xs font-medium text-ink-secondary">
                                                            {account.institution}
                                                        </p>
                                                    ) : null}

                                                    {account.description ? (
                                                        <p className="text-xs leading-relaxed text-ink-muted">
                                                            {account.description}
                                                        </p>
                                                    ) : null}
                                                </div>

                                                <div className="flex flex-wrap items-center justify-between gap-4 border-t border-border-subtle pt-3 sm:flex-col sm:items-end sm:border-t-0 sm:pt-0">
                                                    <div className="text-left sm:text-right">
                                                        <p className="font-mono text-xs text-ink-muted">
                                                            {account.balance.is_liability
                                                                ? 'Liability Balance'
                                                                : 'Ledger Balance'}
                                                        </p>
                                                        <p
                                                            className="mt-0.5 font-mono text-lg font-semibold text-ink tabular-nums"
                                                            data-testid={`account-balance-${account.id}`}
                                                        >
                                                            {account.balance.formatted}
                                                        </p>
                                                    </div>

                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            icon={<PencilIcon />}
                                                            disabled={submittingAccountId !== null}
                                                            onClick={() => startEditing(account)}
                                                        >
                                                            Edit
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            variant="warning-outline"
                                                            size="sm"
                                                            icon={<ArchiveIcon />}
                                                            loading={
                                                                submittingAccountId === account.id
                                                            }
                                                            disabled={
                                                                submittingAccountId !== null &&
                                                                submittingAccountId !== account.id
                                                            }
                                                            onClick={() =>
                                                                openArchiveDialog(account)
                                                            }
                                                        >
                                                            Archive
                                                        </Button>
                                                    </div>
                                                </div>
                                            </div>
                                        </Card>
                                    ))}
                                </div>
                            )}
                        </section>

                        {archivedAccounts.length > 0 ? (
                            <>
                                <Separator />
                                <section
                                    aria-labelledby="archived-accounts-heading"
                                    className="space-y-4"
                                >
                                    <div className="flex items-center justify-between">
                                        <h2
                                            id="archived-accounts-heading"
                                            className="text-base font-semibold tracking-tight text-ink-secondary"
                                        >
                                            Archived Accounts ({archivedAccounts.length})
                                        </h2>
                                    </div>

                                    <div className="space-y-3">
                                        {archivedAccounts.map((account) => (
                                            <Card
                                                key={account.id}
                                                padding="sm"
                                                className="border-border-subtle bg-surface-muted/60"
                                            >
                                                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                                                    <div className="min-w-0 flex-1 space-y-1.5">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <h3 className="text-base font-semibold text-ink">
                                                                {account.name}
                                                            </h3>
                                                            <Badge variant="neutral">
                                                                {account.type_label}
                                                            </Badge>
                                                            <Badge variant="neutral">
                                                                {account.currency_code}
                                                            </Badge>
                                                            {account.balance.is_liability ? (
                                                                <Badge variant="warning">
                                                                    Liability
                                                                </Badge>
                                                            ) : null}
                                                            <Badge variant="warning" dot>
                                                                Archived
                                                            </Badge>
                                                        </div>

                                                        {account.institution ? (
                                                            <p className="text-xs font-medium text-ink-secondary">
                                                                {account.institution}
                                                            </p>
                                                        ) : null}

                                                        {account.description ? (
                                                            <p className="text-xs leading-relaxed text-ink-muted">
                                                                {account.description}
                                                            </p>
                                                        ) : null}
                                                    </div>

                                                    <div className="flex flex-wrap items-center justify-between gap-4 border-t border-border-subtle pt-3 sm:flex-col sm:items-end sm:border-t-0 sm:pt-0">
                                                        <div className="text-left sm:text-right">
                                                            <p className="font-mono text-xs text-ink-muted">
                                                                {account.balance.is_liability
                                                                    ? 'Liability Balance'
                                                                    : 'Ledger Balance'}
                                                            </p>
                                                            <p
                                                                className="mt-0.5 font-mono text-lg font-semibold text-ink tabular-nums"
                                                                data-testid={`account-balance-${account.id}`}
                                                            >
                                                                {account.balance.formatted}
                                                            </p>
                                                        </div>

                                                        <div className="flex items-center gap-2">
                                                            <Button
                                                                type="button"
                                                                variant="accent-outline"
                                                                size="sm"
                                                                icon={<RestoreIcon />}
                                                                loading={
                                                                    submittingAccountId ===
                                                                    account.id
                                                                }
                                                                disabled={
                                                                    submittingAccountId !== null &&
                                                                    submittingAccountId !==
                                                                        account.id
                                                                }
                                                                onClick={() =>
                                                                    handleRestore(account)
                                                                }
                                                            >
                                                                Restore
                                                            </Button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </Card>
                                        ))}
                                    </div>
                                </section>
                            </>
                        ) : null}
                    </div>
                </div>
            </div>

            <ConfirmDialog
                open={pendingArchiveAccount !== null}
                title={
                    pendingArchiveAccount
                        ? `Archive ${pendingArchiveAccount.name}?`
                        : 'Archive account?'
                }
                description={
                    <p>
                        Archiving{' '}
                        <span className="font-semibold text-ink">
                            {pendingArchiveAccount?.name}
                        </span>{' '}
                        hides it from your active accounts while preserving its complete ledger
                        history and balance. You can restore this account at any time.
                    </p>
                }
                confirmLabel="Confirm archive"
                cancelLabel="Cancel"
                confirmVariant="warning-outline"
                confirmIcon={<ArchiveIcon />}
                loading={
                    pendingArchiveAccount !== null &&
                    submittingAccountId === pendingArchiveAccount.id
                }
                onConfirm={handleConfirmArchive}
                onCancel={closeArchiveDialog}
            />
        </AuthenticatedLayout>
    );
}
