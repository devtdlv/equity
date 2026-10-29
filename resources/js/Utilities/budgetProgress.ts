export type UtilizationKind = 'unset_limit' | 'zero_limit' | 'ratio';

export interface UtilizationResult {
    kind: UtilizationKind;
    /**
     * Integer percent of allocated spent when kind === 'ratio'.
     * Null when a ratio cannot be computed without dividing by zero.
     */
    percent: number | null;
    isOverLimit: boolean;
}

/**
 * Compute category utilization without floating-point money math or dividing by zero.
 *
 * - allocated === 0 and actual === 0 → zero_limit, not over
 * - allocated === 0 and actual > 0 → zero_limit, over
 * - allocated > 0 → ratio via integer (actual * 100) / allocated
 */
export function computeUtilization(
    allocatedMinor: number | bigint,
    actualMinor: number | bigint,
    isOverLimitFromApi?: boolean,
): UtilizationResult {
    const allocated = typeof allocatedMinor === 'bigint' ? allocatedMinor : BigInt(allocatedMinor);
    const actual = typeof actualMinor === 'bigint' ? actualMinor : BigInt(actualMinor);

    if (allocated === 0n) {
        return {
            kind: 'zero_limit',
            percent: null,
            isOverLimit: isOverLimitFromApi ?? actual > 0n,
        };
    }

    const percent = Number((actual * 100n) / allocated);

    return {
        kind: 'ratio',
        percent,
        isOverLimit: isOverLimitFromApi ?? actual > allocated,
    };
}

/**
 * Remaining allocated budget from authoritative totals (integer minor units).
 */
export function remainingAllocatedMinor(
    allocatedMinor: number | bigint,
    allocatedActualMinor: number | bigint,
): bigint {
    const allocated = typeof allocatedMinor === 'bigint' ? allocatedMinor : BigInt(allocatedMinor);
    const actual =
        typeof allocatedActualMinor === 'bigint'
            ? allocatedActualMinor
            : BigInt(allocatedActualMinor);

    return allocated - actual;
}

/**
 * Clamped progress width for accessible progress bars (0–100).
 * Zero-limit with positive spend returns 100 for visual over-limit state.
 */
export function progressBarPercent(result: UtilizationResult): number {
    if (result.kind === 'zero_limit') {
        return result.isOverLimit ? 100 : 0;
    }

    if (result.percent === null) {
        return 0;
    }

    if (result.percent < 0) {
        return 0;
    }

    if (result.percent > 100) {
        return 100;
    }

    return result.percent;
}
