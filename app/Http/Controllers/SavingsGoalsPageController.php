<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\SavingsGoal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia page shell for Savings Goals.
 *
 * Goal, portfolio, and event data load client-side via authenticated JSON APIs.
 * Users can create, edit, archive, restore, and allocate toward goals in the UI.
 * Allocations are earmarks and do not move ledger or account balances.
 * This controller only seeds active currency reference data.
 */
class SavingsGoalsPageController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SavingsGoal::class);

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

        return Inertia::render('SavingsGoals/Index', [
            'currencies' => $currencies,
        ]);
    }
}
