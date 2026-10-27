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
