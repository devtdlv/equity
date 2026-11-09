<?php

namespace Database\Seeders;

use App\DataTransferObjects\AllocateSavingsGoalInput;
use App\DataTransferObjects\CreateBudgetInput;
use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\DataTransferObjects\CreateRecurringObligationInput;
use App\DataTransferObjects\CreateSavingsGoalInput;
use App\DataTransferObjects\PayRecurringBillInput;
use App\DataTransferObjects\ReverseFinancialTransactionInput;
use App\DataTransferObjects\SetBudgetAllocationInput;
use App\Enums\CategoryType;
use App\Enums\FinancialTransactionType;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringOccurrenceStatus;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Budget;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\RecurringOccurrence;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\CategoryService;
use App\Services\FinancialAccountService;
use App\Services\FinancialTransactionService;
use App\Services\RecurringObligationService;
use App\Services\RecurringPaymentService;
use App\Services\SavingsGoalAllocationService;
use App\Services\SavingsGoalService;
use App\Support\VisualAuditEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Fictional demo dataset for screenshot capture only.
 *
 * Never called from DatabaseSeeder. Refuses to run unless visual-audit isolation passes.
 */
class VisualAuditSeeder extends Seeder
{
    private const UUID_NS = 'a0000001-e011-4000-8000-';

