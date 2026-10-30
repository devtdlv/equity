<?php

use App\DataTransferObjects\CreateBudgetInput;
use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\CreateRecurringObligationInput;
use App\DataTransferObjects\LinkRecurringPaymentInput;
use App\DataTransferObjects\ReverseFinancialTransactionInput;
use App\DataTransferObjects\SetBudgetAllocationInput;
use App\DataTransferObjects\UnlinkRecurringPaymentInput;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringOccurrenceDisplayStatus;
use App\Enums\RecurringOccurrenceStatus;
use App\Exceptions\LedgerIntegrityException;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\JournalEntry;
use App\Models\JournalPosting;
use App\Models\RecurringOccurrence;
use App\Models\RecurringPaymentLink;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BudgetService;
use App\Services\CategoryService;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransactionService;
use App\Services\RecurringObligationService;
use App\Services\RecurringPaymentService;
use App\Support\Money;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
 *     paymentService: RecurringPaymentService,
 *     obligationService: RecurringObligationService,
 *     transactionService: FinancialTransactionService,
 *     budgetService: BudgetService,
 *     categoryService: CategoryService,
 *     accountService: FinancialAccountService,
 *     rent: Category,
 *     dining: Category,
 *     salary: Category,
 *     checking: FinancialAccount,
 *     usdAccount: FinancialAccount,
 *     otherExpense: Category,
 *     otherChecking: FinancialAccount,
 *     occurrence: RecurringOccurrence
 * }
 */
function recurringPaymentFixtures(string $startDate = '2026-10-31', int $anchorDay = 31): array
{
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $paymentService = app(RecurringPaymentService::class);
    $obligationService = app(RecurringObligationService::class);
    $transactionService = app(FinancialTransactionService::class);
    $budgetService = app(BudgetService::class);
    $categoryService = app(CategoryService::class);
    $accountService = app(FinancialAccountService::class);

    $rent = $categoryService->createCategory($user, [
        'name' => 'Rent',
        'type' => CategoryType::Expense,
    ]);
    $dining = $categoryService->createCategory($user, [
        'name' => 'Dining',
        'type' => CategoryType::Expense,
    ]);
    $salary = $categoryService->createCategory($user, [
        'name' => 'Salary',
        'type' => CategoryType::Income,
    ]);
    $checking = $accountService->createAccount($user, [
        'name' => 'Checking PHP',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);
    $usdAccount = $accountService->createAccount($user, [
        'name' => 'Checking USD',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);
    $otherExpense = $categoryService->createCategory($otherUser, [
        'name' => 'Other Rent',
        'type' => CategoryType::Expense,
    ]);
    $otherChecking = $accountService->createAccount($otherUser, [
        'name' => 'Other Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    $created = $obligationService->create(new CreateRecurringObligationInput(
        user: $user,
        idempotencyKey: (string) Str::uuid(),
        name: 'Apartment Rent',
        amount: '5000.00',
        currencyCode: 'PHP',
        categoryId: $rent->id,
        startDate: $startDate,
        frequency: RecurringFrequency::Monthly,
        anchorDay: $anchorDay,
        endsOfMonth: false,
        description: 'Rent',
        financialAccountId: $checking->id,
    ));

    $occurrence = RecurringOccurrence::query()
        ->where('obligation_id', $created->obligation->id)
        ->whereDate('due_date', $startDate)
        ->firstOrFail();

    return compact(
        'user',
        'otherUser',
        'paymentService',
        'obligationService',
        'transactionService',
        'budgetService',
        'categoryService',
        'accountService',
        'rent',
        'dining',
        'salary',
        'checking',
        'usdAccount',
        'otherExpense',
        'otherChecking',
        'occurrence',
    ) + ['obligation' => $created->obligation];
}

function postExpense(array $fixtures, string $amount, string $entryDate = '2026-10-15', ?int $categoryId = null, ?int $accountId = null): FinancialTransaction
{
    $result = $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $accountId ?? $fixtures['checking']->id,
        amount: $amount,
        entryDate: $entryDate,
        description: 'Bill payment '.$amount,
        idempotencyKey: (string) Str::uuid(),
        categoryId: $categoryId ?? $fixtures['rent']->id,
    ));

    return $result->transaction;
}

