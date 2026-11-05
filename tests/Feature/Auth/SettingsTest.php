<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

test('guests cannot open settings', function (): void {
    $this->get(route('settings.edit'))
        ->assertRedirect(route('login'));

    $this->patch(route('settings.profile.update'), [
        'name' => 'Guest',
        'email' => 'guest@example.com',
    ])->assertRedirect(route('login'));

    $this->put(route('settings.password.update'), [
        'current_password' => 'CorrectHorse!99',
        'password' => 'CorrectHorse!100',
        'password_confirmation' => 'CorrectHorse!100',
    ])->assertRedirect(route('login'));
});

test('authenticated users can view the settings page with their profile props', function (): void {
    $user = User::factory()->create([
        'name' => 'Morgan Lee',
        'email' => 'morgan@example.com',
    ]);

    $this->actingAs($user)
        ->get(route('settings.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Index')
            ->where('profile.name', 'Morgan Lee')
            ->where('profile.email', 'morgan@example.com')
            ->where('auth.user', [
                'id' => $user->id,
                'name' => 'Morgan Lee',
                'email' => 'morgan@example.com',
            ])
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token')
            ->has('emailVerificationNote')
        );
});

test('users can update their name without providing the current password', function (): void {
    $user = User::factory()->create([
        'name' => 'Morgan Lee',
        'email' => 'morgan@example.com',
        'password' => 'CorrectHorse!99',
    ]);

    $this->actingAs($user)
        ->from(route('settings.edit'))
        ->patch(route('settings.profile.update'), [
            'name' => ' Morgan Updated ',
            'email' => 'morgan@example.com',
        ])
        ->assertRedirect(route('settings.edit'))
        ->assertSessionHas('status', 'Profile updated.')
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->name)->toBe('Morgan Updated')
        ->and($user->email)->toBe('morgan@example.com');
});

test('changing email requires the current password', function (): void {
    $user = User::factory()->create([
        'email' => 'morgan@example.com',
        'password' => 'CorrectHorse!99',
    ]);

    $this->actingAs($user)
        ->from(route('settings.edit'))
        ->patch(route('settings.profile.update'), [
            'name' => $user->name,
            'email' => 'new.morgan@example.com',
        ])
        ->assertRedirect(route('settings.edit'))
        ->assertSessionHasErrors('current_password');

    expect($user->fresh()->email)->toBe('morgan@example.com');
});

