<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    /**
     * Reference ISO 4217 currencies supported by Equity.
     *
     * @return list<array{code: string, name: string, symbol: string, minor_unit_exponent: int, is_active: bool}>
     */
    public static function referenceCurrencies(): array
    {
        return [
            [
                'code' => 'PHP',
                'name' => 'Philippine Peso',
                'symbol' => '₱',
                'minor_unit_exponent' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'USD',
                'name' => 'US Dollar',
                'symbol' => '$',
                'minor_unit_exponent' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'AUD',
                'name' => 'Australian Dollar',
                'symbol' => 'A$',
                'minor_unit_exponent' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'minor_unit_exponent' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'GBP',
                'name' => 'British Pound',
                'symbol' => '£',
                'minor_unit_exponent' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'JPY',
                'name' => 'Japanese Yen',
                'symbol' => '¥',
                'minor_unit_exponent' => 0,
                'is_active' => true,
            ],
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::referenceCurrencies() as $currency) {
            Currency::query()->updateOrCreate(
                ['code' => $currency['code']],
                $currency,
            );
        }
    }
}
