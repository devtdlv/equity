<?php

use App\DataTransferObjects\AllocateSavingsGoalInput;
use App\DataTransferObjects\CreatedSavingsGoalEventResult;
use App\DataTransferObjects\CreateSavingsGoalInput;
use App\DataTransferObjects\UpdateSavingsGoalInput;
use App\Enums\SavingsGoalEventType;
use App\Enums\SavingsGoalStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\AuditEvent;
use App\Models\FinancialTransaction;
use App\Models\JournalEntry;
use App\Models\JournalPosting;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalEvent;
use App\Models\User;
use App\Services\AccountBalanceService;
use App\Services\AuditService;
use App\Services\FinancialAccountService;
use App\Services\SavingsGoalAllocationService;
use App\Services\SavingsGoalService;
use App\Support\IdempotencyUniqueConstraint;
use App\Support\Money;
use App\Support\TransientTransactionRetryBackoff;
use Database\Seeders\CurrencySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{
 *     user: User,
 *     otherUser: User,
 *     lifecycle: SavingsGoalService,
 *     allocation: SavingsGoalAllocationService,
 *     goal: SavingsGoal
 * }
 */
function savingsAllocationFixtures(array $goalOverrides = []): array
{
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $lifecycle = app(SavingsGoalService::class);
    $allocation = app(SavingsGoalAllocationService::class);

    $goal = $lifecycle->createGoal(new CreateSavingsGoalInput(
        user: $user,
        idempotencyKey: $goalOverrides['idempotencyKey'] ?? (string) Str::uuid(),
        name: $goalOverrides['name'] ?? 'Allocation Goal',
        currencyCode: $goalOverrides['currencyCode'] ?? 'PHP',
        targetAmountMinor: $goalOverrides['targetAmountMinor'] ?? 10_000_00,
        description: $goalOverrides['description'] ?? null,
        targetDate: $goalOverrides['targetDate'] ?? null,
    ))->goal;

    return compact('user', 'otherUser', 'lifecycle', 'allocation', 'goal');
}

function allocateInput(User $user, int $goalId, array $overrides = []): AllocateSavingsGoalInput
{
    return new AllocateSavingsGoalInput(
        user: $overrides['user'] ?? $user,
        goalId: $overrides['goalId'] ?? $goalId,
        idempotencyKey: $overrides['idempotencyKey'] ?? (string) Str::uuid(),
        amountMinor: $overrides['amountMinor'] ?? 1_000_00,
        note: array_key_exists('note', $overrides) ? $overrides['note'] : null,
    );
}

test('phpunit testing database is isolated sqlite memory', function (): void {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
});

