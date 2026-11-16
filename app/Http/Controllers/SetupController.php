<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\UserRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** First run: while there is no user at all, whoever finishes this form becomes the administrator. */
class SetupController extends Controller
{
    public function create(): View
    {
        User::query()->exists() && abort(404);

        return view('pages.setup', ['tokenNeeded' => $this->token() !== '']);
    }

    public function store(Request $request): RedirectResponse
    {
        User::query()->exists() && abort(404);

        $data = $request->validate([
            'username' => UserRules::username(),
            'password' => [...UserRules::password(), 'confirmed'],
            'setup_token' => ['nullable', 'string'],
        ]);

        $token = $this->token();

        if ($token !== '' && ! hash_equals($token, (string) ($data['setup_token'] ?? ''))) {
            throw ValidationException::withMessages(['setup_token' => 'Wrong set-up token.']);
        }

        // Two requests at the same moment must not both become administrator.
        $user = Cache::lock('magnetite-setup', 10)->block(5, fn () => DB::transaction(function () use ($data) {
            if (User::query()->exists()) {
                return null;
            }

            $username = UserRules::normalise($data['username']);
            $user = new User(['name' => $username, 'username' => $username, 'password' => $data['password']]);
            $user->is_admin = true;
            $user->save();

            return $user;
        }));

        $user ?? abort(404);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('library');
    }

    private function token(): string
    {
        return (string) config('magnetite.setup_token', '');
    }
}
