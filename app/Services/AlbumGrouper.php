<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Makes an album out of tracks that have none (files without an album tag stay loose after a scan): the owner names it.
 * The tracks are marked confirmed (`identified`), so a rescan keeps them in it. Only database rows change, never the files.
 */
class AlbumGrouper
{
    public function __construct(private CoverStore $covers) {}

    /** @param  list<int>  $trackIds */
    public function group(User $user, array $trackIds, string $title, ?string $artist, ?int $year): Album
    {
        return DB::transaction(function () use ($user, $trackIds, $title, $artist, $year) {
            $tracks = Track::where('user_id', $user->id)->whereNull('album_id')->whereIn('id', $trackIds)->orderBy('path')->get();

            if ($tracks->isEmpty()) {
                throw new RuntimeException('None of these tracks is yours and without an album.');
            }

            $identity = ['user_id' => $user->id, 'artist' => $artist, 'title' => $title];
            if ($artist === null) {
                $identity['folder'] = dirname($tracks->first()->path); // as the scanner does for albums without an album artist
            }

            $album = Album::firstOrCreate($identity, ['year' => $year]);
            $year !== null && $album->year === null && $album->update(['year' => $year]);

            Track::whereIn('id', $tracks->pluck('id'))->update(['album_id' => $album->id, 'identified' => true]);

            // A cover in the folder: the owner's own file wins over anything fetched later.
            if ($album->cover === null && ($image = $user->store()->folderCover($tracks->first()->path)) !== null) {
                $name = $this->covers->store($image);
                $name !== null && $album->update(['cover' => $name, 'cover_source' => null]);
            }

            return $album;
        });
    }
}
