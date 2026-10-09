<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Removes tracks from a user's library: the file first (deleted from a local folder, moved to Ferrite's trash for a
 * remote library), then the database row, so a file that could not be removed stays listed. Shares and playlist
 * entries of a track go with it; an album left without tracks goes too. Cover images in the folder are left alone.
 */
class LibraryRemover
{
    public function __construct(private TranscodeCache $transcodes) {}

    /**
     * @param  Collection<int, Track>  $tracks  the user's own tracks
     * @return array{removed: int, failed: int, error: string|null}
     */
    public function remove(User $user, Collection $tracks): array
    {
        $store = $user->store();

        // A Ferrite token that cannot trash is found out before the first file, not after half an album.
        if ($store instanceof FerriteStore && $tracks->isNotEmpty()) {
            try {
                $allowed = $store->canTrash();
            } catch (FerriteException $e) {
                return ['removed' => 0, 'failed' => $tracks->count(), 'error' => $e->getMessage()];
            }

            if (! $allowed) {
                return ['removed' => 0, 'failed' => $tracks->count(), 'error' => 'The Ferrite token of this library cannot move files to the trash. Make a new token in Ferrite with the trash access and run magnetite:storage again.'];
            }
        }

        $removed = 0;
        $error = null;
        foreach ($tracks as $track) {
            abort_unless($track->user_id === $user->id, 404);

            try {
                $store->delete($track->path);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();

                break; // the same problem would repeat for every file
            }

            $track->delete();
            $removed++;
        }

        $user->albums()->doesntHave('tracks')->delete();
        $this->transcodes->prune();

        return ['removed' => $removed, 'failed' => $tracks->count() - $removed, 'error' => $error];
    }

    /** @return array{removed: int, failed: int, error: string|null} */
    public function removeAlbum(User $user, Album $album): array
    {
        return $this->remove($user, $album->tracks()->get());
    }
}
