export interface AppMetadata {
    name: string;
}

export interface AuthenticatedUser {
    id: number;
    name: string;
    email: string;
}

export interface AuthContext {
    user: AuthenticatedUser | null;
}

export interface FlashMessages {
    status?: string | null;
}

export interface SharedPageProps {
    app?: AppMetadata;
    auth?: AuthContext;
    flash?: FlashMessages;
    [key: string]: unknown;
}

export interface WelcomePageProps {
    productName?: string;
    tagline?: string;
    description?: string;
    status?: string;
}

export interface DashboardPageProps {
    status?: string;
}

export interface ResetPasswordPageProps {
    token: string;
    email?: string;
}

export interface CurrencyOption {
    code: string;
    name: string;
    symbol: string;
    minor_unit_exponent: number;
}

export interface AccountTypeOption {
    value: string;
    label: string;
    classification: 'asset' | 'liability';
}

export interface AccountBalanceSummary {
    amount_minor: number;
    currency_code: string;
    exponent: number;
    symbol: string;
    formatted: string;
    is_liability: boolean;
}

export interface FinancialAccountRecord {
    id: number;
    name: string;
    type: string;
    type_label: string;
    classification: 'asset' | 'liability';
    currency_code: string;
    institution: string | null;
    description: string | null;
    is_archived: boolean;
    archived_at: string | null;
    has_posted_activity: boolean;
    balance: AccountBalanceSummary;
}

export interface AccountsIndexPageProps {
    accounts: FinancialAccountRecord[];
    currencies: CurrencyOption[];
    accountTypes: AccountTypeOption[];
}

export type TransactionTypeValue = 'income' | 'expense' | 'transfer';
export type CategoryTypeValue = 'income' | 'expense';

export interface CategoryRecord {
    id: number;
    name: string;
    type: CategoryTypeValue;
    type_label: string;
    classification: 'income' | 'expense';
    is_archived: boolean;
    archived_at: string | null;
}

export interface TransactionAccountOption {
    id: number;
    name: string;
    type: string;
    type_label: string;
    classification: 'asset' | 'liability';
    currency_code: string;
    currency_symbol: string;
    minor_unit_exponent: number;
    is_archived: boolean;
}

export interface TransactionAccountSummary {
    id: number;
    name: string;
    type: string;
    type_label: string;
    currency_code: string;
    is_archived: boolean;
}

export interface TransactionCategorySummary {
    id: number;
    name: string;
    type: CategoryTypeValue;
    type_label: string;
    is_archived: boolean;
}

export interface FinancialTransactionItem {
    id: number;
    journal_entry_id: number;
    type: TransactionTypeValue;
    type_label: string;
    is_reversal: boolean;
    is_reversed: boolean;
    reversed_transaction_id: number | null;
    reversal_transaction_id: number | null;
    reversal_reason: string | null;
    entry_date: string;
    description: string;
    notes: string | null;
    currency_code: string;
    amount_minor: number;
    amount_formatted: string;
    financial_account: TransactionAccountSummary | null;
    transfer_financial_account: TransactionAccountSummary | null;
    category: TransactionCategorySummary | null;
    created_at: string | null;
}

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface TransactionFiltersState {
    type: TransactionTypeValue | null;
    financial_account_id: number | null;
    category_id: number | null;
    start_date: string | null;
    end_date: string | null;
}

export interface TransactionsIndexPageProps {
    transactions: FinancialTransactionItem[];
    meta: PaginationMeta;
    filters: TransactionFiltersState;
    accounts: TransactionAccountOption[];
    categories: CategoryRecord[];
    currencies: CurrencyOption[];
}