test('contributions and withdrawals create immutable events with inherited currency and audits', function (): void {
    $fixtures = savingsAllocationFixtures();
    $txBefore = FinancialTransaction::query()->count();
    $jeBefore = JournalEntry::query()->count();
    $jpBefore = JournalPosting::query()->count();

    $account = app(FinancialAccountService::class)->createAccount($fixtures['user'], [
        'name' => 'Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $balanceBefore = app(AccountBalanceService::class)
        ->getBalancesForFinancialAccounts([$account])[$account->id]['amount_minor'];

    $contribution = $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'amountMinor' => 4_000_00,
        'note' => '  Payday set-aside  ',
    ]));

    expect($contribution->wasRecentlyCreated)->toBeTrue()
        ->and($contribution->event->type)->toBe(SavingsGoalEventType::Contribution)
        ->and((int) $contribution->event->amount_minor)->toBe(4_000_00)
        ->and($contribution->event->currency_code)->toBe('PHP')
        ->and($contribution->event->note)->toBe('Payday set-aside')
        ->and($contribution->event->user_id)->toBe($fixtures['user']->id)
        ->and($contribution->event->savings_goal_id)->toBe($fixtures['goal']->id)
        ->and(Schema::hasColumn('savings_goals', 'completed_at'))->toBeFalse()
        ->and(Schema::hasColumn('savings_goal_events', 'financial_account_id'))->toBeFalse()
        ->and(Schema::hasColumn('savings_goal_events', 'financial_transaction_id'))->toBeFalse();

    $withdrawal = $fixtures['allocation']->withdraw(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'amountMinor' => 1_500_00,
        'note' => null,
    ]));

    expect($withdrawal->wasRecentlyCreated)->toBeTrue()
        ->and($withdrawal->event->type)->toBe(SavingsGoalEventType::Withdrawal)
        ->and((int) $withdrawal->event->amount_minor)->toBe(1_500_00)
        ->and($fixtures['allocation']->getNetAllocatedMinor($fixtures['user'], $fixtures['goal']->id))->toBe(2_500_00);

    expect(AuditEvent::query()->where('action', AuditService::ACTION_SAVINGS_GOAL_CONTRIBUTED)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_SAVINGS_GOAL_WITHDRAWN)->count())->toBe(1);

    expect(fn () => $contribution->event->update(['note' => 'mutated']))
        ->toThrow(ImmutableRecordException::class);
    expect(fn () => $contribution->event->delete())
        ->toThrow(ImmutableRecordException::class);

    expect(FinancialTransaction::query()->count())->toBe($txBefore)
        ->and(JournalEntry::query()->count())->toBe($jeBefore)
        ->and(JournalPosting::query()->count())->toBe($jpBefore)
        ->and(app(AccountBalanceService::class)->getBalancesForFinancialAccounts([$account->fresh()])[$account->id]['amount_minor'])
        ->toBe($balanceBefore);
});

test('allocation amount and note validation reject unsafe values', function (): void {
    $fixtures = savingsAllocationFixtures();

    expect(fn () => $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 0,
    ])))->toThrow(LedgerIntegrityException::class, 'positive integer');

    expect(fn () => $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => -1,
    ])))->toThrow(LedgerIntegrityException::class, 'positive integer');

    expect(fn () => $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => Money::MAX_MINOR_AMOUNT + 1,
    ])))->toThrow(LedgerIntegrityException::class, 'positive integer');

    expect(fn () => $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 100.5,
    ])))->toThrow(LedgerIntegrityException::class, 'integer minor-unit');

    expect(fn () => $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => '1000',
    ])))->toThrow(LedgerIntegrityException::class, 'integer minor-unit');

    expect(fn () => $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => 'not-a-uuid',
    ])))->toThrow(LedgerIntegrityException::class, 'valid UUID');

    expect(fn () => $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'note' => str_repeat('n', 501),
    ])))->toThrow(LedgerIntegrityException::class, '500 characters');
});

test('overfunding is allowed and withdrawals cannot exceed net allocation', function (): void {
    $fixtures = savingsAllocationFixtures(['targetAmountMinor' => 10_000_00]);

    $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 15_000_00,
    ]));

    expect($fixtures['allocation']->getNetAllocatedMinor($fixtures['user'], $fixtures['goal']->id))->toBe(15_000_00)
        ->and($fixtures['goal']->fresh()->status)->toBe(SavingsGoalStatus::Active)
        ->and(Schema::hasColumn('savings_goals', 'completed_at'))->toBeFalse();

    $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 5_000_00,
    ]));
    $fixtures['allocation']->withdraw(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 4_000_00,
    ]));

    expect($fixtures['allocation']->getNetAllocatedMinor($fixtures['user'], $fixtures['goal']->id))->toBe(16_000_00);

    $fixtures['allocation']->withdraw(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 16_000_00,
    ]));
    expect($fixtures['allocation']->getNetAllocatedMinor($fixtures['user'], $fixtures['goal']->id))->toBe(0);

    expect(fn () => $fixtures['allocation']->withdraw(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 1,
    ])))->toThrow(LedgerIntegrityException::class, 'exceeds the current net allocated amount');

    expect(SavingsGoalEvent::query()->where('type', SavingsGoalEventType::Withdrawal)->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_SAVINGS_GOAL_WITHDRAWN)->count())->toBe(2);
});

