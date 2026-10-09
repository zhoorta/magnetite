<?php

namespace App\Policies;

use App\Models\Playlist;
use App\Models\User;

/** Playlists are private until the owner shares one; then everyone with an account may see it, and only the owner changes it. */
class PlaylistPolicy
{
    public function view(User $user, Playlist $playlist): bool
    {
        return $playlist->user_id === $user->id || $playlist->share()->exists();
    }

    public function share(User $user, Playlist $playlist): bool
    {
        return $playlist->user_id === $user->id;
    }

    public function manage(User $user, Playlist $playlist): bool
    {
        return $playlist->user_id === $user->id;
    }
}
