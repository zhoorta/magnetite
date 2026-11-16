<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\View\View;

/** Administrators only: who has an account and how much audio each library holds. */
class AdminUsersController extends Controller
{
    public function index(): View
    {
        $users = User::query()
            ->withCount('tracks')
            ->withSum('tracks', 'size')
            ->orderBy('username')
            ->get();

        return view('pages.admin-users', [
            'users' => $users,
            'totalBytes' => (int) $users->sum('tracks_sum_size'),
            'totalTracks' => (int) $users->sum('tracks_count'),
        ]);
    }
}
