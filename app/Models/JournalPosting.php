<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalPosting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'journal_entry_id',
        'ledger_account_id',
        'debit_amount_minor',
        'credit_amount_minor',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit_amount_minor' => 'integer',
            'credit_amount_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $posting): void {
            $debit = (int) $posting->debit_amount_minor;
            $credit = (int) $posting->credit_amount_minor;

            if ($debit < 0 || $credit < 0) {
                throw new LedgerIntegrityException('Posting debit and credit amounts must be non-negative integers.');
            }

            if (($debit === 0 && $credit === 0) || ($debit > 0 && $credit > 0)) {
                throw new LedgerIntegrityException(
                    'Each posting must have a strictly positive integer amount on exactly one side (debit or credit).',
                );
            }

            if ($debit > Money::MAX_MINOR_AMOUNT || $credit > Money::MAX_MINOR_AMOUNT) {
                throw new LedgerIntegrityException('Posting amount exceeds safe 64-bit integer bounds.');
            }
        });

        static::updating(function (): void {
            throw new ImmutableRecordException('Posted journal postings are immutable.');
        });

        static::deleting(function (): void {
            throw new ImmutableRecordException('Posted journal postings cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }
}
