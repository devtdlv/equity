<?php

use App\DataTransferObjects\CreateSavingsGoalInput;
use App\Enums\SavingsGoalEventType;
use App\Enums\SavingsGoalStatus;
use App\Models\FinancialTransaction;
use App\Models\JournalEntry;
use App\Models\JournalPosting;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalEvent;
use App\Models\User;
use App\Services\SavingsGoalService;
use Database\Seeders\CurrencySeeder;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
});

function fingerprintMigration(): object
{
    return require base_path(
        'database/migrations/2026_10_31_000014_add_create_request_fingerprint_to_savings_goals_table.php',
    );
}

/**
 * Insert a goal using the pre-014 column set (no fingerprint).
 *
 * @param  array<string, mixed>  $overrides
 */
function insertPreFingerprintGoal(User $user, array $overrides = []): int
{
    $payload = array_merge([
        'user_id' => $user->id,
        'idempotency_key' => (string) Str::uuid(),
        'name' => 'Pre-fingerprint Goal',
        'description' => 'Created before 000014',
        'currency_code' => 'PHP',
        'target_amount_minor' => 50_000_00,
        'target_date' => '2027-06-01',
        'status' => 'active',
        'archived_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    return (int) DB::table('savings_goals')->insertGetId($payload);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertPreFingerprintEvent(User $user, int $goalId, array $overrides = []): int
{
    $payload = array_merge([
        'user_id' => $user->id,
        'savings_goal_id' => $goalId,
        'idempotency_key' => (string) Str::uuid(),
        'type' => SavingsGoalEventType::Contribution->value,
        'amount_minor' => 1_000_00,
        'currency_code' => 'PHP',
        'note' => 'Pre-014 event',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    return (int) DB::table('savings_goal_events')->insertGetId($payload);
}

/**
 * Dedicated SQLite :memory: connection with migrations through 000013 only.
 * Used for real Migrator bookkeeping assertions (not the RefreshDatabase connection).
 */
function bootstrapFingerprintVerifyConnectionThrough000013(): string
{
    $connection = 'savings_fingerprint_verify_'.Str::lower(Str::random(8));

    config([
        "database.connections.{$connection}" => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge($connection);
    DB::setDefaultConnection($connection);
    Schema::connection($connection)->getConnection()->getSchemaBuilder();

    /** @var Migrator $migrator */
    $migrator = app('migrator');
    $migrator->setConnection($connection);
    $migrator->getRepository()->createRepository();

    $paths = collect(glob(database_path('migrations/*.php')))
        ->sort()
        ->reject(fn (string $path): bool => str_contains($path, '000014_add_create_request_fingerprint'))
        ->values()
        ->all();

    foreach ($paths as $path) {
        $migrator->run([$path]);
    }

    expect(Schema::hasTable('savings_goals'))->toBeTrue()
        ->and(Schema::hasColumn('savings_goals', 'create_request_fingerprint'))->toBeFalse()
        ->and($migrator->repositoryExists())->toBeTrue();

    $ran = collect($migrator->getRepository()->getRan());
    expect($ran->contains(fn (string $name): bool => str_contains($name, '000013_create_savings_goals')))->toBeTrue()
        ->and($ran->contains(fn (string $name): bool => str_contains($name, '000014_add_create_request_fingerprint')))->toBeFalse();

    return $connection;
}

test('phpunit testing database is isolated sqlite memory', function (): void {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
});

test('empty savings_goals allows fingerprint migration and requires fingerprint for new rows', function (): void {
    $migration = fingerprintMigration();

    expect(Schema::hasColumn('savings_goals', 'create_request_fingerprint'))->toBeTrue();

    $migration->down();

    expect(Schema::hasColumn('savings_goals', 'create_request_fingerprint'))->toBeFalse()
        ->and(DB::table('savings_goals')->count())->toBe(0);

    $txBefore = FinancialTransaction::query()->count();
    $jeBefore = JournalEntry::query()->count();
    $jpBefore = JournalPosting::query()->count();
    $eventBefore = SavingsGoalEvent::query()->count();

    $migration->up();

    expect(Schema::hasColumn('savings_goals', 'create_request_fingerprint'))->toBeTrue()
        ->and(Schema::hasTable('savings_goal_events'))->toBeTrue()
        ->and(Schema::hasColumn('savings_goals', 'completed_at'))->toBeFalse()
        ->and(Schema::hasColumn('savings_goals', 'financial_account_id'))->toBeFalse();

    $user = User::factory()->create();

    expect(fn () => DB::table('savings_goals')->insert([
        'user_id' => $user->id,
        'idempotency_key' => (string) Str::uuid(),
        'name' => 'Missing fingerprint',
        'description' => null,
        'currency_code' => 'PHP',
        'target_amount_minor' => 1000,
        'target_date' => null,
        'status' => 'active',
        'archived_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(FinancialTransaction::query()->count())->toBe($txBefore)
        ->and(JournalEntry::query()->count())->toBe($jeBefore)
        ->and(JournalPosting::query()->count())->toBe($jpBefore)
        ->and(SavingsGoalEvent::query()->count())->toBe($eventBefore);
});

test('existing savings goal rows cause fingerprint migration to fail closed without schema or data changes', function (): void {
    $migration = fingerprintMigration();
    $user = User::factory()->create();

    $migration->down();

    expect(Schema::hasColumn('savings_goals', 'create_request_fingerprint'))->toBeFalse();

    $goalId = insertPreFingerprintGoal($user, [
        'name' => 'Existing Before 014',
        'description' => 'Must remain unchanged',
        'target_amount_minor' => 77_000_00,
    ]);
    $eventId = insertPreFingerprintEvent($user, $goalId);

    $goalBefore = (array) DB::table('savings_goals')->where('id', $goalId)->first();
    $eventBefore = (array) DB::table('savings_goal_events')->where('id', $eventId)->first();
    $txBefore = FinancialTransaction::query()->count();
    $jeBefore = JournalEntry::query()->count();
    $jpBefore = JournalPosting::query()->count();

    expect(fn () => $migration->up())->toThrow(
        RuntimeException::class,
        'Cannot add create_request_fingerprint while savings_goals contains existing rows',
    );

    expect(Schema::hasColumn('savings_goals', 'create_request_fingerprint'))->toBeFalse()
        ->and((array) DB::table('savings_goals')->where('id', $goalId)->first())->toBe($goalBefore)
        ->and((array) DB::table('savings_goal_events')->where('id', $eventId)->first())->toBe($eventBefore)
        ->and(Schema::hasTable('savings_goal_events'))->toBeTrue()
        ->and(FinancialTransaction::query()->count())->toBe($txBefore)
        ->and(JournalEntry::query()->count())->toBe($jeBefore)
        ->and(JournalPosting::query()->count())->toBe($jpBefore)
        ->and(SavingsGoalEvent::query()->count())->toBe(1);
});

test('service create after successful fingerprint migration stores sha-256 and supports replay', function (): void {
    $user = User::factory()->create();
    $service = app(SavingsGoalService::class);
    $uuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    $created = $service->createGoal(new CreateSavingsGoalInput(
        user: $user,
        idempotencyKey: $uuid,
        name: 'Fingerprint Service Goal',
        currencyCode: 'PHP',
        targetAmountMinor: 12_500_00,
        description: 'Durable create',
        targetDate: '2028-02-29',
    ));

    $fp = (string) $created->goal->create_request_fingerprint;

    expect($created->wasRecentlyCreated)->toBeTrue()
        ->and(strlen($fp))->toBe(64)
        ->and(ctype_xdigit($fp))->toBeTrue()
        ->and($fp)->toBe(strtolower($fp));

    $replay = $service->createGoal(new CreateSavingsGoalInput(
        user: $user,
        idempotencyKey: $uuid,
        name: 'Fingerprint Service Goal',
        currencyCode: 'PHP',
        targetAmountMinor: 12_500_00,
        description: 'Durable create',
        targetDate: '2028-02-29',
    ));

    expect($replay->wasRecentlyCreated)->toBeFalse()
        ->and($replay->goal->id)->toBe($created->goal->id)
        ->and((string) $replay->goal->create_request_fingerprint)->toBe($fp)
        ->and(SavingsGoalEvent::query()->count())->toBe(0)
        ->and(FinancialTransaction::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(JournalPosting::query()->count())->toBe(0);
});

test('rollback removes only fingerprint column and preserves goals and events', function (): void {
    $migration = fingerprintMigration();
    $user = User::factory()->create();

    $goal = SavingsGoal::query()->create([
        'user_id' => $user->id,
        'idempotency_key' => (string) Str::uuid(),
        'create_request_fingerprint' => hash('sha256', 'rollback-fixture'),
        'name' => 'Rollback Goal',
        'description' => null,
        'currency_code' => 'PHP',
        'target_amount_minor' => 9_000_00,
        'target_date' => null,
        'status' => SavingsGoalStatus::Active,
        'archived_at' => null,
    ]);

    $event = SavingsGoalEvent::query()->create([
        'user_id' => $user->id,
        'savings_goal_id' => $goal->id,
        'idempotency_key' => (string) Str::uuid(),
        'type' => SavingsGoalEventType::Withdrawal,
        'amount_minor' => 500_00,
        'currency_code' => 'PHP',
        'note' => null,
    ]);

    $migration->down();

    expect(Schema::hasColumn('savings_goals', 'create_request_fingerprint'))->toBeFalse()
        ->and(Schema::hasTable('savings_goals'))->toBeTrue()
        ->and(Schema::hasTable('savings_goal_events'))->toBeTrue()
        ->and(DB::table('savings_goals')->where('id', $goal->id)->exists())->toBeTrue()
        ->and(DB::table('savings_goal_events')->where('id', $event->id)->exists())->toBeTrue()
        ->and(DB::table('savings_goals')->where('id', $goal->id)->value('name'))->toBe('Rollback Goal')
        ->and((int) DB::table('savings_goal_events')->where('id', $event->id)->value('amount_minor'))->toBe(500_00);
});

test('reapplication refuses when goals remain and succeeds when table is empty again', function (): void {
    $migration = fingerprintMigration();
    $user = User::factory()->create();

    SavingsGoal::query()->create([
        'user_id' => $user->id,
        'idempotency_key' => (string) Str::uuid(),
        'create_request_fingerprint' => hash('sha256', 'reapply-fixture'),
        'name' => 'Still Present',
        'description' => null,
        'currency_code' => 'PHP',
        'target_amount_minor' => 3_000_00,
        'target_date' => null,
        'status' => SavingsGoalStatus::Active,
        'archived_at' => null,
    ]);

    $migration->down();

    expect(Schema::hasColumn('savings_goals', 'create_request_fingerprint'))->toBeFalse()
        ->and(DB::table('savings_goals')->count())->toBe(1);

    expect(fn () => $migration->up())->toThrow(
        RuntimeException::class,
        'Cannot add create_request_fingerprint while savings_goals contains existing rows',
    );

    expect(Schema::hasColumn('savings_goals', 'create_request_fingerprint'))->toBeFalse();

    DB::table('savings_goals')->delete();

    $migration->up();

    expect(Schema::hasColumn('savings_goals', 'create_request_fingerprint'))->toBeTrue()
        ->and(DB::table('savings_goals')->count())->toBe(0);
});

test('migrator does not record 000014 when existing-row guard rejects the migration', function (): void {
    $previousDefault = config('database.default');
    $connection = bootstrapFingerprintVerifyConnectionThrough000013();

    try {
        DB::table('currencies')->insert([
            'code' => 'PHP',
            'name' => 'Philippine Peso',
            'symbol' => '₱',
            'minor_unit_exponent' => 2,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Migrator Owner',
            'email' => 'migrator-owner@example.com',
            'password' => 'secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('savings_goals')->insert([
            'user_id' => $userId,
            'idempotency_key' => '11111111-1111-4111-8111-111111111111',
            'name' => 'Blocks 014',
            'description' => null,
            'currency_code' => 'PHP',
            'target_amount_minor' => 1000,
            'target_date' => null,
            'status' => 'active',
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $migrator->setConnection($connection);

        $path = database_path(
            'migrations/2026_10_31_000014_add_create_request_fingerprint_to_savings_goals_table.php',
        );

        expect(fn () => $migrator->run([$path]))->toThrow(
            RuntimeException::class,
            'Cannot add create_request_fingerprint while savings_goals contains existing rows',
        );

        $ran = collect($migrator->getRepository()->getRan());

        expect(Schema::hasColumn('savings_goals', 'create_request_fingerprint'))->toBeFalse()
            ->and(DB::table('savings_goals')->count())->toBe(1)
            ->and($ran->contains(fn (string $name): bool => str_contains($name, '000014_add_create_request_fingerprint')))
            ->toBeFalse();
    } finally {
        DB::purge($connection);
        DB::setDefaultConnection($previousDefault);
        config(['database.default' => $previousDefault]);
        app('migrator')->setConnection($previousDefault);
    }
});

test('migrator records 000014 only after empty-table success', function (): void {
    $previousDefault = config('database.default');
    $connection = bootstrapFingerprintVerifyConnectionThrough000013();

    try {
        expect(DB::table('savings_goals')->count())->toBe(0);

        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $migrator->setConnection($connection);

        $path = database_path(
            'migrations/2026_10_31_000014_add_create_request_fingerprint_to_savings_goals_table.php',
        );

        $migrator->run([$path]);

        $ran = collect($migrator->getRepository()->getRan());

        expect(Schema::hasColumn('savings_goals', 'create_request_fingerprint'))->toBeTrue()
            ->and($ran->contains(fn (string $name): bool => str_contains($name, '000014_add_create_request_fingerprint')))
            ->toBeTrue();
    } finally {
        DB::purge($connection);
        DB::setDefaultConnection($previousDefault);
        config(['database.default' => $previousDefault]);
        app('migrator')->setConnection($previousDefault);
    }
});