test('users can change email with the correct current password', function (): void {
    $user = User::factory()->create([
        'name' => 'Morgan Lee',
        'email' => 'morgan@example.com',
        'password' => 'CorrectHorse!99',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->from(route('settings.edit'))
        ->patch(route('settings.profile.update'), [
            'name' => 'Morgan Lee',
            'email' => 'Morgan.New@Example.com',
            'current_password' => 'CorrectHorse!99',
        ])
        ->assertRedirect(route('settings.edit'))
        ->assertSessionHas('status', 'Profile updated.')
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->email)->toBe('morgan.new@example.com')
        ->and($user->email_verified_at)->toBeNull();
});

test('email changes reject an incorrect current password', function (): void {
    $user = User::factory()->create([
        'email' => 'morgan@example.com',
        'password' => 'CorrectHorse!99',
    ]);

    $this->actingAs($user)
        ->from(route('settings.edit'))
        ->patch(route('settings.profile.update'), [
            'name' => $user->name,
            'email' => 'other@example.com',
            'current_password' => 'WrongPassword!99',
        ])
        ->assertRedirect(route('settings.edit'))
        ->assertSessionHasErrors('current_password');

    expect($user->fresh()->email)->toBe('morgan@example.com');
});

test('profile updates reject duplicate emails with a safe error message', function (): void {
    User::factory()->create([
        'email' => 'taken@example.com',
    ]);

    $user = User::factory()->create([
        'email' => 'morgan@example.com',
        'password' => 'CorrectHorse!99',
    ]);

    $this->actingAs($user)
        ->from(route('settings.edit'))
        ->patch(route('settings.profile.update'), [
            'name' => $user->name,
            'email' => 'TAKEN@example.com',
            'current_password' => 'CorrectHorse!99',
        ])
        ->assertRedirect(route('settings.edit'))
        ->assertSessionHasErrors([
            'email' => 'Unable to update your profile with the provided email address.',
        ]);

    expect($user->fresh()->email)->toBe('morgan@example.com');
});

test('profile updates validate required fields and email format', function (): void {
    $user = User::factory()->create([
        'password' => 'CorrectHorse!99',
    ]);

    $this->actingAs($user)
        ->from(route('settings.edit'))
        ->patch(route('settings.profile.update'), [
            'name' => '',
            'email' => 'not-an-email',
        ])
        ->assertRedirect(route('settings.edit'))
        ->assertSessionHasErrors(['name', 'email']);
});

test('users can change their password with confirmation and session regeneration', function (): void {
    $user = User::factory()->create([
        'password' => 'CorrectHorse!99',
    ]);

    $this->actingAs($user);
    $initialSessionId = session()->getId();

    $this->from(route('settings.edit'))
        ->put(route('settings.password.update'), [
            'current_password' => 'CorrectHorse!99',
            'password' => 'CorrectHorse!100',
            'password_confirmation' => 'CorrectHorse!100',
        ])
        ->assertRedirect(route('settings.edit'))
        ->assertSessionHas('status', 'Password updated.')
        ->assertSessionHasNoErrors();

    expect(session()->getId())->not->toBe($initialSessionId)
        ->and(Hash::check('CorrectHorse!100', (string) $user->fresh()->password))->toBeTrue()
        ->and(Hash::check('CorrectHorse!99', (string) $user->fresh()->password))->toBeFalse();

    $this->assertAuthenticatedAs($user->fresh());
});

test('password change keeps the current session authenticated and invalidates stale password hashes', function (): void {
    $user = User::factory()->create([
        'email' => 'session@example.com',
        'password' => 'CorrectHorse!99',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();

    $stalePasswordHash = session('password_hash_web');
    expect($stalePasswordHash)->not->toBeNull();

    $this->from(route('settings.edit'))
        ->put(route('settings.password.update'), [
            'current_password' => 'CorrectHorse!99',
            'password' => 'CorrectHorse!100',
            'password_confirmation' => 'CorrectHorse!100',
        ])
        ->assertRedirect(route('settings.edit'))
        ->assertSessionHas('status', 'Password updated.');

    $this->assertAuthenticatedAs($user->fresh());

    $this->get(route('settings.edit'))
        ->assertOk();
    $this->assertAuthenticatedAs($user->fresh());

    // Simulate another device that still holds the pre-change session password hash.
    session(['password_hash_web' => $stalePasswordHash]);

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
    $this->assertGuest();
});

test('case-only email changes do not require the current password', function (): void {
    $user = User::factory()->create([
        'name' => 'Morgan Lee',
        'email' => 'morgan@example.com',
        'password' => 'CorrectHorse!99',
        'email_verified_at' => now(),
    ]);
    $verifiedAt = $user->email_verified_at?->toIso8601String();

    $this->actingAs($user)
        ->from(route('settings.edit'))
        ->patch(route('settings.profile.update'), [
            'name' => 'Morgan Lee',
            'email' => 'Morgan@Example.com',
        ])
        ->assertRedirect(route('settings.edit'))
        ->assertSessionHas('status', 'Profile updated.')
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->email)->toBe('morgan@example.com')
        ->and($user->email_verified_at?->toIso8601String())->toBe($verifiedAt);
});

test('whitespace-only email changes preserve email_verified_at', function (): void {
    $user = User::factory()->create([
        'name' => 'Morgan Lee',
        'email' => 'morgan@example.com',
        'password' => 'CorrectHorse!99',
        'email_verified_at' => now(),
    ]);
    $verifiedAt = $user->email_verified_at?->toIso8601String();

    $this->actingAs($user)
        ->from(route('settings.edit'))
        ->patch(route('settings.profile.update'), [
            'name' => 'Morgan Lee',
            'email' => '  morgan@example.com  ',
        ])
        ->assertRedirect(route('settings.edit'))
        ->assertSessionHas('status', 'Profile updated.')
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->email)->toBe('morgan@example.com')
        ->and($user->email_verified_at?->toIso8601String())->toBe($verifiedAt);
});

test('password changes reject an incorrect current password', function (): void {
    $user = User::factory()->create([
        'password' => 'CorrectHorse!99',
    ]);

    $this->actingAs($user)
        ->from(route('settings.edit'))
        ->put(route('settings.password.update'), [
            'current_password' => 'WrongPassword!99',
            'password' => 'CorrectHorse!100',
            'password_confirmation' => 'CorrectHorse!100',
        ])
        ->assertRedirect(route('settings.edit'))
        ->assertSessionHasErrors('current_password');

    expect(Hash::check('CorrectHorse!99', (string) $user->fresh()->password))->toBeTrue();
});

test('password changes enforce strong password requirements and confirmation', function (string $password, string $confirmation): void {
    $user = User::factory()->create([
        'password' => 'CorrectHorse!99',
    ]);

    $this->actingAs($user)
        ->from(route('settings.edit'))
        ->put(route('settings.password.update'), [
            'current_password' => 'CorrectHorse!99',
            'password' => $password,
            'password_confirmation' => $confirmation,
        ])
        ->assertRedirect(route('settings.edit'))
        ->assertSessionHasErrors('password');

    expect(Hash::check('CorrectHorse!99', (string) $user->fresh()->password))->toBeTrue();
})->with([
    'too short' => ['Short1!a', 'Short1!a'],
    'missing uppercase' => ['alllowercase123!', 'alllowercase123!'],
    'missing lowercase' => ['ALLUPPERCASE123!', 'ALLUPPERCASE123!'],
    'missing numbers' => ['NoNumbersHere!@#', 'NoNumbersHere!@#'],
    'missing symbols' => ['NoSymbolsHere1234', 'NoSymbolsHere1234'],
    'mismatched confirmation' => ['CorrectHorse!100', 'DifferentHorse!100'],
]);

test('settings profile and password updates are rate limited', function (): void {
    $user = User::factory()->create([
        'email' => 'morgan@example.com',
        'password' => 'CorrectHorse!99',
    ]);

    $limiterKey = $user->id.'|127.0.0.1';
    RateLimiter::clear($limiterKey);

    $this->actingAs($user);

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $this->patch(route('settings.profile.update'), [
            'name' => 'Morgan '.$attempt,
            'email' => 'morgan@example.com',
        ])->assertRedirect(route('settings.edit'));
    }

    $this->patch(route('settings.profile.update'), [
        'name' => 'Morgan Overflow',
        'email' => 'morgan@example.com',
    ])->assertStatus(429);

    RateLimiter::clear($limiterKey);

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $this->put(route('settings.password.update'), [
            'current_password' => 'CorrectHorse!99',
            'password' => 'CorrectHorse!100',
            'password_confirmation' => 'CorrectHorse!100',
        ]);
    }

    $this->put(route('settings.password.update'), [
        'current_password' => 'CorrectHorse!99',
        'password' => 'CorrectHorse!100',
        'password_confirmation' => 'CorrectHorse!100',
    ])->assertStatus(429);
});
