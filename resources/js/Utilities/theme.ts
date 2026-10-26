export type ThemePreference = 'light' | 'dark' | 'system';
export type ResolvedTheme = 'light' | 'dark';

export const THEME_STORAGE_KEY = 'equity-theme-preference';
export const THEME_CHANGE_EVENT = 'equity:theme-change';

const VALID_PREFERENCES: readonly ThemePreference[] = ['light', 'dark', 'system'];

export function isValidThemePreference(value: unknown): value is ThemePreference {
    return typeof value === 'string' && (VALID_PREFERENCES as readonly string[]).includes(value);
}

/**
 * Safely reads the stored theme preference from localStorage.
 * Falls back to 'system' when localStorage is unavailable or throws.
 */
export function getStoredThemePreference(storageKey: string = THEME_STORAGE_KEY): ThemePreference {
    if (typeof window === 'undefined') {
        return 'system';
    }

    try {
        const stored = window.localStorage?.getItem(storageKey);

        if (isValidThemePreference(stored)) {
            return stored;
        }
    } catch {
        // Ignore storage access errors (private browsing, disabled storage, etc.)
    }

    return 'system';
}

/**
 * Safely persists the theme preference to localStorage.
 */
export function setStoredThemePreference(
    preference: ThemePreference,
    storageKey: string = THEME_STORAGE_KEY,
): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    try {
        window.localStorage?.setItem(storageKey, preference);

        return true;
    } catch {
        return false;
    }
}

/**
 * Detects the operating system theme via matchMedia('(prefers-color-scheme: dark)').
 * Defaults to 'light' if matchMedia is unavailable.
 */
export function getSystemTheme(): ResolvedTheme {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
        return 'light';
    }

    try {
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    } catch {
        return 'light';
    }
}

/**
 * Resolves a ThemePreference ('light' | 'dark' | 'system') to an active theme ('light' | 'dark').
 */
export function resolveTheme(
    preference: ThemePreference,
    systemTheme: ResolvedTheme = getSystemTheme(),
): ResolvedTheme {
    if (preference === 'system') {
        return systemTheme;
    }

    return preference;
}

/**
 * Applies the resolved theme to document.documentElement and notifies listeners.
 */
export function applyThemePreference(preference: ThemePreference): ResolvedTheme {
    const resolved = resolveTheme(preference);

    if (typeof document !== 'undefined' && document.documentElement) {
        const root = document.documentElement;
        root.classList.toggle('dark', resolved === 'dark');
        root.dataset.theme = resolved;
        root.dataset.themePreference = preference;
        root.style.colorScheme = resolved;
    }

    return resolved;
}

/**
 * Persists and applies a new theme preference, dispatching a synchronization event
 * so all mounted ThemeToggle instances stay in sync.
 */
export function updateThemePreference(preference: ThemePreference): ResolvedTheme {
    setStoredThemePreference(preference);
    const resolved = applyThemePreference(preference);

    if (typeof window !== 'undefined' && typeof window.dispatchEvent === 'function') {
        window.dispatchEvent(
            new CustomEvent<{ preference: ThemePreference; resolved: ResolvedTheme }>(
                THEME_CHANGE_EVENT,
                {
                    detail: { preference, resolved },
                },
            ),
        );
    }

    return resolved;
}

/**
 * Initializes the document theme on application boot and attaches a system preference listener.
 */
export function initializeTheme(): () => void {
    const initialPreference = getStoredThemePreference();
    applyThemePreference(initialPreference);

    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
        return () => undefined;
    }

    let mediaQuery: MediaQueryList | null = null;

    try {
        mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
    } catch {
        return () => undefined;
    }

    const handleSystemChange = () => {
        const currentPreference = getStoredThemePreference();
        if (currentPreference === 'system') {
            const resolved = applyThemePreference('system');
            window.dispatchEvent(
                new CustomEvent<{ preference: ThemePreference; resolved: ResolvedTheme }>(
                    THEME_CHANGE_EVENT,
                    {
                        detail: { preference: 'system', resolved },
                    },
                ),
            );
        }
    };

    if (typeof mediaQuery.addEventListener === 'function') {
        mediaQuery.addEventListener('change', handleSystemChange);

        return () => mediaQuery?.removeEventListener('change', handleSystemChange);
    }

    return () => undefined;
}
