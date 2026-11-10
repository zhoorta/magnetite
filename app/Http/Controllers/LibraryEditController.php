<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The owner's corrections to titles, artists, years, track numbers and the record label. They are written to the
 * database only (the files and their tags are never touched), and the tracks concerned are marked `identified`, so a
 * rescan keeps what the owner typed instead of reading the tags again.
 */
class LibraryEditController extends Controller
{
    public function album(Request $request, Album $album): JsonResponse
    {
        abort_unless($request->user()->can('update', $album), 404);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'artist' => ['nullable', 'string', 'max:255'],
            'release_date' => ['nullable', 'string', 'regex:'.Album::DATE_PATTERN],
            'year' => ['nullable', 'integer', 'between:1000,2100'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);
        $data = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $data);

        // The year follows the date; a year alone replaces a date that says something else.
        if (array_key_exists('release_date', $data)) {
            $data['year'] = $data['release_date'] !== null ? (int) substr($data['release_date'], 0, 4) : ($data['year'] ?? null);
        } elseif (array_key_exists('year', $data) && $data['year'] !== (int) substr((string) $album->release_date, 0, 4)) {
            $data['release_date'] = null;
        }

        // The scanner tells albums apart by artist and title: two with the same pair would be one album to it.
        $artist = array_key_exists('artist', $data) ? $data['artist'] : $album->artist; // a field that was not sent stays as it is
        $same = Album::where('user_id', $album->user_id)->whereKeyNot($album->id)->where('title', $data['title'])
            ->where(fn ($q) => $artist === null ? $q->whereNull('artist') : $q->where('artist', $artist));
        abort_if($same->exists(), 422, 'You already have an album with that title and artist.');

        $album->update($data);
        $album->tracks()->update(['identified' => true]);

        return response()->json(['album' => $album->only(['id', 'title', 'artist', 'year', 'release_date', 'label'])]);
    }

    /**
     * The edit table: the album's own fields and the changed tracks in one request, saved together or not at all (a table
     * half saved would leave titles and numbers that belong to different edits). Same rules as `album()` and `track()`;
     * every track must be one of this album's.
     */
    public function details(Request $request, Album $album): JsonResponse
    {
        abort_unless($request->user()->can('update', $album), 404);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'artist' => ['nullable', 'string', 'max:255'],
            'release_date' => ['nullable', 'string', 'regex:'.Album::DATE_PATTERN],
            'label' => ['nullable', 'string', 'max:255'],
            'tracks' => ['present', 'array', 'max:500'],
            'tracks.*.id' => ['required', 'integer', 'distinct'],
            'tracks.*.title' => ['required', 'string', 'max:255'],
            'tracks.*.artist' => ['nullable', 'string', 'max:255'],
            'tracks.*.track_number' => ['nullable', 'integer', 'between:1,999'],
        ]);
        $clean = fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
        $fields = array_map($clean, [
            'title' => $data['title'], 'artist' => $data['artist'] ?? null, 'release_date' => $data['release_date'] ?? null, 'label' => $data['label'] ?? null,
        ]);
        $fields['year'] = $fields['release_date'] !== null ? (int) substr($fields['release_date'], 0, 4) : null;

        $same = Album::where('user_id', $album->user_id)->whereKeyNot($album->id)->where('title', $fields['title'])
            ->where(fn ($q) => $fields['artist'] === null ? $q->whereNull('artist') : $q->where('artist', $fields['artist']));
        abort_if($same->exists(), 422, 'You already have an album with that title and artist.');

        $own = $album->tracks()->pluck('id')->all();
        foreach ($data['tracks'] as $row) {
            abort_unless(in_array($row['id'], $own, true), 422, 'A track in the table is not on this album.');
        }

        DB::transaction(function () use ($album, $fields, $data, $clean) {
            $album->update($fields);
            $album->tracks()->update(['identified' => true]);
            foreach ($data['tracks'] as $row) {
                Track::whereKey($row['id'])->update([
                    'title' => $clean($row['title']),
                    'artist' => $clean($row['artist'] ?? null),
                    'track_number' => $row['track_number'] ?? null,
                    'identified' => true,
                ]);
            }
        });

        return response()->json(['album' => $album->fresh()->only(['id', 'title', 'artist', 'year', 'release_date', 'label'])]);
    }

    public function track(Request $request, Track $track): JsonResponse
    {
        abort_unless($request->user()->can('update', $track), 404);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'artist' => ['nullable', 'string', 'max:255'],
            'track_number' => ['nullable', 'integer', 'between:1,999'],
        ]);
        $data = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $data);

        $track->update($data + ['identified' => true]);

        return response()->json(['track' => $track->only(['id', 'title', 'artist', 'track_number'])]);
    }
}