test('links a valid active expense and returns payment summary with audit', function (): void {
    $fixtures = recurringPaymentFixtures();
    $txn = postExpense($fixtures, '5000.00');

    $result = $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $txn->id,
    ));

    expect($result->wasRecentlyCreated)->toBeTrue()
        ->and($result->paymentSummary->paidAmountMinor)->toBe(500_000)
        ->and($result->paymentSummary->remainingAmountMinor)->toBe(0)
        ->and($result->paymentSummary->overpaidAmountMinor)->toBe(0)
        ->and($result->paymentSummary->displayStatus)->toBe(RecurringOccurrenceDisplayStatus::Paid)
        ->and($result->paymentSummary->isPaid)->toBeTrue();

    $audit = AuditEvent::query()
        ->where('action', AuditService::ACTION_RECURRING_PAYMENT_LINKED)
        ->where('auditable_id', $result->paymentLink->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->metadata['financial_transaction_id'] ?? null)->toBe($txn->id)
        ->and($audit?->metadata['new_paid_amount_minor'] ?? null)->toBe(500_000);
});

test('duplicate link replay is idempotent while linking elsewhere is rejected', function (): void {
    $fixtures = recurringPaymentFixtures();
    $txn = postExpense($fixtures, '2000.00');

    $first = $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $txn->id,
    ));

    $replay = $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $txn->id,
    ));

    expect($replay->wasRecentlyCreated)->toBeFalse()
        ->and($replay->paymentLink->id)->toBe($first->paymentLink->id)
        ->and(RecurringPaymentLink::query()->count())->toBe(1);

    $otherOccurrence = RecurringOccurrence::query()
        ->where('obligation_id', $fixtures['obligation']->id)
        ->where('id', '!=', $fixtures['occurrence']->id)
        ->orderBy('due_date')
        ->firstOrFail();

    expect(fn () => $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $otherOccurrence->id,
        financialTransactionId: (int) $txn->id,
    )))->toThrow(LedgerIntegrityException::class, 'already linked to another');
});

test('rejects cross-tenant, cross-currency, non-expense, category mismatch, and reversal cases', function (): void {
    $fixtures = recurringPaymentFixtures();

    $foreign = $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['otherUser'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['otherChecking']->id,
        amount: '100.00',
        entryDate: '2026-10-15',
        description: 'Other',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $fixtures['otherExpense']->id,
    ))->transaction;

    expect(fn () => $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $foreign->id,
    )))->toThrow(LedgerIntegrityException::class, 'not accessible');

    expect(fn () => $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['otherUser'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $foreign->id,
    )))->toThrow(LedgerIntegrityException::class, 'not accessible');

    $usdExpense = $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Expense,
        financialAccountId: $fixtures['usdAccount']->id,
        amount: '50.00',
        entryDate: '2026-10-15',
        description: 'USD',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $fixtures['rent']->id,
    ))->transaction;

    expect(fn () => $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $usdExpense->id,
    )))->toThrow(LedgerIntegrityException::class, 'currency');

    $income = $fixtures['transactionService']->createTransaction(new CreateFinancialTransactionInput(
        user: $fixtures['user'],
        type: FinancialTransactionType::Income,
        financialAccountId: $fixtures['checking']->id,
        amount: '100.00',
        entryDate: '2026-10-15',
        description: 'Income',
        idempotencyKey: (string) Str::uuid(),
        categoryId: $fixtures['salary']->id,
    ))->transaction;

    expect(fn () => $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $income->id,
    )))->toThrow(LedgerIntegrityException::class, 'expense');

    $diningExpense = postExpense($fixtures, '100.00', categoryId: $fixtures['dining']->id);

    expect(fn () => $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $diningExpense->id,
    )))->toThrow(LedgerIntegrityException::class, 'category');

    $expense = postExpense($fixtures, '100.00');
    $reversal = $fixtures['transactionService']->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $fixtures['user'],
        originalTransactionId: (int) $expense->id,
        idempotencyKey: (string) Str::uuid(),
        reason: 'Mistake',
    ));

    expect(fn () => $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $reversal->reversalTransaction->id,
    )))->toThrow(LedgerIntegrityException::class, 'Reversal');

    expect(fn () => $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $expense->id,
    )))->toThrow(LedgerIntegrityException::class, 'Already reversed');
});

