<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdatePasswordRequest;
use App\Http\Requests\Settings\UpdateProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /**
     * Display the authenticated settings page.
     */
    public function edit(): Response
    {
        $user = Auth::user();

        return Inertia::render('Settings/Index', [
            'profile' => [
                'name' => $user?->name,
                'email' => $user?->email,
            ],
            'emailVerificationNote' => 'Email addresses are not verified in this MVP. Changing your email updates sign-in immediately and does not send a confirmation message.',
        ]);
    }

    /**
     * Update the authenticated user's name and email.
     */
    public function updateProfile(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $emailChanging = $request->emailIsChanging($user);

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        if ($emailChanging && $user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return redirect()
            ->route('settings.edit')
            ->with('status', 'Profile updated.');
    }

    /**
     * Update the authenticated user's password.
     */
    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $user->update([
            'password' => $validated['password'],
        ]);

        Auth::logoutOtherDevices($validated['password']);

        $request->session()->regenerate();

        return redirect()
            ->route('settings.edit')
            ->with('status', 'Password updated.');
    }
}
