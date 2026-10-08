<?php

namespace App\Services;

use App\Models\Track;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Chrome and Firefox cannot decode ALAC, so ALAC tracks are served as FLAC instead: same audio,
 * lossless, played everywhere. The conversion runs once per file (on first play) and is cached
 * on the private disk under a name derived from the file's path, size and mtime.
 */
class TranscodeCache
{
    public const MIME = 'audio/flac';

    private string|false|null $binary = null;

    public function binary(): ?string
    {
        // Looked up once per request: the library page asks for every track.
        $this->binary ??= (config('magnetite.ffmpeg') ?: (new ExecutableFinder)->find('ffmpeg')) ?? false;

        return $this->binary ?: null;
    }

    public function available(): bool
    {
        return $this->binary() !== null;
    }

    public function shouldTranscode(Track $track): bool
    {
        return in_array($track->codec, Track::UNPLAYABLE_IN_CHROME, true) && $this->available();
    }

    /** Absolute path of the cached FLAC for a track, creating it if needed. */
    public function ensure(Track $track, string $source): string
    {
        $name = $this->name($track);
        $disk = Storage::disk('local');
        $final = $disk->path("transcodes/{$name}");

        if (is_file($final)) {
            return $final;
        }

        // One conversion per file at a time: a second request waits for the first instead of repeating it.
        return Cache::lock("transcode:{$name}", 600)->block(300, function () use ($disk, $final, $name, $source) {
            if (is_file($final)) {
                return $final;
            }

            $disk->makeDirectory('transcodes');
            $partial = $disk->path('transcodes/'.$name.'.'.bin2hex(random_bytes(4)).'.part');

            // Arguments are passed as an array (no shell). The source is an absolute, already
            // validated path, so it cannot be mistaken for an ffmpeg option.
            $process = new Process([
                $this->binary(), '-nostdin', '-y', '-v', 'error',
                '-i', $source,
                '-map', '0:a:0', '-map_metadata', '-1',
                '-c:a', 'flac', '-f', 'flac', $partial,
            ]);
            $process->setTimeout(600);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($partial) || filesize($partial) === 0) {
                @unlink($partial);
                Log::warning('ffmpeg transcode failed', ['track' => $source, 'error' => trim($process->getErrorOutput())]);

                throw new RuntimeException('Transcode failed');
            }

            rename($partial, $final); // atomic: readers never see a half-written file

            return $final;
        });
    }

    /** Delete cached files that no current ALAC track maps to (changed or removed files). */
    public function prune(): int
    {
        $keep = Track::whereIn('codec', Track::UNPLAYABLE_IN_CHROME)->get()
            ->map(fn (Track $t) => $this->name($t))
            ->flip();

        $disk = Storage::disk('local');
        $removed = 0;

        foreach ($disk->files('transcodes') as $file) {
            if (! $keep->has(basename($file))) {
                $disk->delete($file);
                $removed++;
            }
        }

        return $removed;
    }

    private function name(Track $track): string
    {
        return sha1("{$track->path}|{$track->size}|{$track->mtime}").'.flac';
    }
}
