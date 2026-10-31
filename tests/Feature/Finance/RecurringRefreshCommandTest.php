<?php

use App\Console\Commands\RecurringRefreshCommand;
use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\CreateRecurringObligationInput;
use App\DataTransferObjects\LinkRecurringPaymentInput;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringObligationStatus;
use App\Enums\RecurringOccurrenceStatus;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\JournalPosting;
use App\Models\RecurringObligation;
use App\Models\RecurringOccurrence;
use App\Models\RecurringPaymentLink;
use App\Models\User;
use App\Services\CategoryService;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransactionService;
use App\Services\RecurringObligationService;
use App\Services\RecurringPaymentService;
use App\Support\RecurrenceCalculator;
use Database\Seeders\CurrencySeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{
 *     user: User,
 *     otherUser: User,
 *     obligationService: RecurringObligationService,
 *     paymentService: RecurringPaymentService,
 *     transactionService: FinancialTransactionService,
 *     rent: Category,
 *     checking: FinancialAccount
 * }
 */
function recurringRefreshFixtures(): array
{
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $obligationService = app(RecurringObligationService::class);
    $paymentService = app(RecurringPaymentService::class);
    $transactionService = app(FinancialTransactionService::class);
    $categoryService = app(CategoryService::class);
    $accountService = app(FinancialAccountService::class);

    $rent = $categoryService->createCategory($user, [
        'name' => 'Rent',
        'type' => CategoryType::Expense,
    ]);
    $checking = $accountService->createAccount($user, [
        'name' => 'Checking PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    return compact(
        'user',
        'otherUser',
        'obligationService',
        'paymentService',
        'transactionService',
        'rent',
        'checking',
    );
}

function createRefreshObligation(array $fixtures, array $overrides = []): RecurringObligation
{
    $result = $fixtures['obligationService']->create(new CreateRecurringObligationInput(
        user: $overrides['user'] ?? $fixtures['user'],
        idempotencyKey: $overrides['idempotencyKey'] ?? (string) Str::uuid(),
        name: $overrides['name'] ?? 'Apartment Rent',
        amount: $overrides['amount'] ?? '15000.00',
        currencyCode: $overrides['currencyCode'] ?? 'PHP',
        categoryId: $overrides['categoryId'] ?? $fixtures['rent']->id,
        startDate: $overrides['startDate'] ?? '2026-10-31',
        frequency: $overrides['frequency'] ?? RecurringFrequency::Monthly,
        anchorDay: $overrides['anchorDay'] ?? 31,
        endsOfMonth: $overrides['endsOfMonth'] ?? false,
        description: $overrides['description'] ?? 'Monthly rent',
        financialAccountId: array_key_exists('financialAccountId', $overrides)
            ? $overrides['financialAccountId']
            : $fixtures['checking']->id,
    ));

    return $result->obligation;
}

test('phpunit testing database is isolated sqlite memory', function (): void {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
});

test('command refreshes active obligations and skips paused and cancelled', function (): void {
    $fixtures = recurringRefreshFixtures();
    $active = createRefreshObligation($fixtures, ['name' => 'Active Bill']);
    $paused = createRefreshObligation($fixtures, ['name' => 'Paused Bill']);
    $cancelled = createRefreshObligation($fixtures, ['name' => 'Cancelled Bill']);

    $fixtures['obligationService']->pause($fixtures['user'], (int) $paused->id);
    $fixtures['obligationService']->cancel($fixtures['user'], (int) $cancelled->id);

    $pausedSnapshot = RecurringObligation::query()->findOrFail($paused->id)->only([
        'status', 'paused_at', 'cancelled_at', 'amount_minor', 'next_occurrence_date', 'updated_at',
    ]);
    $cancelledSnapshot = RecurringObligation::query()->findOrFail($cancelled->id)->only([
        'status', 'paused_at', 'cancelled_at', 'amount_minor', 'next_occurrence_date', 'updated_at',
    ]);
    $pausedCount = RecurringOccurrence::query()->where('obligation_id', $paused->id)->count();
    $cancelledCount = RecurringOccurrence::query()->where('obligation_id', $cancelled->id)->count();

    Carbon::setTestNow(Carbon::parse('2027-04-15 12:00:00', 'UTC'));

    $this->artisan(RecurringRefreshCommand::SIGNATURE_NAME)
        ->expectsOutputToContain('failed=0')
        ->assertSuccessful();

    $activeDates = RecurringOccurrence::query()
        ->where('obligation_id', $active->id)
        ->orderBy('due_date')
        ->pluck('due_date')
        ->map(fn ($date) => $date->toDateString())
        ->all();

    expect($activeDates)->toBe([
        '2026-10-31',
        '2026-11-30',
        '2026-12-31',
        '2027-01-31',
        '2027-02-28',
        '2027-03-31',
        '2027-04-30',
        '2027-05-31',
        '2027-06-30',
    ]);

    expect(RecurringOccurrence::query()->where('obligation_id', $paused->id)->count())->toBe($pausedCount)
        ->and(RecurringOccurrence::query()->where('obligation_id', $cancelled->id)->count())->toBe($cancelledCount);

    $pausedFresh = RecurringObligation::query()->findOrFail($paused->id);
    $cancelledFresh = RecurringObligation::query()->findOrFail($cancelled->id);

    $pausedNext = $pausedSnapshot['next_occurrence_date'];
    $pausedNextString = $pausedNext instanceof Carbon
        ? $pausedNext->toDateString()
        : (string) $pausedNext;

    expect($pausedFresh->status)->toBe(RecurringObligationStatus::Paused)
        ->and($pausedFresh->amount_minor)->toBe($pausedSnapshot['amount_minor'])
        ->and($pausedFresh->next_occurrence_date?->toDateString())->toBe($pausedNextString)
        ->and($cancelledFresh->status)->toBe(RecurringObligationStatus::Cancelled)
        ->and($cancelledFresh->amount_minor)->toBe($cancelledSnapshot['amount_minor'])
        ->and($cancelledFresh->next_occurrence_date)->toBeNull();
});
test('repeated refresh is idempotent and preserves paid skipped links and ledger', function (): void {
    $fixtures = recurringRefreshFixtures();
    $obligation = createRefreshObligation($fixtures);

    $oct = RecurringOccurrence::query()
        ->where('obligation_id', $obligation->id)
        ->whereDate('due_date', '2026-10-31')
        ->firstOrFail();

    $nov = RecurringOccurrence::query()
        ->where('obligation_id', $obligation->id)
        ->whereDate('due_date', '2026-11-30')
        ->firstOrFail();

    $txn = $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['checking']->id,
        amount: '15000.00',
        entryDate: '2026-10-31',
        description: 'October rent',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $fixtures['rent']->id,
    ))->transaction;

    $link = $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $oct->id,
        financialTransactionId: (int) $txn->id,
    ))->paymentLink;

    $fixtures['obligationService']->skipOccurrence($fixtures['user'], (int) $nov->id);

    $journalsBefore = JournalEntry::query()->count();
    $postingsBefore = JournalPosting::query()->count();
    $linksBefore = RecurringPaymentLink::query()->count();
    $octExpected = (int) $oct->fresh()->expected_amount_minor;

    Carbon::setTestNow(Carbon::parse('2027-04-15 12:00:00', 'UTC'));

    $this->artisan(RecurringRefreshCommand::SIGNATURE_NAME)->assertSuccessful();
    $countAfterFirst = RecurringOccurrence::query()->where('obligation_id', $obligation->id)->count();

    $this->artisan(RecurringRefreshCommand::SIGNATURE_NAME)->assertSuccessful();
    $countAfterSecond = RecurringOccurrence::query()->where('obligation_id', $obligation->id)->count();

    expect($countAfterSecond)->toBe($countAfterFirst)
        ->and($countAfterFirst)->toBe(9);

    $oct->refresh();
    $nov->refresh();
    $link->refresh();

    expect($oct->status)->toBe(RecurringOccurrenceStatus::Scheduled)
        ->and((int) $oct->expected_amount_minor)->toBe($octExpected)
        ->and($nov->status)->toBe(RecurringOccurrenceStatus::Skipped)
        ->and($nov->active_slot_key->toDateString())->toBe('2026-11-30')
        ->and(RecurringPaymentLink::query()->whereKey($link->id)->exists())->toBeTrue()
        ->and(RecurringPaymentLink::query()->count())->toBe($linksBefore)
        ->and(JournalEntry::query()->count())->toBe($journalsBefore)
        ->and(JournalPosting::query()->count())->toBe($postingsBefore);

    $horizonEnd = RecurrenceCalculator::horizonBounds(
        $obligation->start_date->toDateString(),
        Carbon::parse('2027-04-15', 'UTC')->toImmutable(),
    )[1];
    expect($horizonEnd)->toBe('2027-07-15')
        ->and(
            RecurringOccurrence::query()
                ->where('obligation_id', $obligation->id)
                ->whereDate('due_date', '>', $horizonEnd)
                ->count(),
        )->toBe(0)
        ->and(
            RecurringOccurrence::query()
                ->where('obligation_id', $obligation->id)
                ->whereDate('due_date', '2027-06-30')
                ->exists(),
        )->toBeTrue()
        ->and(
            RecurringOccurrence::query()
                ->where('obligation_id', $obligation->id)
                ->whereDate('due_date', '2027-07-31')
                ->exists(),
        )->toBeFalse();
});

