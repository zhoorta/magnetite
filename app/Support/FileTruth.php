<?php

namespace App\Support;

use App\Models\Track;
use Illuminate\Support\Collection;

/**
 * Turns the measurements stored by `magnetite:analyze` into plain findings about a file. The thresholds live
 * here and not in the measurements, so changing one never needs a new analysis.
 *
 * Levels: `problem` the file is not what it claims or is damaged, `warn` worth knowing, `note` just information.
 * The lossy-source and upsampling findings are heuristics, hence "likely" in their text: a CD from the early
 * 1980s can be low-passed at 20 kHz without ever having been an MP3.
 */
class FileTruth
{
    /** A lossless file whose spectrum ends at or below this (Hz) was probably made from a lossy one. */
    public const LOSSY_CUTOFF_MAX = 20500;

    /** A file sampled above 48 kHz whose content ends at or below this (Hz) was probably upsampled. */
    public const UPSAMPLED_CONTENT_MAX = 22500;

    public const CLIP_PROBLEM_RUNS = 100;

    public const CLIP_WARN_RUNS = 10;

    public const DC_WARN = 0.01;

    private const LOSSLESS = ['flac', 'alac', 'wavpack', 'ape', 'tta', 'mlp', 'truehd'];

    public static function isLossless(?string $codec): bool
    {
        return $codec !== null && (in_array($codec, self::LOSSLESS, true) || str_starts_with($codec, 'pcm_'));
    }

    /** Words for a dynamic range score, after the DR database's own scale. */
    public static function drClass(?int $dr): ?string
    {
        return match (true) {
            $dr === null => null,
            $dr >= 14 => 'excellent',
            $dr >= 11 => 'good',
            $dr >= 8 => 'fair',
            default => 'poor',
        };
    }

