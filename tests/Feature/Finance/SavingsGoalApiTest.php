<?php

use App\DataTransferObjects\AllocateSavingsGoalInput;
use App\DataTransferObjects\CreateSavingsGoalInput;
use App\Enums\SavingsGoalStatus;
use App\Exceptions\LedgerIntegrityException;
use App\Http\Controllers\SavingsGoalController;
use App\Http\Requests\SavingsGoals\IndexSavingsGoalEventRequest;
use App\Http\Requests\SavingsGoals\ShowSavingsGoalRequest;
use App\Models\AuditEvent;
use App\Models\FinancialTransaction;
use App\Models\JournalEntry;
use App\Models\JournalPosting;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalEvent;
use App\Models\User;
use App\Services\SavingsGoalAllocationService;
use App\Services\SavingsGoalService;
use App\Support\Money;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-11-01 10:00:00', 'UTC'));
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
function savingsApiFixtures(array $goalOverrides = []): array
{
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $lifecycle = app(SavingsGoalService::class);
    $allocation = app(SavingsGoalAllocationService::class);

    $goal = $lifecycle->createGoal(new CreateSavingsGoalInput(
        user: $user,
        idempotencyKey: $goalOverrides['idempotencyKey'] ?? (string) Str::uuid(),
        name: $goalOverrides['name'] ?? 'Emergency Fund',
        currencyCode: $goalOverrides['currencyCode'] ?? 'PHP',
        targetAmountMinor: $goalOverrides['targetAmountMinor'] ?? 1_000_00,
        description: $goalOverrides['description'] ?? 'Primary goal',
        targetDate: $goalOverrides['targetDate'] ?? '2027-06-01',
    ))->goal;

    return compact('user', 'otherUser', 'lifecycle', 'allocation', 'goal');
}

function savingsApiAllocate(
    User $user,
    int $goalId,
    int $amountMinor,
    ?string $note = null,
): AllocateSavingsGoalInput {
    return new AllocateSavingsGoalInput(
        user: $user,
        goalId: $goalId,
        idempotencyKey: (string) Str::uuid(),
        amountMinor: $amountMinor,
        note: $note,
    );
}

function savingsApiSnapshotCounts(): array
{
    return [
        'savings_goals' => SavingsGoal::query()->count(),
        'savings_goal_events' => SavingsGoalEvent::query()->count(),
        'audit_events' => AuditEvent::query()->count(),
        'financial_transactions' => FinancialTransaction::query()->count(),
        'journal_entries' => JournalEntry::query()->count(),
        'journal_postings' => JournalPosting::query()->count(),
    ];
}

test('phpunit testing database is isolated sqlite memory for savings api tests', function (): void {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
});

test('guests cannot access savings goal read endpoints', function (): void {
    $fixtures = savingsApiFixtures();
    $goalId = $fixtures['goal']->id;

    $this->getJson(route('savings-goals.index'))->assertUnauthorized();
    $this->getJson(route('savings-goals.portfolio'))->assertUnauthorized();
    $this->getJson(route('savings-goals.show', $goalId))->assertUnauthorized();
    $this->getJson(route('savings-goals.events', $goalId))->assertUnauthorized();
});

test('authenticated list show portfolio and events succeed for owners', function (): void {
    $fixtures = savingsApiFixtures(['targetAmountMinor' => 1_000_00]);
    $fixtures['allocation']->contribute(savingsApiAllocate($fixtures['user'], $fixtures['goal']->id, 250_00));

    $list = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.index'));
    $list->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.per_page', 25)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('filters.status', null)
        ->assertJsonPath('data.0.id', $fixtures['goal']->id)
        ->assertJsonPath('data.0.name', 'Emergency Fund')
        ->assertJsonPath('data.0.target_amount_minor', '100000')
        ->assertJsonPath('data.0.allocated_amount_minor', '25000')
        ->assertJsonPath('data.0.remaining_amount_minor', '75000')
        ->assertJsonPath('data.0.overfunded_amount_minor', '0')
        ->assertJsonPath('data.0.progress_basis_points', 2500)
        ->assertJsonPath('data.0.target_reached', false);

    $show = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.show', $fixtures['goal']->id));
    $show->assertOk()
        ->assertJsonPath('data.id', $fixtures['goal']->id)
        ->assertJsonPath('data.allocated_amount_minor', '25000')
        ->assertJsonPath('data.target_date', '2027-06-01');

    $portfolio = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.portfolio'));
    $portfolio->assertOk()
        ->assertJsonPath('data.total_goal_count', 1)
        ->assertJsonPath('data.active_goal_count', 1)
        ->assertJsonPath('data.currencies.0.currency_code', 'PHP')
        ->assertJsonPath('data.currencies.0.total_allocated_minor', '25000');

    $events = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.events', $fixtures['goal']->id));
    $events->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.type', 'contribution')
        ->assertJsonPath('data.0.amount_minor', '25000')
        ->assertJsonMissingPath('data.0.idempotency_key')
        ->assertJsonMissingPath('data.0.user_id');
});

