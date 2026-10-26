import type { ThemePreference } from '@/Utilities/theme';
import { useTheme } from '@/Utilities/useTheme';

export interface ThemeToggleProps {
    className?: string;
    size?: 'sm' | 'md';
}

const THEME_OPTIONS: ReadonlyArray<{
    value: ThemePreference;
    label: string;
}> = [
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
    { value: 'system', label: 'System' },
];

export function ThemeToggle({ className = '', size = 'sm' }: ThemeToggleProps) {
    const { preference, setPreference } = useTheme();

    const buttonPadding = size === 'sm' ? 'px-2.5 py-1 text-xs' : 'px-3 py-1.5 text-xs';

    return (
        <div
            role="group"
            aria-label="Theme preference"
            className={`inline-flex items-center rounded-lg border border-border-subtle bg-surface-muted p-0.5 ${className}`.trim()}
        >
            {THEME_OPTIONS.map((option) => {
                const isSelected = preference === option.value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={isSelected}
                        onClick={() => setPreference(option.value)}
                        className={`rounded-md font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring/40 ${buttonPadding} ${
                            isSelected
                                ? 'bg-surface-elevated text-ink shadow-2xs'
                                : 'text-ink-muted hover:text-ink'
                        }`.trim()}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
