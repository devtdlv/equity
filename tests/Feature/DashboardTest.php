<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

test('guests cannot access the dashboard and are redirected to login', function (): void {
    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can access the dashboard and only minimal user props are shared', function (): void {
    $user = User::factory()->create([
        'name' => 'Morgan Lee',
        'email' => 'morgan@example.com',
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Dashboard')
        ->where('auth.user.id', $user->id)
        ->where('auth.user.name', 'Morgan Lee')
        ->where('auth.user.email', 'morgan@example.com')
        ->missing('auth.user.password')
        ->missing('auth.user.remember_token')
    );
});

test('the public welcome page remains accessible to both guests and authenticated users', function (): void {
    $this->get(route('welcome'))->assertOk();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('welcome'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Welcome')
            ->where('auth.user.id', $user->id)
        );
});
