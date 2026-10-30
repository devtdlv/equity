<?php

namespace App\Models;

use App\Enums\CategoryType;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'type',
        'is_archived',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CategoryType::class,
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            $isArchived = (bool) $category->is_archived;
            $hasArchivedAt = $category->archived_at !== null;

            if ($isArchived !== $hasArchivedAt) {
                throw new LedgerIntegrityException(
                    'Category archive state must match archived_at timestamp presence.',
                );
            }
        });

        static::updating(function (self $category): void {
            if ($category->isDirty('user_id')) {
                throw new ImmutableRecordException('Category ownership cannot be modified.');
            }

            if ($category->isDirty('type')) {
                throw new ImmutableRecordException('Category type cannot be changed after creation.');
            }
        });

        static::deleting(function (): void {
            throw new LedgerIntegrityException(
                'Categories cannot be deleted. Archive the category instead.',
            );
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<LedgerAccount, $this>
     */
    public function ledgerAccounts(): HasMany
    {
        return $this->hasMany(LedgerAccount::class, 'category_id');
    }

    /**
     * @return HasMany<FinancialTransaction, $this>
     */
    public function financialTransactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class, 'category_id');
    }

    /**
     * @return HasMany<BudgetAllocation, $this>
     */
    public function budgetAllocations(): HasMany
    {
        return $this->hasMany(BudgetAllocation::class, 'category_id');
    }

    /**
     * @return HasMany<RecurringObligation, $this>
     */
    public function recurringObligations(): HasMany
    {
        return $this->hasMany(RecurringObligation::class, 'category_id');
    }
}