test('missing and foreign goals return equivalent 403 responses', function (): void {
    $fixtures = savingsApiFixtures();
    $foreign = $fixtures['lifecycle']->createGoal(new CreateSavingsGoalInput(
        user: $fixtures['otherUser'],
        idempotencyKey: (string) Str::uuid(),
        name: 'Foreign Goal',
        currencyCode: 'PHP',
        targetAmountMinor: 500_00,
    ))->goal;

    $missingId = $fixtures['goal']->id + 10_000;
    expect(SavingsGoal::query()->whereKey($missingId)->exists())->toBeFalse();

    $missingShow = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.show', $missingId));
    $foreignShow = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.show', $foreign->id));
    $missingEvents = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.events', $missingId));
    $foreignEvents = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.events', $foreign->id));

    $missingShow->assertForbidden();
    $foreignShow->assertForbidden();
    $missingEvents->assertForbidden();
    $foreignEvents->assertForbidden();

    // Outward contract: same status + same client-facing message for missing and foreign IDs.
    expect($missingShow->status())->toBe(403)
        ->and($foreignShow->status())->toBe(403)
        ->and($missingEvents->status())->toBe(403)
        ->and($foreignEvents->status())->toBe(403)
        ->and($missingShow->json('message'))->toBe($foreignShow->json('message'))
        ->and($missingEvents->json('message'))->toBe($foreignEvents->json('message'))
        ->and($missingShow->json())->not->toHaveKey('data')
        ->and($foreignShow->json())->not->toHaveKey('data')
        ->and($missingShow->getContent())->not->toContain('Foreign Goal')
        ->and($foreignShow->getContent())->not->toContain('Foreign Goal')
        ->and($missingShow->getContent())->not->toContain('create_request_fingerprint')
        ->and($foreignShow->getContent())->not->toContain('create_request_fingerprint');
});

