<?php

namespace App\Http\Controllers;

use App\DataTransferObjects\CreateRecurringObligationInput;
use App\DataTransferObjects\UpdateRecurringObligationInput;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\LedgerIntegrityException;
use App\Http\Requests\Recurring\CancelRecurringObligationRequest;
use App\Http\Requests\Recurring\IndexRecurringObligationRequest;
use App\Http\Requests\Recurring\PauseRecurringObligationRequest;
use App\Http\Requests\Recurring\ResumeRecurringObligationRequest;
use App\Http\Requests\Recurring\ShowRecurringObligationRequest;
use App\Http\Requests\Recurring\StoreRecurringObligationRequest;
use App\Http\Requests\Recurring\UpdateRecurringObligationRequest;
use App\Models\RecurringObligation;
use App\Services\RecurringObligationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class RecurringObligationController extends Controller
{
    public const DEFAULT_PER_PAGE = 15;

    public function __construct(
        private readonly RecurringObligationService $obligationService,
    ) {}

    public function index(IndexRecurringObligationRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE);

        $query = RecurringObligation::query()
            ->with([
                'currency:code,name,symbol,minor_unit_exponent',
                'category:id,name,type,is_archived',
                'financialAccount:id,name,type,currency_code,is_archived',
            ])
            ->where('user_id', $user->id)
            ->orderByDesc('next_occurrence_date')
            ->orderBy('id');

        if (! empty($validated['currency_code'])) {
            $query->where('currency_code', $validated['currency_code']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $paginator = $query->paginate($perPage)->withQueryString();

        $items = collect($paginator->items())
            ->map(fn (RecurringObligation $obligation): array => $this->serializeObligation($obligation))
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
                'currency_code' => $validated['currency_code'] ?? null,
                'status' => $validated['status'] ?? null,
            ],
        ]);
    }

    public function store(StoreRecurringObligationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = $this->obligationService->create(new CreateRecurringObligationInput(
                user: $request->user(),
                idempotencyKey: (string) $validated['idempotency_key'],
                name: (string) $validated['name'],
                amount: (string) $validated['amount'],
                currencyCode: (string) $validated['currency_code'],
                categoryId: (int) $validated['category_id'],
                startDate: (string) $validated['start_date'],
                frequency: (string) $validated['frequency'],
                anchorDay: (int) $validated['anchor_day'],
                endsOfMonth: (bool) ($validated['ends_of_month'] ?? false),
                description: isset($validated['description']) ? (string) $validated['description'] : null,
                financialAccountId: isset($validated['financial_account_id'])
                    ? (int) $validated['financial_account_id']
                    : null,
            ));
        } catch (IdempotencyConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'idempotency_key' => [$exception->getMessage()],
                ],
            ], 409);
        } catch (LedgerIntegrityException $exception) {
            throw ValidationException::withMessages([
                $this->mapCreateIntegrityField($exception->getMessage()) => [$exception->getMessage()],
            ]);
        }

        $obligation = $result->obligation->loadMissing([
            'currency:code,name,symbol,minor_unit_exponent',
            'category:id,name,type,is_archived',
            'financialAccount:id,name,type,currency_code,is_archived',
        ]);

        return response()->json([
            'data' => $this->serializeObligation($obligation),
            'meta' => [
                'was_recently_created' => $result->wasRecentlyCreated,
                'idempotent_replay' => ! $result->wasRecentlyCreated,
            ],
        ], $result->wasRecentlyCreated ? 201 : 200);
    }

    public function show(
        ShowRecurringObligationRequest $request,
        RecurringObligation $obligation,
    ): JsonResponse {
        $obligation->loadMissing([
            'currency:code,name,symbol,minor_unit_exponent',
            'category:id,name,type,is_archived',
            'financialAccount:id,name,type,currency_code,is_archived',
        ]);

        return response()->json([
            'data' => $this->serializeObligation($obligation),
        ]);
    }

    public function update(
        UpdateRecurringObligationRequest $request,
        RecurringObligation $obligation,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $updated = $this->obligationService->update(new UpdateRecurringObligationInput(
                user: $request->user(),
                obligationId: (int) $obligation->id,
                name: (string) $validated['name'],
                amount: (string) $validated['amount'],
                categoryId: (int) $validated['category_id'],
                startDate: (string) $validated['start_date'],
                frequency: (string) $validated['frequency'],
                anchorDay: (int) $validated['anchor_day'],
                endsOfMonth: (bool) ($validated['ends_of_month'] ?? false),
                description: array_key_exists('description', $validated)
                    ? ($validated['description'] !== null ? (string) $validated['description'] : null)
                    : null,
                financialAccountId: array_key_exists('financial_account_id', $validated)
                    ? ($validated['financial_account_id'] !== null
                        ? (int) $validated['financial_account_id']
                        : null)
                    : null,
            ));
        } catch (LedgerIntegrityException $exception) {
            throw ValidationException::withMessages([
                $this->mapUpdateIntegrityField($exception->getMessage()) => [$exception->getMessage()],
            ]);
        }

        $updated->loadMissing([
            'currency:code,name,symbol,minor_unit_exponent',
            'category:id,name,type,is_archived',
            'financialAccount:id,name,type,currency_code,is_archived',
        ]);

        return response()->json([
            'data' => $this->serializeObligation($updated),
        ]);
    }

    public function pause(
        PauseRecurringObligationRequest $request,
        RecurringObligation $obligation,
    ): JsonResponse {
        try {
            $updated = $this->obligationService->pause($request->user(), (int) $obligation->id);
        } catch (LedgerIntegrityException $exception) {
            throw ValidationException::withMessages([
                'obligation' => [$exception->getMessage()],
            ]);
        }

        $updated->loadMissing([
            'currency:code,name,symbol,minor_unit_exponent',
            'category:id,name,type,is_archived',
            'financialAccount:id,name,type,currency_code,is_archived',
        ]);

        return response()->json([
            'data' => $this->serializeObligation($updated),
        ]);
    }

    public function resume(
        ResumeRecurringObligationRequest $request,
        RecurringObligation $obligation,
    ): JsonResponse {
        try {
            $updated = $this->obligationService->resume($request->user(), (int) $obligation->id);
        } catch (LedgerIntegrityException $exception) {
            throw ValidationException::withMessages([
                'obligation' => [$exception->getMessage()],
            ]);
        }

        $updated->loadMissing([
            'currency:code,name,symbol,minor_unit_exponent',
            'category:id,name,type,is_archived',
            'financialAccount:id,name,type,currency_code,is_archived',
        ]);

        return response()->json([
            'data' => $this->serializeObligation($updated),
        ]);
    }

    public function cancel(
        CancelRecurringObligationRequest $request,
        RecurringObligation $obligation,
    ): JsonResponse {
        try {
            $updated = $this->obligationService->cancel($request->user(), (int) $obligation->id);
        } catch (LedgerIntegrityException $exception) {
            throw ValidationException::withMessages([
                'obligation' => [$exception->getMessage()],
            ]);
        }

        $updated->loadMissing([
            'currency:code,name,symbol,minor_unit_exponent',
            'category:id,name,type,is_archived',
            'financialAccount:id,name,type,currency_code,is_archived',
        ]);

        return response()->json([
            'data' => $this->serializeObligation($updated),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeObligation(RecurringObligation $obligation): array
    {
        $currency = $obligation->relationLoaded('currency') ? $obligation->currency : null;
        $category = $obligation->relationLoaded('category') ? $obligation->category : null;
        $account = $obligation->relationLoaded('financialAccount') ? $obligation->financialAccount : null;

        return [
            'id' => (int) $obligation->id,
            'idempotency_key' => (string) $obligation->idempotency_key,
            'name' => $obligation->name,
            'description' => $obligation->description,
            'currency_code' => $obligation->currency_code,
            'amount_minor' => (int) $obligation->amount_minor,
            'category_id' => (int) $obligation->category_id,
            'category_type' => $obligation->category_type instanceof \BackedEnum
                ? $obligation->category_type->value
                : (string) $obligation->category_type,
            'financial_account_id' => $obligation->financial_account_id !== null
                ? (int) $obligation->financial_account_id
                : null,
            'start_date' => $obligation->start_date->toDateString(),
            'frequency' => $obligation->frequency instanceof \BackedEnum
                ? $obligation->frequency->value
                : (string) $obligation->frequency,
            'interval_count' => (int) $obligation->interval_count,
            'anchor_day' => (int) $obligation->anchor_day,
            'ends_of_month' => (bool) $obligation->ends_of_month,
            'next_occurrence_date' => $obligation->next_occurrence_date?->toDateString(),
            'status' => $obligation->status instanceof \BackedEnum
                ? $obligation->status->value
                : (string) $obligation->status,
            'paused_at' => optional($obligation->paused_at)?->toIso8601String(),
            'cancelled_at' => optional($obligation->cancelled_at)?->toIso8601String(),
            'currency' => $currency === null ? null : [
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
                'minor_unit_exponent' => (int) $currency->minor_unit_exponent,
            ],
            'category' => $category === null ? null : [
                'id' => (int) $category->id,
                'name' => $category->name,
                'type' => $category->type instanceof \BackedEnum
                    ? $category->type->value
                    : (string) $category->type,
                'is_archived' => (bool) $category->is_archived,
            ],
            'financial_account' => $account === null ? null : [
                'id' => (int) $account->id,
                'name' => $account->name,
                'type' => $account->type instanceof \BackedEnum
                    ? $account->type->value
                    : (string) $account->type,
                'currency_code' => $account->currency_code,
                'is_archived' => (bool) $account->is_archived,
            ],
            'created_at' => optional($obligation->created_at)?->toIso8601String(),
            'updated_at' => optional($obligation->updated_at)?->toIso8601String(),
        ];
    }

    private function mapCreateIntegrityField(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'idempotency')) {
            return 'idempotency_key';
        }

        if (str_contains($lower, 'preferred financial account') || str_contains($lower, 'account currency')) {
            return 'financial_account_id';
        }

        if (str_contains($lower, 'currency')) {
            return 'currency_code';
        }

        if (str_contains($lower, 'categor')) {
            return 'category_id';
        }

        if (str_contains($lower, 'account')) {
            return 'financial_account_id';
        }

        if (str_contains($lower, 'amount') || str_contains($lower, 'monetary') || str_contains($lower, 'fractional')) {
            return 'amount';
        }

        if (str_contains($lower, 'frequency') || str_contains($lower, 'ends-of-month')) {
            return 'frequency';
        }

        if (str_contains($lower, 'anchor')) {
            return 'anchor_day';
        }

        if (str_contains($lower, 'start_date') || str_contains($lower, 'calendar date')) {
            return 'start_date';
        }

        if (str_contains($lower, 'name')) {
            return 'name';
        }

        return 'obligation';
    }

    private function mapUpdateIntegrityField(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'cancelled')) {
            return 'obligation';
        }

        return $this->mapCreateIntegrityField($message);
    }
}
