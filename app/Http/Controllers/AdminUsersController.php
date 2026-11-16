<?php

namespace App\Http\Controllers;

use App\Models\Track;
use App\Models\User;
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

    public function index(): View
    {
        $users = User::query()
            ->withSum('tracks', 'size')
            ->orderBy('username')
            ->get();

        return view('pages.admin-users', [
            'users' => $users,
            'totalBytes' => (int) $users->sum('tracks_sum_size'),
            'totalTracks' => Track::count(),
        ]);
    }
}
