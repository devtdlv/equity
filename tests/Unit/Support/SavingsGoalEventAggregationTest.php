<?php

use App\Enums\SavingsGoalEventType;
use App\Exceptions\LedgerIntegrityException;
use App\Support\SavingsGoalEventAggregation;

test('event aggregation nets contributions and withdrawals with checked arithmetic', function (): void {
    $net = SavingsGoalEventAggregation::netAllocatedMinor([
        (object) ['type' => SavingsGoalEventType::Contribution, 'amount_minor' => 500],
        (object) ['type' => 'withdrawal', 'amount_minor' => 200],
        ['type' => SavingsGoalEventType::Contribution, 'amount_minor' => 50],
    ]);

    expect($net)->toBe(350);
});

test('event aggregation rejects a negative net', function (): void {
    expect(fn () => SavingsGoalEventAggregation::netAllocatedMinor([
        (object) ['type' => SavingsGoalEventType::Withdrawal, 'amount_minor' => 1],
    ]))->toThrow(LedgerIntegrityException::class, 'negative aggregate');
});
