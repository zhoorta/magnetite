<?php

namespace App\Policies;

use App\Models\Album;
use App\Models\User;

/** Mirrors Album::visibleTo(): a cover may be seen by whoever may play something from the album. */
class AlbumPolicy
{
    public function view(User $user, Album $album): bool
    {
        return $album->user_id === $user->id
            || $album->share()->exists()
            || $album->tracks()->whereHas('share')->exists();
    }

    public function share(User $user, Album $album): bool
    {
        return $album->user_id === $user->id;
    }

    public function update(User $user, Album $album): bool
    {
        return $album->user_id === $user->id;
    }
}
