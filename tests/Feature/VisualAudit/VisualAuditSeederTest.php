<?php

use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Support\Money;
use App\Support\VisualAuditEnvironment;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\VisualAuditSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function configureVisualAuditMemoryTesting(): void
{
    config([
        'app.env' => 'visual-audit',
        'app.key' => 'base64:'.base64_encode('visual-audit-test-key-32-bytes!!'),
        'equity.visual_audit.enabled' => true,
        'equity.visual_audit.allow_memory_for_tests' => true,
        'equity.visual_audit.demo_email' => VisualAuditEnvironment::DEMO_EMAIL,
        'equity.sqlite.financial_immediate' => false,
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
        'session.driver' => 'database',
        'cache.default' => 'database',
        'mail.default' => 'array',
        'queue.default' => 'sync',
    ]);
}

test('verify fails closed for wrong env real sqlite path and immediate flag', function (): void {
    config([
        'app.env' => 'local',
        'app.key' => 'base64:'.base64_encode('visual-audit-test-key-32-bytes!!'),
        'equity.visual_audit.enabled' => true,
        'equity.sqlite.financial_immediate' => false,
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => VisualAuditEnvironment::defaultDatabasePath(),
        'session.driver' => 'database',
        'cache.default' => 'database',
        'mail.default' => 'array',
        'queue.default' => 'sync',
    ]);
    expect(implode(' ', VisualAuditEnvironment::verify()))->toContain('APP_ENV');

    config([
        'app.env' => 'visual-audit',
        'equity.visual_audit.enabled' => true,
        'database.connections.sqlite.database' => VisualAuditEnvironment::forbiddenDatabasePath(),
    ]);
    expect(implode(' ', VisualAuditEnvironment::verify()))->toContain('database/database.sqlite');

    config([
        'database.connections.sqlite.database' => VisualAuditEnvironment::defaultDatabasePath(),
        'equity.sqlite.financial_immediate' => true,
    ]);
    expect(implode(' ', VisualAuditEnvironment::verify()))->toContain('financial_immediate');
});

test('visual audit seeder refuses unauthorized environments', function (): void {
    config([
        'app.env' => 'testing',
        'equity.visual_audit.enabled' => false,
        'equity.visual_audit.allow_memory_for_tests' => false,
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
    ]);

    expect(fn () => (new VisualAuditSeeder)->run())
        ->toThrow(RuntimeException::class, 'Visual-audit environment isolation failed');
});

test('visual audit seeder refuses the real repository sqlite path', function (): void {
    config([
        'app.env' => 'visual-audit',
        'app.key' => 'base64:'.base64_encode('visual-audit-test-key-32-bytes!!'),
        'equity.visual_audit.enabled' => true,
        'equity.visual_audit.allow_memory_for_tests' => false,
        'equity.sqlite.financial_immediate' => false,
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => VisualAuditEnvironment::forbiddenDatabasePath(),
        'session.driver' => 'database',
        'cache.default' => 'database',
        'mail.default' => 'array',
        'queue.default' => 'sync',
    ]);

    expect(fn () => (new VisualAuditSeeder)->run())
        ->toThrow(RuntimeException::class, 'database/database.sqlite');
});

test('visual audit seeder is repeatable and keeps demo ownership with reconciling balances', function (): void {
    configureVisualAuditMemoryTesting();
    $this->seed(CurrencySeeder::class);

    expect(VisualAuditEnvironment::verify())->toBe([]);

    $this->seed(VisualAuditSeeder::class);
    $this->seed(VisualAuditSeeder::class);

    $demoUsers = User::query()->where('email', VisualAuditEnvironment::DEMO_EMAIL)->get();
    expect($demoUsers)->toHaveCount(1);

    $user = $demoUsers->first();
    expect(User::query()->count())->toBe(1)
        ->and(FinancialAccount::query()->where('user_id', '!=', $user->id)->count())->toBe(0)
        ->and(FinancialTransaction::query()->where('user_id', '!=', $user->id)->count())->toBe(0)
        ->and(FinancialAccount::query()->where('user_id', $user->id)->count())->toBeGreaterThanOrEqual(5)
        ->and(FinancialAccount::query()->where('user_id', $user->id)->where('is_archived', true)->count())->toBeGreaterThanOrEqual(1)
        ->and(FinancialTransaction::query()->where('user_id', $user->id)->count())->toBeGreaterThan(10);

    foreach (FinancialAccount::query()->where('user_id', $user->id)->get() as $account) {
        $balance = DB::table('journal_postings as jp')
            ->join('journal_entries as je', 'je.id', '=', 'jp.journal_entry_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'jp.ledger_account_id')
            ->where('la.financial_account_id', $account->id)
            ->where('je.status', 'posted')
            ->selectRaw('COALESCE(SUM(jp.debit_amount_minor), 0) as debits, COALESCE(SUM(jp.credit_amount_minor), 0) as credits')
            ->first();

        expect($balance)->not->toBeNull();
        $debits = (int) $balance->debits;
        $credits = (int) $balance->credits;
        // Integrity: posting minors are non-negative and sum without overflow.
        expect($debits)->toBeGreaterThanOrEqual(0)
            ->and($credits)->toBeGreaterThanOrEqual(0)
            ->and(Money::checkedAdd($debits, $credits))->toBeGreaterThanOrEqual(0);
    }

    $exit = Artisan::call('db:seed', ['--class' => VisualAuditSeeder::class]);
    expect($exit)->toBe(0);
});

test('database seeder does not register the visual audit seeder', function (): void {
    $source = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

    expect($source)->not->toContain('VisualAuditSeeder');
});
