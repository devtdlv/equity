<?php

namespace App\Support;

use App\Enums\SavingsGoalEventType;
use App\Exceptions\LedgerIntegrityException;

/**
 * Read-only checked aggregation of immutable savings goal allocation events.
 */
final class SavingsGoalEventAggregation
{
    /**
     * Net allocated minor units: contributions minus withdrawals.
     *
     * @param  iterable<mixed>  $events  Rows/objects exposing type and amount_minor.
     */
    public static function netAllocatedMinor(iterable $events): int
    {
        $net = 0;

        foreach ($events as $row) {
            $typeValue = is_array($row)
                ? ($row['type'] ?? null)
                : ($row->type ?? null);
            $amountValue = is_array($row)
                ? ($row['amount_minor'] ?? null)
                : ($row->amount_minor ?? null);

            $type = $typeValue instanceof SavingsGoalEventType
                ? $typeValue
                : SavingsGoalEventType::from((string) $typeValue);

            $signed = $type->aggregationSign() * (int) $amountValue;
            $net = Money::checkedAdd($net, $signed);
        }

        if ($net < 0) {
            throw new LedgerIntegrityException(
                'Savings goal net allocation integrity failure: negative aggregate.',
            );
        }

        return $net;
    }
}
