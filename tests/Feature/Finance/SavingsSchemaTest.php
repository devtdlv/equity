<?php

use App\Enums\SavingsGoalEventType;
use App\Enums\SavingsGoalStatus;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\FinancialTransaction;
use App\Models\JournalEntry;
use App\Models\JournalPosting;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalEvent;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\CurrencySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
});

/**
 * @return array{user: User, otherUser: User}
 */
function savingsSchemaFixtures(): array
{
    return [
        'user' => User::factory()->create(),
        'otherUser' => User::factory()->create(),
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function createSavingsGoal(User $user, array $overrides = []): SavingsGoal
{
    return SavingsGoal::query()->create(array_merge([
        'user_id' => $user->id,
        'idempotency_key' => (string) Str::uuid(),
        'create_request_fingerprint' => hash('sha256', 'schema-test-fixture'),
        'name' => 'Emergency Fund',
        'description' => null,
        'currency_code' => 'PHP',
        'target_amount_minor' => 100_000_00,
        'target_date' => null,
        'status' => SavingsGoalStatus::Active,
        'archived_at' => null,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function createSavingsEvent(User $user, SavingsGoal $goal, array $overrides = []): SavingsGoalEvent
{
    return SavingsGoalEvent::query()->create(array_merge([
        'user_id' => $user->id,
        'savings_goal_id' => $goal->id,
        'idempotency_key' => (string) Str::uuid(),
        'type' => SavingsGoalEventType::Contribution,
        'amount_minor' => 1_000_00,
        'currency_code' => $goal->currency_code,
        'note' => null,
    ], $overrides));
}

test('phpunit testing database is isolated sqlite memory', function (): void {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
});

test('savings tables exist with expected columns and no progress or transaction columns', function (): void {
    expect(Schema::hasTable('savings_goals'))->toBeTrue()
        ->and(Schema::hasTable('savings_goal_events'))->toBeTrue();

    expect(Schema::hasColumns('savings_goals', [
        'id',
        'user_id',
        'idempotency_key',
        'create_request_fingerprint',
        'name',
        'description',
        'currency_code',
        'target_amount_minor',
        'target_date',
        'status',
        'archived_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasColumn('savings_goals', 'completed_at'))->toBeFalse()
        ->and(Schema::hasColumn('savings_goals', 'allocated_amount_minor'))->toBeFalse()
        ->and(Schema::hasColumn('savings_goals', 'progress_percent'))->toBeFalse()
        ->and(Schema::hasColumn('savings_goals', 'financial_account_id'))->toBeFalse()
        ->and(Schema::hasColumn('savings_goals', 'financial_transaction_id'))->toBeFalse();

    expect(Schema::hasColumns('savings_goal_events', [
        'id',
        'user_id',
        'savings_goal_id',
        'idempotency_key',
        'type',
        'amount_minor',
        'currency_code',
        'note',
        'created_at',
        'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasColumn('savings_goal_events', 'financial_transaction_id'))->toBeFalse()
        ->and(Schema::hasColumn('savings_goal_events', 'financial_account_id'))->toBeFalse()
        ->and(Schema::hasColumn('savings_goal_events', 'running_balance_minor'))->toBeFalse();
});

test('default goal status is active and nullable target date is allowed', function (): void {
    $fixtures = savingsSchemaFixtures();
    $goal = createSavingsGoal($fixtures['user'], [
        'target_date' => '2027-12-31',
    ]);

    expect($goal->status)->toBe(SavingsGoalStatus::Active)
        ->and($goal->archived_at)->toBeNull()
        ->and($goal->target_date?->toDateString())->toBe('2027-12-31')
        ->and((int) $goal->target_amount_minor)->toBe(100_000_00);

    $openEnded = createSavingsGoal($fixtures['user'], [
        'name' => 'Open ended',
        'target_date' => null,
    ]);
    expect($openEnded->target_date)->toBeNull();
});

test('goal and event idempotency keys are unique per user but reusable across users', function (): void {
    $fixtures = savingsSchemaFixtures();
    $sharedGoalKey = (string) Str::uuid();
    $sharedEventKey = (string) Str::uuid();

    createSavingsGoal($fixtures['user'], ['idempotency_key' => $sharedGoalKey]);
    createSavingsGoal($fixtures['otherUser'], ['idempotency_key' => $sharedGoalKey]);

    expect(fn () => createSavingsGoal($fixtures['user'], [
        'idempotency_key' => $sharedGoalKey,
        'name' => 'Duplicate',
    ]))->toThrow(QueryException::class);

    $goalA = createSavingsGoal($fixtures['user'], ['name' => 'Goal A']);
    $goalB = createSavingsGoal($fixtures['otherUser'], ['name' => 'Goal B']);

    createSavingsEvent($fixtures['user'], $goalA, ['idempotency_key' => $sharedEventKey]);
    createSavingsEvent($fixtures['otherUser'], $goalB, ['idempotency_key' => $sharedEventKey]);

    expect(fn () => createSavingsEvent($fixtures['user'], $goalA, [
        'idempotency_key' => $sharedEventKey,
        'amount_minor' => 2_000_00,
    ]))->toThrow(QueryException::class);
});

test('event cannot reference another users goal or mismatched currency', function (): void {
    $fixtures = savingsSchemaFixtures();
    $goal = createSavingsGoal($fixtures['user'], ['currency_code' => 'PHP']);

    expect(fn () => DB::table('savings_goal_events')->insert([
        'user_id' => $fixtures['otherUser']->id,
        'savings_goal_id' => $goal->id,
        'idempotency_key' => (string) Str::uuid(),
        'type' => 'contribution',
        'amount_minor' => 1000,
        'currency_code' => 'PHP',
        'note' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('savings_goal_events')->insert([
        'user_id' => $fixtures['user']->id,
        'savings_goal_id' => $goal->id,
        'idempotency_key' => (string) Str::uuid(),
        'type' => 'contribution',
        'amount_minor' => 1000,
        'currency_code' => 'USD',
        'note' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('goal target and event amount reject zero negative and above maximum', function (): void {
    $fixtures = savingsSchemaFixtures();

    expect(fn () => createSavingsGoal($fixtures['user'], [
        'target_amount_minor' => 0,
    ]))->toThrow(LedgerIntegrityException::class);

    expect(fn () => createSavingsGoal($fixtures['user'], [
        'target_amount_minor' => -100,
    ]))->toThrow(LedgerIntegrityException::class);

    expect(fn () => createSavingsGoal($fixtures['user'], [
        'target_amount_minor' => Money::MAX_MINOR_AMOUNT + 1,
    ]))->toThrow(LedgerIntegrityException::class);

    $goal = createSavingsGoal($fixtures['user']);

    expect(fn () => createSavingsEvent($fixtures['user'], $goal, [
        'amount_minor' => 0,
    ]))->toThrow(LedgerIntegrityException::class);

    expect(fn () => createSavingsEvent($fixtures['user'], $goal, [
        'amount_minor' => -50,
    ]))->toThrow(LedgerIntegrityException::class);

    expect(fn () => createSavingsEvent($fixtures['user'], $goal, [
        'amount_minor' => Money::MAX_MINOR_AMOUNT + 1,
    ]))->toThrow(LedgerIntegrityException::class);

    // Raw inserts also hit SQLite CHECK / MariaDB CHECK when model layer is bypassed.
    expect(fn () => DB::table('savings_goals')->insert([
        'user_id' => $fixtures['user']->id,
        'idempotency_key' => (string) Str::uuid(),
        'create_request_fingerprint' => hash('sha256', 'raw-zero'),
        'name' => 'Raw zero',
        'description' => null,
        'currency_code' => 'PHP',
        'target_amount_minor' => 0,
        'target_date' => null,
        'status' => 'active',
        'archived_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('savings_goal_events')->insert([
        'user_id' => $fixtures['user']->id,
        'savings_goal_id' => $goal->id,
        'idempotency_key' => (string) Str::uuid(),
        'type' => 'contribution',
        'amount_minor' => 0,
        'currency_code' => 'PHP',
        'note' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('invalid goal status and event type are rejected', function (): void {
    $fixtures = savingsSchemaFixtures();

    // Eloquent backed-enum casts raise ValueError before the saving hook runs.
    expect(fn () => createSavingsGoal($fixtures['user'], [
        'status' => 'completed',
    ]))->toThrow(ValueError::class);

    expect(fn () => DB::table('savings_goals')->insert([
        'user_id' => $fixtures['user']->id,
        'idempotency_key' => (string) Str::uuid(),
        'create_request_fingerprint' => hash('sha256', 'invalid-status'),
        'name' => 'Invalid status',
        'description' => null,
        'currency_code' => 'PHP',
        'target_amount_minor' => 1000,
        'target_date' => null,
        'status' => 'completed',
        'archived_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    $goal = createSavingsGoal($fixtures['user']);

    expect(fn () => createSavingsEvent($fixtures['user'], $goal, [
        'type' => 'transfer',
    ]))->toThrow(ValueError::class);

    expect(fn () => DB::table('savings_goal_events')->insert([
        'user_id' => $fixtures['user']->id,
        'savings_goal_id' => $goal->id,
        'idempotency_key' => (string) Str::uuid(),
        'type' => 'transfer',
        'amount_minor' => 1000,
        'currency_code' => 'PHP',
        'note' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // Status/timestamp inconsistency is rejected by the model saving hook.
    expect(fn () => createSavingsGoal($fixtures['user'], [
        'status' => SavingsGoalStatus::Archived,
        'archived_at' => null,
    ]))->toThrow(LedgerIntegrityException::class);
});

test('valid contributions and withdrawals insert and events survive archival', function (): void {
    $fixtures = savingsSchemaFixtures();
    $goal = createSavingsGoal($fixtures['user']);

    $contribution = createSavingsEvent($fixtures['user'], $goal, [
        'type' => SavingsGoalEventType::Contribution,
        'amount_minor' => 2_500_00,
        'note' => 'Payday set-aside',
    ]);
    $withdrawal = createSavingsEvent($fixtures['user'], $goal, [
        'type' => SavingsGoalEventType::Withdrawal,
        'amount_minor' => 500_00,
    ]);

    expect($contribution->type)->toBe(SavingsGoalEventType::Contribution)
        ->and($withdrawal->type)->toBe(SavingsGoalEventType::Withdrawal)
        ->and(SavingsGoalEventType::Contribution->aggregationSign())->toBe(1)
        ->and(SavingsGoalEventType::Withdrawal->aggregationSign())->toBe(-1);

    $goal->status = SavingsGoalStatus::Archived;
    $goal->archived_at = now('UTC');
    $goal->save();

    expect(SavingsGoalEvent::query()->where('savings_goal_id', $goal->id)->count())->toBe(2)
        ->and($contribution->fresh())->not->toBeNull()
        ->and($withdrawal->fresh())->not->toBeNull()
        ->and($goal->fresh()->status)->toBe(SavingsGoalStatus::Archived);
});

test('parent goal deletion is restricted when events exist and events are append-only', function (): void {
    $fixtures = savingsSchemaFixtures();
    $goal = createSavingsGoal($fixtures['user']);
    $event = createSavingsEvent($fixtures['user'], $goal);

    expect(fn () => $goal->delete())->toThrow(QueryException::class);

    expect(fn () => $event->update(['note' => 'mutated']))->toThrow(ImmutableRecordException::class);
    expect(fn () => $event->delete())->toThrow(ImmutableRecordException::class);

    expect(SavingsGoalEvent::query()->whereKey($event->id)->exists())->toBeTrue();
});

test('model relationships and casts work and schema ops do not touch the ledger', function (): void {
    $fixtures = savingsSchemaFixtures();
    $txBefore = FinancialTransaction::query()->count();
    $jeBefore = JournalEntry::query()->count();
    $jpBefore = JournalPosting::query()->count();

    $goal = createSavingsGoal($fixtures['user'], [
        'description' => 'Rainy day',
        'target_amount_minor' => 50_000_00,
    ]);
    $event = createSavingsEvent($fixtures['user'], $goal, [
        'amount_minor' => 10_000_00,
    ]);

    expect($goal->user->is($fixtures['user']))->toBeTrue()
        ->and($goal->currency?->code)->toBe('PHP')
        ->and($goal->events)->toHaveCount(1)
        ->and($event->savingsGoal->is($goal))->toBeTrue()
        ->and($event->user->is($fixtures['user']))->toBeTrue()
        ->and($fixtures['user']->savingsGoals)->toHaveCount(1)
        ->and($fixtures['user']->savingsGoalEvents)->toHaveCount(1)
        ->and((int) $goal->target_amount_minor)->toBe(50_000_00)
        ->and((int) $event->amount_minor)->toBe(10_000_00);

    expect(FinancialTransaction::query()->count())->toBe($txBefore)
        ->and(JournalEntry::query()->count())->toBe($jeBefore)
        ->and(JournalPosting::query()->count())->toBe($jpBefore);
});