test('idempotent allocation replay conflicts and cross-user reuse behave correctly', function (): void {
    $fixtures = savingsAllocationFixtures();
    $otherGoal = $fixtures['lifecycle']->createGoal(new CreateSavingsGoalInput(
        user: $fixtures['user'],
        idempotencyKey: (string) Str::uuid(),
        name: 'Second Goal',
        currencyCode: 'PHP',
        targetAmountMinor: 5_000_00,
    ))->goal;

    $uuid = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    $first = $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => $uuid,
        'amountMinor' => 2_000_00,
        'note' => 'Keep',
    ]));

    $replay = $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => $uuid,
        'amountMinor' => 2_000_00,
        'note' => 'Keep',
    ]));

    expect($replay->wasRecentlyCreated)->toBeFalse()
        ->and($replay->event->id)->toBe($first->event->id)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_SAVINGS_GOAL_CONTRIBUTED)->count())->toBe(1);

    expect(fn () => $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => $uuid,
        'amountMinor' => 3_000_00,
        'note' => 'Keep',
    ])))->toThrow(IdempotencyConflictException::class);

    expect(fn () => $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => $uuid,
        'amountMinor' => 2_000_00,
        'note' => 'Different',
    ])))->toThrow(IdempotencyConflictException::class);

    expect(fn () => $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $otherGoal->id, [
        'idempotencyKey' => $uuid,
        'amountMinor' => 2_000_00,
        'note' => 'Keep',
    ])))->toThrow(IdempotencyConflictException::class);

    expect(fn () => $fixtures['allocation']->withdraw(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => $uuid,
        'amountMinor' => 2_000_00,
        'note' => 'Keep',
    ])))->toThrow(IdempotencyConflictException::class);

    $other = $fixtures['allocation']->contribute(allocateInput($fixtures['otherUser'], (
        $fixtures['lifecycle']->createGoal(new CreateSavingsGoalInput(
            user: $fixtures['otherUser'],
            idempotencyKey: (string) Str::uuid(),
            name: 'Other Goal',
            currencyCode: 'PHP',
            targetAmountMinor: 8_000_00,
        ))->goal->id
    ), [
        'idempotencyKey' => $uuid,
        'amountMinor' => 500_00,
    ]));

    expect($other->wasRecentlyCreated)->toBeTrue()
        ->and($other->event->id)->not->toBe($first->event->id);
});

test('withdrawal replay succeeds after net balance changes and after archive', function (): void {
    $fixtures = savingsAllocationFixtures();

    $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 10_000_00,
    ]));

    $withdrawUuid = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    $withdrawal = $fixtures['allocation']->withdraw(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => $withdrawUuid,
        'amountMinor' => 10_000_00,
        'note' => 'Full withdraw',
    ]));

    expect($fixtures['allocation']->getNetAllocatedMinor($fixtures['user'], $fixtures['goal']->id))->toBe(0);

    $replay = $fixtures['allocation']->withdraw(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => $withdrawUuid,
        'amountMinor' => 10_000_00,
        'note' => 'Full withdraw',
    ]));

    expect($replay->wasRecentlyCreated)->toBeFalse()
        ->and($replay->event->id)->toBe($withdrawal->event->id)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_SAVINGS_GOAL_WITHDRAWN)->count())->toBe(1);

    $contribUuid = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    $contribution = $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => $contribUuid,
        'amountMinor' => 3_000_00,
        'note' => 'Before archive',
    ]));

    $fixtures['lifecycle']->archiveGoal($fixtures['user'], $fixtures['goal']->id);

    expect(fn () => $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 1,
    ])))->toThrow(LedgerIntegrityException::class, 'Archived savings goals cannot accept new allocations');

    $archivedReplay = $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => $contribUuid,
        'amountMinor' => 3_000_00,
        'note' => 'Before archive',
    ]));

    expect($archivedReplay->wasRecentlyCreated)->toBeFalse()
        ->and($archivedReplay->event->id)->toBe($contribution->event->id)
        ->and($fixtures['allocation']->getNetAllocatedMinor($fixtures['user'], $fixtures['goal']->id))->toBe(3_000_00);

    $fixtures['lifecycle']->restoreGoal($fixtures['user'], $fixtures['goal']->id);

    $afterRestore = $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 700_00,
    ]));
    expect($afterRestore->wasRecentlyCreated)->toBeTrue()
        ->and($fixtures['allocation']->getNetAllocatedMinor($fixtures['user'], $fixtures['goal']->id))->toBe(3_700_00);
});

