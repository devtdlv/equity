<?php

use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
});

test('guests are redirected to login when visiting bills index', function (): void {
    $this->get(route('bills.index'))->assertRedirect(route('login'));
});

test('unauthenticated json requests to bills index are unauthorized', function (): void {
    $this->getJson(route('bills.index'))->assertUnauthorized();
});

test('authenticated users receive the Bills Index Inertia page with reference props', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('bills.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Bills/Index')
            ->has('categories')
            ->has('accounts')
            ->has('currencies')
            ->has('initial_year')
            ->has('initial_month')
            ->has('today')
            ->where('initial_year', now('UTC')->year)
            ->where('initial_month', now('UTC')->month)
            ->where('today', now('UTC')->toDateString())
        );
});
