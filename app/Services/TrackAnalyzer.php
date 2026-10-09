<?php

namespace App\Services;

use App\Models\Track;
use App\Support\TrackLibrary;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Measures a track with bin/measure.mjs (ffmpeg decodes, Node does the maths) and stores the result.
 * The music folder is only read. The file is passed as one argument of an argument array, never through a
 * shell, and only after TrackLibrary::resolve has checked that it is a music file inside the library.
 */
class TrackAnalyzer
{
    /** Bump when the measurements change in a way that makes old results wrong; `magnetite:analyze` redoes them. */
    public const VERSION = 1;

    private const TIMEOUT = 1800; // seconds: a long DJ mix is still only a few minutes of work

    public function __construct(private TranscodeCache $ffmpeg) {}

    public function ffmpeg(): ?string
    {
        return $this->ffmpeg->binary();
    }

    public function ffprobe(): ?string
    {
        if ($configured = config('magnetite.ffprobe')) {
            return $configured;
        }

        $next = $this->ffmpeg() ? dirname($this->ffmpeg()).DIRECTORY_SEPARATOR.'ffprobe' : null;

        return ($next && is_executable($next)) ? $next : (new ExecutableFinder)->find('ffprobe');
    }

    public function node(): ?string
    {
        return config('magnetite.node') ?: (new ExecutableFinder)->find('node');
    }

    /** What is missing, so the command can say it instead of failing on the first file. */
    public function missing(): array
    {
        $usable = fn (?string $path) => $path !== null && is_file($path) && is_executable($path);

        return array_keys(array_filter([
            'ffmpeg' => ! $usable($this->ffmpeg()),
            'ffprobe' => ! $usable($this->ffprobe()),
            'node' => ! $usable($this->node()),
        ]));
    }

    /** Measure and save. Throws when the file cannot be decoded. */
    public function analyze(Track $track): Track
    {
        $path = TrackLibrary::resolve($track->path, $track->user);
        if ($path === null) {
            throw new RuntimeException('File not found in the library');
        }

        $process = new Process([
            $this->node(), base_path('bin/measure.mjs'),
            '--ffmpeg', $this->ffmpeg(),
            '--ffprobe', $this->ffprobe(),
            $path,
        ]);
        $process->setTimeout(self::TIMEOUT);
        $process->run();

        $result = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($result)) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Analysis failed');
        }

        $track->forceFill([
            'analysis' => $result,
            'analysis_version' => self::VERSION,
            'analyzed_at' => now(),
            'dr' => isset($result['dr']['rounded']) ? max(0, (int) $result['dr']['rounded']) : null,
            'pcm_sha1' => $result['pcm_sha1'] ?? null,
        ])->save();

        return $track;
    }
}
