<?php

namespace App\DataTransferObjects;

/**
 * Read-only financial dashboard overview for Inertia serialization.
 */
final readonly class DashboardOverview
{
    /**
     * @param  array{year: int, month: int, start: string, end: string, timezone: string}  $period
     * @param  list<array<string, mixed>>  $currencies
     * @param  array{
     *     items: list<array<string, mixed>>,
     *     overdue_count: int,
     *     upcoming_count: int,
     *     horizon_days: int,
     *     display_cap: int
     * }  $bills
     * @param  array{
     *     portfolio: list<array<string, mixed>>,
     *     goals: list<array<string, mixed>>,
     *     earmark_note: string,
     *     active_goal_count: int,
     *     archived_goal_count: int
     * }  $savings
     * @param  array{
     *     has_accounts: bool,
     *     has_transactions_this_month: bool,
     *     has_budgets_this_month: bool,
     *     has_bill_items: bool,
     *     has_active_savings_goals: bool
     * }  $empty
     * @param  array{
     *     accounts: string,
     *     transactions: string,
     *     budgets: string,
     *     bills: string,
     *     savings: string
     * }  $links
     */
    public function __construct(
        public array $period,
        public array $currencies,
        public array $bills,
        public array $savings,
        public array $empty,
        public array $links,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toInertiaProps(): array
    {
        return [
            'period' => $this->period,
            'currencies' => $this->currencies,
            'bills' => $this->bills,
            'savings' => $this->savings,
            'empty' => $this->empty,
            'links' => $this->links,
        ];
    }
}
