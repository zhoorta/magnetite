<?php

namespace App\Services;

use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Level 3 of docs/identify-albums.md: Chromaprint (`fpcalc`) fingerprints of a few tracks, looked up at AcoustID to
 * get the releases they appear on. It only supplies release ids; the lengths of the files still decide whether a
 * release fits. Needs `fpcalc` and an AcoustID application key; without either it does nothing.
 * What leaves the server: a fingerprint and the length of each sampled track, never audio.
 */
class Fingerprinter
{
    /** Tracks sampled per folder, spread over it. */
    public const SAMPLE = 6;

    private string|false|null $binary = null;

    /** What the last `releases()` call did, for the report shown to the owner. @var array{sampled: int, fingerprinted: int, matched: int, failed: int} */
    public array $stats = ['sampled' => 0, 'fingerprinted' => 0, 'matched' => 0, 'failed' => 0];

    /** Why `available()` is false, in words. */
    public function unavailableReason(): ?string
    {
        if ($this->binary() === null) {
            return 'fpcalc is not installed';
        }

        return (string) config('magnetite.acoustid.key') === '' ? 'no AcoustID key set' : null;
    }

    public function binary(): ?string
    {
        $this->binary ??= (config('magnetite.fpcalc') ?: (new ExecutableFinder)->find('fpcalc')) ?? false;

        return $this->binary ?: null;
    }

    public function available(): bool
    {
        return $this->binary() !== null && (string) config('magnetite.acoustid.key') !== '';
    }

    /**
     * Release ids the sampled tracks appear on, with how many tracks voted for each, the most voted first.
     *
     * @param  list<Track>  $tracks  in playing order
     * @return array<string, int>
     */
    public function releases(User $user, array $tracks): array
    {
        if (! $this->available() || $tracks === []) {
            return [];
        }

        $store = $user->store();
        $votes = [];
        $sample = $this->sample($tracks);
        $this->stats = ['sampled' => count($sample), 'fingerprinted' => 0, 'matched' => 0, 'failed' => 0];

        foreach ($sample as $track) {
            try {
                $file = $store->fetch($track->path);
                $print = $file ? $this->fingerprint($file) : null;
                if ($print === null) {
                    continue;
                }

                $this->stats['fingerprinted']++;

                Sleep::for(350)->milliseconds(); // AcoustID allows three requests a second
                $ids = $this->lookup($print['duration'], $print['fingerprint']);
                $this->stats['matched'] += $ids === [] ? 0 : 1;
                foreach ($ids as $id) {
                    $votes[$id] = ($votes[$id] ?? 0) + 1;
                }
            } catch (Throwable $e) {
                $this->stats['failed']++;
                Log::info('Fingerprint lookup failed', ['track' => $track->id, 'error' => $e->getMessage()]);
            }
        }

        arsort($votes);

        return $votes;
    }

    /** @return list<Track> */
    private function sample(array $tracks): array
    {
        if (count($tracks) <= self::SAMPLE) {
            return $tracks;
        }

        $out = [];
        for ($i = 0; $i < self::SAMPLE; $i++) {
            $out[] = $tracks[(int) floor($i * count($tracks) / self::SAMPLE)];
        }

        return $out;
    }

    /** @return array{duration: int, fingerprint: string}|null */
    public function fingerprint(string $file): ?array
    {
        // The file is already a validated absolute path; it is an argument, never part of a shell command.
        $process = new Process([$this->binary(), '-json', '-length', '120', $file]);
        $process->setTimeout(60);
        $process->run();

        $json = $process->isSuccessful() ? json_decode($process->getOutput(), true) : null;

        return is_array($json) && ! empty($json['fingerprint']) && ! empty($json['duration'])
            ? ['duration' => (int) round($json['duration']), 'fingerprint' => (string) $json['fingerprint']]
            : null;
    }

    /** @return list<string> MusicBrainz release ids of the best matches */
    public function lookup(int $duration, string $fingerprint): array
    {
        $results = Http::withUserAgent(config('magnetite.covers.user_agent'))->timeout(15)->withOptions(['allow_redirects' => false])
            ->asForm()->post('https://api.acoustid.org/v2/lookup', [
                'client' => config('magnetite.acoustid.key'),
                'duration' => $duration,
                'fingerprint' => $fingerprint,
                'meta' => 'releaseids',
            ])->throw()->json('results', []);

        $ids = [];
        foreach ($results as $result) {
            if (($result['score'] ?? 0) < 0.8) {
                continue; // a weak fingerprint match says little about the release
            }
            // With `meta=releaseids` the releases sit directly on the result; with `releases` or `recordings`
            // they sit under each recording. Read both.
            $lists = [$result['releases'] ?? []];
            foreach ($result['recordings'] ?? [] as $recording) {
                $lists[] = $recording['releases'] ?? [];
            }
            foreach ($lists as $releases) {
                foreach ($releases as $release) {
                    if (preg_match('/^[0-9a-f-]{36}$/', $release['id'] ?? '')) {
                        $ids[$release['id']] = true;
                    }
                }
            }
        }

        return array_keys($ids);
    }
}
