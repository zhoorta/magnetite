<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Track;
use App\Models\User;

/**
 * Puts a file into an album and takes it out again. The file moves on disk (into the album's folder, or out to the folder
 * that holds it) and the row follows; nothing is deleted or overwritten. The track is marked `identified`, so a rescan
 * keeps the owner's choice instead of reading the tags again. Works on a local folder and on a Ferrite library alike
 * (Ferrite moves the file when its token may write).
 */
class AlbumMembership
{
    public function add(User $user, Album $album, Track $track): Track
    {
        abort_unless($album->user_id === $user->id && $track->user_id === $user->id, 404);

        if ($track->album_id === $album->id) {
            return $track;
        }

        // The folder most of the album's files are in.
        $dirs = $album->tracks()->pluck('path')->map(fn ($p) => $this->dir($p))->countBy()->sortDesc();
        $target = $dirs->keys()->first();

        if ($target !== null && $target !== $this->dir($track->path)) {
            $this->move($user, $track, $target);
        }

        $track->update(['album_id' => $album->id, 'identified' => true]);

        return $track->refresh();
    }

    /** The file leaves the album and goes to the folder that holds its own folder, or stays at the top of the library. */
    public function remove(User $user, Album $album, Track $track): Track
    {
        abort_unless($album->user_id === $user->id && $track->user_id === $user->id && $track->album_id === $album->id, 404);

        $dir = $this->dir($track->path);
        if ($dir !== '') {
            $this->move($user, $track, $this->dir($dir));
        }

        $track->update(['album_id' => null, 'identified' => true]);
        $user->albums()->doesntHave('tracks')->delete();

        return $track->refresh();
    }

    /** Move the file to `$dir` (relative to the library, '' for the top) in whichever store holds it, and update the row's path. */
    private function move(User $user, Track $track, string $dir): void
    {
        $track->update(['path' => $user->store()->move($track->path, $dir)]);
    }

    /** The folder part of a stored path, '' for the top of the library. */
    private function dir(string $path): string
    {
        $dir = dirname($path);

        return $dir === '.' ? '' : $dir;
    }
}
