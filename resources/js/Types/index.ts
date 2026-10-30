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

export interface BudgetCurrencySummary {
    code: string;
    name: string;
    symbol: string;
    minor_unit_exponent: number;
}

export interface BudgetRecord {
    id: number;
    currency_code: string;
    period_start: string;
    period_end: string;
    currency: BudgetCurrencySummary | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface BudgetCategorySpendingRecord {
    category_id: number;
    category_name: string;
    category_is_archived: boolean;
    allocated_minor: number;
    actual_minor: number;
    remaining_minor: number;
    is_over_limit: boolean;
}

export interface BudgetSpendingTotals {
    allocated_minor: number;
    allocated_actual_minor: number;
    unbudgeted_actual_minor: number;
    total_actual_minor: number;
}

export interface BudgetSpendingSummaryRecord {
    budget: BudgetRecord;
    allocations: BudgetCategorySpendingRecord[];
    totals: BudgetSpendingTotals;
}

export interface BudgetsIndexPageProps {
    categories: CategoryRecord[];
    currencies: CurrencyOption[];
    initial_year: number;
    initial_month: number;
}

export type RecurringFrequencyValue =
    | 'weekly'
    | 'biweekly'
    | 'monthly'
    | 'quarterly'
    | 'yearly';

export type RecurringObligationStatusValue = 'active' | 'paused' | 'cancelled';

export type RecurringOccurrenceStatusValue = 'scheduled' | 'skipped' | 'cancelled';

export type RecurringOccurrenceDisplayStatusValue =
    | 'upcoming'
    | 'due'
    | 'overdue'
    | 'partially_paid'
    | 'paid'
    | 'skipped'
    | 'cancelled';

export interface RecurringCurrencySummary {
    code: string;
    name: string;
    symbol: string;
    minor_unit_exponent: number;
}

export interface RecurringObligationRecord {
    id: number;
    idempotency_key: string;
    name: string;
    description: string | null;
    currency_code: string;
    amount_minor: number;
    category_id: number;
    category_type: string;
    financial_account_id: number | null;
    start_date: string;
    frequency: RecurringFrequencyValue;
    interval_count: number;
    anchor_day: number;
    ends_of_month: boolean;
    next_occurrence_date: string | null;
    status: RecurringObligationStatusValue;
    paused_at: string | null;
    cancelled_at: string | null;
    currency: RecurringCurrencySummary | null;
    category: TransactionCategorySummary | null;
    financial_account: TransactionAccountSummary | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface RecurringPaymentLinkRecord {
    id: number;
    occurrence_id: number;
    financial_transaction_id: number;
    amount_minor: number;
    currency_code: string;
    is_active: boolean;
    is_reversed: boolean;
}

export interface RecurringOccurrenceRecord {
    id: number;
    obligation_id: number;
    obligation_name: string | null;
    due_date: string;
    currency_code: string;
    expected_amount_minor: number;
    paid_amount_minor: number;
    remaining_amount_minor: number;
    overpaid_amount_minor: number;
    status: RecurringOccurrenceStatusValue;
    display_status: RecurringOccurrenceDisplayStatusValue;
    is_overdue: boolean;
    is_due_today: boolean;
    is_partially_paid: boolean;
    is_paid: boolean;
    active_slot_key: string | null;
    skipped_at: string | null;
    cancelled_at: string | null;
    active_linked_transaction_ids: number[];
    all_linked_transaction_ids: number[];
    payment_links: RecurringPaymentLinkRecord[];
    currency: RecurringCurrencySummary | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface BillsIndexPageProps {
    categories: CategoryRecord[];
    accounts: TransactionAccountOption[];
    currencies: CurrencyOption[];
    initial_year: number;
    initial_month: number;
    today: string;
}
