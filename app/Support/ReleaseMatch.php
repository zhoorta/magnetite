<?php

namespace App\Support;

/**
 * Compares the files of a folder with the tracks of a release by length, in playing order. Counts and durations are
 * what the files can vouch for without tags; a title is never compared.
 */
class ReleaseMatch
{
    /** Seconds two lengths may differ by and still count as the same track (encoders add or trim a little). */
    public const TOLERANCE = 2.0;

    /**
     * @param  list<float|null>  $local  durations of the files in playing order
     * @param  list<float|null>  $remote  lengths of the release tracks in order
     * @return array{matched: list<bool>, count: int, score: float} one flag per local file; score is matches over the longer list
     */
    public static function compare(array $local, array $remote): array
    {
        $matched = [];
        foreach ($local as $i => $seconds) {
            $other = $remote[$i] ?? null;
            $matched[] = $seconds !== null && $other !== null && abs($seconds - $other) <= self::TOLERANCE;
        }

        $count = count(array_filter($matched));
        $longest = max(count($local), count($remote));

        return ['matched' => $matched, 'count' => $count, 'score' => $longest > 0 ? round($count / $longest, 3) : 0.0];
    }
}
