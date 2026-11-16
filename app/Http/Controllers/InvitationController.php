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
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InvitationController extends Controller
{
    /** Administrators: make an invitation. The link is shown once, on the Users page. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'days' => ['required', 'integer', 'in:1,7,30'],
        ]);

        [, $token] = Invitation::issue($request->user(), $data['label'] ?? null, (int) $data['days']);

        return redirect()->route('admin.users')->with('invite_link', route('invite.show', $token));
    }

    public function destroy(Invitation $invitation): RedirectResponse
    {
        $invitation->delete();

        return redirect()->route('admin.users')->with('status', 'Invitation withdrawn.');
    }

    /** Guests: the form behind an invitation link. Anything wrong with the link is a plain 404. */
    public function show(string $token): View
    {
        $invitation = Invitation::findUsable($token) ?? abort(404);

        return view('pages.invite', ['token' => $token, 'invitation' => $invitation]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        Invitation::findUsable($token) ?? abort(404);

        $request->merge(['username' => UserRules::normalise((string) $request->input('username'))]);
        $data = $request->validate([
            'username' => [...UserRules::username(), Rule::unique('users', 'username')],
            'password' => [...UserRules::password(), 'confirmed'],
        ], ['username.unique' => 'That username is taken.']);

        // One invitation, one account, even when the link is opened twice at the same moment.
        $user = Cache::lock('magnetite-invite', 10)->block(5, fn () => DB::transaction(function () use ($token, $data) {
            $invitation = Invitation::usable()->kind(false)->where('token_hash', Invitation::hash($token))->first();

            if (! $invitation) {
                return null;
            }

            $user = new User(['name' => $data['username'], 'username' => $data['username'], 'password' => $data['password']]);
            $user->save();
            $invitation->forceFill(['used_at' => now(), 'used_by' => $user->id])->save();

            return $user;
        }));

        $user ?? abort(404);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('library');
    }
}