test('list is owner-scoped filtered ordered and validates pagination', function (): void {
    $fixtures = savingsApiFixtures(['name' => 'Goal A', 'targetAmountMinor' => 100]);
    $second = $fixtures['lifecycle']->createGoal(new CreateSavingsGoalInput(
        user: $fixtures['user'],
        idempotencyKey: (string) Str::uuid(),
        name: 'Goal B',
        currencyCode: 'PHP',
        targetAmountMinor: 200,
    ))->goal;
    $archived = $fixtures['lifecycle']->createGoal(new CreateSavingsGoalInput(
        user: $fixtures['user'],
        idempotencyKey: (string) Str::uuid(),
        name: 'Goal C',
        currencyCode: 'PHP',
        targetAmountMinor: 300,
    ))->goal;
    $fixtures['lifecycle']->archiveGoal($fixtures['user'], $archived->id);

    $fixtures['lifecycle']->createGoal(new CreateSavingsGoalInput(
        user: $fixtures['otherUser'],
        idempotencyKey: (string) Str::uuid(),
        name: 'Other',
        currencyCode: 'PHP',
        targetAmountMinor: 999,
    ));

    $all = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.index'));
    $all->assertOk()->assertJsonPath('meta.total', 3);
    expect(collect($all->json('data'))->pluck('id')->all())->toBe([
        $fixtures['goal']->id,
        $second->id,
        $archived->id,
    ]);

    $active = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.index', [
        'status' => 'active',
    ]));
    $active->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('filters.status', 'active');
    expect(collect($active->json('data'))->pluck('status')->unique()->all())->toBe(['active']);

    $archivedList = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.index', [
        'status' => 'archived',
    ]));
    $archivedList->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('filters.status', 'archived')
        ->assertJsonPath('data.0.id', $archived->id);

    $page = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.index', [
        'page' => 1,
        'per_page' => 2,
    ]));
    $page->assertOk()
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonCount(2, 'data');

    $max = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.index', [
        'per_page' => 100,
    ]));
    $max->assertOk()->assertJsonPath('meta.per_page', 100);

    $this->actingAs($fixtures['user'])->getJson(route('savings-goals.index', [
        'per_page' => 101,
    ]))->assertUnprocessable()->assertJsonValidationErrors('per_page');

    $this->actingAs($fixtures['user'])->getJson(route('savings-goals.index', [
        'page' => 0,
    ]))->assertUnprocessable()->assertJsonValidationErrors('page');

    $this->actingAs($fixtures['user'])->getJson(route('savings-goals.index', [
        'status' => 'completed',
    ]))->assertUnprocessable()->assertJsonValidationErrors('status');

    $emptyUser = User::factory()->create();
    $empty = $this->actingAs($emptyUser)->getJson(route('savings-goals.index'));
    $empty->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.from', null)
        ->assertJsonPath('meta.to', null);
});

test('progress semantics include overfunding flooring and archived readability', function (): void {
    $fixtures = savingsApiFixtures(['targetAmountMinor' => 3]);
    $fixtures['allocation']->contribute(savingsApiAllocate($fixtures['user'], $fixtures['goal']->id, 1));
    $fixtures['allocation']->contribute(savingsApiAllocate($fixtures['user'], $fixtures['goal']->id, 1));

    $partial = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.show', $fixtures['goal']->id));
    $partial->assertOk()
        ->assertJsonPath('data.allocated_amount_minor', '2')
        ->assertJsonPath('data.remaining_amount_minor', '1')
        ->assertJsonPath('data.progress_basis_points', 6666)
        ->assertJsonPath('data.target_reached', false);

    $near = savingsApiFixtures([
        'name' => 'Near Max',
        'targetAmountMinor' => Money::MAX_MINOR_AMOUNT,
    ]);
    $near['allocation']->contribute(savingsApiAllocate(
        $near['user'],
        $near['goal']->id,
        Money::MAX_MINOR_AMOUNT - 1,
    ));
    $nearShow = $this->actingAs($near['user'])->getJson(route('savings-goals.show', $near['goal']->id));
    $nearShow->assertOk()
        ->assertJsonPath('data.progress_basis_points', 9999)
        ->assertJsonPath('data.target_reached', false)
        ->assertJsonPath('data.remaining_amount_minor', '1');

    $over = savingsApiFixtures(['name' => 'Over', 'targetAmountMinor' => 100]);
    $over['allocation']->contribute(savingsApiAllocate($over['user'], $over['goal']->id, 150));
    $overShow = $this->actingAs($over['user'])->getJson(route('savings-goals.show', $over['goal']->id));
    $overShow->assertOk()
        ->assertJsonPath('data.allocated_amount_minor', '150')
        ->assertJsonPath('data.remaining_amount_minor', '0')
        ->assertJsonPath('data.overfunded_amount_minor', '50')
        ->assertJsonPath('data.progress_basis_points', 10_000)
        ->assertJsonPath('data.target_reached', true);

    $over['lifecycle']->archiveGoal($over['user'], $over['goal']->id);
    $archived = $this->actingAs($over['user'])->getJson(route('savings-goals.show', $over['goal']->id));
    $archived->assertOk()
        ->assertJsonPath('data.status', SavingsGoalStatus::Archived->value)
        ->assertJsonPath('data.allocated_amount_minor', '150')
        ->assertJsonPath('data.progress_basis_points', 10_000)
        ->assertJsonPath('data.archived_at', fn ($value) => is_string($value) && $value !== '');
});