test('supports partial, multiple, exact, and overpayment with remaining and overpaid amounts', function (): void {
    $fixtures = recurringPaymentFixtures();

    $a = postExpense($fixtures, '2000.00');
    $partial = $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $a->id,
    ))->paymentSummary;

    expect($partial->paidAmountMinor)->toBe(200_000)
        ->and($partial->remainingAmountMinor)->toBe(300_000)
        ->and($partial->overpaidAmountMinor)->toBe(0)
        ->and($partial->displayStatus)->toBe(RecurringOccurrenceDisplayStatus::PartiallyPaid)
        ->and($partial->isPartiallyPaid)->toBeTrue()
        ->and($partial->isPaid)->toBeFalse();

    $b = postExpense($fixtures, '3000.00');
    $exact = $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $b->id,
    ))->paymentSummary;

    expect($exact->paidAmountMinor)->toBe(500_000)
        ->and($exact->remainingAmountMinor)->toBe(0)
        ->and($exact->displayStatus)->toBe(RecurringOccurrenceDisplayStatus::Paid);

    $c = postExpense($fixtures, '500.00');
    $over = $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $c->id,
    ))->paymentSummary;

    expect($over->paidAmountMinor)->toBe(550_000)
        ->and($over->remainingAmountMinor)->toBe(0)
        ->and($over->overpaidAmountMinor)->toBe(50_000)
        ->and($over->isPaid)->toBeTrue();
});

test('reversal-aware paid totals keep the link and recalculate remaining status', function (): void {
    $fixtures = recurringPaymentFixtures();

    $a = postExpense($fixtures, '2000.00');
    $b = postExpense($fixtures, '3000.00');

    $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $a->id,
    ));
    $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $b->id,
    ));

    $before = $fixtures['paymentService']->getOccurrencePaymentSummary(
        $fixtures['user'],
        (int) $fixtures['occurrence']->id,
    );
    expect($before->paidAmountMinor)->toBe(500_000)
        ->and($before->displayStatus)->toBe(RecurringOccurrenceDisplayStatus::Paid);

    $fixtures['transactionService']->reverseTransaction(new ReverseFinancialTransactionInput(
        user: $fixtures['user'],
        originalTransactionId: (int) $b->id,
        idempotencyKey: (string) Str::uuid(),
        reason: 'Correction',
    ));

    expect(RecurringPaymentLink::query()->where('occurrence_id', $fixtures['occurrence']->id)->count())->toBe(2);

    $after = $fixtures['paymentService']->getOccurrencePaymentSummary(
        $fixtures['user'],
        (int) $fixtures['occurrence']->id,
    );

    expect($after->paidAmountMinor)->toBe(200_000)
        ->and($after->remainingAmountMinor)->toBe(300_000)
        ->and($after->displayStatus)->toBe(RecurringOccurrenceDisplayStatus::PartiallyPaid)
        ->and($after->allLinkedTransactionIds)->toHaveCount(2)
        ->and($after->activeLinkedTransactionIds)->toBe([$a->id]);
});

