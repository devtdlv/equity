/**
 * Precision-safe money helpers for Savings Goals JSON digit-string minor units.
 *
 * Unlike Utilities/money.ts, these never coerce through Number(), so values above
 * Number.MAX_SAFE_INTEGER remain exact.
 */

export class SavingsMoneyFormatError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'SavingsMoneyFormatError';
    }
}

export interface SavingsCurrencyMetadata {
    code: string;
    symbol: string;
    minor_unit_exponent: number;
}

/**
 * Parse a canonical signed decimal digit string (or bigint) into BigInt.
 * Rejects floats, exponents, and empty input.
 */
export function parseMinorUnitString(amountMinor: string | bigint): bigint {
    if (typeof amountMinor === 'bigint') {
        return amountMinor;
    }

    const trimmed = amountMinor.trim();

    if (trimmed === '') {
        throw new SavingsMoneyFormatError('Minor amount string is empty.');
    }

    if (!/^-?\d+$/.test(trimmed)) {
        throw new SavingsMoneyFormatError('Minor amount string must be a signed digit sequence.');
    }

    // Avoid "-0" ambiguity; BigInt('-0') === 0n which is fine.
    return BigInt(trimmed);
}

function addThousandsSeparators(digits: string): string {
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function isUsableExponent(exponent: number): boolean {
    return Number.isInteger(exponent) && exponent >= 0 && exponent <= 6;
}

/**
 * Exact, non-misleading fallback when currency metadata (especially exponent) is unknown.
 * Preserves the original minor-unit digit string — never invents decimal places.
 */
export function formatSavingsMinorUnitsFallback(
    amountMinor: string | bigint,
    currencyCode: string,
): string {
    const code = currencyCode.trim().toUpperCase() || 'UNKNOWN';
    const digits =
        typeof amountMinor === 'bigint' ? amountMinor.toString() : amountMinor.trim();

    // Validate shape without Number coercion; keep the original digit text for display.
    parseMinorUnitString(digits);

    return `${code} ${digits} minor units`;
}

/**
 * Format a minor-unit digit string for display using a known currency exponent and symbol.
 * Uses BigInt integer division only — no floating-point arithmetic.
 *
 * Callers must supply a trusted exponent. Prefer {@link formatSavingsAmount} when
 * metadata may be missing.
 */
export function formatSavingsMinorUnits(
    amountMinor: string | bigint,
    currencyCode: string,
    exponent: number,
    symbol: string = '',
): string {
    if (!isUsableExponent(exponent)) {
        return formatSavingsMinorUnitsFallback(amountMinor, currencyCode);
    }

    const normalizedAmount = parseMinorUnitString(amountMinor);
    const isNegative = normalizedAmount < 0n;
    const absoluteAmount = isNegative ? -normalizedAmount : normalizedAmount;

    let formattedNumber: string;

    if (exponent === 0) {
        formattedNumber = addThousandsSeparators(absoluteAmount.toString());
    } else {
        const divisor = 10n ** BigInt(exponent);
        const majorUnits = absoluteAmount / divisor;
        const minorUnits = absoluteAmount % divisor;
        const majorPart = addThousandsSeparators(majorUnits.toString());
        const minorPart = minorUnits.toString().padStart(exponent, '0');
        formattedNumber = `${majorPart}.${minorPart}`;
    }

    const prefix = symbol !== '' ? symbol : `${currencyCode.toUpperCase()} `;

    return `${isNegative ? '-' : ''}${prefix}${formattedNumber}`;
}

/**
 * Format using optional currency metadata. Missing or invalid metadata never invents
 * an exponent or symbol — the exact minor-unit digit string is shown instead.
 */
export function formatSavingsAmount(
    amountMinor: string | bigint,
    currencyCode: string,
    currency: SavingsCurrencyMetadata | null | undefined,
): string {
    if (!currency || !isUsableExponent(currency.minor_unit_exponent)) {
        return formatSavingsMinorUnitsFallback(amountMinor, currencyCode);
    }

    return formatSavingsMinorUnits(
        amountMinor,
        currency.code.trim() !== '' ? currency.code : currencyCode,
        currency.minor_unit_exponent,
        currency.symbol,
    );
}

/**
 * True when the minor-unit digit string is non-zero (exact BigInt comparison).
 */
export function isNonZeroMinorString(amountMinor: string | bigint): boolean {
    try {
        return parseMinorUnitString(amountMinor) !== 0n;
    } catch {
        return false;
    }
}
