<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

test('the login page can be rendered for guests', function (): void {
    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Auth/Login')
        ->where('auth.user', null)
    );
});

test('users can authenticate using the login screen and have their session regenerated', function (): void {
    $user = User::factory()->create([
        'email' => 'alex@example.com',
        'password' => 'CorrectHorse!99',
    ]);

    $initialSessionId = session()->getId();

    $response = $this->post('/login', [
        'email' => 'ALEX@example.com',
        'password' => 'CorrectHorse!99',
        'remember' => true,
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect('/dashboard');
    expect(session()->getId())->not->toBe($initialSessionId);
});

test('users cannot authenticate with an invalid password and receive a generic error message', function (): void {
    $user = User::factory()->create([
        'email' => 'alex@example.com',
        'password' => 'CorrectHorse!99',
    ]);

    $responseWithExistingEmail = $this->from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'WrongPassword!12',
    ]);

    $responseWithExistingEmail->assertRedirect('/login');
    $responseWithExistingEmail->assertSessionHasErrors([
        'email' => trans('auth.failed'),
    ]);
    $this->assertGuest();

    $responseWithUnknownEmail = $this->from('/login')->post('/login', [
        'email' => 'nonexistent@example.com',
        'password' => 'WrongPassword!12',
    ]);

    $responseWithUnknownEmail->assertRedirect('/login');
    $responseWithUnknownEmail->assertSessionHasErrors([
        'email' => trans('auth.failed'),
    ]);
    $this->assertGuest();
});

test('login attempts are rate limited after five failed attempts', function (): void {
    $user = User::factory()->create([
        'email' => 'throttled@example.com',
        'password' => 'CorrectHorse!99',
    ]);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'InvalidPassword!1',
        ]);
    }

    $response = $this->from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'InvalidPassword!1',
    ]);

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('email');

    $errorMessage = session('errors')?->first('email');
    expect($errorMessage)->toContain('Too many login attempts');
    $this->assertGuest();
});

test('login redirects to internal intended urls and blocks external open redirects', function (): void {
    $user = User::factory()->create([
        'email' => 'redirect@example.com',
        'password' => 'CorrectHorse!99',
    ]);

    $this->withSession(['url.intended' => 'https://malicious.example.org/phish'])
        ->post('/login', [
            'email' => $user->email,
            'password' => 'CorrectHorse!99',
        ])
        ->assertRedirect('/dashboard');

    auth()->logout();

    $this->withSession(['url.intended' => '//malicious.example.org/phish'])
        ->post('/login', [
            'email' => $user->email,
            'password' => 'CorrectHorse!99',
        ])
        ->assertRedirect('/dashboard');

    auth()->logout();

    $this->withSession(['url.intended' => '/dashboard?welcome=1'])
        ->post('/login', [
            'email' => $user->email,
            'password' => 'CorrectHorse!99',
        ])
        ->assertRedirect('/dashboard?welcome=1');
});

test('authenticated users can log out via post, invalidating the session and regenerating the csrf token', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);
    $initialSessionId = session()->getId();
    $initialToken = session()->token();

    $response = $this->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect(route('welcome'));
    expect(session()->getId())->not->toBe($initialSessionId)
        ->and(session()->token())->not->toBe($initialToken);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('get requests to logout are not allowed', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/logout')
        ->assertMethodNotAllowed();
});

test('authenticated users are redirected to the dashboard when visiting the login route', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));
});
