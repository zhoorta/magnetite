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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

        return $file === null ? null : $this->cached($file);
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

    private function cacheDir(): string
    {
        return Storage::disk('local')->path('ferrite-cache');
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

    /** Download a file into the cache under its hash; the name appears only when the bytes are complete and match. */
    private function download(StoredFile $file): ?string
    {
        if ($file->key === null || $file->hash === null) {
            return null;
        }

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

            if ($old->getRealPath() !== realpath($keep)) {
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
