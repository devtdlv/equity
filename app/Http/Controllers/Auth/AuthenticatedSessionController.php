<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->to($this->resolveSafeIntendedUrl($request));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('welcome');
    }

    /**
     * Resolve the intended redirect destination while preventing open redirects.
     */
    private function resolveSafeIntendedUrl(Request $request): string
    {
        $defaultUrl = route('dashboard', absolute: false);
        $intended = $request->session()->pull('url.intended');

        if (! is_string($intended) || $intended === '') {
            return $defaultUrl;
        }

        if (str_starts_with($intended, '/') && ! str_starts_with($intended, '//') && ! str_starts_with($intended, '/\\')) {
            return $intended;
        }

        $parsedIntended = parse_url($intended);

        if ($parsedIntended === false || ! isset($parsedIntended['host'])) {
            return $defaultUrl;
        }

        $scheme = strtolower($parsedIntended['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            return $defaultUrl;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $requestHost = $request->getHost();
        $intendedHost = $parsedIntended['host'];

        if (strcasecmp($intendedHost, (string) $requestHost) !== 0 && strcasecmp($intendedHost, (string) $appHost) !== 0) {
            return $defaultUrl;
        }

        $path = $parsedIntended['path'] ?? '/';

        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return $defaultUrl;
        }

        $query = isset($parsedIntended['query']) ? '?'.$parsedIntended['query'] : '';
        $fragment = isset($parsedIntended['fragment']) ? '#'.$parsedIntended['fragment'] : '';

        return $path.$query.$fragment;
    }
}
