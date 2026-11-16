<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\Track;
use App\Models\User;
use App\Services\FerriteStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Administrators only: who has an account and how much audio each library holds. */
class AdminUsersController extends Controller
{
    /** Set a user's limit in GB (an empty value removes it). */
    public function quota(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['quota_gb' => ['nullable', 'numeric', 'min:0', 'max:1000000']]);

        $user->forceFill(['quota_mb' => ($data['quota_gb'] ?? null) === null ? null : (int) round((float) $data['quota_gb'] * 1024)])->save();

        return back()->with('status', "Limit of {$user->username} saved.");
    }

    /** Make a user an administrator, or take it away. Never your own, so there is always at least one. */
    public function role(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 403, 'You cannot change your own role.');

        $data = $request->validate(['is_admin' => ['required', 'boolean']]);
        $user->forceFill(['is_admin' => (bool) $data['is_admin']])->save();

        return back()->with('status', $user->is_admin ? "{$user->username} is an administrator now." : "{$user->username} is no longer an administrator.");
    }

    /**
     * Delete an account and what hangs on it in the database (library entries, playlists, shares, tokens, sessions).
     * The music files stay where they are: nothing here touches the disk or a Ferrite store.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 403, 'You cannot delete yourself.');

        $request->validate(['confirm' => ['required', 'in:'.$user->username]], ['confirm.in' => 'Type the username to confirm.']);

        $folder = $user->store() instanceof FerriteStore ? null : $user->libraryPath();
        $name = $user->username;

        $user->revokeAccess();
        $user->delete();

        return back()->with('status', "{$name} was deleted.".($folder ? " Their music files are still in {$folder}." : ''));
    }

    public function index(): View
    {
        $users = User::query()
            ->withSum('tracks', 'size')
            ->orderBy('username')
            ->get();

        return view('pages.admin-users', [
            'users' => $users,
            'invitations' => Invitation::usable()->kind(false)->latest()->get(),
            'resets' => Invitation::usable()->kind(true)->with('user')->latest()->get(),
            'totalBytes' => (int) $users->sum('tracks_sum_size'),
            'totalTracks' => Track::count(),
        ]);
    }
}
