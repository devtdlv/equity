export type BudgetWarningKind = 'budget' | 'category' | null;

/**
 * Derive dashboard budget warning copy without changing accounting semantics.
 *
 * `isOverLimit` remains the authoritative OR of aggregate-over and any-category-over.
 * Aggregate comparison uses integer minor units from the overview payload.
 */
export function resolveBudgetWarningKind(options: {
    isOverLimit: boolean;
    allocatedMinor: string | number | bigint;
    spentAllocatedMinor: string | number | bigint;
}): BudgetWarningKind {
    if (!options.isOverLimit) {
        return null;
    }

    const allocated = toBigInt(options.allocatedMinor);
    const spent = toBigInt(options.spentAllocatedMinor);

    if (spent > allocated) {
        return 'budget';
    }

    return 'category';
}

export function budgetWarningLabel(kind: BudgetWarningKind): string | null {
    if (kind === 'budget') {
        return 'Budget over limit';
    }

    if (kind === 'category') {
        return 'Category over limit';
    }

    return null;
}

function toBigInt(value: string | number | bigint): bigint {
    if (typeof value === 'bigint') {
        return value;
    }

    if (typeof value === 'number') {
        return BigInt(value);
    }

    return BigInt(value);
}
