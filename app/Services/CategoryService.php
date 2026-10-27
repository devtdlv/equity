<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use ValueError;

class CategoryService
{
    public const CATEGORY_NAME_UNIQUE_INDEX = 'categories_user_id_type_name_unique';

    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Lists categories belonging to the authenticated user.
     *
     * @return Collection<int, Category>
     */
    public function listForUser(
        User $user,
        CategoryType|string|null $type = null,
        bool $includeArchived = true,
    ): Collection {
        $this->assertValidUser($user);

        $query = Category::query()
            ->where('user_id', $user->id)
            ->orderBy('is_archived')
            ->orderBy('type')
            ->orderBy('name');

        if ($type !== null) {
            $resolvedType = $this->resolveCategoryType($type);
            $query->where('type', $resolvedType->value);
        }

        if (! $includeArchived) {
            $query->where('is_archived', false);
        }

        return $query->get();
    }

    /**
     * Creates a user-owned income or expense category and records an audit event.
     *
     * @param  array{name: string, type: CategoryType|string}  $data
     */
    public function createCategory(User $user, array $data): Category
    {
        $this->assertValidUser($user);

        $categoryType = $this->resolveCategoryType($data['type'] ?? '');
        $trimmedName = $this->validateCategoryName((string) ($data['name'] ?? ''));

        $this->assertUniqueCategoryName($user->id, $categoryType, $trimmedName);

        try {
            return DB::transaction(function () use ($user, $categoryType, $trimmedName): Category {
                $this->assertUniqueCategoryName($user->id, $categoryType, $trimmedName);

                $category = Category::query()->create([
                    'user_id' => $user->id,
                    'name' => $trimmedName,
                    'type' => $categoryType,
                    'is_archived' => false,
                    'archived_at' => null,
                ]);

                $this->auditService->record(
                    actor: $user,
                    action: AuditService::ACTION_CATEGORY_CREATED,
                    auditableType: Category::class,
                    auditableId: $category->id,
                    metadata: [
                        'name' => $trimmedName,
                        'type' => $categoryType->value,
                    ],
                );

                return $category;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($this->isCategoryNameUniqueViolation($exception)) {
                throw new LedgerIntegrityException(
                    'A category with this name and type already exists.',
                    previous: $exception,
                );
            }

            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isCategoryNameUniqueViolation($exception)) {
                throw new LedgerIntegrityException(
                    'A category with this name and type already exists.',
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }

    /**
     * Renames a user-owned category, synchronizes any linked ledger account names, and records an audit event.
     */
    public function renameCategory(User $actor, Category $category, string $newName): Category
    {
        $this->assertValidUser($actor);
        $this->assertOwnership($actor, $category);

        $trimmedName = $this->validateCategoryName($newName);

        try {
            return DB::transaction(function () use ($actor, $category, $trimmedName): Category {
                /** @var Category $lockedCategory */
                $lockedCategory = Category::query()
                    ->whereKey($category->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertOwnership($actor, $lockedCategory);

                if ($lockedCategory->name === $trimmedName) {
                    return $lockedCategory;
                }

                $this->assertUniqueCategoryName(
                    userId: (int) $lockedCategory->user_id,
                    type: $lockedCategory->type,
                    name: $trimmedName,
                    ignoreCategoryId: (int) $lockedCategory->id,
                );

                $previousName = $lockedCategory->name;
                $lockedCategory->update([
                    'name' => $trimmedName,
                ]);

                $ledgerAccounts = $lockedCategory->ledgerAccounts()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                foreach ($ledgerAccounts as $ledgerAccount) {
                    $ledgerAccount->update([
                        'name' => sprintf('%s (%s)', $trimmedName, $ledgerAccount->currency_code),
                    ]);
                }

                $this->auditService->record(
                    actor: $actor,
                    action: AuditService::ACTION_CATEGORY_UPDATED,
                    auditableType: Category::class,
                    auditableId: $lockedCategory->id,
                    metadata: [
                        'previous_name' => $previousName,
                        'name' => $trimmedName,
                        'type' => $lockedCategory->type->value,
                    ],
                );

                return $lockedCategory->fresh() ?? $lockedCategory;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($this->isCategoryNameUniqueViolation($exception)) {
                throw new LedgerIntegrityException(
                    'A category with this name and type already exists.',
                    previous: $exception,
                );
            }

            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isCategoryNameUniqueViolation($exception)) {
                throw new LedgerIntegrityException(
                    'A category with this name and type already exists.',
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }

    public function archiveCategory(User $actor, Category $category): Category
    {
        $this->assertValidUser($actor);
        $this->assertOwnership($actor, $category);

        return DB::transaction(function () use ($actor, $category): Category {
            /** @var Category $lockedCategory */
            $lockedCategory = Category::query()
                ->whereKey($category->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertOwnership($actor, $lockedCategory);

            if ($lockedCategory->is_archived) {
                return $lockedCategory;
            }

            $lockedCategory->update([
                'is_archived' => true,
                'archived_at' => now(),
            ]);

            $this->auditService->record(
                actor: $actor,
                action: AuditService::ACTION_CATEGORY_ARCHIVED,
                auditableType: Category::class,
                auditableId: $lockedCategory->id,
                metadata: [
                    'name' => $lockedCategory->name,
                    'type' => $lockedCategory->type->value,
                ],
            );

            return $lockedCategory->fresh() ?? $lockedCategory;
        });
    }

    public function restoreCategory(User $actor, Category $category): Category
    {
        $this->assertValidUser($actor);
        $this->assertOwnership($actor, $category);

        return DB::transaction(function () use ($actor, $category): Category {
            /** @var Category $lockedCategory */
            $lockedCategory = Category::query()
                ->whereKey($category->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertOwnership($actor, $lockedCategory);

            if (! $lockedCategory->is_archived) {
                return $lockedCategory;
            }

            $lockedCategory->update([
                'is_archived' => false,
                'archived_at' => null,
            ]);

            $this->auditService->record(
                actor: $actor,
                action: AuditService::ACTION_CATEGORY_RESTORED,
                auditableType: Category::class,
                auditableId: $lockedCategory->id,
                metadata: [
                    'name' => $lockedCategory->name,
                    'type' => $lockedCategory->type->value,
                ],
            );

            return $lockedCategory->fresh() ?? $lockedCategory;
        });
    }

    private function resolveCategoryType(CategoryType|string $type): CategoryType
    {
        if ($type instanceof CategoryType) {
            return $type;
        }

        try {
            return CategoryType::from(strtolower(trim($type)));
        } catch (ValueError $exception) {
            throw new LedgerIntegrityException('Category type must be either income or expense.', previous: $exception);
        }
    }

    private function validateCategoryName(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw new LedgerIntegrityException('Category name is required.');
        }

        if (mb_strlen($trimmed) > 80) {
            throw new LedgerIntegrityException('Category name must not exceed 80 characters.');
        }

        return $trimmed;
    }

    private function assertUniqueCategoryName(
        int $userId,
        CategoryType $type,
        string $name,
        ?int $ignoreCategoryId = null,
    ): void {
        $query = Category::query()
            ->where('user_id', $userId)
            ->where('type', $type->value)
            ->where('name', $name);

        if ($ignoreCategoryId !== null) {
            $query->where('id', '!=', $ignoreCategoryId);
        }

        if ($query->exists()) {
            throw new LedgerIntegrityException('A category with this name and type already exists.');
        }
    }

    private function assertValidUser(User $user): void
    {
        if (! $user->exists || $user->id === null) {
            throw new LedgerIntegrityException('Authenticated user is required.');
        }
    }

    private function assertOwnership(User $actor, Category $category): void
    {
        if ((int) $actor->id !== (int) $category->user_id) {
            throw new LedgerIntegrityException('Category does not exist or is not accessible.');
        }
    }

    private function isCategoryNameUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        $isUniqueError = $sqlState === '23505'
            || $driverCode === 1062
            || $driverCode === 2067
            || (in_array($sqlState, ['23000', '19'], true) && str_contains($message, 'unique'));

        if (! $isUniqueError) {
            return false;
        }

        return str_contains($message, self::IDEMPOTENCY_FALLBACK_TOKEN())
            || (
                str_contains($message, 'categories.user_id')
                && str_contains($message, 'categories.type')
                && str_contains($message, 'categories.name')
            );
    }

    private static function IDEMPOTENCY_FALLBACK_TOKEN(): string
    {
        return self::CATEGORY_NAME_UNIQUE_INDEX;
    }
}