    /**
     * @param  array<int, array{id: int, title: string}>  $copies  other tracks with the same audio or the same recording
     * @return list<array{code: string, level: string, label: string, detail: string}>
     */
    public static function flags(Track $track, array $copies = []): array
    {
        $a = $track->analysis;
        if (! is_array($a)) {
            return [];
        }

        $flags = [];
        $add = function (string $code, string $level, string $label, string $detail) use (&$flags) {
            $flags[] = ['code' => $code, 'level' => $level, 'label' => $label, 'detail' => $detail];
        };

        $codec = $a['source']['codec'] ?? null;
        $lossless = self::isLossless($codec);
        $spectrum = $a['spectrum'] ?? [];

        if (! empty($a['decode_errors'])) {
            $add('decode_errors', 'problem', 'Decode errors', 'ffmpeg reported: '.$a['decode_errors'][0]);
        }
        if (($a['missing_seconds'] ?? 0) >= 1) {
            $add('cut_short', 'problem', 'Cut short', 'The file holds '.$a['missing_seconds'].' s less audio than its header says: an incomplete rip or download.');
        }

        $runs = $a['clipping']['runs'] ?? 0;
        if ($runs >= self::CLIP_WARN_RUNS) {
            $add('clipping', $runs >= self::CLIP_PROBLEM_RUNS ? 'problem' : 'warn', 'Clipped',
                "{$runs} flat-topped peaks where the waveform hit the ceiling and was cut off; that cannot be undone.");
        }

        if ($lossless && ($cut = $spectrum['cutoff_hz'] ?? null) !== null && $cut <= self::LOSSY_CUTOFF_MAX) {
            $add('lossy_source', 'problem', 'Likely lossy source',
                sprintf('The spectrum drops %d dB at %s kHz and stays empty above, the low-pass of an MP3 or AAC encoder, inside a lossless %s file.',
                    round($spectrum['cliff_db'] ?? 0), rtrim(rtrim(number_format($cut / 1000, 1), '0'), '.'), strtoupper($codec)));
        }

        $rate = $a['sample_rate'] ?? 0;
        $content = $spectrum['content_hz'] ?? null;
        if ($lossless && $rate >= 88200 && $content !== null && $content <= self::UPSAMPLED_CONTENT_MAX) {
            $add('upsampled', 'problem', 'Likely upsampled',
                sprintf('Sampled at %s kHz but nothing above %s kHz: the content of a CD-quality master.', rtrim(rtrim(number_format($rate / 1000, 1), '0'), '.'), rtrim(rtrim(number_format($content / 1000, 1), '0'), '.')));
        }

        $declared = $a['source']['declared_bits'] ?? null;
        $effective = $a['effective_bits'] ?? null;
        if ($lossless && $declared && $effective && $effective < $declared) {
            $add('padded_bits', 'problem', 'Padded bit depth', "Stored as {$declared}-bit but only {$effective} bits are in use.");
        }

        if ($a['inverted_channels'] ?? false) {
            $add('inverted', 'problem', 'Channels out of phase', 'The right channel is the left one upside down: it cancels to nothing in mono.');
        }

        if (($dr = $track->dr) !== null && $dr <= 5) {
            $add('crushed', 'warn', "Very compressed (DR{$dr})", 'Almost no difference between loud and quiet: a heavily limited master.');
        }

        $tp = $a['loudness']['true_peak_db'] ?? null;
        if ($tp !== null && $tp > 0) {
            $add('true_peak', $tp >= 1.0 ? 'warn' : 'note', sprintf('Peaks over 0 dBTP (+%.1f)', $tp), 'Between the samples the reconstructed wave goes over full scale; some players and lossy encoders will clip it.');
        }

        if (($a['dc_offset'] ?? 0) >= self::DC_WARN) {
            $add('dc_offset', 'warn', 'DC offset', sprintf('The signal sits %.1f%% off centre, which wastes headroom.', $a['dc_offset'] * 100));
        }

        if (($a['gaps']['count'] ?? 0) > 0) {
            $add('gaps', 'note', 'Silent gaps', sprintf('%d stretch(es) of digital silence inside the track, the longest %d ms. Intentional in some music, a dropout in a bad rip.', $a['gaps']['count'], $a['gaps']['longest_ms']));
        }

        if ($a['dual_mono'] ?? false) {
            $add('dual_mono', 'note', 'Dual mono', 'Both channels are identical: a mono recording stored as stereo.');
        }

        foreach ($copies as $copy) {
            $exact = $copy['exact'];
            $add($exact ? 'duplicate' : 'same_recording', $exact ? 'warn' : 'note',
                $exact ? 'Exact duplicate' : 'Another copy',
                ($exact ? 'The audio is bit-identical to ' : 'Same artist, title and length as ')."“{$copy['title']}” ({$copy['path']}).");
        }

        return $flags;
    }

    /**
     * For each analysed track, the other tracks that carry the same audio (same decoded hash) or look like the
     * same recording (same artist, title and length within 2 s, different audio).
     *
     * @param  Collection<int, Track>  $tracks
     * @return array<int, list<array{id: int, title: string, path: string, exact: bool}>>
     */
    public static function copies(Collection $tracks): array
    {
        $norm = fn (?string $s) => mb_strtolower(trim((string) $s));
        $out = [];

        foreach ($tracks->groupBy(fn (Track $t) => $t->pcm_sha1 ?? 'none-'.$t->id)->filter(fn ($g) => $g->count() > 1) as $group) {
            foreach ($group as $t) {
                foreach ($group->where('id', '!=', $t->id) as $other) {
                    $out[$t->id][$other->id] = ['id' => $other->id, 'title' => $other->title, 'path' => $other->path, 'exact' => true];
                }
            }
        }

        $byName = $tracks->filter(fn (Track $t) => $norm($t->title) !== '' && $t->artist !== null)
            ->groupBy(fn (Track $t) => $norm($t->artist).'|'.$norm($t->title));
        foreach ($byName->filter(fn ($g) => $g->count() > 1) as $group) {
            foreach ($group as $t) {
                foreach ($group->where('id', '!=', $t->id) as $other) {
                    if (abs(($t->duration ?? 0) - ($other->duration ?? 0)) <= 2 && ! isset($out[$t->id][$other->id])) {
                        $out[$t->id][$other->id] = ['id' => $other->id, 'title' => $other->title, 'path' => $other->path, 'exact' => false];
                    }
                }
            }
        }

        return array_map('array_values', $out);
    }
}
