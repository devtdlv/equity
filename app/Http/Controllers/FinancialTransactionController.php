<?php

namespace App\Http\Controllers;

use App\DataTransferObjects\CreateFinancialTransactionInput;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\LedgerIntegrityException;
use App\Http\Requests\Transactions\IndexFinancialTransactionRequest;
use App\Http\Requests\Transactions\StoreFinancialTransactionRequest;
use App\Models\FinancialTransaction;
use App\Services\FinancialTransactionService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class FinancialTransactionController extends Controller
{
    public const DEFAULT_PER_PAGE = 15;

    public function __construct(
        private readonly FinancialTransactionService $transactionService,
    ) {}

    /**
     * List the authenticated user's financial transactions with optional filtering and pagination.
     */
    public function index(IndexFinancialTransactionRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $query = FinancialTransaction::query()
            ->with([
                'currency:code,name,symbol,minor_unit_exponent',
                'financialAccount:id,name,type,currency_code,is_archived',
                'transferFinancialAccount:id,name,type,currency_code,is_archived',
                'category:id,name,type,is_archived',
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

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => [
                'type' => $validated['type'] ?? null,
                'financial_account_id' => isset($validated['financial_account_id'])
                    ? (int) $validated['financial_account_id']
                    : null,
                'category_id' => isset($validated['category_id'])
                    ? (int) $validated['category_id']
                    : null,
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
            ],
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

        return [
            'id' => (int) $transaction->id,
            'journal_entry_id' => (int) $transaction->journal_entry_id,
            'type' => $transaction->type->value,
            'type_label' => $transaction->type->label(),
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
