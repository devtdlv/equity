export interface AppMetadata {
    name: string;
}

export interface SharedPageProps {
    app?: AppMetadata;
    [key: string]: unknown;
}

export interface WelcomePageProps {
    productName?: string;
    tagline?: string;
    description?: string;
    status?: string;
}
