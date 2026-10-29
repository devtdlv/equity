<?php

namespace App\Http\Controllers;

use App\DataTransferObjects\BudgetCategorySpending;
use App\DataTransferObjects\BudgetSpendingSummary;
use App\DataTransferObjects\CreateBudgetInput;
use App\DataTransferObjects\SetBudgetAllocationInput;
use App\Enums\CategoryType;
use App\Exceptions\LedgerIntegrityException;
use App\Http\Requests\Budgets\IndexBudgetRequest;
use App\Http\Requests\Budgets\ShowBudgetRequest;
use App\Http\Requests\Budgets\StoreBudgetRequest;
use App\Http\Requests\Budgets\UpdateBudgetAllocationRequest;
use App\Models\Budget;
use App\Models\BudgetAllocation;
use App\Models\Category;
use App\Models\Currency;
use App\Services\BudgetService;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public const DEFAULT_PER_PAGE = 15;

    public function __construct(
        private readonly BudgetService $budgetService,
        private readonly CategoryService $categoryService,
    ) {}

    /**
     * List the authenticated user's budgets (newest month first).
     *
     * Supports both Inertia page visits (initial browser load or Inertia <Link> navigation)
     * and JSON API requests (`Accept: application/json`).
     */
    public function index(IndexBudgetRequest $request): JsonResponse|Response
    {
        $user = $request->user();
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE);

        $query = Budget::query()
            ->with(['currency:code,name,symbol,minor_unit_exponent'])
            ->where('user_id', $user->id)
            ->orderByDesc('period_start')
            ->orderBy('currency_code')
            ->orderBy('id');

        if (! empty($validated['currency_code'])) {
            $query->where('currency_code', $validated['currency_code']);
        }

        $paginator = $query->paginate($perPage)->withQueryString();

        $items = collect($paginator->items())
            ->map(fn (Budget $budget): array => $this->serializeBudget($budget))
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
            'currency_code' => $validated['currency_code'] ?? null,
        ];

        if ($request->wantsJson() && ! $request->inertia()) {
            return response()->json([
                'data' => $items,
                'meta' => $meta,
                'filters' => $filters,
            ]);
        }

        $categories = $this->categoryService
            ->listForUser(user: $user, type: CategoryType::Expense, includeArchived: true)
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

        $now = now('UTC');

        return Inertia::render('Budgets/Index', [
            'categories' => $categories,
            'currencies' => $currencies,
            'initial_year' => (int) $now->year,
            'initial_month' => (int) $now->month,
        ]);
    }

    /**
     * Create a monthly budget for the authenticated user.
     *
     * Duplicate (user, currency, month) conflicts return HTTP 409. Schema 000011 does not
     * provide durable UUID idempotent replay for budget creation.
     */
    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = $this->budgetService->createBudget(new CreateBudgetInput(
                user: $request->user(),
                currencyCode: (string) $validated['currency_code'],
                year: (int) $validated['year'],
                month: (int) $validated['month'],
            ));
        } catch (LedgerIntegrityException $exception) {
            if ($this->isDuplicateBudgetException($exception)) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => [
                        'budget' => [$exception->getMessage()],
                    ],
                ], 409);
            }

            throw ValidationException::withMessages([
                $this->mapCreateIntegrityField($exception->getMessage()) => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'data' => $this->serializeBudget($result->budget->loadMissing('currency')),
            'meta' => [
                'was_recently_created' => $result->wasRecentlyCreated,
            ],
        ], 201);
    }

    /**
     * Return an authoritative spending summary for a user-owned budget.
     */
    public function show(ShowBudgetRequest $request, Budget $budget): JsonResponse
    {
        $summary = $this->budgetService->getBudgetSpending($request->user(), (int) $budget->id);

        return response()->json([
            'data' => $this->serializeSpendingSummary($summary),
        ]);
    }

    /**
     * Create or update a category allocation and return a refreshed spending summary.
     */
    public function updateAllocation(
        UpdateBudgetAllocationRequest $request,
        Budget $budget,
        Category $category,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $result = $this->budgetService->setAllocation(new SetBudgetAllocationInput(
                user: $request->user(),
                budgetId: (int) $budget->id,
                categoryId: (int) $category->id,
                amount: (string) $validated['amount'],
            ));
        } catch (LedgerIntegrityException $exception) {
            throw ValidationException::withMessages([
                $this->mapAllocationIntegrityField($exception->getMessage()) => [$exception->getMessage()],
            ]);
        }

        $summary = $this->budgetService->getBudgetSpending($request->user(), (int) $budget->id);

        return response()->json([
            'data' => [
                'allocation' => $this->serializeAllocation($result->allocation),
                'previous_amount_minor' => $result->previousAmountMinor,
                'summary' => $this->serializeSpendingSummary($summary),
            ],
            'meta' => [
                'was_recently_created' => $result->wasRecentlyCreated,
            ],
        ], $result->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBudget(Budget $budget): array
    {
        $currency = $budget->relationLoaded('currency') ? $budget->currency : null;

        return [
            'id' => (int) $budget->id,
            'currency_code' => $budget->currency_code,
            'period_start' => $budget->period_start->toDateString(),
            'period_end' => $budget->period_end->toDateString(),
            'currency' => $currency === null ? null : [
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
                'minor_unit_exponent' => (int) $currency->minor_unit_exponent,
            ],
            'created_at' => optional($budget->created_at)?->toIso8601String(),
            'updated_at' => optional($budget->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAllocation(BudgetAllocation $allocation): array
    {
        $allocation->loadMissing('category:id,name,type,is_archived');

        $category = $allocation->category;

        return [
            'id' => (int) $allocation->id,
            'budget_id' => (int) $allocation->budget_id,
            'category_id' => (int) $allocation->category_id,
            'category_type' => $allocation->category_type instanceof \BackedEnum
                ? $allocation->category_type->value
                : (string) $allocation->category_type,
            'amount_minor' => (int) $allocation->amount_minor,
            'category' => $category === null ? null : [
                'id' => (int) $category->id,
                'name' => $category->name,
                'type' => $category->type instanceof \BackedEnum
                    ? $category->type->value
                    : (string) $category->type,
                'is_archived' => (bool) $category->is_archived,
            ],
            'created_at' => optional($allocation->created_at)?->toIso8601String(),
            'updated_at' => optional($allocation->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSpendingSummary(BudgetSpendingSummary $summary): array
    {
        $budget = $summary->budget->loadMissing('currency:code,name,symbol,minor_unit_exponent');

        return [
            'budget' => $this->serializeBudget($budget),
            'allocations' => array_map(
                fn (BudgetCategorySpending $row): array => $this->serializeCategorySpending($row),
                $summary->allocatedCategories,
            ),
            'totals' => [
                'allocated_minor' => $summary->totalAllocatedMinor,
                'allocated_actual_minor' => $summary->allocatedActualMinor,
                'unbudgeted_actual_minor' => $summary->unbudgetedActualMinor,
                'total_actual_minor' => $summary->totalActualMinor,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCategorySpending(BudgetCategorySpending $row): array
    {
        return [
            'category_id' => $row->categoryId,
            'category_name' => $row->categoryName,
            'category_is_archived' => $row->categoryIsArchived,
            'allocated_minor' => $row->allocatedMinor,
            'actual_minor' => $row->actualMinor,
            'remaining_minor' => $row->remainingMinor,
            'is_over_limit' => $row->isOverLimit,
        ];
    }

    private function isDuplicateBudgetException(LedgerIntegrityException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'already exists');
    }

    private function mapCreateIntegrityField(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'currency')) {
            return 'currency_code';
        }

        if (str_contains($lower, 'year') || str_contains($lower, 'month') || str_contains($lower, 'calendar')) {
            return 'month';
        }

        return 'budget';
    }

    private function mapAllocationIntegrityField(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'past-month') || str_contains($lower, 'read-only')) {
            return 'budget';
        }

        if (
            str_contains($lower, 'expense categor')
            || str_contains($lower, 'archived categor')
            || str_contains($lower, 'category is not accessible')
            || str_contains($lower, 'categories can receive')
            || str_contains($lower, 'category')
        ) {
            return 'category_id';
        }

        if (
            str_contains($lower, 'amount')
            || str_contains($lower, 'fractional')
            || str_contains($lower, 'exponent')
            || str_contains($lower, 'monetary')
        ) {
            return 'amount';
        }

        if (str_contains($lower, 'budget')) {
            return 'budget';
        }

        return 'allocation';
    }
}
