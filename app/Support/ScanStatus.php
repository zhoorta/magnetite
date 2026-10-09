<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/** What the library scan of one user is doing, kept in the cache so the web process can show what the queue worker is up to. */
class ScanStatus
{
    private static function key(int $userId): string
    {
        return "magnetite.scan.{$userId}";
    }

    /** @return array{state: string, processed: int, started_at: ?int, finished_at: ?int, counts: ?array, error: ?string} */
    public static function get(int $userId): array
    {
        $s = Cache::get(self::key($userId), []) + [
            'pending' => false, 'running' => false, 'processed' => 0,
            'started_at' => null, 'finished_at' => null, 'counts' => null, 'error' => null,
        ];

        $state = match (true) {
            $s['running'] => 'running',
            $s['pending'] => 'queued',
            $s['error'] !== null => 'failed',
            $s['finished_at'] !== null => 'done',
            default => 'idle',
        };

        return ['state' => $state] + array_intersect_key($s, array_flip(['processed', 'started_at', 'finished_at', 'counts', 'error']));
    }

    /** A job is waiting in the queue. Ignored after an hour, in case the job was lost (queue flushed, worker killed). */
    public static function pending(int $userId): bool
    {
        $s = Cache::get(self::key($userId), []);

        return ($s['pending'] ?? false) && now()->getTimestamp() - ($s['queued_at'] ?? 0) < 3600;
    }

    public static function queued(int $userId): void
    {
        self::merge($userId, ['pending' => true, 'queued_at' => now()->getTimestamp()]);
    }

    public static function started(int $userId): void
    {
        self::merge($userId, ['pending' => false, 'running' => true, 'processed' => 0, 'started_at' => now()->getTimestamp(), 'finished_at' => null, 'counts' => null, 'error' => null]);
    }

    public static function progress(int $userId, int $processed): void
    {
        self::merge($userId, ['processed' => $processed]);
    }

    public static function finished(int $userId, array $counts): void
    {
        self::merge($userId, ['running' => false, 'finished_at' => now()->getTimestamp(), 'counts' => $counts]);
    }

    public static function failed(int $userId, string $message): void
    {
        self::merge($userId, ['running' => false, 'pending' => false, 'finished_at' => now()->getTimestamp(), 'error' => $message]);
    }

    private static function merge(int $userId, array $values): void
    {
        Cache::forever(self::key($userId), $values + Cache::get(self::key($userId), []));
    }
}
