<?php

namespace App\Enums;

enum SavingsGoalEventType: string
{
    case Contribution = 'contribution';
    case Withdrawal = 'withdrawal';

    /**
     * Sign applied when aggregating net allocated minor units.
     * Stored amount_minor is always positive; this sign is derived.
     */
    public function aggregationSign(): int
    {
        return match ($this) {
            self::Contribution => 1,
            self::Withdrawal => -1,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            self::cases(),
        );
    }
}
