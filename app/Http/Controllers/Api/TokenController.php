<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class TokenController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    /** Sign in from the app: same username rules and throttle as the web login, answered with a personal access token. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            'read_only' => ['sometimes', 'boolean'],
        ]);

        $username = mb_strtolower(trim($data['username']));
        $throttle = $username.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttle, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'username' => 'Too many attempts. Try again in '.RateLimiter::availableIn($throttle).' seconds.',
            ]);
        }

        $user = User::where('username', $username)->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($throttle);

            throw ValidationException::withMessages(['username' => 'Wrong username or password.']);
        }

        RateLimiter::clear($throttle);

        return response()->json([
            'token' => $user->createToken($data['device_name'], $request->boolean('read_only') ? ['library:read'] : ['*'])->plainTextToken,
            'user' => ['id' => $user->id, 'username' => $user->username, 'meters' => $user->meters ?? ['vu-wide']],
        ], 201);
    }

    /** Sign out: revoke only the token that made this request. */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }
}
