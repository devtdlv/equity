/**
 * Progress helpers for Savings Goals `progress_basis_points` (0–10000 = 0–100%).
 * Values above 10000 indicate overfunding and must remain visible in text while
 * the visual bar width is clamped to 100%.
 */

export interface SavingsProgressDisplay {
    /** Exact percentage as a number for text (may exceed 100). */
    percentExact: number;
    /** Clamped 0–100 width for the visual progress bar. */
    barPercent: number;
    /** True when progress exceeds 100%. */
    isOverfunded: boolean;
    /** Accessible label describing actual progress. */
    accessibleLabel: string;
}

/**
 * Convert basis points to display percentages without monetary floating-point math.
 * Division uses integer arithmetic for the whole percent and one decimal when needed.
 */
export function savingsProgressFromBasisPoints(basisPoints: number): SavingsProgressDisplay {
    const safePoints = Number.isFinite(basisPoints) ? Math.trunc(basisPoints) : 0;
    const percentExact = safePoints / 100;
    const isOverfunded = safePoints > 10_000;
    const barPercent = Math.max(0, Math.min(100, Math.trunc(safePoints / 100)));

    const labelPercent =
        Number.isInteger(percentExact) || Math.abs(percentExact - Math.round(percentExact)) < 1e-9
            ? String(Math.trunc(percentExact))
            : percentExact.toFixed(2).replace(/\.?0+$/, '');

    return {
        percentExact,
        barPercent: isOverfunded ? 100 : Math.max(0, Math.min(100, barPercent)),
        isOverfunded,
        accessibleLabel: isOverfunded
            ? `Progress ${labelPercent} percent, overfunded`
            : `Progress ${labelPercent} percent`,
    };
}
