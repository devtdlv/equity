<?php

namespace App\Http\Controllers;

use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\LedgerIntegrityException;
use App\Http\Requests\Transactions\IndexFinancialTransactionRequest;
use App\Http\Requests\Transactions\StoreFinancialTransactionRequest;
use App\Models\Category;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Services\CategoryService;
use App\Services\FinancialTransactionService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FinancialTransactionController extends Controller
{
    public const DEFAULT_PER_PAGE = 15;

    public function __construct(
        private readonly FinancialTransactionService $transactionService,
        private readonly CategoryService $categoryService,
    ) {}

    /**
     * List the authenticated user's financial transactions with optional filtering and pagination.
     *
     * Supports both Inertia page visits (initial browser load or Inertia <Link> navigation)
     * and JSON API requests (`Accept: application/json`).
     */
    public function index(IndexFinancialTransactionRequest $request): JsonResponse|Response
    {
        $user = $request->user();
        $validated = $request->validated();

        $query = FinancialTransaction::query()
            ->with([
                'currency:code,name,symbol,minor_unit_exponent',
                'financialAccount:id,name,type,currency_code,is_archived',
                'transferFinancialAccount:id,name,type,currency_code,is_archived',
                'category:id,name,type,is_archived',
                'reversalTransaction:id,reversed_transaction_id,journal_entry_id',
            ])
            ->where('user_id', $user->id);

        if (isset($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (isset($validated['financial_account_id'])) {
            $accountId = (int) $validated['financial_account_id'];
            $query->where(function ($subQuery) use ($accountId): void {
                $subQuery->where('financial_account_id', $accountId)
                    ->orWhere('transfer_financial_account_id', $accountId);
            });
        }

        if (isset($validated['category_id'])) {
            $query->where('category_id', (int) $validated['category_id']);
        }

        if (isset($validated['start_date'])) {
            $query->where('entry_date', '>=', $validated['start_date']);
        }

        if (isset($validated['end_date'])) {
            $query->where('entry_date', '<=', $validated['end_date']);
        }

        $perPage = isset($validated['per_page'])
            ? (int) $validated['per_page']
            : self::DEFAULT_PER_PAGE;

        $paginator = $query
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $items = collect($paginator->items())
            ->map(fn (FinancialTransaction $transaction): array => $this->serializeTransaction($transaction))
            ->values()
            ->all();

        $meta = [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];

        $filters = [
            'type' => $validated['type'] ?? null,
            'financial_account_id' => isset($validated['financial_account_id'])
                ? (int) $validated['financial_account_id']
                : null,
            'category_id' => isset($validated['category_id'])
                ? (int) $validated['category_id']
                : null,
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
        ];

        if ($request->wantsJson() && ! $request->inertia()) {
            return response()->json([
                'data' => $items,
                'meta' => $meta,
                'filters' => $filters,
            ]);
        }

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

        $categories = $this->categoryService
            ->listForUser(user: $user, includeArchived: true)
            ->map(static fn (Category $category): array => [
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

        return Inertia::render('Transactions/Index', [
            'transactions' => $items,
            'meta' => $meta,
            'filters' => $filters,
            'accounts' => $accounts,
            'categories' => $categories,
            'currencies' => $currencies,
        ]);
    }

    /**
     * Record a new income, expense, or transfer transaction (or replay an identical idempotent request).
     */
    public function store(StoreFinancialTransactionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $input = new CreateFinancialTransactionInput(
            user: $request->user(),
            type: (string) $validated['type'],
            financialAccountId: (int) $validated['financial_account_id'],
            amount: (string) $validated['amount'],
            entryDate: (string) $validated['entry_date'],
            description: (string) $validated['description'],
            idempotencyKey: (string) $validated['idempotency_key'],
            transferFinancialAccountId: isset($validated['transfer_financial_account_id'])
                ? (int) $validated['transfer_financial_account_id']
                : null,
            categoryId: isset($validated['category_id'])
                ? (int) $validated['category_id']
                : null,
            notes: isset($validated['notes']) ? (string) $validated['notes'] : null,
        );

        try {
            $result = $this->transactionService->createTransaction($input);
        } catch (IdempotencyConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'idempotency_key' => [$exception->getMessage()],
                ],
            ], 409);
        } catch (LedgerIntegrityException $exception) {
            throw ValidationException::withMessages([
                $this->mapIntegrityExceptionField($exception->getMessage()) => [$exception->getMessage()],
            ]);
        }

        $transaction = $result->transaction->loadMissing([
            'currency:code,name,symbol,minor_unit_exponent',
            'financialAccount:id,name,type,currency_code,is_archived',
            'transferFinancialAccount:id,name,type,currency_code,is_archived',
            'category:id,name,type,is_archived',
            'reversalTransaction:id,reversed_transaction_id,journal_entry_id',
        ]);

        return response()->json([
            'data' => $this->serializeTransaction($transaction),
            'meta' => [
                'was_recently_created' => $result->wasRecentlyCreated,
                'idempotent_replay' => ! $result->wasRecentlyCreated,
            ],
        ], $result->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Serializes a FinancialTransaction for presentation without exposing internal ledger postings.
     *
     * @return array<string, mixed>
     */
    private function serializeTransaction(FinancialTransaction $transaction): array
    {
        $currency = $transaction->currency;
        $exponent = $currency !== null ? (int) $currency->minor_unit_exponent : 2;
        $symbol = $currency !== null ? $currency->symbol : $transaction->currency_code.' ';

        $money = new Money(
            amountMinor: (int) $transaction->amount_minor,
            currencyCode: $transaction->currency_code,
            exponent: $exponent,
        );

        $primaryAccount = $transaction->financialAccount;
        $transferAccount = $transaction->transferFinancialAccount;
        $category = $transaction->category;
        $reversalTransaction = $transaction->relationLoaded('reversalTransaction')
            ? $transaction->reversalTransaction
            : null;
        $isReversal = $transaction->isReversal();
        $isReversed = ! $isReversal && $reversalTransaction !== null;

        return [
            'id' => (int) $transaction->id,
            'journal_entry_id' => (int) $transaction->journal_entry_id,
            'type' => $transaction->type->value,
            'type_label' => $transaction->type->label(),
            'is_reversal' => $isReversal,
            'is_reversed' => $isReversed,
            'reversed_transaction_id' => $transaction->reversed_transaction_id !== null
                ? (int) $transaction->reversed_transaction_id
                : null,
            'reversal_transaction_id' => $reversalTransaction !== null
                ? (int) $reversalTransaction->id
                : null,
            'reversal_reason' => $transaction->reversal_reason,
            'entry_date' => $transaction->entry_date->format('Y-m-d'),
            'description' => $transaction->description,
            'notes' => $transaction->notes,
            'currency_code' => $transaction->currency_code,
            'amount_minor' => (int) $transaction->amount_minor,
            'amount_formatted' => $money->format($symbol),
            'financial_account' => $primaryAccount !== null ? [
                'id' => (int) $primaryAccount->id,
                'name' => $primaryAccount->name,
                'type' => $primaryAccount->type->value,
                'type_label' => $primaryAccount->type->label(),
                'currency_code' => $primaryAccount->currency_code,
                'is_archived' => (bool) $primaryAccount->is_archived,
            ] : null,
            'transfer_financial_account' => $transferAccount !== null ? [
                'id' => (int) $transferAccount->id,
                'name' => $transferAccount->name,
                'type' => $transferAccount->type->value,
                'type_label' => $transferAccount->type->label(),
                'currency_code' => $transferAccount->currency_code,
                'is_archived' => (bool) $transferAccount->is_archived,
            ] : null,
            'category' => $category !== null ? [
                'id' => (int) $category->id,
                'name' => $category->name,
                'type' => $category->type->value,
                'type_label' => $category->type->label(),
                'is_archived' => (bool) $category->is_archived,
            ] : null,
            'created_at' => $transaction->created_at?->toIso8601String(),
        ];
    }

    private function mapIntegrityExceptionField(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'transfer') || str_contains($lower, 'destination') || str_contains($lower, 'cross-currency')) {
            return 'transfer_financial_account_id';
        }

        if (str_contains($lower, 'category') || str_contains($lower, 'categories')) {
            return 'category_id';
        }

        if (str_contains($lower, 'financial account')) {
            return 'financial_account_id';
        }

        if (str_contains($lower, 'amount') || str_contains($lower, 'fractional') || str_contains($lower, 'exponent')) {
            return 'amount';
        }

        if (str_contains($lower, 'date')) {
            return 'entry_date';
        }

        if (str_contains($lower, 'description')) {
            return 'description';
        }

        if (str_contains($lower, 'notes')) {
            return 'notes';
        }

        if (str_contains($lower, 'idempotency') || str_contains($lower, 'uuid')) {
            return 'idempotency_key';
        }

        return 'amount';
    }
}
