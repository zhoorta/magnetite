<?php

namespace App\Services;

use App\Models\Album;
use App\Models\User;
use App\Support\AlbumKey;
use Illuminate\Support\Collection;

/**
 * Finds albums that were split in two (docs/editing.md): the same title, and either the same folder or the same artist. Only
 * albums with one title are compared, so it is cheap. Reasons are listed so the owner can judge; `high` is what the command
 * merges by itself, `medium` is only shown. Two albums whose album artists are both set and differ are never related unless
 * they share a folder, and sharing a folder with different artists is left alone too: that is two albums called alike.
 */
class AlbumDoctor
{
    /**
     * @return list<array{keep: Album, merge: Album, confidence: string, reasons: list<string>}>
     */
    public function suspects(User $user): array
    {
        $albums = Album::where('user_id', $user->id)->with('tracks:id,album_id,artist,track_number,disc_number,path')->get();
        $out = [];

        foreach ($albums->groupBy('title_key')->filter(fn (Collection $g) => $g->count() > 1) as $group) {
            // The kept album is the fullest (the lowest id on a tie); every other one of the group that relates to it is a suspect.
            $sorted = $group->sort(fn (Album $a, Album $b) => [$b->tracks->count(), $a->id] <=> [$a->tracks->count(), $b->id])->values();
            $keep = $sorted->first();

            foreach ($sorted->slice(1) as $other) {
                if ($found = $this->compare($keep, $other)) {
                    $out[] = ['keep' => $keep, 'merge' => $other] + $found;
                }
            }
        }

        return $out;
    }

    /** @return array{confidence: string, reasons: list<string>}|null */
    private function compare(Album $keep, Album $other): ?array
    {
        $artistA = AlbumKey::artist($keep->display_artist);
        $artistB = AlbumKey::artist($other->display_artist);
        $differentArtists = $artistA !== null && $artistB !== null && $artistA !== $artistB;

        $dirs = fn (Album $a) => $a->tracks->map(fn ($t) => dirname($t->path))->unique()->values();
        $sharedDirs = $dirs($keep)->intersect($dirs($other));
        $sameFolder = $sharedDirs->isNotEmpty();
        $sameArtist = $artistA !== null && $artistA === $artistB;

        if ($differentArtists || (! $sameFolder && ! $sameArtist)) {
            return null;
        }

        $reasons = [];
        $sameFolder && $reasons[] = 'the files are in the same folder ('.$sharedDirs->first().')';
        $sameArtist && $reasons[] = 'the same artist ('.$keep->display_artist.')';

        // Numbers (disc, number) of the two: disjoint and fitting the gaps of the kept album is the picture of one album cut in two.
        $key = fn ($t) => ($t->disc_number ?? 1).'-'.($t->track_number ?? '?');
        $numbersA = $keep->tracks->filter(fn ($t) => $t->track_number !== null)->map($key);
        $numbersB = $other->tracks->filter(fn ($t) => $t->track_number !== null)->map($key);
        $overlap = $numbersA->intersect($numbersB);

        if ($overlap->isNotEmpty()) {
            $reasons[] = 'but both have track '.$overlap->map(fn ($k) => explode('-', $k)[1])->implode(', ').': two discs or two editions?';
            $confidence = 'medium';
        } else {
            if ($numbersB->isNotEmpty() && $numbersA->isNotEmpty()) {
                $reasons[] = 'its track numbers ('.$numbersB->map(fn ($k) => explode('-', $k)[1])->implode(', ').') do not clash with the other\'s';
            }
            $confidence = $sameFolder || ($sameArtist && $numbersB->isNotEmpty()) ? 'high' : 'medium';
        }

        return ['confidence' => $confidence, 'reasons' => $reasons];
    }
}
