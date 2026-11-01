<?php

use App\DataTransferObjects\AllocateSavingsGoalInput;
use App\DataTransferObjects\CreateSavingsGoalInput;
use App\Enums\SavingsGoalStatus;
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
use App\Services\FinancialAccountService;
use App\Services\SavingsGoalAllocationService;
use App\Services\SavingsGoalProgressService;
use App\Services\SavingsGoalService;
use App\Support\Money;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
 *     progress: SavingsGoalProgressService,
 *     goal: SavingsGoal
 * }
 */
function savingsProgressFixtures(array $goalOverrides = []): array
{
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $lifecycle = app(SavingsGoalService::class);
    $allocation = app(SavingsGoalAllocationService::class);
    $progress = app(SavingsGoalProgressService::class);

    $goal = $lifecycle->createGoal(new CreateSavingsGoalInput(
        user: $user,
        idempotencyKey: $goalOverrides['idempotencyKey'] ?? (string) Str::uuid(),
        name: $goalOverrides['name'] ?? 'Progress Goal',
        currencyCode: $goalOverrides['currencyCode'] ?? 'PHP',
        targetAmountMinor: $goalOverrides['targetAmountMinor'] ?? 10_000_00,
        description: $goalOverrides['description'] ?? 'Desc',
        targetDate: $goalOverrides['targetDate'] ?? '2027-01-15',
    ))->goal;

    return compact('user', 'otherUser', 'lifecycle', 'allocation', 'progress', 'goal');
}

function progressAllocateInput(User $user, int $goalId, array $overrides = []): AllocateSavingsGoalInput
{
    return new AllocateSavingsGoalInput(
        user: $overrides['user'] ?? $user,
        goalId: $overrides['goalId'] ?? $goalId,
        idempotencyKey: $overrides['idempotencyKey'] ?? (string) Str::uuid(),
        amountMinor: $overrides['amountMinor'] ?? 1_000_00,
        note: array_key_exists('note', $overrides) ? $overrides['note'] : null,
    );
}

test('phpunit testing database is isolated sqlite memory for progress tests', function (): void {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
});

test('goal summary covers zero exact overfunded and withdrawal-after-target cases', function (): void {
    $fixtures = savingsProgressFixtures(['targetAmountMinor' => 1_000_00]);

    $empty = $fixtures['progress']->getGoalSummary($fixtures['user'], $fixtures['goal']->id);
    expect($empty->allocatedAmountMinor)->toBe(0)
        ->and($empty->remainingAmountMinor)->toBe(1_000_00)
        ->and($empty->overfundedAmountMinor)->toBe(0)
        ->and($empty->progressBasisPoints)->toBe(0)
        ->and($empty->targetReached)->toBeFalse()
        ->and($empty->targetDate)->toBe('2027-01-15')
        ->and($empty->description)->toBe('Desc')
        ->and($empty->status)->toBe(SavingsGoalStatus::Active);

    $fixtures['allocation']->contribute(progressAllocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 400_00,
    ]));
    $partial = $fixtures['progress']->getGoalSummary($fixtures['user'], $fixtures['goal']->id);
    expect($partial->allocatedAmountMinor)->toBe(400_00)
        ->and($partial->remainingAmountMinor)->toBe(600_00)
        ->and($partial->progressBasisPoints)->toBe(4000)
        ->and($partial->targetReached)->toBeFalse();

    $fixtures['allocation']->contribute(progressAllocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 600_00,
    ]));
    $exact = $fixtures['progress']->getGoalSummary($fixtures['user'], $fixtures['goal']->id);
    expect($exact->allocatedAmountMinor)->toBe(1_000_00)
        ->and($exact->remainingAmountMinor)->toBe(0)
        ->and($exact->overfundedAmountMinor)->toBe(0)
        ->and($exact->progressBasisPoints)->toBe(10_000)
        ->and($exact->targetReached)->toBeTrue();

    $fixtures['allocation']->contribute(progressAllocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 250_00,
    ]));
    $over = $fixtures['progress']->getGoalSummary($fixtures['user'], $fixtures['goal']->id);
    expect($over->allocatedAmountMinor)->toBe(1_250_00)
        ->and($over->remainingAmountMinor)->toBe(0)
        ->and($over->overfundedAmountMinor)->toBe(250_00)
        ->and($over->progressBasisPoints)->toBe(10_000)
        ->and($over->targetReached)->toBeTrue();

    $fixtures['allocation']->withdraw(progressAllocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 500_00,
    ]));
    $after = $fixtures['progress']->getGoalSummary($fixtures['user'], $fixtures['goal']->id);
    expect($after->allocatedAmountMinor)->toBe(750_00)
        ->and($after->remainingAmountMinor)->toBe(250_00)
        ->and($after->overfundedAmountMinor)->toBe(0)
        ->and($after->progressBasisPoints)->toBe(7500)
        ->and($after->targetReached)->toBeFalse();
});

