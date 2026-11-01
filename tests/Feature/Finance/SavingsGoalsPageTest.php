<?php

use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
});

test('guests are redirected to login when visiting savings index', function (): void {
    $this->get(route('savings.index'))->assertRedirect(route('login'));
});

test('unauthenticated json requests to savings index are unauthorized', function (): void {
    $this->getJson(route('savings.index'))->assertUnauthorized();
});

test('authenticated users receive the Savings Goals Inertia page with currencies', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('savings.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('SavingsGoals/Index')
            ->has('currencies')
            ->where('currencies.0.code', fn ($code) => is_string($code) && strlen($code) === 3)
        );
});

test('savings-goals json list endpoint remains distinct from the inertia shell', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('savings-goals.index'))
        ->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
            'filters' => ['status'],
        ]);

    $this->actingAs($user)
        ->get(route('savings.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('SavingsGoals/Index'));
});
