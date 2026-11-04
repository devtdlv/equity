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

export interface DashboardMoneyView {
    amount_minor: string;
    currency_code: string;
    exponent: number;
    symbol: string;
    formatted: string;
}

export interface DashboardCurrencySection {
    currency_code: string;
    position: {
        assets: DashboardMoneyView;
        liabilities: DashboardMoneyView;
        net: DashboardMoneyView;
        active_account_count: number;
        archived_account_count: number;
    } | null;
    cash_flow: {
        income: DashboardMoneyView;
        expenses: DashboardMoneyView;
        net: DashboardMoneyView;
    };
    budget: {
        budget_id: number;
        allocated: DashboardMoneyView;
        spent_allocated: DashboardMoneyView;
        remaining: DashboardMoneyView;
        unbudgeted_spent: DashboardMoneyView;
        total_spent: DashboardMoneyView;
        is_over_limit: boolean;
    } | null;
}

export interface DashboardBillItem {
    occurrence_id: number;
    obligation_name: string;
    due_date: string;
    currency_code: string;
    expected: DashboardMoneyView;
    remaining: DashboardMoneyView;
    paid: DashboardMoneyView;
    display_status: 'overdue' | 'due' | 'upcoming' | 'partially_paid';
    is_overdue: boolean;
}

export interface DashboardPageProps {
    status?: string;
    period: {
        year: number;
        month: number;
        start: string;
        end: string;
        timezone: string;
    };
    currencies: DashboardCurrencySection[];
    bills: {
        items: DashboardBillItem[];
        overdue_count: number;
        upcoming_count: number;
        horizon_days: number;
        display_cap: number;
    };
    savings: {
        portfolio: Array<{
            currency_code: string;
            active_goal_count: number;
            total_target: DashboardMoneyView;
            total_allocated: DashboardMoneyView;
            total_remaining: DashboardMoneyView;
        }>;
        goals: Array<{
            id: number;
            name: string;
            currency_code: string;
            progress_basis_points: number;
            target_reached: boolean;
            allocated: DashboardMoneyView;
            remaining: DashboardMoneyView;
            target: DashboardMoneyView;
        }>;
        earmark_note: string;
        active_goal_count: number;
        archived_goal_count: number;
    };
    empty: {
        has_accounts: boolean;
        has_transactions_this_month: boolean;
        has_budgets_this_month: boolean;
        has_bill_items: boolean;
        has_active_savings_goals: boolean;
    };
    links: {
        accounts: string;
        transactions: string;
        budgets: string;
        bills: string;
        savings: string;
    };
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

export type SavingsGoalStatusValue = 'active' | 'archived';

export type SavingsGoalEventTypeValue = 'contribution' | 'withdrawal';

export type SavingsGoalStatusFilter = 'all' | SavingsGoalStatusValue;

/**
 * Progress summary for a savings goal. Monetary minors are digit strings.
 */
export interface SavingsGoalProgressRecord {
    id: number;
    name: string;
    description: string | null;
    currency_code: string;
    status: SavingsGoalStatusValue;
    target_amount_minor: string;
    allocated_amount_minor: string;
    remaining_amount_minor: string;
    overfunded_amount_minor: string;
    progress_basis_points: number;
    target_reached: boolean;
    target_date: string | null;
    archived_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface SavingsGoalCurrencyPortfolioTotals {
    currency_code: string;
    active_goal_count: number;
    total_target_minor: string;
    total_allocated_minor: string;
    total_remaining_minor: string;
    total_overfunded_minor: string;
}

export interface SavingsGoalPortfolioRecord {
    total_goal_count: number;
    active_goal_count: number;
    archived_goal_count: number;
    active_at_target_count: number;
    active_below_target_count: number;
    currencies: SavingsGoalCurrencyPortfolioTotals[];
}

export interface SavingsGoalEventRecord {
    id: number;
    savings_goal_id: number;
    type: SavingsGoalEventTypeValue;
    amount_minor: string;
    currency_code: string;
    note: string | null;
    created_at: string;
    updated_at: string;
}

export interface SavingsGoalsIndexPageProps {
    currencies: CurrencyOption[];
}
