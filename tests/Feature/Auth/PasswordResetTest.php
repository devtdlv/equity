<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

test('the forgot password page can be rendered for guests', function (): void {
    $response = $this->get(route('password.request'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Auth/ForgotPassword')
    );
});

test('a password reset link can be requested without disclosing whether the email exists', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'member@example.com',
    ]);

    $expectedStatus = 'If an account matches that email address, we have sent a password reset link.';

    $existingResponse = $this->from('/forgot-password')->post(route('password.email'), [
        'email' => $user->email,
    ]);

    $existingResponse->assertRedirect('/forgot-password');
    $existingResponse->assertSessionHas('status', $expectedStatus);
    Notification::assertSentTo($user, ResetPassword::class);

    $unknownResponse = $this->from('/forgot-password')->post(route('password.email'), [
        'email' => 'unregistered@example.com',
    ]);

    $unknownResponse->assertRedirect('/forgot-password');
    $unknownResponse->assertSessionHas('status', $expectedStatus);
});

test('the password reset form can be rendered with token and email', function (): void {
    $response = $this->get(route('password.reset', [
        'token' => 'sample-reset-token',
        'email' => 'member@example.com',
    ]));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Auth/ResetPassword')
        ->where('token', 'sample-reset-token')
        ->where('email', 'member@example.com')
    );
});

test('a password can be reset with a valid token and the token is invalidated afterward', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'member@example.com',
        'password' => 'OldPassword!123',
    ]);

    $token = Password::broker()->createToken($user);

    $response = $this->post(route('password.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewStrongPass!456',
        'password_confirmation' => 'NewStrongPass!456',
    ]);

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status', trans(Password::PASSWORD_RESET));

    $user->refresh();
    expect(Hash::check('NewStrongPass!456', $user->password))->toBeTrue();

    $reuseResponse = $this->from("/reset-password/{$token}")->post(route('password.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'AnotherPass!789',
        'password_confirmation' => 'AnotherPass!789',
    ]);

    $reuseResponse->assertRedirect("/reset-password/{$token}");
    $reuseResponse->assertSessionHasErrors([
        'email' => 'This password reset link is invalid or has expired.',
    ]);
});

test('password reset fails with an invalid token', function (): void {
    $user = User::factory()->create([
        'email' => 'member@example.com',
        'password' => 'OldPassword!123',
    ]);

    $response = $this->from('/reset-password/invalid-token')->post(route('password.store'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'NewStrongPass!456',
        'password_confirmation' => 'NewStrongPass!456',
    ]);

    $response->assertRedirect('/reset-password/invalid-token');
    $response->assertSessionHasErrors([
        'email' => 'This password reset link is invalid or has expired.',
    ]);

    $user->refresh();
    expect(Hash::check('OldPassword!123', $user->password))->toBeTrue();
});