test('event history is user-scoped stably ordered and paginated including archived goals', function (): void {
    $fixtures = savingsAllocationFixtures();

    $events = [];
    foreach ([100, 200, 300, 50] as $index => $amount) {
        Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:0'.$index, 'UTC'));
        $events[] = $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
            'amountMinor' => $amount,
        ]))->event;
    }

    Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:05', 'UTC'));
    $fixtures['allocation']->withdraw(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 50,
    ]));

    $fixtures['lifecycle']->archiveGoal($fixtures['user'], $fixtures['goal']->id);

    $page1 = $fixtures['allocation']->getEvents($fixtures['user'], $fixtures['goal']->id, page: 1, perPage: 2);
    $page2 = $fixtures['allocation']->getEvents($fixtures['user'], $fixtures['goal']->id, page: 2, perPage: 2);

    expect($page1->total)->toBe(5)
        ->and($page1->events)->toHaveCount(2)
        ->and($page1->lastPage)->toBe(3)
        ->and($page1->events->pluck('id')->all())->toBe([$events[0]->id, $events[1]->id])
        ->and($page2->events->pluck('id')->all())->toBe([$events[2]->id, $events[3]->id]);

    expect(fn () => $fixtures['allocation']->getEvents($fixtures['otherUser'], $fixtures['goal']->id))
        ->toThrow(LedgerIntegrityException::class, 'not accessible');

    expect(fn () => $fixtures['allocation']->contribute(allocateInput($fixtures['otherUser'], $fixtures['goal']->id, [
        'amountMinor' => 1,
    ])))->toThrow(LedgerIntegrityException::class, 'not accessible');
});

test('audit failure rolls back allocation and rejected withdrawals leave no traces', function (): void {
    $fixtures = savingsAllocationFixtures();

    expect(fn () => $fixtures['allocation']->withdraw(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 1,
    ])))->toThrow(LedgerIntegrityException::class, 'exceeds the current net allocated amount');

    expect(SavingsGoalEvent::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_SAVINGS_GOAL_WITHDRAWN)->count())->toBe(0);

    $failingAudit = new class extends AuditService
    {
        public function record(
            User $actor,
            string $action,
            string $auditableType,
            int $auditableId,
            array $metadata = [],
        ): AuditEvent {
            throw new RuntimeException('Forced allocation audit failure.');
        }
    };

    $failing = new SavingsGoalAllocationService($failingAudit);

    expect(fn () => $failing->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 500_00,
    ])))->toThrow(RuntimeException::class, 'Forced allocation audit failure');

    expect(SavingsGoalEvent::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_SAVINGS_GOAL_CONTRIBUTED)->count())->toBe(0);
});

test('large aggregate arithmetic rejects overflow without silent saturation', function (): void {
    $fixtures = savingsAllocationFixtures(['targetAmountMinor' => 1]);

    $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => Money::MAX_MINOR_AMOUNT,
    ]));

    expect($fixtures['allocation']->getNetAllocatedMinor($fixtures['user'], $fixtures['goal']->id))
        ->toBe(Money::MAX_MINOR_AMOUNT);

    expect(fn () => $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 1,
    ])))->toThrow(LedgerIntegrityException::class, 'overflow');

    expect(SavingsGoalEvent::query()->count())->toBe(1)
        ->and($fixtures['allocation']->getNetAllocatedMinor($fixtures['user'], $fixtures['goal']->id))
        ->toBe(Money::MAX_MINOR_AMOUNT);
});

