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

/**
 * Canonical maximum positive minor-unit digit string (Money::MAX_MINOR_AMOUNT).
 * Compared as digit text — never via Number.
 */
export const MAX_SAVINGS_MINOR_AMOUNT_DIGITS = '1000000000000000000';

function isWithinMaxMinorDigits(digits: string): boolean {
    const max = MAX_SAVINGS_MINOR_AMOUNT_DIGITS;
    return digits.length < max.length || (digits.length === max.length && digits <= max);
}

/**
 * Convert a minor-unit digit string to a major-unit decimal text for form inputs.
 * Uses BigInt only — never Number.
 */
export function minorUnitsToMajorDecimalString(
    amountMinor: string | bigint,
    exponent: number,
): string {
    if (!isUsableExponent(exponent)) {
        throw new SavingsMoneyFormatError('Currency exponent is unavailable for decimal conversion.');
    }

    const normalizedAmount = parseMinorUnitString(amountMinor);
    const isNegative = normalizedAmount < 0n;
    const absoluteAmount = isNegative ? -normalizedAmount : normalizedAmount;

    if (exponent === 0) {
        return `${isNegative ? '-' : ''}${absoluteAmount.toString()}`;
    }

    const divisor = 10n ** BigInt(exponent);
    const majorUnits = absoluteAmount / divisor;
    const minorUnits = absoluteAmount % divisor;

    return `${isNegative ? '-' : ''}${majorUnits.toString()}.${minorUnits
        .toString()
        .padStart(exponent, '0')}`;
}

/**
 * Convert a human-entered major-unit decimal string into a canonical positive
 * minor-unit digit string for Savings Goals mutation APIs.
 *
 * Never uses Number/parseFloat/Math.round. Rejects excess precision, scientific
 * notation, negatives, zero, and values above Money::MAX_MINOR_AMOUNT.
 */
export function decimalMajorToPositiveMinorUnitString(
    majorDecimal: string,
    exponent: number,
): string {
    if (!isUsableExponent(exponent)) {
        throw new SavingsMoneyFormatError('Currency exponent is unavailable for amount conversion.');
    }

    const trimmed = majorDecimal.trim();

    if (trimmed === '') {
        throw new SavingsMoneyFormatError('Enter a target amount.');
    }

    if (/[eE]/.test(trimmed)) {
        throw new SavingsMoneyFormatError('Scientific notation is not allowed.');
    }

    if (trimmed.startsWith('-')) {
        throw new SavingsMoneyFormatError('Amount must be greater than zero.');
    }

    if (trimmed.startsWith('+')) {
        throw new SavingsMoneyFormatError('Amount must be a plain decimal value.');
    }

    // Allow optional leading zeros in the major integer part (e.g. 01.50 → 1.50),
    // but reject malformed tokens, multiple dots, and empty segments.
    if (!/^\d+(\.\d+)?$/.test(trimmed) && !/^\.\d+$/.test(trimmed)) {
        throw new SavingsMoneyFormatError('Amount must be a valid decimal value.');
    }

    let integerPart: string;
    let fractionPart: string;

    if (trimmed.startsWith('.')) {
        integerPart = '0';
        fractionPart = trimmed.slice(1);
    } else {
        const dot = trimmed.indexOf('.');
        if (dot === -1) {
            integerPart = trimmed;
            fractionPart = '';
        } else {
            integerPart = trimmed.slice(0, dot);
            fractionPart = trimmed.slice(dot + 1);
        }
    }

    if (exponent === 0) {
        if (fractionPart !== '' && !/^0+$/.test(fractionPart)) {
            throw new SavingsMoneyFormatError(
                'This currency does not allow decimal places.',
            );
        }
        fractionPart = '';
    } else if (fractionPart.length > exponent) {
        throw new SavingsMoneyFormatError(
            `Amount may have at most ${exponent} decimal place${exponent === 1 ? '' : 's'}.`,
        );
    } else {
        fractionPart = fractionPart.padEnd(exponent, '0');
    }

    const combined = `${integerPart.replace(/^0+(?=\d)/, '') || '0'}${fractionPart}`;
    // Strip leading zeros from the combined minor digit string.
    const canonical = combined.replace(/^0+(?=\d)/, '') || '0';

    if (canonical === '0') {
        throw new SavingsMoneyFormatError('Amount must be greater than zero.');
    }

    if (!/^[1-9]\d*$/.test(canonical)) {
        throw new SavingsMoneyFormatError('Amount must be a positive integer digit string.');
    }

    if (!isWithinMaxMinorDigits(canonical)) {
        throw new SavingsMoneyFormatError('Amount exceeds the supported monetary maximum.');
    }

    return canonical;
}

/**
 * Stable fingerprint of create-form fields used for idempotency key rotation.
 */
export function savingsGoalCreateDraftFingerprint(draft: {
    name: string;
    currency_code: string;
    target_amount: string;
    description: string;
    target_date: string;
}): string {
    return JSON.stringify({
        name: draft.name.trim(),
        currency_code: draft.currency_code.trim().toUpperCase(),
        target_amount: draft.target_amount.trim(),
        description: draft.description.trim(),
        target_date: draft.target_date.trim(),
    });
}
