<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Track;
use App\Models\User;
use App\Support\PathGuess;
use Illuminate\Support\Collection;
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
            $this->number($tracks);

            // A cover in the folder: the owner's own file wins over anything fetched later.
            if ($album->cover === null && ($image = $user->store()->folderCover($tracks->first()->path)) !== null) {
                $name = $this->covers->store($image);
                $name !== null && $album->update(['cover' => $name, 'cover_source' => null]);
            }

            return $album;
        });
    }

    /**
     * Untagged files have no track numbers, and an album plays in disc, number, then title order: without numbers it would be
     * alphabetical. The numbers (and discs) come from the file names, "03 - Title" or "1-03 Title", like the web's identify
     * does; a title that is still the file name loses its number and "Artist - " prefix the same way. Tags that exist stay.
     *
     * @param  Collection<int, Track>  $tracks
     */
    public function number(Collection $tracks): void
    {
        foreach ($tracks->groupBy(fn (Track $t) => PathGuess::groupKey($t->path)[0]) as $key => $folder) {
            $guess = collect(PathGuess::group((string) $key, $folder)['tracks'])->keyBy('id');

            foreach ($folder as $track) {
                $row = $guess[$track->id];
                $stem = pathinfo($track->path, PATHINFO_FILENAME);
                $untitled = $track->title === '' || $track->title === $stem || $track->title === str_replace('_', ' ', $stem);

                $track->update(array_filter([
                    'track_number' => $track->track_number === null ? $row['number'] : null,
                    'disc_number' => $track->disc_number === null ? $row['disc'] : null,
                    'title' => $untitled && $row['title'] !== '' ? $row['title'] : null,
                ], fn ($v) => $v !== null));
            }
        }
    }
}
