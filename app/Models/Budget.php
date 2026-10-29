<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Budget extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'currency_code',
        'period_start',
        'period_end',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $budget): void {
            if (! self::isValidCalendarMonth($budget->period_start, $budget->period_end)) {
                throw new LedgerIntegrityException(
                    'Budget period_start must be the first day of a month and period_end must be the last day of the same month.',
                );
            }
        });

        static::updating(function (self $budget): void {
            if ($budget->isDirty('user_id')) {
                throw new ImmutableRecordException('Budget ownership cannot be modified.');
            }

            if ($budget->isDirty('currency_code')) {
                throw new ImmutableRecordException('Budget currency cannot be modified.');
            }

            if ($budget->isDirty('period_start') || $budget->isDirty('period_end')) {
                throw new ImmutableRecordException('Budget period boundaries cannot be modified.');
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
     * @return HasMany<BudgetAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(BudgetAllocation::class);
    }

    public static function isValidCalendarMonth(
        CarbonInterface|string|null $periodStart,
        CarbonInterface|string|null $periodEnd,
    ): bool {
        if ($periodStart === null || $periodEnd === null) {
            return false;
        }

        $start = $periodStart instanceof CarbonInterface
            ? $periodStart->copy()->startOfDay()
            : Carbon::parse($periodStart)->startOfDay();
        $end = $periodEnd instanceof CarbonInterface
            ? $periodEnd->copy()->startOfDay()
            : Carbon::parse($periodEnd)->startOfDay();

        if ($start->day !== 1) {
            return false;
        }

        if (! $end->equalTo($start->copy()->endOfMonth()->startOfDay())) {
            return false;
        }

        return $end->greaterThanOrEqualTo($start);
    }
}