test('derives upcoming, due, overdue, and overdue partially paid indicators', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'UTC'));
    $upcomingFixtures = recurringPaymentFixtures('2026-10-31', 31);
    $upcoming = $upcomingFixtures['paymentService']->getOccurrencePaymentSummary(
        $upcomingFixtures['user'],
        (int) $upcomingFixtures['occurrence']->id,
    );
    expect($upcoming->displayStatus)->toBe(RecurringOccurrenceDisplayStatus::Upcoming)
        ->and($upcoming->isOverdue)->toBeFalse()
        ->and($upcoming->isDueToday)->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:00', 'UTC'));
    $due = $upcomingFixtures['paymentService']->getOccurrencePaymentSummary(
        $upcomingFixtures['user'],
        (int) $upcomingFixtures['occurrence']->id,
    );
    expect($due->displayStatus)->toBe(RecurringOccurrenceDisplayStatus::Due)
        ->and($due->isDueToday)->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-11-02 12:00:00', 'UTC'));
    $overdue = $upcomingFixtures['paymentService']->getOccurrencePaymentSummary(
        $upcomingFixtures['user'],
        (int) $upcomingFixtures['occurrence']->id,
    );
    expect($overdue->displayStatus)->toBe(RecurringOccurrenceDisplayStatus::Overdue)
        ->and($overdue->isOverdue)->toBeTrue();

    $partialTxn = postExpense($upcomingFixtures, '1000.00', '2026-11-01');
    $partialOverdue = $upcomingFixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $upcomingFixtures['user'],
        occurrenceId: (int) $upcomingFixtures['occurrence']->id,
        financialTransactionId: (int) $partialTxn->id,
    ))->paymentSummary;

    expect($partialOverdue->displayStatus)->toBe(RecurringOccurrenceDisplayStatus::PartiallyPaid)
        ->and($partialOverdue->isPartiallyPaid)->toBeTrue()
        ->and($partialOverdue->isOverdue)->toBeTrue();
});

test('rejects skipped and cancelled occurrences but allows paused obligation scheduled links', function (): void {
    $fixtures = recurringPaymentFixtures();
    $txn = postExpense($fixtures, '100.00');

    $fixtures['obligationService']->skipOccurrence(
        $fixtures['user'],
        (int) $fixtures['occurrence']->id,
    );

    expect(fn () => $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $txn->id,
    )))->toThrow(LedgerIntegrityException::class, 'skipped');

    $fixtures2 = recurringPaymentFixtures();
    $fixtures2['obligationService']->cancel($fixtures2['user'], (int) $fixtures2['obligation']->id);
    $txn2 = postExpense($fixtures2, '100.00');

    expect(fn () => $fixtures2['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures2['user'],
        occurrenceId: (int) $fixtures2['occurrence']->id,
        financialTransactionId: (int) $txn2->id,
    )))->toThrow(LedgerIntegrityException::class, 'cancelled');

    $fixtures3 = recurringPaymentFixtures();
    $fixtures3['obligationService']->pause($fixtures3['user'], (int) $fixtures3['obligation']->id);
    $txn3 = postExpense($fixtures3, '2500.00');

    $linked = $fixtures3['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures3['user'],
        occurrenceId: (int) $fixtures3['occurrence']->id,
        financialTransactionId: (int) $txn3->id,
    ));

    expect($linked->wasRecentlyCreated)->toBeTrue()
        ->and($linked->paymentSummary->paidAmountMinor)->toBe(250_000);
});

test('unlinks payment with durable audit history and rejects double unlink', function (): void {
    $fixtures = recurringPaymentFixtures();
    $txn = postExpense($fixtures, '2000.00');

    $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $txn->id,
    ));

    $journalCountBefore = JournalEntry::query()->count();
    $postingCountBefore = JournalPosting::query()->count();
    $txnCountBefore = FinancialTransaction::query()->count();

    $unlinked = $fixtures['paymentService']->unlinkPayment(new UnlinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $txn->id,
    ));

    expect($unlinked->paymentSummary->paidAmountMinor)->toBe(0)
        ->and(RecurringPaymentLink::query()->count())->toBe(0)
        ->and(FinancialTransaction::query()->whereKey($txn->id)->exists())->toBeTrue()
        ->and(JournalEntry::query()->count())->toBe($journalCountBefore)
        ->and(JournalPosting::query()->count())->toBe($postingCountBefore)
        ->and(FinancialTransaction::query()->count())->toBe($txnCountBefore);

    $audit = AuditEvent::query()
        ->where('action', AuditService::ACTION_RECURRING_PAYMENT_UNLINKED)
        ->where('auditable_id', $fixtures['occurrence']->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->metadata['financial_transaction_id'] ?? null)->toBe($txn->id)
        ->and($audit?->metadata['previous_paid_amount_minor'] ?? null)->toBe(200_000)
        ->and($audit?->metadata['new_paid_amount_minor'] ?? null)->toBe(0);

    expect(fn () => $fixtures['paymentService']->unlinkPayment(new UnlinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $txn->id,
    )))->toThrow(LedgerIntegrityException::class, 'No payment link exists');

    expect(fn () => $fixtures['paymentService']->unlinkPayment(new UnlinkRecurringPaymentInput(
        user: $fixtures['otherUser'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $txn->id,
    )))->toThrow(LedgerIntegrityException::class, 'not accessible');
});

