<?php

namespace App\Models;

use App\Enums\CategoryType;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetAllocation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'budget_id',
        'user_id',
        'category_id',
        'category_type',
        'amount_minor',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_type' => CategoryType::class,
            'amount_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $allocation): void {
            $allocation->category_type = CategoryType::Expense;

            $amount = (int) $allocation->amount_minor;

            if ($amount < 0 || $amount > Money::MAX_MINOR_AMOUNT) {
                throw new LedgerIntegrityException(
                    'Budget allocation amount must be a non-negative integer within supported bounds.',
                );
            }
        });

        static::updating(function (self $allocation): void {
            if ($allocation->isDirty('user_id')) {
                throw new ImmutableRecordException('Budget allocation ownership cannot be modified.');
            }

            if ($allocation->isDirty('budget_id')) {
                throw new ImmutableRecordException('Budget allocation budget cannot be modified.');
            }

            if ($allocation->isDirty('category_id') || $allocation->isDirty('category_type')) {
                throw new ImmutableRecordException('Budget allocation category cannot be modified.');
            }
        });
    }

    /**
     * @return BelongsTo<Budget, $this>
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
