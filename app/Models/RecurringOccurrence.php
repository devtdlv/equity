<?php

namespace App\Models;

use App\Enums\RecurringOccurrenceStatus;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringOccurrence extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'obligation_id',
        'due_date',
        'expected_amount_minor',
        'currency_code',
        'status',
        'active_slot_key',
        'skipped_at',
        'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_amount_minor' => 'integer',
            'status' => RecurringOccurrenceStatus::class,
            'due_date' => 'date:Y-m-d',
            'active_slot_key' => 'date:Y-m-d',
            'skipped_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $occurrence): void {
            $amount = (int) $occurrence->expected_amount_minor;
            if ($amount <= 0 || $amount > Money::MAX_MINOR_AMOUNT) {
                throw new LedgerIntegrityException(
                    'Recurring occurrence expected amount must be a positive integer within supported bounds.',
                );
            }

            $status = $occurrence->status instanceof RecurringOccurrenceStatus
                ? $occurrence->status
                : RecurringOccurrenceStatus::tryFrom((string) $occurrence->status);

            if ($status === null) {
                throw new LedgerIntegrityException('Invalid recurring occurrence status.');
            }

            $dueDate = $occurrence->due_date;
            $dueDateString = $dueDate instanceof CarbonInterface
                ? $dueDate->toDateString()
                : (string) $dueDate;

            // Keep active_slot_key aligned with stored lifecycle occupancy rules.
            if (
                $status === RecurringOccurrenceStatus::Scheduled
                || $status === RecurringOccurrenceStatus::Skipped
            ) {
                $occurrence->active_slot_key = $dueDateString;
            } else {
                $occurrence->active_slot_key = null;
            }

            $hasSkippedAt = $occurrence->skipped_at !== null;
            $hasCancelledAt = $occurrence->cancelled_at !== null;

            $consistent = match ($status) {
                RecurringOccurrenceStatus::Scheduled => ! $hasSkippedAt && ! $hasCancelledAt,
                RecurringOccurrenceStatus::Skipped => $hasSkippedAt && ! $hasCancelledAt,
                RecurringOccurrenceStatus::Cancelled => $hasCancelledAt,
            };

            if (! $consistent) {
                throw new LedgerIntegrityException(
                    'Recurring occurrence status must match skipped_at and cancelled_at timestamps.',
                );
            }
        });

        static::updating(function (self $occurrence): void {
            if ($occurrence->isDirty('user_id')) {
                throw new ImmutableRecordException('Recurring occurrence ownership cannot be modified.');
            }

            if ($occurrence->isDirty('obligation_id')) {
                throw new ImmutableRecordException('Recurring occurrence obligation cannot be modified.');
            }

            if ($occurrence->isDirty('currency_code')) {
                throw new ImmutableRecordException('Recurring occurrence currency cannot be modified.');
            }

            if ($occurrence->isDirty('due_date')) {
                throw new ImmutableRecordException('Recurring occurrence due date cannot be modified.');
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
     * @return BelongsTo<RecurringObligation, $this>
     */
    public function obligation(): BelongsTo
    {
        return $this->belongsTo(RecurringObligation::class, 'obligation_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * @return HasMany<RecurringPaymentLink, $this>
     */
    public function paymentLinks(): HasMany
    {
        return $this->hasMany(RecurringPaymentLink::class, 'occurrence_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', RecurringOccurrenceStatus::Scheduled->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSkipped(Builder $query): Builder
    {
        return $query->where('status', RecurringOccurrenceStatus::Skipped->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', RecurringOccurrenceStatus::Cancelled->value);
    }

    /**
     * Occurrences that currently occupy a schedule slot (scheduled or skipped).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOccupyingSlot(Builder $query): Builder
    {
        return $query->whereNotNull('active_slot_key');
    }
}
