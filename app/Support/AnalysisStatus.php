<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/** What the file analysis of one user is doing, kept in the cache so the web process can show what the queue worker is up to. */
class AnalysisStatus
{
    private static function key(int $userId): string
    {
        return "magnetite.analysis.{$userId}";
    }

    /** @return array{state: string, total: int, done: int, failed: int} */
    public static function get(int $userId): array
    {
        $s = Cache::get(self::key($userId), []) + ['pending' => false, 'queued_at' => 0, 'running' => false, 'total' => 0, 'done' => 0, 'failed' => 0, 'finished' => false, 'current' => null, 'updated_at' => 0, 'albums' => []];

        return [
            'state' => match (true) {
                $s['running'] && ! self::stale($s) => 'running',
                self::pending($userId) => 'queued',
                $s['finished'] => 'done',
                default => 'idle',
            },
        ] + array_intersect_key($s, array_flip(['total', 'done', 'failed', 'current', 'albums']));
    }

    /** A running job that has said nothing for 40 minutes (longer than one track may take) was killed with the worker. */
    private static function stale(array $s): bool
    {
        return now()->getTimestamp() - ($s['updated_at'] ?? 0) > 2400;
    }

    /** A job is waiting in the queue. Ignored after an hour, in case the job was lost. */
    public static function pending(int $userId): bool
    {
        $s = Cache::get(self::key($userId), []);

        return ($s['pending'] ?? false) && now()->getTimestamp() - ($s['queued_at'] ?? 0) < 3600;
    }

    /** `$albums` maps an album id to its number of tracks waiting, so the library can mark every album as queued. */
    public static function queued(int $userId, array $albums = []): void
    {
        self::put($userId, [
            'pending' => true, 'queued_at' => now()->getTimestamp(), 'finished' => false, 'running' => false,
            'albums' => array_map(fn (int $n) => ['state' => 'queued', 'done' => 0, 'failed' => 0, 'total' => $n], $albums),
        ]);
    }

    /** `$albums` maps an album id to its number of tracks in this run. */
    public static function started(int $userId, int $total, array $albums = []): void
    {
        self::put($userId, [
            'pending' => false, 'running' => true, 'total' => $total, 'done' => 0, 'failed' => 0, 'finished' => false, 'current' => null, 'updated_at' => now()->getTimestamp(),
            'albums' => array_map(fn (int $n) => ['state' => 'queued', 'done' => 0, 'failed' => 0, 'total' => $n], $albums),
        ]);
    }

    /** One track of an album is being measured or has been: the album is running, and done with its last track. */
    public static function album(int $userId, int $albumId, int $done, int $failed, bool $finished): void
    {
        $albums = Cache::get(self::key($userId), [])['albums'] ?? [];
        if (! isset($albums[$albumId])) {
            return;
        }
        $albums[$albumId] = [
            'state' => $finished ? ($failed ? 'failed' : 'done') : 'running', 'done' => $done, 'failed' => $failed,
        ] + $albums[$albumId];
        self::put($userId, ['albums' => $albums]);
    }

    /** `$current` is the file being measured now, so a long track shows that it is still working. */
    public static function progress(int $userId, int $done, int $failed, ?string $current = null): void
    {
        self::put($userId, ['done' => $done, 'failed' => $failed, 'current' => $current, 'updated_at' => now()->getTimestamp()]);
    }

    public static function finished(int $userId): void
    {
        self::put($userId, ['running' => false, 'pending' => false, 'finished' => true, 'current' => null]);
    }

    private static function put(int $userId, array $values): void
    {
        Cache::forever(self::key($userId), $values + Cache::get(self::key($userId), []));
    }
}
