/**
 * Formats an exact minor-unit integer amount using BigInt integer division and remainder.
 * Never uses binary floating-point arithmetic.
 */
export function formatMinorUnits(
    amountMinor: number | bigint | string,
    currencyCode: string,
    exponent: number = 2,
    symbol: string = '',
): string {
    const normalizedAmount =
        typeof amountMinor === 'bigint' ? amountMinor : BigInt(Math.trunc(Number(amountMinor)));
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
 * Converts an exact minor-unit integer into a decimal string for form inputs.
 * Uses BigInt only; never rounds or uses floating-point arithmetic.
 */
export function minorUnitsToDecimalString(
    amountMinor: number | bigint | string,
    exponent: number = 2,
): string {
    const normalizedAmount =
        typeof amountMinor === 'bigint' ? amountMinor : BigInt(Math.trunc(Number(amountMinor)));
    const isNegative = normalizedAmount < 0n;
    const absoluteAmount = isNegative ? -normalizedAmount : normalizedAmount;
    const safeExponent = Math.max(0, Math.min(6, Math.trunc(exponent)));

    if (safeExponent === 0) {
        return `${isNegative ? '-' : ''}${absoluteAmount.toString()}`;
    }

    const divisor = 10n ** BigInt(safeExponent);
    const majorUnits = absoluteAmount / divisor;
    const minorUnits = absoluteAmount % divisor;

    return `${isNegative ? '-' : ''}${majorUnits.toString()}.${minorUnits
        .toString()
        .padStart(safeExponent, '0')}`;
}

function addThousandsSeparators(digits: string): string {
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/**
 * Validates an optional signed major-unit decimal string against a currency exponent.
 *
 * Empty / whitespace-only input is allowed (optional create-account field; callers must
 * submit trimmed `''` so FinancialAccountService skips opening posting). When present,
 * the value must match MoneyInputParser::parseSignedToMinorUnits canonical form after
 * trim: optional ASCII minus, digits, optional '.' with at most `exponent` fractional
 * digits (no '.' when exponent is 0). No Number()/parseFloat conversion.
 *
 * Magnitude against Money::MAX_MINOR_AMOUNT is intentionally left to the backend.
 *
 * @returns null when valid; otherwise a field-level error message.
 */
export function validateOptionalSignedDecimalInput(
    input: string,
    exponent: number,
): string | null {
    const safeExponent = Math.trunc(exponent);

    if (!Number.isInteger(safeExponent) || safeExponent < 0 || safeExponent > 6) {
        return 'Selected currency precision is unavailable.';
    }

    const trimmed = input.trim();

    if (trimmed === '') {
        return null;
    }

    // Mirror MoneyInputParser: length is evaluated on the post-trim canonical body for
    // values that will be submitted after the create form normalizes with trim().
    if (trimmed.length > 64) {
        return 'Monetary amount input exceeds maximum permitted length.';
    }

    let body = trimmed;

    if (body.startsWith('-')) {
        body = body.slice(1);

        if (body === '') {
            return safeExponent === 0
                ? 'Monetary amount must be a valid signed whole number.'
                : 'Monetary amount must be a valid signed number.';
        }
    }

    if (safeExponent === 0) {
        if (body.includes('.')) {
            return 'Currency does not support fractional minor units.';
        }

        if (!/^[0-9]+$/.test(body)) {
            return 'Monetary amount must be a valid signed whole number.';
        }

        return null;
    }

    if (!/^[0-9]+(?:\.[0-9]+)?$/.test(body)) {
        return 'Monetary amount must be a valid signed number.';
    }

    const dot = body.indexOf('.');
    const fractional = dot === -1 ? '' : body.slice(dot + 1);

    if (fractional.length > safeExponent) {
        return `Monetary amount cannot have more than ${safeExponent} decimal places.`;
    }

    return null;
}