test('goal lifecycle remains compatible and concurrent withdrawals serialize on locked goal net', function (): void {
    $fixtures = savingsAllocationFixtures();

    $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 5_000_00,
    ]));

    $updated = $fixtures['lifecycle']->updateGoal(new UpdateSavingsGoalInput(
        user: $fixtures['user'],
        goalId: $fixtures['goal']->id,
        name: 'Still Compatible',
        targetAmountMinor: 20_000_00,
    ));

    expect($updated->name)->toBe('Still Compatible')
        ->and($fixtures['allocation']->getNetAllocatedMinor($fixtures['user'], $fixtures['goal']->id))->toBe(5_000_00);

    // Deterministic concurrency safeguard: under one locked goal transaction, two full
    // withdrawals of the entire net cannot both succeed. SQLite :memory: cannot prove
    // true multi-writer races; unique index + lockForUpdate are the production backstops.
    DB::transaction(function () use ($fixtures): void {
        $goal = SavingsGoal::query()->whereKey($fixtures['goal']->id)->lockForUpdate()->firstOrFail();
        $net = $fixtures['allocation']->getNetAllocatedMinor($fixtures['user'], (int) $goal->id);
        expect($net)->toBe(5_000_00);

        $fixtures['allocation']->withdraw(allocateInput($fixtures['user'], $fixtures['goal']->id, [
            'amountMinor' => 5_000_00,
        ]));

        expect(fn () => $fixtures['allocation']->withdraw(allocateInput($fixtures['user'], $fixtures['goal']->id, [
            'amountMinor' => 5_000_00,
        ])))->toThrow(LedgerIntegrityException::class, 'exceeds the current net allocated amount');
    });

    expect($fixtures['allocation']->getNetAllocatedMinor($fixtures['user'], $fixtures['goal']->id))->toBe(0)
        ->and(SavingsGoalEvent::query()->where('type', SavingsGoalEventType::Withdrawal)->count())->toBe(1);
});

test('allocation idempotency classifier rejects fk and check failures that mention idempotency_key in sql', function (): void {
    $fixtures = savingsAllocationFixtures();
    $uuid = 'ffffffff-ffff-4fff-8fff-ffffffffffff';

    $first = $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => $uuid,
        'amountMinor' => 2_000_00,
        'note' => 'Keep',
    ]));
    $replay = $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => $uuid,
        'amountMinor' => 2_000_00,
        'note' => 'Keep',
    ]));
    expect($replay->wasRecentlyCreated)->toBeFalse()
        ->and($replay->event->id)->toBe($first->event->id);

    $sql = 'insert into "savings_goal_events" ("user_id", "savings_goal_id", "idempotency_key", "type", "amount_minor", "currency_code") values (1, 999, ?, ?, ?, ?)';
    $previous = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row');
    $previous->errorInfo = ['23000', 1452, 'Cannot add or update a child row'];
    $fk = new QueryException('sqlite', $sql, [], $previous);

    $checkPrevious = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 4025 CONSTRAINT failed');
    $checkPrevious->errorInfo = ['23000', 4025, 'CONSTRAINT failed'];
    $check = new QueryException('sqlite', $sql, [], $checkPrevious);

    expect(str_contains(strtolower($fk->getMessage()), 'idempotency_key'))->toBeTrue()
        ->and(IdempotencyUniqueConstraint::matches(
            $fk,
            SavingsGoalAllocationService::IDEMPOTENCY_UNIQUE_INDEX,
            'savings_goal_events',
        ))->toBeFalse()
        ->and(IdempotencyUniqueConstraint::matches(
            $check,
            SavingsGoalAllocationService::IDEMPOTENCY_UNIQUE_INDEX,
            'savings_goal_events',
        ))->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_SAVINGS_GOAL_CONTRIBUTED)->count())->toBe(1)
        ->and(SavingsGoalEvent::query()->count())->toBe(1);
});

