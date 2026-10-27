<?php

namespace App\Models;

use App\Enums\FinancialTransactionType;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialTransaction extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'journal_entry_id',
        'financial_account_id',
        'transfer_financial_account_id',
        'category_id',
        'type',
        'currency_code',
        'amount_minor',
        'entry_date',
        'description',
        'notes',
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
}
