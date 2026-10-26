import { useCallback, useEffect, useState } from 'react';
import {
    applyThemePreference,
    getStoredThemePreference,
    type ResolvedTheme,
    THEME_CHANGE_EVENT,
    type ThemePreference,
    updateThemePreference,
} from '@/Utilities/theme';

export interface UseThemeResult {
    preference: ThemePreference;
    resolvedTheme: ResolvedTheme;
    setPreference: (next: ThemePreference) => void;
}

export function useTheme(): UseThemeResult {
    const [preference, setPreferenceState] = useState<ThemePreference>(() =>
        getStoredThemePreference(),
    );
    const [resolvedTheme, setResolvedThemeState] = useState<ResolvedTheme>(() =>
        applyThemePreference(getStoredThemePreference()),
    );

    useEffect(() => {
        const currentPreference = getStoredThemePreference();
        const currentResolved = applyThemePreference(currentPreference);
        setPreferenceState(currentPreference);
        setResolvedThemeState(currentResolved);

        const handleThemeEvent = (event: Event) => {
            const customEvent = event as CustomEvent<{
                preference: ThemePreference;
                resolved: ResolvedTheme;
            }>;

            if (customEvent.detail) {
                setPreferenceState(customEvent.detail.preference);
                setResolvedThemeState(customEvent.detail.resolved);
            } else {
                const stored = getStoredThemePreference();
                setPreferenceState(stored);
                setResolvedThemeState(applyThemePreference(stored));
            }
        };

        let mediaQuery: MediaQueryList | null = null;
        const handleMediaChange = () => {
            const stored = getStoredThemePreference();
            if (stored === 'system') {
                setResolvedThemeState(applyThemePreference('system'));
            }
        };

        window.addEventListener(THEME_CHANGE_EVENT, handleThemeEvent);

        if (typeof window.matchMedia === 'function') {
            try {
                mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
                if (typeof mediaQuery.addEventListener === 'function') {
                    mediaQuery.addEventListener('change', handleMediaChange);
                }
            } catch {
                mediaQuery = null;
            }
        }

        return () => {
            window.removeEventListener(THEME_CHANGE_EVENT, handleThemeEvent);
            if (mediaQuery && typeof mediaQuery.removeEventListener === 'function') {
                mediaQuery.removeEventListener('change', handleMediaChange);
            }
        };
    }, []);

    const setPreference = useCallback((next: ThemePreference) => {
        const resolved = updateThemePreference(next);
        setPreferenceState(next);
        setResolvedThemeState(resolved);
    }, []);

    return {
        preference,
        resolvedTheme,
        setPreference,
    };
}
