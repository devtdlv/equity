import { describe, expect, it } from 'vitest';
import { formatPageTitle } from '@/Utilities/title';

describe('formatPageTitle', () => {
    it('returns the application name when no page title is provided', () => {
        expect(formatPageTitle()).toBe('Equity');
        expect(formatPageTitle('')).toBe('Equity');
    });

    it('returns the application name without duplication when title matches appName', () => {
        expect(formatPageTitle('Equity', 'Equity')).toBe('Equity');
    });

    it('formats a distinct page title with the application name', () => {
        expect(formatPageTitle('Overview', 'Equity')).toBe('Overview - Equity');
    });
});
