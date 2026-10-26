export const DEFAULT_APP_NAME = 'Equity';

/**
 * Formats an Inertia page title consistently with the application name.
 */
export function formatPageTitle(title?: string, appName: string = DEFAULT_APP_NAME): string {
    const trimmedTitle = title?.trim();

    if (!trimmedTitle || trimmedTitle === appName) {
        return appName;
    }

    return `${trimmedTitle} - ${appName}`;
}