test('progress floors fractional basis points and never rounds early to full', function (): void {
    $fixtures = savingsProgressFixtures(['targetAmountMinor' => 3]);

    $fixtures['allocation']->contribute(progressAllocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 1,
    ]));
    $one = $fixtures['progress']->getGoalSummary($fixtures['user'], $fixtures['goal']->id);
    expect($one->progressBasisPoints)->toBe(3333)
        ->and($one->targetReached)->toBeFalse();

    $fixtures['allocation']->contribute(progressAllocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 1,
    ]));
    $two = $fixtures['progress']->getGoalSummary($fixtures['user'], $fixtures['goal']->id);
    expect($two->progressBasisPoints)->toBe(6666)
        ->and($two->targetReached)->toBeFalse();

    $near = savingsProgressFixtures(['targetAmountMinor' => Money::MAX_MINOR_AMOUNT, 'name' => 'Near']);
    $near['allocation']->contribute(progressAllocateInput($near['user'], $near['goal']->id, [
        'amountMinor' => Money::MAX_MINOR_AMOUNT - 1,
    ]));
    $summary = $near['progress']->getGoalSummary($near['user'], $near['goal']->id);
    expect($summary->progressBasisPoints)->toBe(9999)
        ->and($summary->targetReached)->toBeFalse()
        ->and($summary->remainingAmountMinor)->toBe(1);
});

test('progress net matches allocation service for multi-event goals', function (): void {
    $fixtures = savingsProgressFixtures();

    $fixtures['allocation']->contribute(progressAllocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 5_000_00,
    ]));
    $fixtures['allocation']->contribute(progressAllocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 2_500_00,
    ]));
    $fixtures['allocation']->withdraw(progressAllocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 1_200_00,
    ]));

    $fromAllocation = $fixtures['allocation']->getNetAllocatedMinor($fixtures['user'], $fixtures['goal']->id);
    $fromProgress = $fixtures['progress']->getGoalSummary($fixtures['user'], $fixtures['goal']->id)->allocatedAmountMinor;

    expect($fromProgress)->toBe($fromAllocation)->toBe(6_300_00);
});

test('archived goals retain progress and are excluded from active portfolio money', function (): void {
    $fixtures = savingsProgressFixtures(['targetAmountMinor' => 1_000_00, 'name' => 'Archive Me']);
    $fixtures['allocation']->contribute(progressAllocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 1_000_00,
    ]));

    $fixtures['lifecycle']->archiveGoal($fixtures['user'], $fixtures['goal']->id);
    $archivedSummary = $fixtures['progress']->getGoalSummary($fixtures['user'], $fixtures['goal']->id);
    expect($archivedSummary->status)->toBe(SavingsGoalStatus::Archived)
        ->and($archivedSummary->targetReached)->toBeTrue()
        ->and($archivedSummary->allocatedAmountMinor)->toBe(1_000_00)
        ->and($archivedSummary->archivedAt)->not->toBeNull();

    $portfolio = $fixtures['progress']->getPortfolioSummary($fixtures['user']);
    expect($portfolio->totalGoalCount)->toBe(1)
        ->and($portfolio->activeGoalCount)->toBe(0)
        ->and($portfolio->archivedGoalCount)->toBe(1)
        ->and($portfolio->activeAtTargetCount)->toBe(0)
        ->and($portfolio->activeBelowTargetCount)->toBe(0)
        ->and($portfolio->currencies)->toBe([]);

    $all = $fixtures['progress']->getGoalSummaries($fixtures['user']);
    $archivedOnly = $fixtures['progress']->getGoalSummaries($fixtures['user'], SavingsGoalStatus::Archived);
    expect($all->total)->toBe(1)
        ->and($archivedOnly->total)->toBe(1)
        ->and($archivedOnly->summaries->first()->goalId)->toBe($fixtures['goal']->id);

    $fixtures['lifecycle']->restoreGoal($fixtures['user'], $fixtures['goal']->id);
    $restored = $fixtures['progress']->getGoalSummary($fixtures['user'], $fixtures['goal']->id);
    expect($restored->status)->toBe(SavingsGoalStatus::Active)
        ->and($restored->archivedAt)->toBeNull();
});

