<?php

namespace App\Enums;

enum LedgerAccountClassification: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Asset',
            self::Liability => 'Liability',
            self::Equity => 'Equity',
            self::Income => 'Income',
            self::Expense => 'Expense',
        };
    }

    /**
     * Assets and Expenses carry a normal debit balance (debits - credits).
     * Liabilities, Equity, and Income carry a normal credit balance (credits - debits).
     */
    public function isDebitNormal(): bool
    {
        return match ($this) {
            self::Asset, self::Expense => true,
            self::Liability, self::Equity, self::Income => false,
        };
    }
}
