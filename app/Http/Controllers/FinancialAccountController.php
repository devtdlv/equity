<?php

namespace App\Http\Controllers;

use App\Enums\FinancialAccountType;
use App\Http\Requests\Accounts\StoreFinancialAccountRequest;
use App\Http\Requests\Accounts\UpdateFinancialAccountRequest;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Services\AccountBalanceService;
use App\Services\FinancialAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class FinancialAccountController extends Controller
{
    public function __construct(
        private readonly FinancialAccountService $accountService,
        private readonly AccountBalanceService $balanceService,
    ) {}

    /**
     * Display the authenticated user's financial accounts and ledger-derived balances.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', FinancialAccount::class);

        $user = $request->user();

        $accounts = FinancialAccount::query()
            ->with(['currency', 'ledgerAccount'])
            ->where('user_id', $user->id)
            ->orderBy('is_archived')
            ->orderBy('name')
            ->get();

        $derivedBalances = $this->balanceService->getBalancesForFinancialAccounts($accounts);

        $serializedAccounts = $accounts->map(function (FinancialAccount $account) use ($derivedBalances): array {
            $balance = $derivedBalances[$account->id] ?? [
                'amount_minor' => 0,
                'currency_code' => $account->currency_code,
                'exponent' => $account->currency?->minor_unit_exponent ?? 2,
                'symbol' => $account->currency?->symbol ?? $account->currency_code.' ',
                'formatted' => ($account->currency?->symbol ?? $account->currency_code.' ').'0.00',
                'is_liability' => ! $account->type->ledgerClassification()->isDebitNormal(),
                'has_posted_activity' => false,
            ];

            return [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'type_label' => $account->type->label(),
                'classification' => $account->type->ledgerClassification()->value,
                'currency_code' => $account->currency_code,
                'institution' => $account->institution,
                'description' => $account->description,
                'is_archived' => $account->is_archived,
                'archived_at' => $account->archived_at?->toIso8601String(),
                'has_posted_activity' => $balance['has_posted_activity'],
                'balance' => [
                    'amount_minor' => $balance['amount_minor'],
                    'currency_code' => $balance['currency_code'],
                    'exponent' => $balance['exponent'],
                    'symbol' => $balance['symbol'],
                    'formatted' => $balance['formatted'],
                    'is_liability' => $balance['is_liability'],
                ],
            ];
        })->values()->all();

        $currencies = Currency::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['code', 'name', 'symbol', 'minor_unit_exponent'])
            ->map(static fn (Currency $currency): array => [
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
                'minor_unit_exponent' => $currency->minor_unit_exponent,
            ])
            ->values()
            ->all();

        return Inertia::render('Accounts/Index', [
            'accounts' => $serializedAccounts,
            'currencies' => $currencies,
            'accountTypes' => FinancialAccountType::options(),
        ]);
    }

    /**
     * Create a new financial account and its linked ledger account.
     */
    public function store(StoreFinancialAccountRequest $request): RedirectResponse
    {
        $this->accountService->createAccount(
            user: $request->user(),
            data: $request->validated(),
        );

        return redirect()
            ->route('accounts.index')
            ->with('status', 'Account created.');
    }

    /**
     * Update an existing financial account's metadata.
     */
    public function update(UpdateFinancialAccountRequest $request, FinancialAccount $account): RedirectResponse
    {
        $this->accountService->updateAccount(
            actor: $request->user(),
            account: $account,
            data: $request->validated(),
        );

        return redirect()
            ->route('accounts.index')
            ->with('status', 'Account updated.');
    }

    /**
     * Archive a financial account.
     */
    public function archive(Request $request, FinancialAccount $account): RedirectResponse
    {
        Gate::authorize('archive', $account);

        $this->accountService->archiveAccount(
            actor: $request->user(),
            account: $account,
        );

        return redirect()
            ->route('accounts.index')
            ->with('status', 'Account archived.');
    }

    /**
     * Restore an archived financial account.
     */
    public function restore(Request $request, FinancialAccount $account): RedirectResponse
    {
        Gate::authorize('restore', $account);

        $this->accountService->restoreAccount(
            actor: $request->user(),
            account: $account,
        );

        return redirect()
            ->route('accounts.index')
            ->with('status', 'Account restored.');
    }
}