test('failure of one obligation does not stop others and exits nonzero', function (): void {
    $fixtures = recurringRefreshFixtures();
    $failing = createRefreshObligation($fixtures, ['name' => 'Failing Bill']);
    $ok = createRefreshObligation($fixtures, ['name' => 'Healthy Bill']);
    $failingId = (int) $failing->id;
    $okId = (int) $ok->id;

    $this->partialMock(RecurringObligationService::class, function ($mock) use ($failingId): void {
        $mock->shouldReceive('generateOccurrences')
            ->withArgs(fn (User $user, int $obligationId): bool => $obligationId === $failingId)
            ->once()
            ->andThrow(new LedgerIntegrityException('Forced refresh failure for isolation test.'));
    });

    Carbon::setTestNow(Carbon::parse('2027-04-15 12:00:00', 'UTC'));

    $failingCountBefore = RecurringOccurrence::query()->where('obligation_id', $failingId)->count();

    $exitCode = $this->withoutMockingConsoleOutput()
        ->artisan(RecurringRefreshCommand::SIGNATURE_NAME);
    $output = Artisan::output();

    expect($exitCode)->toBe(RecurringRefreshCommand::FAILURE)
        ->and($output)->toContain('processed=2 succeeded=1 failed=1')
        ->and(RecurringOccurrence::query()->where('obligation_id', $okId)->count())->toBe(9)
        ->and(RecurringOccurrence::query()->where('obligation_id', $failingId)->count())
        ->toBe($failingCountBefore);
});