/**
 * Synthetic MariaDB deadlock for retry-path tests.
 *
 * Limitation: this does not reproduce InnoDB gap-lock deadlocks; it only exercises
 * the allocation service retry/recovery loop after a classified transient failure.
 */
function syntheticMariaDbDeadlock(string $sql = 'select * from `savings_goals` where `id` = 1 limit 1 for update'): QueryException
{
    $previous = new PDOException(
        'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction',
    );
    $previous->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

    return new QueryException('mariadb', $sql, [], $previous);
}

/**
 * @param  list<QueryException>  $failures
 */
function flakyAllocationService(array $failures): SavingsGoalAllocationService
{
    return new class($failures, app(AuditService::class)) extends SavingsGoalAllocationService
    {
        /** @param  list<QueryException>  $failures */
        public function __construct(
            private array $failures,
            AuditService $auditService,
        ) {
            parent::__construct($auditService);
        }

        public int $attemptCount = 0;

        protected function executeAllocationAttempt(
            AllocateSavingsGoalInput $input,
            string $idempotencyKey,
            int $amountMinor,
            ?string $note,
            SavingsGoalEventType $type,
        ): CreatedSavingsGoalEventResult {
            $this->attemptCount++;

            if ($this->failures !== []) {
                throw array_shift($this->failures);
            }

            return parent::executeAllocationAttempt(
                input: $input,
                idempotencyKey: $idempotencyKey,
                amountMinor: $amountMinor,
                note: $note,
                type: $type,
            );
        }
    };
}

test('successful allocation without contention executes a single attempt', function (): void {
    $fixtures = savingsAllocationFixtures();
    $service = flakyAllocationService([]);

    $result = $service->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 1_500_00,
    ]));

    expect($result->wasRecentlyCreated)->toBeTrue()
        ->and($service->attemptCount)->toBe(1)
        ->and(SavingsGoalEvent::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_SAVINGS_GOAL_CONTRIBUTED)->count())->toBe(1);
});

test('transient deadlock retries then creates exactly one event and audit', function (): void {
    $fixtures = savingsAllocationFixtures();
    $service = flakyAllocationService([syntheticMariaDbDeadlock()]);

    $result = $service->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 900_00,
        'note' => 'After retry',
    ]));

    expect($result->wasRecentlyCreated)->toBeTrue()
        ->and($service->attemptCount)->toBe(2)
        ->and(SavingsGoalEvent::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_SAVINGS_GOAL_CONTRIBUTED)->count())->toBe(1)
        ->and(FinancialTransaction::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(JournalPosting::query()->count())->toBe(0);
});

