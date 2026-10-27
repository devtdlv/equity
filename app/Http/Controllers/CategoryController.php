<?php

namespace App\Http\Controllers;

use App\Exceptions\LedgerIntegrityException;
use App\Http\Requests\Categories\IndexCategoryRequest;
use App\Http\Requests\Categories\StoreCategoryRequest;
use App\Http\Requests\Categories\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryService $categoryService,
    ) {}

    /**
     * List the authenticated user's categories.
     */
    public function index(IndexCategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $type = $validated['type'] ?? null;
        $includeArchived = array_key_exists('include_archived', $validated)
            ? (bool) $validated['include_archived']
            : true;

        $categories = $this->categoryService->listForUser(
            user: $request->user(),
            type: $type,
            includeArchived: $includeArchived,
        );

        return response()->json([
            'data' => $categories
                ->map(fn (Category $category): array => $this->serializeCategory($category))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Create a new income or expense category for the authenticated user.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        try {
            $category = $this->categoryService->createCategory(
                user: $request->user(),
                data: $request->validated(),
            );
        } catch (LedgerIntegrityException $exception) {
            throw ValidationException::withMessages([
                'name' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'data' => $this->serializeCategory($category),
        ], 201);
    }

    /**
     * Rename an existing category owned by the authenticated user.
     */
    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        try {
            $updated = $this->categoryService->renameCategory(
                actor: $request->user(),
                category: $category,
                newName: (string) $request->validated('name'),
            );
        } catch (LedgerIntegrityException $exception) {
            throw ValidationException::withMessages([
                'name' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'data' => $this->serializeCategory($updated),
        ]);
    }

    /**
     * Archive a category owned by the authenticated user.
     */
    public function archive(Request $request, Category $category): JsonResponse
    {
        Gate::authorize('archive', $category);

        $archived = $this->categoryService->archiveCategory(
            actor: $request->user(),
            category: $category,
        );

        return response()->json([
            'data' => $this->serializeCategory($archived),
        ]);
    }

    /**
     * Restore an archived category owned by the authenticated user.
     */
    public function restore(Request $request, Category $category): JsonResponse
    {
        Gate::authorize('restore', $category);

        $restored = $this->categoryService->restoreCategory(
            actor: $request->user(),
            category: $category,
        );

        return response()->json([
            'data' => $this->serializeCategory($restored),
        ]);
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     type: string,
     *     type_label: string,
     *     classification: string,
     *     is_archived: bool,
     *     archived_at: string|null
     * }
     */
    private function serializeCategory(Category $category): array
    {
        return [
            'id' => (int) $category->id,
            'name' => $category->name,
            'type' => $category->type->value,
            'type_label' => $category->type->label(),
            'classification' => $category->type->ledgerClassification()->value,
            'is_archived' => (bool) $category->is_archived,
            'archived_at' => $category->archived_at?->toIso8601String(),
        ];
    }
}
