<?php

namespace App\Policies;

use App\Models\Track;
use App\Models\User;

/** Mirrors Track::visibleTo(): the owner, plus anyone when the track or its album is in the shared area. */
class TrackPolicy
{
    public function view(User $user, Track $track): bool
    {
        return $track->user_id === $user->id
            || $track->share()->exists()
            || ($track->album?->share()->exists() ?? false);
    }

    /** Only the owner removes a track from the library. */
    public function delete(User $user, Track $track): bool
    {
        return $track->user_id === $user->id;
    }

    /** Only the owner corrects a track's details. */
    public function update(User $user, Track $track): bool
    {
        return $track->user_id === $user->id;
    }

    /** Only the owner changes what is shared. */
    public function share(User $user, Track $track): bool
    {
        return $track->user_id === $user->id;
    }
}
