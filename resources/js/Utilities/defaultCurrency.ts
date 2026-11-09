/**
 * Deterministic default currency selection for create/budget flows.
 *
 * Priority:
 * 1. Valid URL currency (must be in the active catalog)
 * 2. Alphabetically first currency used by active accounts that is also in the catalog
 * 3. First active catalog currency
 * 4. Explicit 'USD' fallback when the catalog is empty
 */
export function resolveDefaultCurrencyCode(options: {
    catalogCodes: readonly string[];
    accountCurrencyCodes?: readonly string[];
    urlCurrencyCode?: string | null;
}): string {
    const catalog = uniqueSorted(
        options.catalogCodes
            .map((code) => code.trim().toUpperCase())
            .filter((code) => code !== ''),
    );
    const catalogSet = new Set(catalog);

    const url = options.urlCurrencyCode?.trim().toUpperCase() ?? '';
    if (url !== '' && catalogSet.has(url)) {
        return url;
    }

    const fromAccounts = uniqueSorted(
        (options.accountCurrencyCodes ?? [])
            .map((code) => code.trim().toUpperCase())
            .filter((code) => code !== '' && catalogSet.has(code)),
    );

    if (fromAccounts.length > 0) {
        return fromAccounts[0];
    }

    if (catalog.length > 0) {
        return catalog[0];
    }

    return 'USD';
}

/**
 * Distinct currency codes from active (non-archived) account records.
 */
export function activeAccountCurrencyCodes(
    accounts: readonly { currency_code: string; is_archived?: boolean }[],
): string[] {
    return uniqueSorted(
        accounts
            .filter((account) => !account.is_archived)
            .map((account) => account.currency_code.trim().toUpperCase())
            .filter((code) => code !== ''),
    );
}

function uniqueSorted(codes: readonly string[]): string[] {
    return [...new Set(codes)].sort((a, b) => a.localeCompare(b));
}

/**
 * Read `currency_code` from a path+query or full URL string.
 */
export function readCurrencyCodeFromUrl(url: string): string | null {
    try {
        const normalized = url.includes('://')
            ? url
            : `http://local.invalid${url.startsWith('/') ? url : `/${url}`}`;
        const value = new URL(normalized).searchParams.get('currency_code');
        if (value === null || value.trim() === '') {
            return null;
        }

        return value.trim().toUpperCase();
    } catch {
        return null;
    }
}

/**
 * Merge `currency_code` into a path+query string without dropping other params.
 */
export function withCurrencyCodeInUrl(url: string, currencyCode: string): string {
    try {
        const hasOrigin = url.includes('://');
        const normalized = hasOrigin
            ? url
            : `http://local.invalid${url.startsWith('/') ? url : `/${url}`}`;
        const parsed = new URL(normalized);
        parsed.searchParams.set('currency_code', currencyCode.trim().toUpperCase());
        if (hasOrigin) {
            return parsed.toString();
        }

        return `${parsed.pathname}${parsed.search}${parsed.hash}`;
    } catch {
        return url;
    }
}
