<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Track;
use Illuminate\Support\Facades\DB;

/**
 * Joins two albums of one owner that are really one (a track whose album-artist tag differed from its neighbours' made a
 * second album). Only database rows change: the tracks move to the other album and all of its tracks are marked `identified`, so a rescan keeps
 * the merge instead of reading the tags again; the files are not touched. What the emptied album had and the kept one lacks
 * (artist, year, date, label, cover, library folder, share) is carried over, then it is deleted.
 */
class AlbumMerger
{
    public function merge(Album $from, Album $into): Album
    {
        abort_unless($from->user_id === $into->user_id && $from->id !== $into->id, 422, 'Two different albums of the same library are needed.');

        return DB::transaction(function () use ($from, $into) {
            Track::where('album_id', $from->id)->update(['album_id' => $into->id]);
            // The owner confirmed the album as a whole (as when editing it): a rescan keeps every one of its tracks where they are.
            Track::where('album_id', $into->id)->update(['identified' => true]);

            $fill = [];
            foreach (['artist', 'year', 'release_date', 'label', 'folder_id', 'folder'] as $column) {
                if ($into->{$column} === null && $from->{$column} !== null) {
                    $fill[$column] = $from->{$column};
                }
            }
            // A cover of the owner's own files beats a downloaded one, as in the scanner; otherwise the kept album's cover stays.
            if ($into->cover === null && $from->cover !== null) {
                $fill += ['cover' => $from->cover, 'cover_source' => $from->cover_source];
            }
            $into->update($fill);

            // A share of the emptied album moves to the kept one (unless that one is shared already), so what was visible stays visible.
            if ($from->share && ! $into->share()->exists()) {
                $from->share->update(['album_id' => $into->id]);
            }

            $from->delete();

            return $into->refresh();
        });
    }
}
