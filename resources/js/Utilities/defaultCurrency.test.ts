import { describe, expect, it } from 'vitest';
import {
    activeAccountCurrencyCodes,
    readCurrencyCodeFromUrl,
    resolveDefaultCurrencyCode,
    withCurrencyCodeInUrl,
} from '@/Utilities/defaultCurrency';

describe('resolveDefaultCurrencyCode', () => {
    const catalog = ['AUD', 'EUR', 'GBP', 'JPY', 'PHP', 'USD'];

    it('prefers the alphabetically first active-account currency present in the catalog', () => {
        expect(
            resolveDefaultCurrencyCode({
                catalogCodes: catalog,
                accountCurrencyCodes: ['USD', 'PHP', 'USD'],
            }),
        ).toBe('PHP');
    });

    it('ignores archived-only account currencies when none are active', () => {
        expect(
            resolveDefaultCurrencyCode({
                catalogCodes: catalog,
                accountCurrencyCodes: [],
            }),
        ).toBe('AUD');
    });

    it('falls back to the first catalog currency when the user has no accounts', () => {
        expect(
            resolveDefaultCurrencyCode({
                catalogCodes: catalog,
            }),
        ).toBe('AUD');
    });

    it('never selects a currency absent from the active catalog', () => {
        expect(
            resolveDefaultCurrencyCode({
                catalogCodes: catalog,
                accountCurrencyCodes: ['XXX', 'usd'],
            }),
        ).toBe('USD');
    });

    it('respects a valid URL currency over the calculated account default', () => {
        expect(
            resolveDefaultCurrencyCode({
                catalogCodes: catalog,
                accountCurrencyCodes: ['PHP', 'USD'],
                urlCurrencyCode: 'jpy',
            }),
        ).toBe('JPY');
    });

    it('ignores an invalid URL currency and uses the account default', () => {
        expect(
            resolveDefaultCurrencyCode({
                catalogCodes: catalog,
                accountCurrencyCodes: ['PHP', 'USD'],
                urlCurrencyCode: 'XXX',
            }),
        ).toBe('PHP');
    });

    it('falls back to USD when the catalog is empty', () => {
        expect(
            resolveDefaultCurrencyCode({
                catalogCodes: [],
                accountCurrencyCodes: ['PHP'],
            }),
        ).toBe('USD');
    });
});

describe('activeAccountCurrencyCodes', () => {
    it('returns distinct sorted codes from active accounts only', () => {
        expect(
            activeAccountCurrencyCodes([
                { currency_code: 'USD', is_archived: false },
                { currency_code: 'php', is_archived: false },
                { currency_code: 'USD', is_archived: false },
                { currency_code: 'EUR', is_archived: true },
            ]),
        ).toEqual(['PHP', 'USD']);
    });

    it('returns an empty list when only archived accounts exist', () => {
        expect(
            activeAccountCurrencyCodes([{ currency_code: 'PHP', is_archived: true }]),
        ).toEqual([]);
    });
});

describe('URL currency helpers', () => {
    it('reads currency_code from a relative Inertia url', () => {
        expect(readCurrencyCodeFromUrl('/budgets?currency_code=php&x=1')).toBe('PHP');
        expect(readCurrencyCodeFromUrl('/budgets')).toBeNull();
    });

    it('writes currency_code while preserving other query params', () => {
        expect(withCurrencyCodeInUrl('/budgets?foo=1', 'php')).toBe(
            '/budgets?foo=1&currency_code=PHP',
        );
    });
});
