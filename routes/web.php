<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinancialAccountController;
use App\Http\Controllers\FinancialTransactionController;
use App\Http\Controllers\RecurringObligationController;
use App\Http\Controllers\RecurringOccurrenceController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::get('/', function (): Response {
    return Inertia::render('Welcome', [
        'productName' => config('app.name', 'Equity'),
        'tagline' => 'Personal finance, clearly understood.',
        'description' => 'Track your accounts, understand your spending, and plan your financial future in one place.',
        'status' => 'Application foundation',
    ]);
})->name('welcome');

Route::middleware('guest')->group(function (): void {
    Route::get('/register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:6,1');

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)
        ->name('dashboard');

    Route::get('/accounts', [FinancialAccountController::class, 'index'])
        ->name('accounts.index');

    Route::post('/accounts', [FinancialAccountController::class, 'store'])
        ->name('accounts.store');

    Route::patch('/accounts/{account}', [FinancialAccountController::class, 'update'])
        ->name('accounts.update');

    Route::post('/accounts/{account}/archive', [FinancialAccountController::class, 'archive'])
        ->name('accounts.archive');

    Route::post('/accounts/{account}/restore', [FinancialAccountController::class, 'restore'])
        ->name('accounts.restore');

    Route::get('/transactions', [FinancialTransactionController::class, 'index'])
        ->name('transactions.index');

    Route::post('/transactions', [FinancialTransactionController::class, 'store'])
        ->name('transactions.store');

    Route::post('/transactions/{transaction}/reverse', [FinancialTransactionController::class, 'reverse'])
        ->name('transactions.reverse');

    Route::get('/categories', [CategoryController::class, 'index'])
        ->name('categories.index');

    Route::post('/categories', [CategoryController::class, 'store'])
        ->name('categories.store');

    Route::patch('/categories/{category}', [CategoryController::class, 'update'])
        ->name('categories.update');

    Route::post('/categories/{category}/archive', [CategoryController::class, 'archive'])
        ->name('categories.archive');

    Route::post('/categories/{category}/restore', [CategoryController::class, 'restore'])
        ->name('categories.restore');

    Route::get('/budgets', [BudgetController::class, 'index'])
        ->name('budgets.index');

    Route::post('/budgets', [BudgetController::class, 'store'])
        ->name('budgets.store');

    Route::get('/budgets/{budget}', [BudgetController::class, 'show'])
        ->name('budgets.show');

    Route::put('/budgets/{budget}/allocations/{category}', [BudgetController::class, 'updateAllocation'])
        ->name('budgets.allocations.update');

    Route::get('/bills', [BillController::class, 'index'])
        ->name('bills.index');

    Route::get('/recurring-obligations', [RecurringObligationController::class, 'index'])
        ->name('recurring-obligations.index');

    Route::post('/recurring-obligations', [RecurringObligationController::class, 'store'])
        ->name('recurring-obligations.store');

    Route::get('/recurring-obligations/{obligation}', [RecurringObligationController::class, 'show'])
        ->name('recurring-obligations.show');

    Route::patch('/recurring-obligations/{obligation}', [RecurringObligationController::class, 'update'])
        ->name('recurring-obligations.update');

    Route::post('/recurring-obligations/{obligation}/pause', [RecurringObligationController::class, 'pause'])
        ->name('recurring-obligations.pause');

    Route::post('/recurring-obligations/{obligation}/resume', [RecurringObligationController::class, 'resume'])
        ->name('recurring-obligations.resume');

    Route::post('/recurring-obligations/{obligation}/cancel', [RecurringObligationController::class, 'cancel'])
        ->name('recurring-obligations.cancel');

    Route::get('/recurring-occurrences', [RecurringOccurrenceController::class, 'index'])
        ->name('recurring-occurrences.index');

    Route::get('/recurring-occurrences/{occurrence}', [RecurringOccurrenceController::class, 'show'])
        ->name('recurring-occurrences.show');

    Route::post('/recurring-occurrences/{occurrence}/skip', [RecurringOccurrenceController::class, 'skip'])
        ->name('recurring-occurrences.skip');

    Route::post('/recurring-occurrences/{occurrence}/payment-links', [RecurringOccurrenceController::class, 'storePaymentLink'])
        ->name('recurring-occurrences.payment-links.store');

    Route::delete('/recurring-occurrences/{occurrence}/payment-links/{link}', [RecurringOccurrenceController::class, 'destroyPaymentLink'])
        ->name('recurring-occurrences.payment-links.destroy');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
