<?php

namespace App\Models;

use App\Enums\JournalEntryStatus;
use App\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class JournalEntry extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'currency_code',
        'idempotency_key',
        'request_fingerprint',
        'entry_date',
        'description',
        'external_reference',
        'status',
        'posted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_date' => 'date:Y-m-d',
            'status' => JournalEntryStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new ImmutableRecordException(
                'Posted journal entries are immutable. Use a compensating journal entry for corrections.',
            );
        });

        static::deleting(function (): void {
            throw new ImmutableRecordException(
                'Posted journal entries cannot be deleted.',
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
     * @return HasMany<JournalPosting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(JournalPosting::class, 'journal_entry_id');
    }

    /**
     * @return HasOne<FinancialTransaction, $this>
     */
    public function financialTransaction(): HasOne
    {
        return $this->hasOne(FinancialTransaction::class, 'journal_entry_id');
    }
}
