<?php

namespace App\Enums;

/**
 * Derived payment/schedule display status for a recurring occurrence.
 * Never persisted; computed from stored status, paid totals, and UTC calendar date.
 */
enum RecurringOccurrenceDisplayStatus: string
{
    case Upcoming = 'upcoming';
    case Due = 'due';
    case Overdue = 'overdue';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases(),
        );
    }
}