test('linking and unlinking do not change budget actuals or create ledger entries', function (): void {
    $fixtures = recurringPaymentFixtures();

    $budget = $fixtures['budgetService']->createBudget(new CreateBudgetInput(
        user: $fixtures['user'],
        currencyCode: 'PHP',
        year: 2026,
        month: 10,
    ))->budget;

    $fixtures['budgetService']->setAllocation(new SetBudgetAllocationInput(
        user: $fixtures['user'],
        budgetId: (int) $budget->id,
        categoryId: (int) $fixtures['rent']->id,
        amount: '20000.00',
    ));

    $txn = postExpense($fixtures, '2000.00');
    $afterPost = $fixtures['budgetService']->getBudgetSpending($fixtures['user'], (int) $budget->id);
    expect($afterPost->totalActualMinor)->toBe(200_000);

    $journalsBeforeLink = JournalEntry::query()->count();
    $postingsBeforeLink = JournalPosting::query()->count();

    $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $txn->id,
    ));

    $afterLink = $fixtures['budgetService']->getBudgetSpending($fixtures['user'], (int) $budget->id);
    expect($afterLink->totalActualMinor)->toBe(200_000)
        ->and(JournalEntry::query()->count())->toBe($journalsBeforeLink)
        ->and(JournalPosting::query()->count())->toBe($postingsBeforeLink);

    $fixtures['paymentService']->unlinkPayment(new UnlinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $txn->id,
    ));

    $afterUnlink = $fixtures['budgetService']->getBudgetSpending($fixtures['user'], (int) $budget->id);
    expect($afterUnlink->totalActualMinor)->toBe(200_000)
        ->and(JournalEntry::query()->count())->toBe($journalsBeforeLink)
        ->and(JournalPosting::query()->count())->toBe($postingsBeforeLink);
});

test('checked integer overflow is raised when active paid totals exceed safe bounds', function (): void {
    $fixtures = recurringPaymentFixtures();

    // PHP currency exponent 2: MAX_MINOR_AMOUNT as major units string.
    $maxMajor = bcdiv((string) Money::MAX_MINOR_AMOUNT, '100', 2);

    $first = postExpense($fixtures, $maxMajor);
    $second = postExpense($fixtures, $maxMajor);

    $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $first->id,
    ));

    expect(fn () => $fixtures['paymentService']->linkPayment(new LinkRecurringPaymentInput(
        user: $fixtures['user'],
        occurrenceId: (int) $fixtures['occurrence']->id,
        financialTransactionId: (int) $second->id,
    )))->toThrow(LedgerIntegrityException::class, 'overflow');
});

test('skipped and cancelled display statuses take precedence over payment state', function (): void {
    $fixtures = recurringPaymentFixtures();

    $fixtures['obligationService']->skipOccurrence(
        $fixtures['user'],
        (int) $fixtures['occurrence']->id,
    );

    $skipped = $fixtures['paymentService']->getOccurrenceDisplayStatus(
        $fixtures['user'],
        (int) $fixtures['occurrence']->id,
    );
    expect($skipped)->toBe(RecurringOccurrenceDisplayStatus::Skipped);

    $fixtures2 = recurringPaymentFixtures();
    $occ = $fixtures2['occurrence'];
    $occ->status = RecurringOccurrenceStatus::Cancelled;
    $occ->cancelled_at = now('UTC');
    $occ->skipped_at = null;
    $occ->active_slot_key = null;
    $occ->save();

    $cancelled = $fixtures2['paymentService']->getOccurrenceDisplayStatus(
        $fixtures2['user'],
        (int) $occ->id,
    );
    expect($cancelled)->toBe(RecurringOccurrenceDisplayStatus::Cancelled);
});
