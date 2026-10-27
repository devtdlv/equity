<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'symbol',
        'minor_unit_exponent',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minor_unit_exponent' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<FinancialAccount, $this>
     */
    public function financialAccounts(): HasMany
    {
        return $this->hasMany(FinancialAccount::class, 'currency_code', 'code');
    }

    /**
     * @return HasMany<LedgerAccount, $this>
     */
    public function ledgerAccounts(): HasMany
    {
        return $this->hasMany(LedgerAccount::class, 'currency_code', 'code');
    }
}
