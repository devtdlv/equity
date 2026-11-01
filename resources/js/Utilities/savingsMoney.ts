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

/**
 * Format a minor-unit digit string for display using currency exponent and symbol.
 * Uses BigInt integer division only — no floating-point arithmetic.
 */
export function formatSavingsMinorUnits(
    amountMinor: string | bigint,
    currencyCode: string,
    exponent: number = 2,
    symbol: string = '',
): string {
    const normalizedAmount = parseMinorUnitString(amountMinor);
    const isNegative = normalizedAmount < 0n;
    const absoluteAmount = isNegative ? -normalizedAmount : normalizedAmount;
    const safeExponent = Math.max(0, Math.min(6, Math.trunc(exponent)));

    let formattedNumber: string;

    if (safeExponent === 0) {
        formattedNumber = addThousandsSeparators(absoluteAmount.toString());
    } else {
        const divisor = 10n ** BigInt(safeExponent);
        const majorUnits = absoluteAmount / divisor;
        const minorUnits = absoluteAmount % divisor;
        const majorPart = addThousandsSeparators(majorUnits.toString());
        const minorPart = minorUnits.toString().padStart(safeExponent, '0');
        formattedNumber = `${majorPart}.${minorPart}`;
    }

    const prefix = symbol !== '' ? symbol : `${currencyCode.toUpperCase()} `;

    return `${isNegative ? '-' : ''}${prefix}${formattedNumber}`;
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
