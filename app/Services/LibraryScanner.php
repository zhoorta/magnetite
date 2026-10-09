<?php

namespace App\Services;

use App\Contracts\LibraryStore;
use App\Models\Album;
use App\Models\Track;
use App\Models\User;
use App\Support\StoredFile;
use App\Support\TrackLibrary;
use getID3;
use getid3_lib;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class LibraryScanner
{
    public function __construct(private CoverStore $covers, private TranscodeCache $transcodes) {}

    /**
     * Sync one user's tracks with their folder. Files whose size and mtime are unchanged are skipped.
     *
     * @param  callable(string $path): void|null  $onFile
     * @return array{added: int, updated: int, unchanged: int, removed: int, failed: int}
     */
    public function scan(User $user, bool $force = false, ?callable $onFile = null): array
    {
        $store = $user->store();

        if (! $store->isAvailable()) {
            // A new user has no folder yet; but a folder that vanished from under a library (disk not mounted) must not wipe it.
            if ($user->tracks()->exists()) {
                throw new RuntimeException("The library folder of '{$user->username}' is missing.");
            }

            return ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'failed' => 0];
        }

        $counts = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'failed' => 0];
        $known = $user->tracks()->get(['id', 'path', 'size', 'mtime'])->keyBy('path');
        $seen = [];

        $reader = new getID3;
        $reader->setOption(['encoding' => 'UTF-8']);

        foreach ($store->files() as $file) {
            $relative = $file->path;
            $seen[$relative] = true;
            $existing = $known->get($relative);

            if (! $force && $existing && $existing->size === $file->size && $existing->mtime === $file->mtime) {
                $counts['unchanged']++;

                continue;
            }

            $onFile && $onFile($relative);

            // getID3 calls set_time_limit(30) while it reads an MP3, which also switches off a limit that was lifted for
            // the request. A scan run inside a web request (sync queue) would then time out after 30 s of other files.
            @set_time_limit(0);

            try {
                $this->import($user, $store, $reader, $file);
                $counts[$existing ? 'updated' : 'added']++;
            } catch (Throwable $e) {
                // One unreadable file must not abort the scan.
                Log::warning('Scan failed for a file', ['path' => $relative, 'error' => $e->getMessage()]);
                $counts['failed']++;
            }
        }

        $gone = $known->keys()->diff(array_keys($seen));
        if ($gone->isNotEmpty()) {
            $user->tracks()->whereIn('path', $gone)->delete();
            $counts['removed'] = $gone->count();
        }

        $user->albums()->doesntHave('tracks')->delete();
        $this->transcodes->prune();

        return $counts;
    }

    private function import(User $user, LibraryStore $store, getID3 $reader, StoredFile $file): void
    {
        $relative = $file->path;
        $local = $store->fetch($relative) ?? throw new RuntimeException('The file is gone.');
        $info = $reader->analyze($local);
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
            $identity = ['user_id' => $user->id, 'artist' => $albumArtist, 'title' => $albumTitle];
            if ($albumArtist === null) {
                $identity['folder'] = dirname($relative);
            }

            $album = Album::firstOrCreate(
                $identity,
                ['year' => $this->year($first('year') ?? $first('recording_time') ?? $first('date') ?? $first('creation_date'))],
            );

            // A cover from the user's own files replaces one that was downloaded.
            if ($album->cover === null || $album->cover_source !== null) {
                $cover = $this->coverFor($tags, $store, $relative);
                if ($cover !== null) {
                    $album->update(['cover' => $cover, 'cover_source' => null]);
                }
            }
        }

        // Measurements belong to the exact bytes they were taken from: a changed file needs a new analysis.
        $before = $user->tracks()->where('path', $relative)->first(['id', 'size', 'mtime']);
        $changed = $before && ($before->size !== $file->size || $before->mtime !== $file->mtime)
            ? ['analysis' => null, 'analysis_version' => null, 'analyzed_at' => null, 'dr' => null, 'pcm_sha1' => null]
            : [];

        Track::updateOrCreate(['user_id' => $user->id, 'path' => $relative], $changed + [
            'album_id' => $album?->id,
            'title' => $first('title') ?? $this->titleFromFilename(pathinfo($relative, PATHINFO_FILENAME)),
            'artist' => $artist,
            'track_number' => $this->number($first('track_number') ?? $first('track')),
            'disc_number' => $this->number($first('part_of_a_set') ?? $first('disc_number')),
            'duration' => isset($info['playtime_seconds']) ? round((float) $info['playtime_seconds'], 2) : null,
            'codec' => $this->codec($info, $local),
            'mime' => TrackLibrary::contentType($relative),
            'bitrate' => isset($info['audio']['bitrate']) ? (int) $info['audio']['bitrate'] : null,
            'size' => $file->size,
            'mtime' => $file->mtime,
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
    private function coverFor(array $tags, LibraryStore $store, string $relative): ?string
    {
        $data = $tags['picture'][0]['data'] ?? null;
        if (is_string($data) && $data !== '' && ($name = $this->covers->store($data))) {
            return $name;
        }

        $image = $store->folderCover($relative);

        return $image !== null ? $this->covers->store($image) : null;
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
