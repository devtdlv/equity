<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    private const GENERIC_RESET_LINK_MESSAGE = 'If an account matches that email address, we have sent a password reset link.';

    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    /**
     * Handle an incoming password reset link request.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->has('email') && is_string($request->input('email'))) {
            $request->merge([
                'email' => Str::lower(trim($request->input('email'))),
            ]);
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        Password::broker()->sendResetLink([
            'email' => $validated['email'],
        ]);

        return back()->with('status', self::GENERIC_RESET_LINK_MESSAGE);
    }
}
