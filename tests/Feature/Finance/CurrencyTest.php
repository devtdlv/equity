<?php

use App\Exceptions\LedgerIntegrityException;
use App\Models\Currency;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
});

test('reference currencies are seeded with accurate ISO 4217 minor unit exponents', function (): void {
    $currencies = Currency::query()->orderBy('code')->get()->keyBy('code');

    expect($currencies)->toHaveCount(6)
        ->and($currencies['PHP']->minor_unit_exponent)->toBe(2)
        ->and($currencies['USD']->minor_unit_exponent)->toBe(2)
        ->and($currencies['AUD']->minor_unit_exponent)->toBe(2)
        ->and($currencies['EUR']->minor_unit_exponent)->toBe(2)
        ->and($currencies['GBP']->minor_unit_exponent)->toBe(2)
        ->and($currencies['JPY']->minor_unit_exponent)->toBe(0)
        ->and($currencies['PHP']->is_active)->toBeTrue();
});

test('money value object formats minor units without binary floating point and enforces currency and overflow rules', function (): void {
    $usd = new Money(123456, 'USD', 2);
    expect($usd->format('$'))->toBe('$1,234.56');

    $jpy = new Money(15800, 'JPY', 0);
    expect($jpy->format('¥'))->toBe('¥15,800');

    $negativePhp = new Money(-505, 'PHP', 2);
    expect($negativePhp->format('₱'))->toBe('-₱5.05');

    $sum = $usd->add(new Money(44, 'USD', 2));
    expect($sum->amountMinor)->toBe(123500)
        ->and($sum->format('$'))->toBe('$1,235.00');

    expect(fn () => $usd->add(new Money(100, 'EUR', 2)))
        ->toThrow(LedgerIntegrityException::class);

    expect(fn () => new Money(Money::MAX_MINOR_AMOUNT + 1, 'USD', 2))
        ->toThrow(LedgerIntegrityException::class);

    expect(fn () => Money::checkedAdd(Money::MAX_MINOR_AMOUNT, 1))
        ->toThrow(LedgerIntegrityException::class);
});

test('inactive currencies are rejected when creating a financial account', function (): void {
    Currency::query()->where('code', 'EUR')->update(['is_active' => false]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->from(route('accounts.index'))
        ->post(route('accounts.store'), [
            'name' => 'Euro Savings',
            'type' => 'savings',
            'currency_code' => 'EUR',
        ]);

    $response->assertRedirect(route('accounts.index'));
    $response->assertSessionHasErrors('currency_code');
    expect($user->financialAccounts()->count())->toBe(0);
});
