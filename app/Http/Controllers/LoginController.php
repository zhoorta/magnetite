<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function create(): View
    {
        return view('pages.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $username = mb_strtolower(trim($credentials['username']));
        $throttle = $username.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttle, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'username' => 'Too many attempts. Try again in '.RateLimiter::availableIn($throttle).' seconds.',
            ]);
        }

        if (! Auth::attempt(['username' => $username, 'password' => $credentials['password']])) {
            RateLimiter::hit($throttle);

            throw ValidationException::withMessages(['username' => 'Wrong username or password.']);
        }

        RateLimiter::clear($throttle);
        $request->session()->regenerate();

        return redirect()->intended(route('library'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('welcome');
    }
}
