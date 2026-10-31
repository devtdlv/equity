<?php

namespace App\Models;

use App\Enums\SavingsGoalEventType;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsGoalEvent extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'savings_goal_id',
        'idempotency_key',
        'type',
        'amount_minor',
        'currency_code',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'type' => SavingsGoalEventType::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $event): void {
            $amount = (int) $event->amount_minor;
            if ($amount <= 0 || $amount > Money::MAX_MINOR_AMOUNT) {
                throw new LedgerIntegrityException(
                    'Savings goal event amount must be a positive integer within supported bounds.',
                );
            }

            $type = $event->type instanceof SavingsGoalEventType
                ? $event->type
                : SavingsGoalEventType::tryFrom((string) $event->type);

            if ($type === null) {
                throw new LedgerIntegrityException('Invalid savings goal event type.');
            }
        });

        static::updating(function (): void {
            throw new ImmutableRecordException(
                'Savings goal events are append-only and cannot be modified.',
            );
        });

        static::deleting(function (): void {
            throw new ImmutableRecordException(
                'Savings goal events are append-only and cannot be deleted.',
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
     * @return BelongsTo<SavingsGoal, $this>
     */
    public function savingsGoal(): BelongsTo
    {
        return $this->belongsTo(SavingsGoal::class, 'savings_goal_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }
}
