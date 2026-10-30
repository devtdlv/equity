<?php

namespace App\Enums;

enum RecurringOccurrenceStatus: string
{
    case Scheduled = 'scheduled';
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
