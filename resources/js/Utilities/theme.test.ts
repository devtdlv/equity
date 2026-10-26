import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    applyThemePreference,
    getStoredThemePreference,
    getSystemTheme,
    initializeTheme,
    resolveTheme,
    setStoredThemePreference,
    THEME_STORAGE_KEY,
    updateThemePreference,
} from '@/Utilities/theme';

function createMatchMediaMock(matchesDark: boolean) {
    const listeners = new Set<(event: MediaQueryListEvent) => void>();

    const mediaQueryList = {
        matches: matchesDark,
        media: '(prefers-color-scheme: dark)',
        onchange: null,
        addEventListener: vi.fn(
            (_event: string, listener: (event: MediaQueryListEvent) => void) => {
                listeners.add(listener);
            },
        ),
        removeEventListener: vi.fn(
            (_event: string, listener: (event: MediaQueryListEvent) => void) => {
                listeners.delete(listener);
            },
        ),
        dispatchChange(nextMatches: boolean) {
            mediaQueryList.matches = nextMatches;
            listeners.forEach((listener) =>
                listener({ matches: nextMatches } as MediaQueryListEvent),
            );
        },
    };

    return mediaQueryList;
}

describe('theme utilities', () => {
    beforeEach(() => {
        window.localStorage.clear();
        document.documentElement.className = '';
        delete document.documentElement.dataset.theme;
        delete document.documentElement.dataset.themePreference;
        document.documentElement.style.colorScheme = '';
    });

    it('defaults to system preference when nothing is stored in localStorage', () => {
        expect(getStoredThemePreference()).toBe('system');
    });

    it('persists and retrieves valid theme preferences from localStorage', () => {
        setStoredThemePreference('dark');
        expect(window.localStorage.getItem(THEME_STORAGE_KEY)).toBe('dark');
        expect(getStoredThemePreference()).toBe('dark');

        setStoredThemePreference('light');
        expect(getStoredThemePreference()).toBe('light');
    });

    it('falls back to system when stored value is invalid or localStorage throws', () => {
        window.localStorage.setItem(THEME_STORAGE_KEY, 'invalid-mode');
        expect(getStoredThemePreference()).toBe('system');

        const getItemSpy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('Storage disabled');
        });
        const setItemSpy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('Storage disabled');
        });

        expect(getStoredThemePreference()).toBe('system');
        expect(setStoredThemePreference('dark')).toBe(false);

        getItemSpy.mockRestore();
        setItemSpy.mockRestore();
    });

    it('detects system theme from matchMedia and resolves system preference', () => {
        const mediaMock = createMatchMediaMock(true);
        vi.stubGlobal(
            'matchMedia',
            vi.fn(() => mediaMock),
        );

        expect(getSystemTheme()).toBe('dark');
        expect(resolveTheme('system')).toBe('dark');
        expect(resolveTheme('light')).toBe('light');
        expect(resolveTheme('dark')).toBe('dark');

        vi.unstubAllGlobals();
    });

    it('applies dark and light classes and attributes to document.documentElement', () => {
        applyThemePreference('dark');
        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(document.documentElement.dataset.theme).toBe('dark');
        expect(document.documentElement.dataset.themePreference).toBe('dark');

        applyThemePreference('light');
        expect(document.documentElement.classList.contains('dark')).toBe(false);
        expect(document.documentElement.dataset.theme).toBe('light');
        expect(document.documentElement.dataset.themePreference).toBe('light');
    });

    it('reacts to system color-scheme changes when preference is set to system', () => {
        const mediaMock = createMatchMediaMock(false);
        vi.stubGlobal(
            'matchMedia',
            vi.fn(() => mediaMock),
        );

        updateThemePreference('system');
        const cleanup = initializeTheme();

        expect(document.documentElement.classList.contains('dark')).toBe(false);

        mediaMock.dispatchChange(true);
        expect(document.documentElement.classList.contains('dark')).toBe(true);

        cleanup();
        vi.unstubAllGlobals();
    });
});