test('portfolio groups active goals by currency with deterministic ordering and counts', function (): void {
    $user = User::factory()->create();
    $lifecycle = app(SavingsGoalService::class);
    $allocation = app(SavingsGoalAllocationService::class);
    $progress = app(SavingsGoalProgressService::class);

    $phpBelow = $lifecycle->createGoal(new CreateSavingsGoalInput(
        user: $user,
        idempotencyKey: (string) Str::uuid(),
        name: 'PHP Below',
        currencyCode: 'PHP',
        targetAmountMinor: 1_000_00,
    ))->goal;
    $phpAt = $lifecycle->createGoal(new CreateSavingsGoalInput(
        user: $user,
        idempotencyKey: (string) Str::uuid(),
        name: 'PHP At',
        currencyCode: 'PHP',
        targetAmountMinor: 500_00,
    ))->goal;
    $usd = $lifecycle->createGoal(new CreateSavingsGoalInput(
        user: $user,
        idempotencyKey: (string) Str::uuid(),
        name: 'USD Goal',
        currencyCode: 'USD',
        targetAmountMinor: 200_00,
    ))->goal;
    $archived = $lifecycle->createGoal(new CreateSavingsGoalInput(
        user: $user,
        idempotencyKey: (string) Str::uuid(),
        name: 'Archived PHP',
        currencyCode: 'PHP',
        targetAmountMinor: 9_000_00,
    ))->goal;

    $allocation->contribute(progressAllocateInput($user, $phpBelow->id, ['amountMinor' => 250_00]));
    $allocation->contribute(progressAllocateInput($user, $phpAt->id, ['amountMinor' => 500_00]));
    $allocation->contribute(progressAllocateInput($user, $usd->id, ['amountMinor' => 50_00]));
    $allocation->contribute(progressAllocateInput($user, $archived->id, ['amountMinor' => 9_000_00]));
    $lifecycle->archiveGoal($user, $archived->id);

    $portfolio = $progress->getPortfolioSummary($user);

    expect($portfolio->totalGoalCount)->toBe(4)
        ->and($portfolio->activeGoalCount)->toBe(3)
        ->and($portfolio->archivedGoalCount)->toBe(1)
        ->and($portfolio->activeAtTargetCount)->toBe(1)
        ->and($portfolio->activeBelowTargetCount)->toBe(2)
        ->and($portfolio->currencies)->toHaveCount(2)
        ->and($portfolio->currencies[0]->currencyCode)->toBe('PHP')
        ->and($portfolio->currencies[1]->currencyCode)->toBe('USD')
        ->and($portfolio->currencies[0]->activeGoalCount)->toBe(2)
        ->and($portfolio->currencies[0]->totalTargetMinor)->toBe(1_500_00)
        ->and($portfolio->currencies[0]->totalAllocatedMinor)->toBe(750_00)
        ->and($portfolio->currencies[0]->totalRemainingMinor)->toBe(750_00)
        ->and($portfolio->currencies[0]->totalOverfundedMinor)->toBe(0)
        ->and($portfolio->currencies[1]->totalAllocatedMinor)->toBe(50_00);
});