    public function run(): void
    {
        VisualAuditEnvironment::assertSafeDatabaseTarget();

        $this->call(CurrencySeeder::class);

        $now = CarbonImmutable::now('UTC');
        $user = $this->resolveDemoUser();

        $accounts = app(FinancialAccountService::class);
        $categories = app(CategoryService::class);
        $transactions = app(FinancialTransactionService::class);
        $budgets = app(BudgetService::class);
        $obligations = app(RecurringObligationService::class);
        $payments = app(RecurringPaymentService::class);
        $goals = app(SavingsGoalService::class);
        $allocations = app(SavingsGoalAllocationService::class);

        $phpChecking = $accounts->createAccount($user, [
            'name' => 'Demo PHP Checking',
            'type' => 'checking',
            'currency_code' => 'PHP',
            'institution' => 'Demo Bank PH',
            'starting_balance' => '125000.00',
            'idempotency_key' => $this->uuid(1),
        ]);
        $phpCash = $accounts->createAccount($user, [
            'name' => 'Demo PHP Cash',
            'type' => 'cash',
            'currency_code' => 'PHP',
            'starting_balance' => '3500.00',
            'idempotency_key' => $this->uuid(2),
        ]);
        $phpCard = $accounts->createAccount($user, [
            'name' => 'Demo PHP Credit Card',
            'type' => 'credit_card',
            'currency_code' => 'PHP',
            'institution' => 'Demo Card Co',
            'starting_balance' => '8500.00',
            'idempotency_key' => $this->uuid(3),
        ]);
        $usdChecking = $accounts->createAccount($user, [
            'name' => 'Demo USD Checking',
            'type' => 'checking',
            'currency_code' => 'USD',
            'institution' => 'Demo Bank US',
            'starting_balance' => '4200.00',
            'idempotency_key' => $this->uuid(4),
        ]);
        $archived = $accounts->createAccount($user, [
            'name' => 'Demo Archived Wallet',
            'type' => 'other',
            'currency_code' => 'PHP',
            'starting_balance' => '0.00',
            'idempotency_key' => $this->uuid(5),
        ]);
        $accounts->archiveAccount($user, $archived);

        $salary = $this->category($categories, $user, 'Demo Salary', CategoryType::Income);
        $freelance = $this->category($categories, $user, 'Demo Freelance', CategoryType::Income);
        $rent = $this->category($categories, $user, 'Demo Rent', CategoryType::Expense);
        $groceries = $this->category($categories, $user, 'Demo Groceries', CategoryType::Expense);
        $utilities = $this->category($categories, $user, 'Demo Utilities', CategoryType::Expense);
        $transport = $this->category($categories, $user, 'Demo Transport', CategoryType::Expense);
        $dining = $this->category($categories, $user, 'Demo Dining', CategoryType::Expense);

        // Prior months for trend charts.
        foreach ([3, 2, 1] as $monthsAgo) {
            $month = $now->subMonthsNoOverflow($monthsAgo)->startOfMonth();
            $transactions->createTransaction(new CreateFinancialTransactionInput(
                user: $user,
                type: FinancialTransactionType::Income,
                financialAccountId: (int) $phpChecking->id,
                amount: '55000.00',
                entryDate: $month->addDays(1)->toDateString(),
                description: 'Demo paycheck '.$month->format('Y-m'),
                idempotencyKey: $this->uuid(10 + $monthsAgo),
                categoryId: (int) $salary->id,
            ));
            $transactions->createTransaction(new CreateFinancialTransactionInput(
                user: $user,
                type: FinancialTransactionType::Expense,
                financialAccountId: (int) $phpChecking->id,
                amount: '18000.00',
                entryDate: $month->addDays(3)->toDateString(),
                description: 'Demo rent '.$month->format('Y-m'),
                idempotencyKey: $this->uuid(20 + $monthsAgo),
                categoryId: (int) $rent->id,
            ));
            $transactions->createTransaction(new CreateFinancialTransactionInput(
                user: $user,
                type: FinancialTransactionType::Expense,
                financialAccountId: (int) $phpChecking->id,
                amount: (string) (4500 + ($monthsAgo * 250)).'.00',
                entryDate: $month->addDays(10)->toDateString(),
                description: 'Demo groceries '.$month->format('Y-m'),
                idempotencyKey: $this->uuid(30 + $monthsAgo),
                categoryId: (int) $groceries->id,
            ));
        }

        // Current UTC month activity.
        $transactions->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Income,
            financialAccountId: (int) $phpChecking->id,
            amount: '58000.00',
            entryDate: $now->startOfMonth()->addDays(1)->toDateString(),
            description: 'Demo current paycheck',
            idempotencyKey: $this->uuid(40),
            categoryId: (int) $salary->id,
        ));
        $transactions->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Income,
            financialAccountId: (int) $phpChecking->id,
            amount: '7500.00',
            entryDate: $now->startOfMonth()->addDays(5)->toDateString(),
            description: 'Demo freelance invoice',
            idempotencyKey: $this->uuid(41),
            categoryId: (int) $freelance->id,
        ));
        $transactions->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Expense,
            financialAccountId: (int) $phpChecking->id,
            amount: '18500.00',
            entryDate: $now->startOfMonth()->addDays(2)->toDateString(),
            description: 'Demo rent current',
            idempotencyKey: $this->uuid(42),
            categoryId: (int) $rent->id,
        ));
        $transactions->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Expense,
            financialAccountId: (int) $phpChecking->id,
            amount: '5200.00',
            entryDate: $now->startOfMonth()->addDays(6)->toDateString(),
            description: 'Demo groceries current',
            idempotencyKey: $this->uuid(43),
            categoryId: (int) $groceries->id,
        ));
        $transactions->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Expense,
            financialAccountId: (int) $phpChecking->id,
            amount: '2100.00',
            entryDate: $now->startOfMonth()->addDays(7)->toDateString(),
            description: 'Demo utilities',
            idempotencyKey: $this->uuid(44),
            categoryId: (int) $utilities->id,
        ));
        $transactions->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Expense,
            financialAccountId: (int) $phpCash->id,
            amount: '650.00',
            entryDate: $now->startOfMonth()->addDays(8)->toDateString(),
            description: 'Demo transport cash',
            idempotencyKey: $this->uuid(45),
            categoryId: (int) $transport->id,
        ));
        $transactions->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Expense,
            financialAccountId: (int) $phpCard->id,
            amount: '1899.50',
            entryDate: $now->startOfMonth()->addDays(9)->toDateString(),
            description: 'Demo dining on card',
            idempotencyKey: $this->uuid(46),
            categoryId: (int) $dining->id,
        ));
        $transactions->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Transfer,
            financialAccountId: (int) $phpChecking->id,
            amount: '2000.00',
            entryDate: $now->startOfMonth()->addDays(4)->toDateString(),
            description: 'Demo transfer to cash',
            idempotencyKey: $this->uuid(47),
            transferFinancialAccountId: (int) $phpCash->id,
        ));
        $transactions->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Income,
            financialAccountId: (int) $usdChecking->id,
            amount: '1800.00',
            entryDate: $now->startOfMonth()->addDays(3)->toDateString(),
            description: 'Demo USD consulting',
            idempotencyKey: $this->uuid(48),
            categoryId: (int) $freelance->id,
        ));
        $transactions->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Expense,
            financialAccountId: (int) $usdChecking->id,
            amount: '120.00',
            entryDate: $now->startOfMonth()->addDays(11)->toDateString(),
            description: 'Demo USD software',
            idempotencyKey: $this->uuid(49),
            categoryId: (int) $utilities->id,
        ));

        $toReverse = $transactions->createTransaction(new CreateFinancialTransactionInput(
            user: $user,
            type: FinancialTransactionType::Expense,
            financialAccountId: (int) $phpChecking->id,
            amount: '999.00',
            entryDate: $now->startOfMonth()->addDays(12)->toDateString(),
            description: 'Demo mistaken expense',
            idempotencyKey: $this->uuid(50),
            categoryId: (int) $dining->id,
        ));
        $transactions->reverseTransaction(new ReverseFinancialTransactionInput(
            user: $user,
            originalTransactionId: (int) $toReverse->transaction->id,
            idempotencyKey: $this->uuid(51),
            reason: 'Demo reversal for screenshots',
        ));

        $budget = $this->budgetForCurrentMonth($budgets, $user, $now);
        foreach (
            [
                [(int) $rent->id, '20000.00'],
                [(int) $groceries->id, '8000.00'],
                [(int) $utilities->id, '3000.00'],
                [(int) $transport->id, '2500.00'],
                [(int) $dining->id, '4000.00'],
            ] as [$categoryId, $amount]
        ) {
            $budgets->setAllocation(new SetBudgetAllocationInput(
                user: $user,
                budgetId: (int) $budget->id,
                categoryId: $categoryId,
                amount: $amount,
            ));
        }

        // Bills: upcoming, overdue (past start), paid, partial, paused.
        $upcomingDue = min(28, max(1, (int) $now->day + 10));
        $upcomingStart = $now->startOfMonth()->setDay($upcomingDue);
        if ($upcomingStart->lessThanOrEqualTo($now->startOfDay())) {
            $upcomingStart = $now->addMonthNoOverflow()->startOfMonth()->setDay(min(28, $upcomingDue));
        }
        $upcoming = $obligations->create(new CreateRecurringObligationInput(
            user: $user,
            idempotencyKey: $this->uuid(60),
            name: 'Demo Internet',
            amount: '1699.00',
            currencyCode: 'PHP',
            categoryId: (int) $utilities->id,
            startDate: $upcomingStart->toDateString(),
            frequency: RecurringFrequency::Monthly,
            anchorDay: (int) $upcomingStart->day,
            financialAccountId: (int) $phpChecking->id,
        ))->obligation;

        $overdueStart = $now->subMonthsNoOverflow(2)->startOfMonth()->setDay(5);
        $overdue = $obligations->create(new CreateRecurringObligationInput(
            user: $user,
            idempotencyKey: $this->uuid(61),
            name: 'Demo Storage Locker',
            amount: '2500.00',
            currencyCode: 'PHP',
            categoryId: (int) $utilities->id,
            startDate: $overdueStart->toDateString(),
            frequency: RecurringFrequency::Monthly,
            anchorDay: 5,
            financialAccountId: (int) $phpChecking->id,
        ))->obligation;

        $paidStart = $now->startOfMonth()->setDay(1);
        $paidBill = $obligations->create(new CreateRecurringObligationInput(
            user: $user,
            idempotencyKey: $this->uuid(62),
            name: 'Demo Music Subscription',
            amount: '149.00',
            currencyCode: 'PHP',
            categoryId: (int) $utilities->id,
            startDate: $paidStart->toDateString(),
            frequency: RecurringFrequency::Monthly,
            anchorDay: 1,
            financialAccountId: (int) $phpChecking->id,
        ))->obligation;

        $partialBill = $obligations->create(new CreateRecurringObligationInput(
            user: $user,
            idempotencyKey: $this->uuid(63),
            name: 'Demo Tuition Installment',
            amount: '10000.00',
            currencyCode: 'PHP',
            categoryId: (int) $utilities->id,
            startDate: $paidStart->toDateString(),
            frequency: RecurringFrequency::Monthly,
            anchorDay: 1,
            financialAccountId: (int) $phpChecking->id,
        ))->obligation;

        $pausedStart = $now->startOfMonth()->setDay(min(28, 15));
        $pausedBill = $obligations->create(new CreateRecurringObligationInput(
            user: $user,
            idempotencyKey: $this->uuid(64),
            name: 'Demo Gym Membership',
            amount: '999.00',
            currencyCode: 'PHP',
            categoryId: (int) $transport->id,
            startDate: $pausedStart->toDateString(),
            frequency: RecurringFrequency::Monthly,
            anchorDay: (int) $pausedStart->day,
            financialAccountId: (int) $phpChecking->id,
        ))->obligation;
        $obligations->pause($user, (int) $pausedBill->id);

        foreach ([$upcoming, $overdue, $paidBill, $partialBill] as $obligation) {
            $obligations->generateOccurrences($user, (int) $obligation->id);
        }

        $paidOccurrence = $this->firstScheduledOccurrence((int) $paidBill->id, $now->startOfMonth()->toDateString());
        if ($paidOccurrence !== null) {
            $payments->payAndPost(new PayRecurringBillInput(
                user: $user,
                occurrenceId: (int) $paidOccurrence->id,
                financialAccountId: (int) $phpChecking->id,
                amount: '149.00',
                idempotencyKey: $this->uuid(70),
                entryDate: $now->toDateString(),
                notes: 'Demo full payment',
            ));
        }

        $partialOccurrence = $this->firstScheduledOccurrence((int) $partialBill->id, $now->startOfMonth()->toDateString());
        if ($partialOccurrence !== null) {
            $payments->payAndPost(new PayRecurringBillInput(
                user: $user,
                occurrenceId: (int) $partialOccurrence->id,
                financialAccountId: (int) $phpChecking->id,
                amount: '4000.00',
                idempotencyKey: $this->uuid(71),
                entryDate: $now->toDateString(),
                notes: 'Demo partial payment',
            ));
        }

        // Ensure overdue occurrence exists for the past due date.
        $this->firstScheduledOccurrence((int) $overdue->id, $now->subMonthNoOverflow()->startOfMonth()->toDateString());

        $emergency = $goals->createGoal(new CreateSavingsGoalInput(
            user: $user,
            idempotencyKey: $this->uuid(80),
            name: 'Demo Emergency Fund',
            currencyCode: 'PHP',
            targetAmountMinor: 100_000_00,
            description: 'Fictional emergency reserve',
            targetDate: $now->addMonthsNoOverflow(8)->endOfMonth()->toDateString(),
        ))->goal;
        $allocations->contribute(new AllocateSavingsGoalInput(
            user: $user,
            goalId: (int) $emergency->id,
            idempotencyKey: $this->uuid(81),
            amountMinor: 35_000_00,
            note: 'Demo earmark 1',
        ));
        $allocations->contribute(new AllocateSavingsGoalInput(
            user: $user,
            goalId: (int) $emergency->id,
            idempotencyKey: $this->uuid(82),
            amountMinor: 15_000_00,
            note: 'Demo earmark 2',
        ));

        $travel = $goals->createGoal(new CreateSavingsGoalInput(
            user: $user,
            idempotencyKey: $this->uuid(83),
            name: 'Demo Travel Fund',
            currencyCode: 'USD',
            targetAmountMinor: 200_000,
            description: 'Fictional travel earmark',
            targetDate: $now->addYear()->endOfMonth()->toDateString(),
        ))->goal;
        $allocations->contribute(new AllocateSavingsGoalInput(
            user: $user,
            goalId: (int) $travel->id,
            idempotencyKey: $this->uuid(84),
            amountMinor: 75_00,
            note: 'Demo USD earmark',
        ));

        $archivedGoal = $goals->createGoal(new CreateSavingsGoalInput(
            user: $user,
            idempotencyKey: $this->uuid(85),
            name: 'Demo Completed Gadget Fund',
            currencyCode: 'PHP',
            targetAmountMinor: 10_000_00,
            description: 'Archived fictional goal',
        ))->goal;
        $allocations->contribute(new AllocateSavingsGoalInput(
            user: $user,
            goalId: (int) $archivedGoal->id,
            idempotencyKey: $this->uuid(86),
            amountMinor: 10_000_00,
            note: 'Demo completed earmark',
        ));
        $goals->archiveGoal($user, (int) $archivedGoal->id);

        // Touch variables used for clarity / static analysis of required demo entities.
        unset($phpChecking, $usdChecking, $overdue, $upcoming);
    }

    private function resolveDemoUser(): User
    {
        $email = (string) config('equity.visual_audit.demo_email', VisualAuditEnvironment::DEMO_EMAIL);

        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null) {
            return $existing;
        }

        return User::query()->create([
            'name' => VisualAuditEnvironment::DEMO_NAME,
            'email' => $email,
            'password' => Hash::make(VisualAuditEnvironment::DEMO_PASSWORD),
            'email_verified_at' => null,
        ]);
    }

    private function category(
        CategoryService $categories,
        User $user,
        string $name,
        CategoryType $type,
    ): Category {
        $existing = Category::query()
            ->where('user_id', $user->id)
            ->where('type', $type->value)
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $categories->createCategory($user, [
            'name' => $name,
            'type' => $type,
        ]);
    }

    private function budgetForCurrentMonth(
        BudgetService $budgets,
        User $user,
        CarbonImmutable $now,
    ): Budget {
        $existing = Budget::query()
            ->where('user_id', $user->id)
            ->where('currency_code', 'PHP')
            ->whereDate('period_start', $now->startOfMonth()->toDateString())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $budgets->createBudget(new CreateBudgetInput(
                user: $user,
                currencyCode: 'PHP',
                year: (int) $now->year,
                month: (int) $now->month,
            ))->budget;
        } catch (LedgerIntegrityException) {
            return Budget::query()
                ->where('user_id', $user->id)
                ->where('currency_code', 'PHP')
                ->whereDate('period_start', $now->startOfMonth()->toDateString())
                ->firstOrFail();
        }
    }

    private function firstScheduledOccurrence(int $obligationId, string $onOrAfter): ?RecurringOccurrence
    {
        return RecurringOccurrence::query()
            ->where('obligation_id', $obligationId)
            ->where('status', RecurringOccurrenceStatus::Scheduled->value)
            ->whereDate('due_date', '>=', $onOrAfter)
            ->orderBy('due_date')
            ->orderBy('id')
            ->first();
    }

    private function uuid(int $n): string
    {
        return self::UUID_NS.str_pad(dechex($n), 12, '0', STR_PAD_LEFT);
    }
}
