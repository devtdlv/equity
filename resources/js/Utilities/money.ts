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

function addThousandsSeparators(digits: string): string {
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}