test('portfolio aggregate overflow fails explicitly under Money checked bounds', function (): void {
    $user = User::factory()->create();
    $lifecycle = app(SavingsGoalService::class);
    $allocation = app(SavingsGoalAllocationService::class);
    $progress = app(SavingsGoalProgressService::class);

    $first = $lifecycle->createGoal(new CreateSavingsGoalInput(
        user: $user,
        idempotencyKey: (string) Str::uuid(),
        name: 'Max A',
        currencyCode: 'PHP',
        targetAmountMinor: Money::MAX_MINOR_AMOUNT,
    ))->goal;
    $second = $lifecycle->createGoal(new CreateSavingsGoalInput(
        user: $user,
        idempotencyKey: (string) Str::uuid(),
        name: 'Max B',
        currencyCode: 'PHP',
        targetAmountMinor: Money::MAX_MINOR_AMOUNT,
    ))->goal;

    $allocation->contribute(progressAllocateInput($user, $first->id, [
        'amountMinor' => Money::MAX_MINOR_AMOUNT,
    ]));
    $allocation->contribute(progressAllocateInput($user, $second->id, [
        'amountMinor' => Money::MAX_MINOR_AMOUNT,
    ]));

    expect(fn () => $progress->getPortfolioSummary($user))
        ->toThrow(LedgerIntegrityException::class, 'overflow');
});

test('paginated summaries are deterministic owner-scoped and avoid n-plus-one event queries', function (): void {
    $fixtures = savingsProgressFixtures(['name' => 'Goal 0', 'targetAmountMinor' => 100]);

    for ($i = 1; $i <= 5; $i++) {
        $goal = $fixtures['lifecycle']->createGoal(new CreateSavingsGoalInput(
            user: $fixtures['user'],
            idempotencyKey: (string) Str::uuid(),
            name: "Goal {$i}",
            currencyCode: 'PHP',
            targetAmountMinor: 100 + $i,
        ))->goal;
        $fixtures['allocation']->contribute(progressAllocateInput($fixtures['user'], $goal->id, [
            'amountMinor' => 10,
        ]));
    }

    $fixtures['allocation']->contribute(progressAllocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 10,
    ]));

    // Other user's goals must not appear.
    $foreign = $fixtures['lifecycle']->createGoal(new CreateSavingsGoalInput(
        user: $fixtures['otherUser'],
        idempotencyKey: (string) Str::uuid(),
        name: 'Foreign',
        currencyCode: 'PHP',
        targetAmountMinor: 100,
    ))->goal;
    $fixtures['allocation']->contribute(progressAllocateInput($fixtures['otherUser'], $foreign->id, [
        'amountMinor' => 10,
    ]));

    DB::flushQueryLog();
    DB::enableQueryLog();
    $page1 = $fixtures['progress']->getGoalSummaries($fixtures['user'], page: 1, perPage: 2);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    $eventQueries = $queries->filter(
        fn (array $q): bool => str_contains(strtolower($q['query']), 'savings_goal_events'),
    );
    expect($eventQueries)->toHaveCount(1)
        ->and($page1->total)->toBe(6)
        ->and($page1->perPage)->toBe(2)
        ->and($page1->currentPage)->toBe(1)
        ->and($page1->lastPage)->toBe(3)
        ->and($page1->summaries)->toHaveCount(2)
        ->and($page1->summaries->pluck('goalId')->all())->toBe([
            $fixtures['goal']->id,
            $fixtures['goal']->id + 1,
        ]);

    $page2 = $fixtures['progress']->getGoalSummaries($fixtures['user'], page: 2, perPage: 2);
    expect($page2->summaries->pluck('goalId')->all())->toBe([
        $fixtures['goal']->id + 2,
        $fixtures['goal']->id + 3,
    ]);

    $active = $fixtures['progress']->getGoalSummaries($fixtures['user'], SavingsGoalStatus::Active, perPage: 100);
    expect($active->total)->toBe(6);

    $clamped = $fixtures['progress']->getGoalSummaries($fixtures['user'], page: 0, perPage: 999);
    expect($clamped->currentPage)->toBe(1)
        ->and($clamped->perPage)->toBe(100);

    $empty = $fixtures['progress']->getGoalSummaries($fixtures['user'], page: 99, perPage: 2);
    expect($empty->summaries)->toHaveCount(0)
        ->and($empty->total)->toBe(6);
});