test('event history serializes contribution and withdrawal with pagination', function (): void {
    $fixtures = savingsApiFixtures(['targetAmountMinor' => 10_000]);
    $fixtures['allocation']->contribute(savingsApiAllocate(
        $fixtures['user'],
        $fixtures['goal']->id,
        4_000,
        'First deposit',
    ));
    $fixtures['allocation']->contribute(savingsApiAllocate($fixtures['user'], $fixtures['goal']->id, 3_000));
    $fixtures['allocation']->withdraw(savingsApiAllocate($fixtures['user'], $fixtures['goal']->id, 1_000, 'Adjust'));

    $events = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.events', [
        'goal' => $fixtures['goal']->id,
        'per_page' => 2,
        'page' => 1,
    ]));
    $events->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'contribution')
        ->assertJsonPath('data.0.amount_minor', '4000')
        ->assertJsonPath('data.0.note', 'First deposit')
        ->assertJsonPath('data.1.type', 'contribution')
        ->assertJsonPath('data.1.amount_minor', '3000');

    $page2 = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.events', [
        'goal' => $fixtures['goal']->id,
        'per_page' => 2,
        'page' => 2,
    ]));
    $page2->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'withdrawal')
        ->assertJsonPath('data.0.amount_minor', '1000')
        ->assertJsonPath('data.0.note', 'Adjust');

    $this->actingAs($fixtures['user'])->getJson(route('savings-goals.events', [
        'goal' => $fixtures['goal']->id,
        'per_page' => 0,
    ]))->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

test('portfolio separates currencies and excludes archived monetary totals', function (): void {
    $fixtures = savingsApiFixtures([
        'name' => 'PHP Active',
        'currencyCode' => 'PHP',
        'targetAmountMinor' => 1_000,
    ]);
    $fixtures['allocation']->contribute(savingsApiAllocate($fixtures['user'], $fixtures['goal']->id, 400));

    $usd = $fixtures['lifecycle']->createGoal(new CreateSavingsGoalInput(
        user: $fixtures['user'],
        idempotencyKey: (string) Str::uuid(),
        name: 'USD Active',
        currencyCode: 'USD',
        targetAmountMinor: 500,
    ))->goal;
    $fixtures['allocation']->contribute(savingsApiAllocate($fixtures['user'], $usd->id, 500));

    $archived = $fixtures['lifecycle']->createGoal(new CreateSavingsGoalInput(
        user: $fixtures['user'],
        idempotencyKey: (string) Str::uuid(),
        name: 'PHP Archived',
        currencyCode: 'PHP',
        targetAmountMinor: 9_000,
    ))->goal;
    $fixtures['allocation']->contribute(savingsApiAllocate($fixtures['user'], $archived->id, 9_000));
    $fixtures['lifecycle']->archiveGoal($fixtures['user'], $archived->id);

    $fixtures['lifecycle']->createGoal(new CreateSavingsGoalInput(
        user: $fixtures['otherUser'],
        idempotencyKey: (string) Str::uuid(),
        name: 'Other PHP',
        currencyCode: 'PHP',
        targetAmountMinor: 50_000,
    ));

    $portfolio = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.portfolio'));
    $portfolio->assertOk()
        ->assertJsonPath('data.total_goal_count', 3)
        ->assertJsonPath('data.active_goal_count', 2)
        ->assertJsonPath('data.archived_goal_count', 1)
        ->assertJsonPath('data.active_at_target_count', 1)
        ->assertJsonPath('data.active_below_target_count', 1)
        ->assertJsonPath('data.currencies.0.currency_code', 'PHP')
        ->assertJsonPath('data.currencies.0.active_goal_count', 1)
        ->assertJsonPath('data.currencies.0.total_target_minor', '1000')
        ->assertJsonPath('data.currencies.0.total_allocated_minor', '400')
        ->assertJsonPath('data.currencies.0.total_remaining_minor', '600')
        ->assertJsonPath('data.currencies.1.currency_code', 'USD')
        ->assertJsonPath('data.currencies.1.total_allocated_minor', '500')
        ->assertJsonPath('data.currencies.1.total_remaining_minor', '0');

    $empty = $this->actingAs(User::factory()->create())->getJson(route('savings-goals.portfolio'));
    $empty->assertOk()
        ->assertJsonPath('data.total_goal_count', 0)
        ->assertJsonPath('data.currencies', []);
});

