<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Track;
use App\Support\TrackLibrary;
use getID3;
use getid3_lib;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

class LibraryScanner
{
    private const FOLDER_COVERS = ['cover', 'folder', 'front', 'album', 'albumart'];

    public function __construct(private CoverStore $covers, private TranscodeCache $transcodes) {}

    /**
     * Sync the tracks table with the music folder. Files whose size and mtime are unchanged are skipped.
     *
     * @param  callable(string $path): void|null  $onFile
     * @return array{added: int, updated: int, unchanged: int, removed: int, failed: int}
     */
    public function scan(bool $force = false, ?callable $onFile = null): array
    {
        $counts = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'failed' => 0];
        $known = Track::query()->get(['id', 'path', 'size', 'mtime'])->keyBy('path');
        $seen = [];

        $reader = new getID3;
        $reader->setOption(['encoding' => 'UTF-8']);

        foreach (File::allFiles(TrackLibrary::path()) as $file) {
            if (TrackLibrary::contentType($file->getFilename()) === null) {
                continue;
            }

            $relative = $file->getRelativePathname();
            $seen[$relative] = true;
            $existing = $known->get($relative);

            if (! $force && $existing && $existing->size === $file->getSize() && $existing->mtime === $file->getMTime()) {
                $counts['unchanged']++;

                continue;
            }

            $onFile && $onFile($relative);

            try {
                $this->import($reader, $file, $relative);
                $counts[$existing ? 'updated' : 'added']++;
            } catch (Throwable $e) {
                // One unreadable file must not abort the scan.
                Log::warning('Scan failed for a file', ['path' => $relative, 'error' => $e->getMessage()]);
                $counts['failed']++;
            }
        }

        $gone = $known->keys()->diff(array_keys($seen));
        if ($gone->isNotEmpty()) {
            Track::whereIn('path', $gone)->delete();
            $counts['removed'] = $gone->count();
        }

        Album::doesntHave('tracks')->delete();
        $this->transcodes->prune();

        return $counts;
    }

    private function import(getID3 $reader, SplFileInfo $file, string $relative): void
    {
        $info = $reader->analyze($file->getRealPath());
        getid3_lib::CopyTagsToComments($info);
        $tags = $info['comments'] ?? [];

        $first = fn (string $key) => isset($tags[$key][0]) && is_string($tags[$key][0]) && trim($tags[$key][0]) !== ''
            ? trim($tags[$key][0])
            : null;

        $artist = $first('artist');
        $albumTitle = $first('album');
        // Only an explicit album-artist tag names the album's artist. Without one, tracks are grouped by
        // album title within their folder, so compilations with a different artist per track stay together.
        $albumArtist = $first('album_artist') ?? $first('band');

        $album = null;
        if ($albumTitle !== null) {
            $identity = ['artist' => $albumArtist, 'title' => $albumTitle];
            if ($albumArtist === null) {
                $identity['folder'] = dirname($relative);
            }

            $album = Album::firstOrCreate(
                $identity,
                ['year' => $this->year($first('year') ?? $first('recording_time') ?? $first('date') ?? $first('creation_date'))],
            );

            if ($album->cover === null) {
                $cover = $this->coverFor($tags, $file);
                if ($cover !== null) {
                    $album->update(['cover' => $cover]);
                }
            }
        }

        // Measurements belong to the exact bytes they were taken from: a changed file needs a new analysis.
        $before = Track::where('path', $relative)->first(['id', 'size', 'mtime']);
        $changed = $before && ($before->size !== $file->getSize() || $before->mtime !== $file->getMTime())
            ? ['analysis' => null, 'analysis_version' => null, 'analyzed_at' => null, 'dr' => null, 'pcm_sha1' => null]
            : [];

        Track::updateOrCreate(['path' => $relative], $changed + [
            'album_id' => $album?->id,
            'title' => $first('title') ?? $this->titleFromFilename($file->getFilenameWithoutExtension()),
            'artist' => $artist,
            'track_number' => $this->number($first('track_number') ?? $first('track')),
            'disc_number' => $this->number($first('part_of_a_set') ?? $first('disc_number')),
            'duration' => isset($info['playtime_seconds']) ? round((float) $info['playtime_seconds'], 2) : null,
            'codec' => $this->codec($info, $file->getRealPath()),
            'mime' => TrackLibrary::contentType($file->getFilename()),
            'bitrate' => isset($info['audio']['bitrate']) ? (int) $info['audio']['bitrate'] : null,
            'size' => $file->getSize(),
            'mtime' => $file->getMTime(),
        ]);
    }

    /**
     * getID3 reports the codec for most formats, but leaves it empty for ALAC in .m4a (and ALAC is the
     * one codec Chrome and Firefox cannot play), so read the QuickTime sample description directly.
     */
    private function codec(array $info, string $path): ?string
    {
        if (($info['fileformat'] ?? null) === 'quicktime') {
            return $this->quicktimeCodec($path) ?? (($info['audio']['dataformat'] ?? '') ?: null);
        }

        $format = strtolower((string) ($info['audio']['dataformat'] ?? ''));

        return $format !== '' ? $format : null;
    }

    private function quicktimeCodec(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            // The moov atom sits at the start of the file or at the end; look at both ends.
            $size = filesize($path);
            $chunks = [(string) fread($handle, 4_000_000)];
            if ($size > 4_000_000) {
                fseek($handle, max(0, $size - 4_000_000));
                $chunks[] = (string) fread($handle, 4_000_000);
            }
        } finally {
            fclose($handle);
        }

        foreach ($chunks as $chunk) {
            // 'stsd' + version/flags (4) + entry count (4) + first entry: size (4) + format (4).
            if (preg_match('/stsd.{8}.{4}(alac|mp4a)/s', $chunk, $m)) {
                return $m[1] === 'alac' ? 'alac' : 'aac';
            }
        }

        return null;
    }

    /** Embedded picture first, then a conventional image file next to the track. */
    private function coverFor(array $tags, SplFileInfo $file): ?string
    {
        $data = $tags['picture'][0]['data'] ?? null;
        if (is_string($data) && $data !== '' && ($name = $this->covers->store($data))) {
            return $name;
        }

        $dir = dirname($file->getRealPath());
        foreach (File::files($dir) as $candidate) {
            $ext = strtolower($candidate->getExtension());
            if (in_array($ext, ['jpg', 'jpeg', 'png'], true)
                && in_array(strtolower($candidate->getFilenameWithoutExtension()), self::FOLDER_COVERS, true)
                && $candidate->getSize() < 15_000_000
                && ($name = $this->covers->store(File::get($candidate->getRealPath())))) {
                return $name;
            }
        }

        return null;
    }

    private function number(?string $value): ?int
    {
        // Tags like "13" or "13/20": the part before the slash is the number.
        return $value !== null && preg_match('/^\d+/', $value, $m) ? (int) $m[0] : null;
    }

    private function year(?string $value): ?int
    {
        return $value !== null && preg_match('/\b(1[89]\d\d|20\d\d)\b/', $value, $m) ? (int) $m[1] : null;
    }

    private function titleFromFilename(string $name): string
    {
        return (string) str($name)->replaceMatches('/^\d+[\s._-]+/', '')->replace('_', ' ')->trim();
    }
}
