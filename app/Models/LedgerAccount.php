<?php

namespace App\Models;

use App\Enums\LedgerAccountClassification;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerAccount extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'financial_account_id',
        'code',
        'name',
        'classification',
        'currency_code',
        'is_system',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'classification' => LedgerAccountClassification::class,
            'is_system' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $ledgerAccount): void {
            if ($ledgerAccount->financial_account_id === null) {
                return;
            }

            $financialAccount = FinancialAccount::query()->find($ledgerAccount->financial_account_id);

            if ($financialAccount === null) {
                throw new LedgerIntegrityException('Linked financial account does not exist.');
            }

            if ((int) $financialAccount->user_id !== (int) $ledgerAccount->user_id) {
                throw new LedgerIntegrityException(
                    'Ledger account owner must match its linked financial account owner.',
                );
            }

            if ($financialAccount->currency_code !== $ledgerAccount->currency_code) {
                throw new LedgerIntegrityException(
                    'Ledger account currency must match its linked financial account currency.',
                );
            }

            $classification = $ledgerAccount->classification instanceof LedgerAccountClassification
                ? $ledgerAccount->classification
                : LedgerAccountClassification::tryFrom((string) $ledgerAccount->classification);

            if ($classification !== $financialAccount->type->ledgerClassification()) {
                throw new LedgerIntegrityException(
                    'Ledger account classification must match its linked financial account type.',
                );
            }
        });

        static::updating(function (self $ledgerAccount): void {
            if ($ledgerAccount->isDirty('user_id') || $ledgerAccount->isDirty('financial_account_id')) {
                throw new ImmutableRecordException('Ledger account ownership and mapping cannot be modified.');
            }

            if ($ledgerAccount->isDirty('currency_code') && $ledgerAccount->postings()->exists()) {
                throw new ImmutableRecordException(
                    'Ledger account currency cannot be changed once postings exist.',
                );
            }
        });

        static::deleting(function (): void {
            throw new LedgerIntegrityException('Ledger accounts cannot be deleted.');
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
     * @return BelongsTo<FinancialAccount, $this>
     */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * @return HasMany<JournalPosting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(JournalPosting::class, 'ledger_account_id');
    }
}