test('monetary minor units above max safe integer serialize as exact json strings', function (): void {
    $aboveSafe = 9_007_199_254_740_992;
    expect($aboveSafe)->toBeGreaterThan(9_007_199_254_740_991);

    $fixtures = savingsApiFixtures([
        'name' => 'Huge',
        'targetAmountMinor' => Money::MAX_MINOR_AMOUNT,
    ]);
    $fixtures['allocation']->contribute(savingsApiAllocate(
        $fixtures['user'],
        $fixtures['goal']->id,
        $aboveSafe,
    ));

    $show = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.show', $fixtures['goal']->id));
    $show->assertOk();

    $raw = $show->getContent();
    expect($raw)->toContain('"target_amount_minor":"1000000000000000000"')
        ->and($raw)->toContain('"allocated_amount_minor":"9007199254740992"')
        ->and($raw)->not->toMatch('/"target_amount_minor":1000000000000000000/')
        ->and($raw)->not->toMatch('/"allocated_amount_minor":9007199254740992(?!")/');

    $decoded = $show->json('data');
    expect($decoded['target_amount_minor'])->toBe('1000000000000000000')
        ->and($decoded['allocated_amount_minor'])->toBe('9007199254740992')
        ->and(is_string($decoded['target_amount_minor']))->toBeTrue()
        ->and(is_string($decoded['allocated_amount_minor']))->toBeTrue();

    $events = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.events', $fixtures['goal']->id));
    $eventsRaw = $events->getContent();
    expect($eventsRaw)->toContain('"amount_minor":"9007199254740992"');

    $portfolio = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.portfolio'));
    $portfolioRaw = $portfolio->getContent();
    expect($portfolioRaw)->toContain('"total_target_minor":"1000000000000000000"')
        ->and($portfolioRaw)->toContain('"total_allocated_minor":"9007199254740992"');
});

test('portfolio aggregate overflow returns generic http 500 not 422', function (): void {
    $user = User::factory()->create();
    $lifecycle = app(SavingsGoalService::class);
    $allocation = app(SavingsGoalAllocationService::class);

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

    $allocation->contribute(savingsApiAllocate($user, $first->id, Money::MAX_MINOR_AMOUNT));
    $allocation->contribute(savingsApiAllocate($user, $second->id, Money::MAX_MINOR_AMOUNT));

    $response = $this->actingAs($user)->getJson(route('savings-goals.portfolio'));
    $response->assertStatus(500)
        ->assertJsonPath('message', 'Unable to compute savings portfolio summary.')
        ->assertJsonMissingPath('errors');

    expect($response->getContent())->not->toContain('SQL')
        ->and($response->getContent())->not->toContain('overflow')
        ->and($response->getContent())->not->toContain((string) Money::MAX_MINOR_AMOUNT);
});

test('read endpoints do not write goals events audits or ledger rows', function (): void {
    $fixtures = savingsApiFixtures(['targetAmountMinor' => 500]);
    $fixtures['allocation']->contribute(savingsApiAllocate($fixtures['user'], $fixtures['goal']->id, 100));
    $before = savingsApiSnapshotCounts();

    $this->actingAs($fixtures['user'])->getJson(route('savings-goals.index'))->assertOk();
    $this->actingAs($fixtures['user'])->getJson(route('savings-goals.show', $fixtures['goal']->id))->assertOk();
    $this->actingAs($fixtures['user'])->getJson(route('savings-goals.portfolio'))->assertOk();
    $this->actingAs($fixtures['user'])->getJson(route('savings-goals.events', $fixtures['goal']->id))->assertOk();

    expect(savingsApiSnapshotCounts())->toBe($before);
});

