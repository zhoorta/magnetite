<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/** What the cover search of one user is doing, kept in the cache so the web process can show what the queue worker is up to. */
class CoverStatus
{
    private static function key(int $userId): string
    {
        return "magnetite.covers.{$userId}";
    }

    /** @return array{state: string, total: int, done: int, found: int} */
    public static function get(int $userId): array
    {
        $s = Cache::get(self::key($userId), []) + ['pending' => false, 'queued_at' => 0, 'running' => false, 'total' => 0, 'done' => 0, 'found' => 0, 'finished' => false];

        return [
            'state' => match (true) {
                $s['running'] => 'running',
                self::pending($userId) => 'queued',
                $s['finished'] => 'done',
                default => 'idle',
            },
        ] + array_intersect_key($s, array_flip(['total', 'done', 'found']));
    }

    /** A job is waiting in the queue. Ignored after an hour, in case the job was lost. */
    public static function pending(int $userId): bool
    {
        $s = Cache::get(self::key($userId), []);

        return ($s['pending'] ?? false) && now()->getTimestamp() - ($s['queued_at'] ?? 0) < 3600;
    }

    public static function queued(int $userId): void
    {
        self::put($userId, ['pending' => true, 'queued_at' => now()->getTimestamp()]);
    }

    public static function started(int $userId, int $total): void
    {
        self::put($userId, ['pending' => false, 'running' => true, 'total' => $total, 'done' => 0, 'found' => 0, 'finished' => false]);
    }

    public static function progress(int $userId, int $done, int $found): void
    {
        self::put($userId, ['done' => $done, 'found' => $found]);
    }

    public static function finished(int $userId): void
    {
        self::put($userId, ['running' => false, 'pending' => false, 'finished' => true]);
    }

    private static function put(int $userId, array $values): void
    {
        Cache::forever(self::key($userId), $values + Cache::get(self::key($userId), []));
    }
}
