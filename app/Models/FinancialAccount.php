<?php

namespace App\Models;

use App\Enums\FinancialAccountType;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FinancialAccount extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'type',
        'currency_code',
        'institution',
        'description',
        'is_archived',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FinancialAccountType::class,
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $account): void {
            if ($account->isDirty('user_id')) {
                throw new ImmutableRecordException('Financial account ownership cannot be modified.');
            }

            if ($account->isDirty('currency_code') && $account->hasPostedActivity()) {
                throw new ImmutableRecordException(
                    'Financial account currency cannot be changed once ledger activity exists.',
                );
            }
        });

        static::deleting(function (): void {
            throw new LedgerIntegrityException(
                'Financial accounts cannot be deleted. Archive the account instead.',
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
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * @return HasOne<LedgerAccount, $this>
     */
    public function ledgerAccount(): HasOne
    {
        return $this->hasOne(LedgerAccount::class, 'financial_account_id');
    }

    public function hasPostedActivity(): bool
    {
        return $this->ledgerAccount()
            ->whereHas('postings')
            ->exists();
    }
}
