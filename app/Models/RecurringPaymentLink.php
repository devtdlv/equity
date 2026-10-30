<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringPaymentLink extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'occurrence_id',
        'financial_transaction_id',
        'currency_code',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $link): void {
            if ($link->isDirty([
                'user_id',
                'occurrence_id',
                'financial_transaction_id',
                'currency_code',
            ])) {
                throw new ImmutableRecordException(
                    'Recurring payment links are immutable. Unlink and create a new association instead.',
                );
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
     * @return BelongsTo<RecurringOccurrence, $this>
     */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(RecurringOccurrence::class, 'occurrence_id');
    }

    /**
     * @return BelongsTo<FinancialTransaction, $this>
     */
    public function financialTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }
}