test('command returns success when there are no active obligations', function (): void {
    $this->artisan(RecurringRefreshCommand::SIGNATURE_NAME)
        ->expectsOutputToContain('processed=0 succeeded=0 failed=0 occurrences_created=0')
        ->assertSuccessful();
});

test('invalid batch size is rejected', function (): void {
    $this->artisan(RecurringRefreshCommand::SIGNATURE_NAME, ['--batch-size' => 0])
        ->assertFailed();

    $this->artisan(RecurringRefreshCommand::SIGNATURE_NAME, ['--batch-size' => 501])
        ->assertFailed();
});

test('command fails when an overlapping cache lock is held', function (): void {
    $lock = Cache::lock(RecurringRefreshCommand::CACHE_LOCK_KEY, 30);
    expect($lock->get())->toBeTrue();

    try {
        $this->artisan(RecurringRefreshCommand::SIGNATURE_NAME)
            ->assertFailed()
            ->expectsOutputToContain('already running');
    } finally {
        $lock->release();
    }
});

test('scheduler registers daily UTC refresh with withoutOverlapping', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    $events = collect($schedule->events())
        ->filter(function ($event): bool {
            $command = (string) ($event->command ?? '');

            return str_contains($command, RecurringRefreshCommand::SIGNATURE_NAME);
        })
        ->values();

    expect($events)->toHaveCount(1);

    $event = $events->first();
    expect($event->expression)->toBe('15 2 * * *')
        ->and((string) $event->timezone)->toBe('UTC')
        ->and($event->withoutOverlapping)->toBeTrue();
});