test('goal list does not issue one event aggregation query per goal', function (): void {
    $fixtures = savingsApiFixtures(['name' => 'G0', 'targetAmountMinor' => 100]);

    for ($i = 1; $i <= 4; $i++) {
        $goal = $fixtures['lifecycle']->createGoal(new CreateSavingsGoalInput(
            user: $fixtures['user'],
            idempotencyKey: (string) Str::uuid(),
            name: "G{$i}",
            currencyCode: 'PHP',
            targetAmountMinor: 100 + $i,
        ))->goal;
        $fixtures['allocation']->contribute(savingsApiAllocate($fixtures['user'], $goal->id, 10));
        $fixtures['allocation']->contribute(savingsApiAllocate($fixtures['user'], $goal->id, 5));
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($fixtures['user'])->getJson(route('savings-goals.index', [
        'per_page' => 25,
    ]))->assertOk()->assertJsonPath('meta.total', 5);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    $eventQueries = $queries->filter(
        fn (array $q): bool => str_contains(strtolower($q['query']), 'savings_goal_events'),
    );

    expect($eventQueries->count())->toBe(1);
});

test('goal and event responses serialize required timestamps and nullable archived_at', function (): void {
    $fixtures = savingsApiFixtures(['targetAmountMinor' => 100]);
    $fixtures['allocation']->contribute(savingsApiAllocate($fixtures['user'], $fixtures['goal']->id, 40));

    $active = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.show', $fixtures['goal']->id));
    $active->assertOk();
    expect($active->json('data.created_at'))->toBeString()->not->toBe('')
        ->and($active->json('data.updated_at'))->toBeString()->not->toBe('')
        ->and($active->json('data.archived_at'))->toBeNull();

    $events = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.events', $fixtures['goal']->id));
    $events->assertOk();
    expect($events->json('data.0.created_at'))->toBeString()->not->toBe('')
        ->and($events->json('data.0.updated_at'))->toBeString()->not->toBe('');

    $fixtures['lifecycle']->archiveGoal($fixtures['user'], $fixtures['goal']->id);
    $archived = $this->actingAs($fixtures['user'])->getJson(route('savings-goals.show', $fixtures['goal']->id));
    $archived->assertOk();
    expect($archived->json('data.archived_at'))->toBeString()->not->toBe('')
        ->and($archived->json('data.created_at'))->toBeString()
        ->and($archived->json('data.updated_at'))->toBeString();
});

test('timestamp serializers fail closed without fabricating or nulling required values', function (): void {
    $controller = app(SavingsGoalController::class);
    $required = new ReflectionMethod(SavingsGoalController::class, 'formatRequiredTimestamp');
    $nullable = new ReflectionMethod(SavingsGoalController::class, 'formatNullableTimestamp');

    $valid = Carbon::parse('2026-11-01 10:00:00', 'UTC');
    expect($required->invoke($controller, $valid, 'created_at'))
        ->toBe($valid->toIso8601String())
        ->and($nullable->invoke($controller, null, 'archived_at'))->toBeNull()
        ->and($nullable->invoke($controller, $valid, 'archived_at'))
        ->toBe($valid->toIso8601String());

    expect(fn () => $required->invoke($controller, null, 'created_at'))
        ->toThrow(LedgerIntegrityException::class, 'created_at')
        ->and(fn () => $required->invoke($controller, null, 'updated_at'))
        ->toThrow(LedgerIntegrityException::class, 'updated_at')
        ->and(fn () => $required->invoke($controller, '2026-11-01', 'created_at'))
        ->toThrow(LedgerIntegrityException::class)
        ->and(fn () => $required->invoke($controller, 1_730_448_000, 'updated_at'))
        ->toThrow(LedgerIntegrityException::class)
        ->and(fn () => $nullable->invoke($controller, '2026-11-01', 'archived_at'))
        ->toThrow(LedgerIntegrityException::class, 'archived_at')
        ->and(fn () => $nullable->invoke($controller, 1_730_448_000, 'archived_at'))
        ->toThrow(LedgerIntegrityException::class);

    // Required failures must not silently become null.
    foreach ([null, '2026-11-01', 123] as $invalid) {
        try {
            $required->invoke($controller, $invalid, 'created_at');
            expect(false)->toBeTrue();
        } catch (LedgerIntegrityException $exception) {
            expect($exception->getMessage())->not->toBe('')
                ->and($exception->getMessage())->not->toContain(Carbon::now()->toIso8601String());
        }
    }
});

test('shared authorization rejects invalid and oversized goal route identifiers safely', function (): void {
    $fixtures = savingsApiFixtures();
    $foreign = $fixtures['lifecycle']->createGoal(new CreateSavingsGoalInput(
        user: $fixtures['otherUser'],
        idempotencyKey: (string) Str::uuid(),
        name: 'Foreign',
        currencyCode: 'PHP',
        targetAmountMinor: 100,
    ))->goal;

    foreach (['show', 'events'] as $routeName) {
        $owned = $this->actingAs($fixtures['user'])->getJson(
            route("savings-goals.{$routeName}", $fixtures['goal']->id),
        );
        $owned->assertOk();

        $foreignResponse = $this->actingAs($fixtures['user'])->getJson(
            route("savings-goals.{$routeName}", $foreign->id),
        );
        $missingResponse = $this->actingAs($fixtures['user'])->getJson(
            route("savings-goals.{$routeName}", $fixtures['goal']->id + 50_000),
        );

        $foreignResponse->assertForbidden();
        $missingResponse->assertForbidden();
        expect($foreignResponse->json('message'))->toBe($missingResponse->json('message'));

        // whereNumber rejects non-numeric IDs before FormRequest authorization.
        $this->actingAs($fixtures['user'])
            ->getJson('/savings-goals/abc'.($routeName === 'events' ? '/events' : ''))
            ->assertNotFound();
    }

    $resolveShow = new ReflectionMethod(ShowSavingsGoalRequest::class, 'resolvePositiveRouteGoalId');
    $resolveEvents = new ReflectionMethod(IndexSavingsGoalEventRequest::class, 'resolvePositiveRouteGoalId');

    $makeRequestWithGoal = function (string $goalParam): ShowSavingsGoalRequest {
        $request = ShowSavingsGoalRequest::create('/savings-goals/'.$goalParam, 'GET');
        $route = Mockery::mock(Route::class);
        $route->shouldReceive('parameter')->with('goal', null)->andReturn($goalParam);

        $request->setRouteResolver(fn () => $route);

        return $request;
    };

    $makeEventRequestWithGoal = function (string $goalParam): IndexSavingsGoalEventRequest {
        $request = IndexSavingsGoalEventRequest::create(
            '/savings-goals/'.$goalParam.'/events',
            'GET',
        );
        $route = Mockery::mock(Route::class);
        $route->shouldReceive('parameter')->with('goal', null)->andReturn($goalParam);
        $request->setRouteResolver(fn () => $route);

        return $request;
    };

    $oversized = (string) PHP_INT_MAX.'0';
    expect($resolveShow->invoke($makeRequestWithGoal($oversized)))->toBeNull()
        ->and($resolveEvents->invoke($makeEventRequestWithGoal($oversized)))->toBeNull()
        ->and($resolveShow->invoke($makeRequestWithGoal('0')))->toBeNull()
        ->and($resolveShow->invoke($makeRequestWithGoal('-1')))->toBeNull()
        ->and($resolveShow->invoke($makeRequestWithGoal('01')))->toBeNull()
        ->and($resolveShow->invoke($makeRequestWithGoal((string) $fixtures['goal']->id)))
        ->toBe((int) $fixtures['goal']->id);

    // Oversized digit string must not coerce into an unintended existing goal id.
    $coerced = (int) $oversized;
    if ($coerced === (int) $fixtures['goal']->id || $coerced === (int) $foreign->id) {
        expect($resolveShow->invoke($makeRequestWithGoal($oversized)))->toBeNull();
    }

    $before = savingsApiSnapshotCounts();
    $this->actingAs($fixtures['user'])->getJson(route('savings-goals.show', $fixtures['goal']->id))->assertOk();
    $this->actingAs($fixtures['user'])->getJson(route('savings-goals.events', $fixtures['goal']->id))->assertOk();
    expect(savingsApiSnapshotCounts())->toBe($before);
});
