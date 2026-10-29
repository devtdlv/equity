<?php

namespace App\Models;

use App\Enums\FinancialTransactionType;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FinancialTransaction extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'journal_entry_id',
        'reversed_transaction_id',
        'financial_account_id',
        'transfer_financial_account_id',
        'category_id',
        'type',
        'currency_code',
        'amount_minor',
        'entry_date',
        'description',
        'notes',
        'reversal_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FinancialTransactionType::class,
            'amount_minor' => 'integer',
            'entry_date' => 'date:Y-m-d',
            'reversed_transaction_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $transaction): void {
            $amount = (int) $transaction->amount_minor;

            if ($amount <= 0 || $amount > Money::MAX_MINOR_AMOUNT) {
                throw new LedgerIntegrityException(
                    'Financial transaction amount must be a positive integer within supported bounds.',
                );
            }

            $type = $transaction->type instanceof FinancialTransactionType
                ? $transaction->type
                : FinancialTransactionType::tryFrom((string) $transaction->type);

            if ($type === null) {
                throw new LedgerIntegrityException('Invalid financial transaction type.');
            }

            if ($type === FinancialTransactionType::Transfer) {
                if ($transaction->category_id !== null) {
                    throw new LedgerIntegrityException('Transfer transactions cannot be assigned a category.');
                }

                if ($transaction->transfer_financial_account_id === null) {
                    throw new LedgerIntegrityException('Transfer transactions require a destination financial account.');
                }

                if ((int) $transaction->transfer_financial_account_id === (int) $transaction->financial_account_id) {
                    throw new LedgerIntegrityException(
                        'Transfer source and destination financial accounts must be distinct.',
                    );
                }
            } else {
                if ($transaction->category_id === null) {
                    throw new LedgerIntegrityException(
                        'Income and expense transactions require a category.',
                    );
                }

                if ($transaction->transfer_financial_account_id !== null) {
                    throw new LedgerIntegrityException(
                        'Income and expense transactions cannot specify a transfer destination account.',
                    );
                }
            }

            if ($transaction->reversed_transaction_id === null && $transaction->reversal_reason !== null) {
                throw new LedgerIntegrityException(
                    'Non-reversal transactions cannot specify a reversal reason.',
                );
            }

            if (
                $transaction->reversed_transaction_id !== null
                && $transaction->id !== null
                && (int) $transaction->reversed_transaction_id === (int) $transaction->id
            ) {
                throw new LedgerIntegrityException(
                    'A financial transaction cannot reverse itself.',
                );
            }
        });

        static::updating(function (): void {
            throw new ImmutableRecordException(
                'Posted financial transactions are immutable. Use a compensating transaction for corrections.',
            );
        });

        static::deleting(function (): void {
            throw new ImmutableRecordException(
                'Posted financial transactions cannot be deleted.',
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
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<FinancialAccount, $this>
     */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    /**
     * @return BelongsTo<FinancialAccount, $this>
     */
    public function transferFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'transfer_financial_account_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * The original transaction that this reversal transaction reversed.
     *
     * @return BelongsTo<self, $this>
     */
    public function reversedTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_transaction_id');
    }

    /**
     * The reversal transaction that reversed this original transaction, if any.
     *
     * @return HasOne<self, $this>
     */
    public function reversalTransaction(): HasOne
    {
        return $this->hasOne(self::class, 'reversed_transaction_id');
    }

    /**
     * Indicates whether this record is itself a reversal correction of an earlier transaction.
     */
    public function isReversal(): bool
    {
        return $this->reversed_transaction_id !== null;
    }

    /**
     * Indicates whether this original transaction has been reversed by a reversal transaction.
     */
    public function isReversed(): bool
    {
        if ($this->isReversal()) {
            return false;
        }

        if ($this->relationLoaded('reversalTransaction')) {
            return $this->reversalTransaction !== null;
        }

        return $this->reversalTransaction()->exists();
    }

    /**
     * Scope to ordinary (non-reversal) posted transactions.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNonReversal(Builder $query): Builder
    {
        return $query->whereNull('reversed_transaction_id');
    }

    /**
     * Scope to reversal correction transactions only.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOnlyReversals(Builder $query): Builder
    {
        return $query->whereNotNull('reversed_transaction_id');
    }

    /**
     * Scope to active (effective) ordinary transactions that are neither reversals nor reversed.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('reversed_transaction_id')
            ->whereDoesntHave('reversalTransaction');
    }
}
