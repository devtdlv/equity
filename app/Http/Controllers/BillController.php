<?php

namespace App\Http\Controllers;

use App\Enums\CategoryType;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\RecurringObligation;
use App\Services\CategoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia page shell for Bills & Subscriptions.
 *
 * Financial data is loaded client-side via the authenticated recurring JSON APIs.
 * This controller only seeds reference data (categories, accounts, currencies).
 */
class BillController extends Controller
{
    public function __construct(
        private readonly CategoryService $categoryService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        Gate::authorize('viewAny', RecurringObligation::class);

        $categories = $this->categoryService
            ->listForUser(user: $user, type: CategoryType::Expense, includeArchived: true)
            ->map(static fn ($category): array => [
                'id' => (int) $category->id,
                'name' => $category->name,
                'type' => $category->type->value,
                'type_label' => $category->type->label(),
                'classification' => $category->type->ledgerClassification()->value,
                'is_archived' => (bool) $category->is_archived,
                'archived_at' => $category->archived_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $accounts = FinancialAccount::query()
            ->with('currency:code,name,symbol,minor_unit_exponent')
            ->where('user_id', $user->id)
            ->orderBy('is_archived')
            ->orderBy('name')
            ->get()
            ->map(static fn (FinancialAccount $account): array => [
                'id' => (int) $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'type_label' => $account->type->label(),
                'classification' => $account->type->ledgerClassification()->value,
                'currency_code' => $account->currency_code,
                'currency_symbol' => $account->currency?->symbol ?? $account->currency_code,
                'minor_unit_exponent' => $account->currency?->minor_unit_exponent ?? 2,
                'is_archived' => (bool) $account->is_archived,
            ])
            ->values()
            ->all();

        $currencies = Currency::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['code', 'name', 'symbol', 'minor_unit_exponent'])
            ->map(static fn (Currency $currency): array => [
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
                'minor_unit_exponent' => (int) $currency->minor_unit_exponent,
            ])
            ->values()
            ->all();

        $now = now('UTC');

        return Inertia::render('Bills/Index', [
            'categories' => $categories,
            'accounts' => $accounts,
            'currencies' => $currencies,
            'initial_year' => (int) $now->year,
            'initial_month' => (int) $now->month,
            'today' => $now->toDateString(),
        ]);
    }
}