test('portfolio avoids n-plus-one event queries', function (): void {
    $user = User::factory()->create();
    $lifecycle = app(SavingsGoalService::class);
    $allocation = app(SavingsGoalAllocationService::class);
    $progress = app(SavingsGoalProgressService::class);

    for ($i = 0; $i < 4; $i++) {
        $goal = $lifecycle->createGoal(new CreateSavingsGoalInput(
            user: $user,
            idempotencyKey: (string) Str::uuid(),
            name: "P{$i}",
            currencyCode: 'PHP',
            targetAmountMinor: 100,
        ))->goal;
        $allocation->contribute(progressAllocateInput($user, $goal->id, ['amountMinor' => 10]));
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $progress->getPortfolioSummary($user);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    $eventQueries = $queries->filter(
        fn (array $q): bool => str_contains(strtolower($q['query']), 'savings_goal_events'),
    );
    expect($eventQueries)->toHaveCount(1);
});

test('tenant isolation denies cross-user progress access', function (): void {
    $fixtures = savingsProgressFixtures();
    $fixtures['allocation']->contribute(progressAllocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 100_00,
    ]));

    expect(fn () => $fixtures['progress']->getGoalSummary($fixtures['otherUser'], $fixtures['goal']->id))
        ->toThrow(LedgerIntegrityException::class, 'not accessible');

    $otherList = $fixtures['progress']->getGoalSummaries($fixtures['otherUser']);
    expect($otherList->total)->toBe(0);

    $otherPortfolio = $fixtures['progress']->getPortfolioSummary($fixtures['otherUser']);
    expect($otherPortfolio->totalGoalCount)->toBe(0)
        ->and($otherPortfolio->currencies)->toBe([]);

    $fixtures['lifecycle']->archiveGoal($fixtures['user'], $fixtures['goal']->id);
    expect(fn () => $fixtures['progress']->getGoalSummary($fixtures['otherUser'], $fixtures['goal']->id))
        ->toThrow(LedgerIntegrityException::class, 'not accessible');
});

test('progress reads create no ledger audits or event mutations', function (): void {
    $fixtures = savingsProgressFixtures(['targetAmountMinor' => 500_00]);
    $account = app(FinancialAccountService::class)->createAccount($fixtures['user'], [
        'name' => 'Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $balanceBefore = app(AccountBalanceService::class)
        ->getBalancesForFinancialAccounts([$account])[$account->id]['amount_minor'];

    $fixtures['allocation']->contribute(progressAllocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 200_00,
    ]));

    $eventsBefore = SavingsGoalEvent::query()->count();
    $auditsBefore = AuditEvent::query()->count();
    $txBefore = FinancialTransaction::query()->count();
    $jeBefore = JournalEntry::query()->count();
    $jpBefore = JournalPosting::query()->count();

    $fixtures['progress']->getGoalSummary($fixtures['user'], $fixtures['goal']->id);
    $fixtures['progress']->getGoalSummaries($fixtures['user']);
    $fixtures['progress']->getPortfolioSummary($fixtures['user']);

    expect(SavingsGoalEvent::query()->count())->toBe($eventsBefore)
        ->and(AuditEvent::query()->count())->toBe($auditsBefore)
        ->and(FinancialTransaction::query()->count())->toBe($txBefore)
        ->and(JournalEntry::query()->count())->toBe($jeBefore)
        ->and(JournalPosting::query()->count())->toBe($jpBefore);

    $event = SavingsGoalEvent::query()->firstOrFail();
    expect(fn () => tap($event, function (SavingsGoalEvent $row): void {
        $row->amount_minor = 1;
        $row->save();
    }))->toThrow(ImmutableRecordException::class);

    $balanceAfter = app(AccountBalanceService::class)
        ->getBalancesForFinancialAccounts([$account])[$account->id]['amount_minor'];
    expect($balanceAfter)->toBe($balanceBefore);
});

