<?php

namespace App\Services;

use App\Contracts\LibraryStore;
use App\Models\Track;
use App\Models\User;
use App\Support\StoredFile;
use App\Support\TrackLibrary;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A library kept in a Ferrite folder, read through Ferrite's token API (a folder-scoped, read-only use of it here).
 * The token never leaves the server: the browser only ever talks to `/stream`, which proxies Range requests.
 */
class FerriteStore implements LibraryStore
{
    private const FOLDER_COVERS = ['cover', 'folder', 'front', 'album', 'albumart'];

    private const CHUNK = 262_144;

    /** @var array<string, StoredFile>|null Every file of the folder by path (not only music), filled by files(). */
    private ?array $index = null;

    private ?string $failure = null;

    public function __construct(private User $user, private string $url, private string $token) {}

    /** The address of the API, for messages. */
    public function url(): string
    {
        return $this->url;
    }

    /** What `GET /root` says about the token: name, id, access, expires_at, quota. Throws FerriteException. */
    public function root(): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get($this->api('root')))->json() ?? [];
    }

    public function isAvailable(): bool
    {
        try {
            $this->root();
            $this->failure = null;

            return true;
        } catch (FerriteException $e) {
            $this->failure = $e->getMessage();

            return false;
        }
    }

    public function unavailableReason(): string
    {
        return $this->failure ?? 'Ferrite cannot be used.';
    }

    /** Whether the token may add files, per `GET /root`. Throws FerriteException when Ferrite cannot be used. */
    public function canWrite(): bool
    {
        return str_starts_with($this->root()['access'] ?? 'read', 'read+write');
    }

    /** Whether the token may move files to Ferrite's trash (it never purges), per `GET /root`. */
    public function canTrash(): bool
    {
        return ($this->root()['access'] ?? 'read') === 'read+write+trash';
    }

    public function delete(string $path): void
    {
        $file = $this->locate($path);
        if ($file === null || $file->key === null) {
            return; // not in Ferrite (any more)
        }

        try {
            $this->send(fn (PendingRequest $http) => $http->delete($this->api('files/'.rawurlencode($file->key))), [200, 404]);
        } catch (FerriteException $e) {
            throw new RuntimeException($e->getMessage() === 'The Ferrite token is not allowed to do that.'
                ? 'The Ferrite token cannot move files to the trash. Make a new token in Ferrite with the trash access and run magnetite:storage again.'
                : $e->getMessage(), 0, $e);
        }

        if (($local = $this->cached($file)) !== null) {
            @unlink($local);
        }
        unset($this->index[$path]);
    }

    /**
     * Add a local file at $path, never replacing anything. Returns `added`, `existing` (the same bytes are already
     * there) or `conflict` (a different file is in the way, left alone). Sends the file in the chunks Ferrite asks
     * for, resumes from the offset Ferrite reports after an interruption, then waits until Ferrite has stored it.
     */
    public function put(string $localFile, string $path): string
    {
        $size = (int) filesize($localFile);
        $hash = (string) hash_file('sha256', $localFile);

        $start = $this->send(fn (PendingRequest $http) => $http->post($this->api('uploads'), ['path' => $path, 'size' => $size, 'fingerprint' => $hash]), [200, 201, 409, 422]);

        if ($start->status() === 409) {
            return hash_equals($hash, (string) $start->json('sha256')) ? 'existing' : 'conflict';
        }

        if ($start->status() === 422) {
            throw new FerriteException(collect($start->json('errors') ?? [])->flatten()->first() ?? 'Ferrite refused the upload.');
        }

        $id = (string) $start->json('id');
        $chunk = max(1, (int) $start->json('chunk_size'));
        $offset = (int) $start->json('offset');
        $handle = fopen($localFile, 'rb');
        $state = $start;

        $sent = false;
        $stalled = 0;

        try {
            // An empty file still needs one (empty) chunk to be completed.
            while ($offset < $size || ! $sent) {
                $sent = true;
                fseek($handle, $offset);
                $body = (string) fread($handle, $chunk);

                $state = $this->send(fn (PendingRequest $http) => $http
                    ->withBody($body, 'application/octet-stream')
                    ->withHeaders(['Upload-Offset' => (string) $offset])
                    ->patch($this->api("uploads/{$id}")), [200, 202, 409, 422]);

                if ($state->status() === 422) {
                    return $this->failed($state);
                }

                if ($state->status() === 409) {
                    // Ferrite is at another offset (a chunk that arrived after all) or is busy; carry on from where it really is.
                    if ($state->json('status') !== 'receiving') {
                        break;
                    }

                    $stalled = (int) $state->json('offset') === $offset ? $stalled + 1 : 0;
                    if ($stalled > 5) {
                        throw new FerriteException('Ferrite did not accept the upload.');
                    }
                    sleep($stalled);
                    $offset = (int) $state->json('offset');

                    continue;
                }

                $offset += strlen($body);
            }
        } finally {
            fclose($handle);
        }

        return $this->await($id, $state);
    }

    /** Wait for Ferrite to finish storing an upload (it does so on a queue), up to ten minutes. */
    private function await(string $id, HttpResponse $last): string
    {
        $deadline = time() + 600;

        for ($wait = 0; ; $wait = min(5, $wait + 1)) {
            $status = $last->json('status');

            if ($status === 'done') {
                return 'added';
            }

            if ($status === 'failed') {
                return $this->failed($last);
            }

            if (time() > $deadline) {
                throw new FerriteException('Ferrite is taking too long to store a file.');
            }

            sleep($wait);
            $last = $this->send(fn (PendingRequest $http) => $http->get($this->api("uploads/{$id}")));
        }
    }

    /** A failed upload: a name that was taken meanwhile is a conflict, anything else stops the upload. */
    private function failed(HttpResponse $response): string
    {
        $error = (string) ($response->json('error') ?? $response->json('message') ?? 'Ferrite could not store the file.');

        if (str_contains($error, 'already exists')) {
            return 'conflict';
        }

        throw new FerriteException($error);
    }

    public function files(): iterable
    {
        $this->loadIndex();

        foreach ($this->index as $file) {
            if (TrackLibrary::contentType($file->path) !== null) {
                yield $file;
            }
        }
    }

    public function fetch(string $path): ?string
    {
        $file = $this->locate($path);

        if ($file === null || $file->hash === null || TrackLibrary::contentType($path) === null) {
            return null;
        }

        return $this->cached($file) ?? $this->download($file);
    }

    public function folderCover(string $path): ?string
    {
        $this->loadIndex();
        $dir = dirname($path);

        foreach ($this->index as $candidate) {
            $name = strtolower(pathinfo($candidate->path, PATHINFO_FILENAME));

            if (dirname($candidate->path) === $dir
                && in_array(strtolower(pathinfo($candidate->path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true)
                && in_array($name, self::FOLDER_COVERS, true)
                && $candidate->size < 15_000_000
                && ($local = $this->cached($candidate) ?? $this->download($candidate)) !== null) {
                return File::get($local);
            }
        }

        return null;
    }

    /** The cached local copy of a track, if it is there and still the file Ferrite has. */
    public function cachedFor(Track $track): ?string
    {
        $file = $this->locate($track->path);
        $path = $file === null ? null : $this->cached($file);

        if ($path !== null) {
            $this->pin($file); // being played: not the first thing to go when the cache is full
        }

        return $path;
    }

    /**
     * Pass the bytes of a track through, with Range and If-Range forwarded, so playback starts and seeks without
     * downloading the whole file. The type, `nosniff` and `inline` are Magnetite's own; nothing else Ferrite sends is trusted.
     */
    public function proxy(Request $request, Track $track, string $type): Response
    {
        $file = $this->locate($track->path);
        abort_if($file === null || $file->key === null, 404);

        $headers = array_filter([
            'Range' => $request->header('Range'),
            'If-Range' => $request->header('If-Range'),
            'If-None-Match' => $request->header('If-None-Match'),
        ]);

        try {
            $upstream = $this->send(fn (PendingRequest $http) => $http
                ->withOptions(['stream' => true, 'timeout' => 0, 'read_timeout' => 30])
                ->withHeaders($headers)
                ->get($this->api("files/{$file->key}/content")), [200, 206, 304, 416]);
        } catch (FerriteException $e) {
            abort(502, $e->getMessage());
        }

        $out = [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-cache',
        ];
        foreach (['Content-Range', 'Content-Length', 'ETag'] as $name) {
            if ($upstream->header($name) !== '') {
                $out[$name] = $upstream->header($name);
            }
        }

        $status = $upstream->status();
        if ($status === 304 || $status === 416) {
            return new Response(null, $status, $out);
        }

        return new StreamedResponse(function () use ($upstream) {
            @set_time_limit(0);
            $body = $upstream->toPsrResponse()->getBody();

            while (! $body->eof()) {
                echo $body->read(self::CHUNK);
                flush();
            }
        }, $status, $out);
    }

    private function loadIndex(): void
    {
        if ($this->index !== null) {
            return;
        }

        $index = [];
        $cursor = null;

        do {
            $page = $this->send(fn (PendingRequest $http) => $http->get($this->api('files'), $cursor === null ? [] : ['cursor' => $cursor]))->json();

            foreach ($page['data'] ?? [] as $row) {
                $index[$row['path']] = new StoredFile($row['path'], (int) $row['size'], (int) $row['mtime'], (string) $row['id'], $row['sha256'] ?? null);
            }

            $cursor = $page['next_cursor'] ?? null;
        } while ($cursor !== null);

        $this->index = $index;
    }

    /** The listing entry for a path: from this run's listing, or else from what the last scan stored. */
    private function locate(string $path): ?StoredFile
    {
        if ($this->index !== null) {
            return $this->index[$path] ?? null;
        }

        $track = $this->user->tracks()->where('path', $path)->first(['path', 'size', 'mtime', 'store_key', 'store_hash']);

        return $track?->store_key === null ? null : new StoredFile($track->path, $track->size, $track->mtime, $track->store_key, $track->store_hash);
    }

    private static function cacheFolder(): string
    {
        return Storage::disk('local')->path('ferrite-cache');
    }

    /** What the cache holds (it is shared by every user: files are named by their hash). @return array{files: int, bytes: int} */
    public static function cacheStats(): array
    {
        $files = is_dir(self::cacheFolder()) ? collect(File::files(self::cacheFolder()))->reject(fn ($f) => str_ends_with($f->getFilename(), '.part')) : collect();

        return ['files' => $files->count(), 'bytes' => (int) $files->sum(fn ($f) => $f->getSize())];
    }

    /** Remove every cached file (not the ones still being downloaded). Returns what was removed. @return array{files: int, bytes: int} */
    public static function clearCache(): array
    {
        $before = self::cacheStats();

        if (is_dir(self::cacheFolder())) {
            foreach (File::files(self::cacheFolder()) as $file) {
                if (! str_ends_with($file->getFilename(), '.part')) {
                    @unlink($file->getRealPath());
                }
            }
        }

        return $before;
    }

    private function cacheDir(): string
    {
        return self::cacheFolder();
    }

    private function cachePath(StoredFile $file): string
    {
        return $this->cacheDir().'/'.$file->hash.'.'.strtolower(pathinfo($file->path, PATHINFO_EXTENSION));
    }

    private function cached(StoredFile $file): ?string
    {
        if ($file->hash === null || ! is_file($path = $this->cachePath($file))) {
            return null;
        }

        touch($path); // least recently used goes first when the cache is full

        return $path;
    }

    /**
     * Download a file into the cache under its hash; the name appears only when the bytes are complete and match.
     * One download per file at a time (a scan, an analysis and a prefetch may want the same one): the others wait for it.
     */
    private function download(StoredFile $file): ?string
    {
        if ($file->key === null || $file->hash === null) {
            return null;
        }

        return Cache::lock("ferrite-download:{$file->hash}", 900)->block(600, fn () => $this->cached($file) ?? $this->fetchIntoCache($file));
    }

    private function fetchIntoCache(StoredFile $file): string
    {
        File::ensureDirectoryExists($this->cacheDir());
        $final = $this->cachePath($file);
        $part = $final.'.'.bin2hex(random_bytes(4)).'.part';

        try {
            $this->send(fn (PendingRequest $http) => $http
                ->withOptions(['sink' => $part])
                ->timeout(0)
                ->get($this->api("files/{$file->key}/content")));

            if (! hash_equals($file->hash, (string) hash_file('sha256', $part))) {
                throw new FerriteException('A file from Ferrite did not match its checksum.');
            }

            rename($part, $final);
        } finally {
            @unlink($part);
        }

        $this->prune($final);

        return $final;
    }

    /**
     * Fetch a track into the cache ahead of its turn (see PrefetchTrack). Does nothing for a file that is cached
     * already (it is only pinned again) or that could never fit in the cache.
     */
    public function prefetch(Track $track): bool
    {
        $file = $this->locate($track->path);

        if ($file === null || $file->key === null || $file->hash === null) {
            return false;
        }

        $this->pin($file); // the next track: kept until it has been played

        if ($this->cached($file) !== null) {
            return true;
        }

        if ($file->size > (int) (config('magnetite.ferrite.cache_max_gb') * 1024 ** 3)) {
            return false;
        }

        return $this->download($file) !== null;
    }

    /** The track playing now and the one fetched ahead are not removed to make room, for an hour after they were used. */
    private function pin(StoredFile $file): void
    {
        Cache::put("ferrite-pin:{$file->hash}", true, 3600);
    }

    /** Keep the cache under its size limit by removing the least recently used files, never the one just fetched. */
    private function prune(string $keep): void
    {
        $limit = (int) (config('magnetite.ferrite.cache_max_gb') * 1024 ** 3);
        $files = collect(File::files($this->cacheDir()))->reject(fn ($f) => str_ends_with($f->getFilename(), '.part'));
        $total = $files->sum(fn ($f) => $f->getSize());

        foreach ($files->sortBy(fn ($f) => $f->getMTime()) as $old) {
            if ($total <= $limit) {
                break;
            }

            $hash = strstr($old->getFilename(), '.', true);

            if ($old->getRealPath() !== realpath($keep) && ! Cache::has("ferrite-pin:{$hash}")) {
                $total -= $old->getSize();
                @unlink($old->getRealPath());
            }
        }
    }

    private function api(string $path): string
    {
        return rtrim($this->url, '/').'/api/v1/'.$path;
    }

    /**
     * Send one request with the token, waiting out Ferrite's rate limit, and turn failures into plain words.
     *
     * @param  callable(PendingRequest): HttpResponse  $call
     * @param  list<int>  $accept
     */
    private function send(callable $call, array $accept = [200]): HttpResponse
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $call(Http::withToken($this->token)
                    ->withOptions(['allow_redirects' => false])
                    ->acceptJson()
                    ->connectTimeout(10)
                    ->timeout(60));
            } catch (ConnectionException) {
                throw new FerriteException('Ferrite cannot be reached.');
            }

            if ($response->status() === 429 && $attempt < 5) {
                sleep(min(60, max(1, (int) $response->header('Retry-After'))));

                continue;
            }

            break;
        }

        if (in_array($response->status(), $accept, true)) {
            return $response;
        }

        throw new FerriteException(match ($response->status()) {
            401 => 'The Ferrite token was revoked or has expired.',
            403 => 'The Ferrite token is not allowed to do that.',
            404 => 'The Ferrite folder was deleted or the token cannot see it.',
            429 => 'Ferrite is limiting requests; try again in a minute.',
            default => "Ferrite answered {$response->status()}.",
        });
    }
}
