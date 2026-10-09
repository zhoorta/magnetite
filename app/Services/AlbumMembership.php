<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Track;
use App\Models\User;
use App\Support\TrackLibrary;
use RuntimeException;

/**
 * Puts a file into an album and takes it out again. The file moves on disk (into the album's folder, or out to the folder
 * that holds it) and the row follows; nothing is deleted or overwritten. The track is marked `identified`, so a rescan
 * keeps the owner's choice instead of reading the tags again. Only for a library in a local folder.
 */
class AlbumMembership
{
    public function add(User $user, Album $album, Track $track): Track
    {
        abort_unless($album->user_id === $user->id && $track->user_id === $user->id, 404);

        if ($track->album_id === $album->id) {
            return $track;
        }

        $this->assertLocal($user);

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

        $this->assertLocal($user);

        $dir = $this->dir($track->path);
        if ($dir !== '') {
            $this->move($user, $track, $this->dir($dir));
        }

        $track->update(['album_id' => null, 'identified' => true]);
        $user->albums()->doesntHave('tracks')->delete();

        return $track->refresh();
    }

    private function assertLocal(User $user): void
    {
        if (! $user->store() instanceof LocalStore) {
            throw new RuntimeException('Files can only be moved in a library kept in a local folder.');
        }
    }

    /** The folder part of a stored path, '' for the top of the library. */
    private function dir(string $path): string
    {
        $dir = dirname($path);

        return $dir === '.' ? '' : $dir;
    }

    /** Move the file to `$dir` (relative to the library, '' for the top) and update the row's path. Never overwrites. */
    private function move(User $user, Track $track, string $dir): void
    {
        $from = TrackLibrary::resolve($track->path, $user) ?? throw new RuntimeException('The file is missing.');
        $base = realpath(TrackLibrary::userPath($user));
        $to = realpath($dir === '' ? $base : $base.'/'.$dir);

        if ($base === false || $to === false || ($to !== $base && ! str_starts_with($to, $base.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('That folder is not in your library.');
        }

        $destination = $to.'/'.basename($from);
        if (file_exists($destination) || is_link($destination)) {
            abort(422, 'A file with that name is already in the folder.');
        }

        if (! @rename($from, $destination)) {
            throw new RuntimeException('The file could not be moved (is the music folder writable?).');
        }

        $track->update(['path' => ltrim(($dir === '' ? '' : $dir.'/').basename($from), '/')]);

        // The folder it left may be empty now; never remove the library folder itself.
        for ($d = dirname($from); $d !== $base && str_starts_with($d, $base.DIRECTORY_SEPARATOR); $d = dirname($d)) {
            if (! @rmdir($d)) {
                break;
            }
        }
    }
}
