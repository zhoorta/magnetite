<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\User;
use App\Support\UserRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Changing a password without mail: your own (signed in), or through a one-time link an administrator makes. */
class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('pages.password');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => [...UserRules::password(), 'confirmed', 'different:current_password'],
        ], ['current_password.current_password' => 'The current password is wrong.']);

        $user = $request->user();
        $user->forceFill(['password' => $data['password']])->save();
        $user->revokeAccess($request->session()->getId());

        return back()->with('status', 'Password changed. Your other devices were signed out.');
    }

    /** Administrators: make a reset link for a user, shown once on the Users page. */
    public function link(Request $request, User $user): RedirectResponse
    {
        [, $token] = Invitation::issueReset($request->user(), $user);

        return redirect()->route('admin.users')->with('reset_link', ['user' => $user->username, 'url' => route('reset.show', $token)]);
    }

    public function show(string $token): View
    {
        $reset = Invitation::findUsable($token, true) ?? abort(404);

        return view('pages.reset', ['token' => $token, 'username' => $reset->user->username]);
    }

    public function reset(Request $request, string $token): RedirectResponse
    {
        Invitation::findUsable($token, true) ?? abort(404);

        $data = $request->validate(['password' => [...UserRules::password(), 'confirmed']]);

        $user = Cache::lock('magnetite-invite', 10)->block(5, fn () => DB::transaction(function () use ($token, $data) {
            $reset = Invitation::usable()->kind(true)->where('token_hash', Invitation::hash($token))->first();

            if (! $reset) {
                return null;
            }

            $user = $reset->user;
            $user->forceFill(['password' => $data['password']])->save();
            $reset->forceFill(['used_at' => now(), 'used_by' => $user->id])->save();
            // Any other pending link for this user dies with the first one that is used.
            Invitation::usable()->kind(true)->where('user_id', $user->id)->delete();

            return $user;
        }));

        $user ?? abort(404);

        $user->revokeAccess();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('library');
    }
}
