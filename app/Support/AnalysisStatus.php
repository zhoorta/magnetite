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

        return ($s['jobs'] ?? 0) > 0 && now()->getTimestamp() - ($s['queued_at'] ?? 0) < 3600;
    }

    /** An album that is waiting for the worker or being measured now. */
    public static function albumActive(int $userId, int $albumId): bool
    {
        return in_array(Cache::get(self::key($userId), [])['albums'][$albumId]['state'] ?? null, ['queued', 'running'], true);
    }

    /** A job was put on the queue. `$albums` maps an album id to its number of tracks to measure; albums already waiting keep their place. */
    public static function queued(int $userId, array $albums = []): void
    {
        $s = Cache::get(self::key($userId), []);
        $known = $s['albums'] ?? [];
        foreach ($albums as $id => $n) {
            if (! in_array($known[$id]['state'] ?? null, ['queued', 'running'], true)) {
                $known[$id] = ['state' => 'queued', 'done' => 0, 'failed' => 0, 'total' => $n];
            }
        }

        self::put($userId, ['jobs' => (self::pending($userId) ? $s['jobs'] : 0) + 1, 'pending' => true, 'queued_at' => now()->getTimestamp(), 'finished' => false, 'albums' => $known]);
    }

    /** The worker took a job. Albums of other jobs still waiting keep their "queued"; `$albums` maps an album id to its tracks in this job. */
    public static function started(int $userId, int $total, array $albums = []): void
    {
        $s = Cache::get(self::key($userId), []);
        $known = $s['albums'] ?? [];
        foreach ($albums as $id => $n) {
            $known[$id] = ['state' => 'queued', 'done' => 0, 'failed' => 0, 'total' => $n];
        }
        $jobs = max(0, ($s['jobs'] ?? 1) - 1);

        self::put($userId, [
            'jobs' => $jobs, 'pending' => $jobs > 0, 'running' => true, 'total' => $total, 'done' => 0, 'failed' => 0, 'finished' => false, 'current' => null, 'updated_at' => now()->getTimestamp(),
            'albums' => $known,
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

    /** Albums still marked as waiting that have nothing left to measure (another job did it) are done. `$stillMissing` lists the album ids that do. */
    public static function settle(int $userId, array $stillMissing): void
    {
        $albums = Cache::get(self::key($userId), [])['albums'] ?? [];
        foreach ($albums as $id => $a) {
            if ($a['state'] === 'queued' && ! in_array($id, $stillMissing, true)) {
                $albums[$id]['state'] = 'done';
            }
        }
        self::put($userId, ['albums' => $albums]);
    }

    /** `$current` is the file being measured now, so a long track shows that it is still working. */
    public static function progress(int $userId, int $done, int $failed, ?string $current = null): void
    {
        self::put($userId, ['done' => $done, 'failed' => $failed, 'current' => $current, 'updated_at' => now()->getTimestamp()]);
    }

    /** This job is over; the run is only done when no other job is waiting. */
    public static function finished(int $userId): void
    {
        $waiting = self::pending($userId);
        self::put($userId, ['running' => false, 'finished' => ! $waiting, 'current' => null]);
    }

    private static function put(int $userId, array $values): void
    {
        Cache::forever(self::key($userId), $values + Cache::get(self::key($userId), []));
    }
}
