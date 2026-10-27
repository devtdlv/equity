<?php

namespace App\Enums;

enum FinancialAccountType: string
{
    case Checking = 'checking';
    case Savings = 'savings';
    case Cash = 'cash';
    case CreditCard = 'credit_card';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Checking => 'Checking',
            self::Savings => 'Savings',
            self::Cash => 'Cash',
            self::CreditCard => 'Credit Card',
            self::Other => 'Other',
        };
    }

    public function ledgerClassification(): LedgerAccountClassification
    {
        return match ($this) {
            self::CreditCard => LedgerAccountClassification::Liability,
            self::Checking, self::Savings, self::Cash, self::Other => LedgerAccountClassification::Asset,
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

    /**
     * @return list<array{value: string, label: string, classification: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'classification' => $type->ledgerClassification()->value,
            ],
            self::cases(),
        );
    }
}
