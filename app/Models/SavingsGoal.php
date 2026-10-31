<?php

namespace App\Models;

use App\Enums\SavingsGoalStatus;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavingsGoal extends Model
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
        'target_amount_minor',
        'target_date',
        'status',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_amount_minor' => 'integer',
            'target_date' => 'date:Y-m-d',
            'status' => SavingsGoalStatus::class,
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $goal): void {
            $amount = (int) $goal->target_amount_minor;
            if ($amount <= 0 || $amount > Money::MAX_MINOR_AMOUNT) {
                throw new LedgerIntegrityException(
                    'Savings goal target must be a positive integer within supported bounds.',
                );
            }

            $status = $goal->status instanceof SavingsGoalStatus
                ? $goal->status
                : SavingsGoalStatus::tryFrom((string) $goal->status);

            if ($status === null) {
                throw new LedgerIntegrityException('Invalid savings goal status.');
            }

            $hasArchivedAt = $goal->archived_at !== null;
            $consistent = match ($status) {
                SavingsGoalStatus::Active => ! $hasArchivedAt,
                SavingsGoalStatus::Archived => $hasArchivedAt,
            };

            if (! $consistent) {
                throw new LedgerIntegrityException(
                    'Savings goal status must match archived_at timestamp.',
                );
            }
        });

        static::updating(function (self $goal): void {
            if ($goal->isDirty('user_id')) {
                throw new ImmutableRecordException('Savings goal ownership cannot be modified.');
            }

            if ($goal->isDirty('idempotency_key')) {
                throw new ImmutableRecordException('Savings goal idempotency key cannot be modified.');
            }

            if ($goal->isDirty('currency_code')) {
                throw new ImmutableRecordException('Savings goal currency cannot be modified.');
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
     * @return HasMany<SavingsGoalEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SavingsGoalEvent::class, 'savings_goal_id');
    }
}