test('empty portfolio and repeated reads are deterministic', function (): void {
    $user = User::factory()->create();
    $progress = app(SavingsGoalProgressService::class);

    $empty = $progress->getPortfolioSummary($user);
    expect($empty->totalGoalCount)->toBe(0)
        ->and($empty->activeGoalCount)->toBe(0)
        ->and($empty->archivedGoalCount)->toBe(0)
        ->and($empty->activeAtTargetCount)->toBe(0)
        ->and($empty->activeBelowTargetCount)->toBe(0)
        ->and($empty->currencies)->toBe([]);

    $fixtures = savingsProgressFixtures(['targetAmountMinor' => 100]);
    $fixtures['allocation']->contribute(progressAllocateInput($fixtures['user'], $fixtures['goal']->id, [
        'amountMinor' => 40,
    ]));

    $first = $fixtures['progress']->getGoalSummary($fixtures['user'], $fixtures['goal']->id);
    $second = $fixtures['progress']->getGoalSummary($fixtures['user'], $fixtures['goal']->id);
    expect($second->allocatedAmountMinor)->toBe($first->allocatedAmountMinor)
        ->and($second->progressBasisPoints)->toBe($first->progressBasisPoints)
        ->and($second->remainingAmountMinor)->toBe($first->remainingAmountMinor);
});

test('goal summary preserves persisted created_at and updated_at', function (): void {
    $fixtures = savingsProgressFixtures();
    $goal = $fixtures['goal']->fresh();

    $createdAt = $goal->created_at?->copy();
    $updatedAt = $goal->updated_at?->copy();
    expect($createdAt)->not->toBeNull()
        ->and($updatedAt)->not->toBeNull();

    Carbon::setTestNow(Carbon::parse('2026-11-01 09:00:00', 'UTC'));

    $summary = $fixtures['progress']->getGoalSummary($fixtures['user'], $goal->id);

    expect($summary->createdAt->equalTo($createdAt))->toBeTrue()
        ->and($summary->updatedAt->equalTo($updatedAt))->toBeTrue()
        ->and($summary->createdAt->equalTo(Carbon::now()))->toBeFalse()
        ->and($summary->updatedAt->equalTo(Carbon::now()))->toBeFalse();
});

test('missing created_at fails closed without fabricating now or writing', function (): void {
    $fixtures = savingsProgressFixtures();
    $goal = $fixtures['goal']->fresh();
    $eventsBefore = SavingsGoalEvent::query()->count();
    $auditsBefore = AuditEvent::query()->count();

    $goal->setAttribute('created_at', null);

    $method = new ReflectionMethod(SavingsGoalProgressService::class, 'buildSummary');

    expect(fn () => $method->invoke($fixtures['progress'], $goal, 0))
        ->toThrow(LedgerIntegrityException::class, 'created_at');

    expect(SavingsGoalEvent::query()->count())->toBe($eventsBefore)
        ->and(AuditEvent::query()->count())->toBe($auditsBefore);

    $reloaded = SavingsGoal::query()->findOrFail($goal->id);
    expect($reloaded->created_at)->not->toBeNull();
});

test('missing updated_at fails closed without fabricating now or writing', function (): void {
    $fixtures = savingsProgressFixtures();
    $goal = $fixtures['goal']->fresh();
    $eventsBefore = SavingsGoalEvent::query()->count();
    $auditsBefore = AuditEvent::query()->count();

    $goal->setAttribute('updated_at', null);

    $method = new ReflectionMethod(SavingsGoalProgressService::class, 'buildSummary');

    expect(fn () => $method->invoke($fixtures['progress'], $goal, 0))
        ->toThrow(LedgerIntegrityException::class, 'updated_at');

    expect(SavingsGoalEvent::query()->count())->toBe($eventsBefore)
        ->and(AuditEvent::query()->count())->toBe($auditsBefore);

    $reloaded = SavingsGoal::query()->findOrFail($goal->id);
    expect($reloaded->updated_at)->not->toBeNull();
});