test('transient deadlock then discovers matching uuid as replay', function (): void {
    $fixtures = savingsAllocationFixtures();
    $uuid = '12131213-1213-4121-8121-121312131213';

    $service = new class([syntheticMariaDbDeadlock()], app(AuditService::class), $fixtures, $uuid) extends SavingsGoalAllocationService
    {
        /** @param  list<QueryException>  $failures */
        public function __construct(
            private array $failures,
            AuditService $auditService,
            private array $fixtures,
            private string $uuid,
        ) {
            parent::__construct($auditService);
        }

        public int $attemptCount = 0;

        public ?int $winnerEventId = null;

        protected function executeAllocationAttempt(
            AllocateSavingsGoalInput $input,
            string $idempotencyKey,
            int $amountMinor,
            ?string $note,
            SavingsGoalEventType $type,
        ): CreatedSavingsGoalEventResult {
            $this->attemptCount++;

            if ($this->failures !== []) {
                // Concurrent winner commits while this attempt is the deadlock victim.
                $winner = $this->fixtures['allocation']->contribute(allocateInput(
                    $this->fixtures['user'],
                    $this->fixtures['goal']->id,
                    [
                        'idempotencyKey' => $this->uuid,
                        'amountMinor' => 750_00,
                        'note' => 'Winner',
                    ],
                ));
                $this->winnerEventId = (int) $winner->event->id;
                throw array_shift($this->failures);
            }

            return parent::executeAllocationAttempt(
                input: $input,
                idempotencyKey: $idempotencyKey,
                amountMinor: $amountMinor,
                note: $note,
                type: $type,
            );
        }
    };

    $replay = $service->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => $uuid,
        'amountMinor' => 750_00,
        'note' => 'Winner',
    ]));

    expect($replay->wasRecentlyCreated)->toBeFalse()
        ->and($replay->event->id)->toBe($service->winnerEventId)
        ->and($service->attemptCount)->toBe(1)
        ->and(SavingsGoalEvent::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_SAVINGS_GOAL_CONTRIBUTED)->count())->toBe(1);
});
test('transient failure then conflicting payload after concurrent winner still conflicts', function (): void {
    $fixtures = savingsAllocationFixtures();
    $uuid = '40014001-4001-4001-8001-400140014001';

    $service = new class([syntheticMariaDbDeadlock()], app(AuditService::class), $fixtures) extends SavingsGoalAllocationService
    {
        /** @param  list<QueryException>  $failures */
        public function __construct(
            private array $failures,
            AuditService $auditService,
            private array $fixtures,
        ) {
            parent::__construct($auditService);
        }

        public int $attemptCount = 0;

        protected function executeAllocationAttempt(
            AllocateSavingsGoalInput $input,
            string $idempotencyKey,
            int $amountMinor,
            ?string $note,
            SavingsGoalEventType $type,
        ): CreatedSavingsGoalEventResult {
            $this->attemptCount++;

            if ($this->failures !== []) {
                throw array_shift($this->failures);
            }

            // Concurrent winner committed a different payload during the failed attempt.
            $this->fixtures['allocation']->contribute(allocateInput(
                $this->fixtures['user'],
                $this->fixtures['goal']->id,
                [
                    'idempotencyKey' => $idempotencyKey,
                    'amountMinor' => 100_00,
                    'note' => 'Committed first',
                ],
            ));

            return parent::executeAllocationAttempt(
                input: $input,
                idempotencyKey: $idempotencyKey,
                amountMinor: $amountMinor,
                note: $note,
                type: $type,
            );
        }
    };

    expect(fn () => $service->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => $uuid,
        'amountMinor' => 999_00,
        'note' => 'Loser payload',
    ])))->toThrow(IdempotencyConflictException::class);

    expect($service->attemptCount)->toBe(2)
        ->and(SavingsGoalEvent::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_SAVINGS_GOAL_CONTRIBUTED)->count())->toBe(1);
});

test('retry after archive preserves matching contribution replay', function (): void {
    $fixtures = savingsAllocationFixtures();
    $uuid = 'aaaa1213-aaaa-4aaa-8aaa-aaaaaaaa1213';
    $created = $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => $uuid,
        'amountMinor' => 400_00,
        'note' => 'Pre-archive',
    ]));
    $fixtures['lifecycle']->archiveGoal($fixtures['user'], $fixtures['goal']->id);

    $service = flakyAllocationService([syntheticMariaDbDeadlock()]);
    $replay = $service->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => $uuid,
        'amountMinor' => 400_00,
        'note' => 'Pre-archive',
    ]));

    expect($replay->wasRecentlyCreated)->toBeFalse()
        ->and($replay->event->id)->toBe($created->event->id)
        ->and(SavingsGoalEvent::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_SAVINGS_GOAL_CONTRIBUTED)->count())->toBe(1);
});

