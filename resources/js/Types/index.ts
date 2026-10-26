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
