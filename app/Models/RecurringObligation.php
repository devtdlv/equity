<?php

namespace App\Models;

use App\Enums\CategoryType;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringObligationStatus;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringObligation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'idempotency_key',
        'name',
        'description',
        'currency_code',
        'amount_minor',
        'category_id',
        'category_type',
        'financial_account_id',
        'start_date',
        'frequency',
        'interval_count',
        'anchor_day',
        'ends_of_month',
        'next_occurrence_date',
        'status',
        'paused_at',
        'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_type' => CategoryType::class,
            'amount_minor' => 'integer',
            'interval_count' => 'integer',
            'anchor_day' => 'integer',
            'ends_of_month' => 'boolean',
            'frequency' => RecurringFrequency::class,
            'status' => RecurringObligationStatus::class,
            'start_date' => 'date:Y-m-d',
            'next_occurrence_date' => 'date:Y-m-d',
            'paused_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $obligation): void {
            $obligation->category_type = CategoryType::Expense;
            $obligation->interval_count = 1;

            $amount = (int) $obligation->amount_minor;
            if ($amount <= 0 || $amount > Money::MAX_MINOR_AMOUNT) {
                throw new LedgerIntegrityException(
                    'Recurring obligation amount must be a positive integer within supported bounds.',
                );
            }

            $anchorDay = (int) $obligation->anchor_day;
            if ($anchorDay < 1 || $anchorDay > 31) {
                throw new LedgerIntegrityException(
                    'Recurring obligation anchor_day must be between 1 and 31.',
                );
            }

            $status = $obligation->status instanceof RecurringObligationStatus
                ? $obligation->status
                : RecurringObligationStatus::tryFrom((string) $obligation->status);

            if ($status === null) {
                throw new LedgerIntegrityException('Invalid recurring obligation status.');
            }

            $hasPausedAt = $obligation->paused_at !== null;
            $hasCancelledAt = $obligation->cancelled_at !== null;

            $consistent = match ($status) {
                RecurringObligationStatus::Active => ! $hasPausedAt && ! $hasCancelledAt,
                RecurringObligationStatus::Paused => $hasPausedAt && ! $hasCancelledAt,
                RecurringObligationStatus::Cancelled => $hasCancelledAt,
            };

            if (! $consistent) {
                throw new LedgerIntegrityException(
                    'Recurring obligation status must match paused_at and cancelled_at timestamps.',
                );
            }
        });

        static::updating(function (self $obligation): void {
            if ($obligation->isDirty('user_id')) {
                throw new ImmutableRecordException('Recurring obligation ownership cannot be modified.');
            }

            if ($obligation->isDirty('idempotency_key')) {
                throw new ImmutableRecordException('Recurring obligation idempotency key cannot be modified.');
            }

            if ($obligation->isDirty('currency_code')) {
                throw new ImmutableRecordException('Recurring obligation currency cannot be modified.');
            }
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
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<FinancialAccount, $this>
     */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    /**
     * @return HasMany<RecurringOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(RecurringOccurrence::class, 'obligation_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RecurringObligationStatus::Active->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePaused(Builder $query): Builder
    {
        return $query->where('status', RecurringObligationStatus::Paused->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', RecurringObligationStatus::Cancelled->value);
    }
}