test('retry after net changes preserves matching withdrawal replay', function (): void {
    $fixtures = savingsAllocationFixtures();
    $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 1_000_00,
    ]));
    $uuid = 'bbbb1213-bbbb-4bbb-8bbb-bbbbbbbb1213';
    $withdrawal = $fixtures['allocation']->withdraw(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => $uuid,
        'amountMinor' => 1_000_00,
    ]));

    $service = flakyAllocationService([syntheticMariaDbDeadlock()]);
    $replay = $service->withdraw(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'idempotencyKey' => $uuid,
        'amountMinor' => 1_000_00,
    ]));

    expect($replay->wasRecentlyCreated)->toBeFalse()
        ->and($replay->event->id)->toBe($withdrawal->event->id)
        ->and($fixtures['allocation']->getNetAllocatedMinor($fixtures['user'], $fixtures['goal']->id))->toBe(0)
        ->and(SavingsGoalEvent::query()->where('type', SavingsGoalEventType::Withdrawal)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_SAVINGS_GOAL_WITHDRAWN)->count())->toBe(1);
});

test('retry exhaustion is bounded and preserves the original deadlock cause', function (): void {
    $fixtures = savingsAllocationFixtures();
    $deadlocks = [
        syntheticMariaDbDeadlock(),
        syntheticMariaDbDeadlock(),
        syntheticMariaDbDeadlock(),
    ];
    $slept = [];
    TransientTransactionRetryBackoff::setSleeper(function (int $micros) use (&$slept): void {
        $slept[] = $micros;
    });
    $service = flakyAllocationService($deadlocks);

    try {
        $service->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
            'amountMinor' => 50_00,
        ]));
        expect(false)->toBeTrue('Expected contention failure after exhaustion');
    } catch (QueryException $exception) {
        expect((int) ($exception->errorInfo[1] ?? 0))->toBe(1213)
            ->and($service->attemptCount)->toBe(SavingsGoalAllocationService::MAX_CONCURRENCY_ATTEMPTS);
    } catch (LedgerIntegrityException $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(QueryException::class)
            ->and((int) ($exception->getPrevious()->errorInfo[1] ?? 0))->toBe(1213)
            ->and($service->attemptCount)->toBe(SavingsGoalAllocationService::MAX_CONCURRENCY_ATTEMPTS);
    } finally {
        TransientTransactionRetryBackoff::resetSleeper();
    }

    expect($slept)->toHaveCount(2)
        ->and(SavingsGoalEvent::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditService::ACTION_SAVINGS_GOAL_CONTRIBUTED)->count())->toBe(0);
});

test('non-retryable integrity failures are not swallowed by the deadlock retry loop', function (): void {
    $fixtures = savingsAllocationFixtures();
    $sql = 'insert into `savings_goal_events` (`user_id`, `idempotency_key`) values (?, ?)';
    $previous = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row');
    $previous->errorInfo = ['23000', 1452, 'Cannot add or update a child row'];
    $fk = new QueryException('mariadb', $sql, [], $previous);

    $service = flakyAllocationService([$fk]);

    expect(fn () => $service->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 10_00,
    ])))->toThrow(QueryException::class);

    expect($service->attemptCount)->toBe(1)
        ->and(SavingsGoalEvent::query()->count())->toBe(0);
});

test('deadlock retry then create still serializes sequential withdrawals and archive rules', function (): void {
    $fixtures = savingsAllocationFixtures();
    $service = flakyAllocationService([syntheticMariaDbDeadlock()]);

    $service->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 2_000_00,
    ]));

    $fixtures['allocation']->withdraw(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 2_000_00,
    ]));

    expect($fixtures['allocation']->getNetAllocatedMinor($fixtures['user'], $fixtures['goal']->id))->toBe(0);

    expect(fn () => $fixtures['allocation']->withdraw(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 1,
    ])))->toThrow(LedgerIntegrityException::class, 'exceeds the current net allocated amount');

    $fixtures['lifecycle']->archiveGoal($fixtures['user'], $fixtures['goal']->id);

    expect(fn () => $fixtures['allocation']->contribute(allocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 1,
    ])))->toThrow(LedgerIntegrityException::class, 'Archived savings goals cannot accept new allocations');
});
