<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

test('the registration page can be rendered for guests', function (): void {
    $response = $this->get(route('register'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Auth/Register')
        ->where('auth.user', null)
    );
});

test('new users can register, have their password hashed, and have their session regenerated', function (): void {
    $initialSessionId = session()->getId();

    $response = $this->post('/register', [
        'name' => ' Jordan Taylor ',
        'email' => 'Jordan.Taylor@Example.com',
        'password' => 'CorrectHorse!99',
        'password_confirmation' => 'CorrectHorse!99',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard'));
    expect(session()->getId())->not->toBe($initialSessionId);

    $user = User::where('email', 'jordan.taylor@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user?->name)->toBe('Jordan Taylor')
        ->and($user?->password)->not->toBe('CorrectHorse!99')
        ->and(Hash::check('CorrectHorse!99', (string) $user?->password))->toBeTrue();
});

test('registration rejects duplicate email addresses with a safe error message', function (): void {
    User::factory()->create([
        'email' => 'existing@example.com',
    ]);

    $response = $this->from('/register')->post('/register', [
        'name' => 'Another User',
        'email' => 'EXISTING@example.com',
        'password' => 'CorrectHorse!99',
        'password_confirmation' => 'CorrectHorse!99',
    ]);

    $response->assertRedirect('/register');
    $response->assertSessionHasErrors([
        'email' => 'Unable to complete registration with the provided details.',
    ]);
    $this->assertGuest();
    expect(User::where('email', 'existing@example.com')->count())->toBe(1);
});

test('registration enforces strong password requirements and confirmation', function (string $password, string $confirmation): void {
    $response = $this->from('/register')->post('/register', [
        'name' => 'Jordan Taylor',
        'email' => 'jordan@example.com',
        'password' => $password,
        'password_confirmation' => $confirmation,
    ]);

    $response->assertRedirect('/register');
    $response->assertSessionHasErrors('password');
    $this->assertGuest();
})->with([
    'too short' => ['Short1!a', 'Short1!a'],
    'missing uppercase' => ['alllowercase123!', 'alllowercase123!'],
    'missing lowercase' => ['ALLUPPERCASE123!', 'ALLUPPERCASE123!'],
    'missing numbers' => ['NoNumbersHere!@#', 'NoNumbersHere!@#'],
    'missing symbols' => ['NoSymbolsHere1234', 'NoSymbolsHere1234'],
    'mismatched confirmation' => ['CorrectHorse!99', 'DifferentHorse!99'],
]);

test('registration requires name and a valid email address', function (): void {
    $response = $this->from('/register')->post('/register', [
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'CorrectHorse!99',
        'password_confirmation' => 'CorrectHorse!99',
    ]);

    $response->assertRedirect('/register');
    $response->assertSessionHasErrors(['name', 'email']);
    $this->assertGuest();
});

test('authenticated users are redirected away from the registration routes', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('register'))
        ->assertRedirect(route('dashboard'));

    $this->actingAs($user)
        ->post('/register', [
            'name' => 'Second Account',
            'email' => 'second@example.com',
            'password' => 'CorrectHorse!99',
            'password_confirmation' => 'CorrectHorse!99',
        ])
        ->assertRedirect(route('dashboard'));
});
