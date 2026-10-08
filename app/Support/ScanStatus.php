<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/** What the library scan is doing, kept in the cache so the web process can show what the queue worker is up to. */
class ScanStatus
{
    private const KEY = 'magnetite.scan';

    /** @return array{state: string, processed: int, started_at: ?int, finished_at: ?int, counts: ?array, error: ?string} */
    public static function get(): array
    {
        $s = Cache::get(self::KEY, []) + [
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
    public static function pending(): bool
    {
        $s = Cache::get(self::KEY, []);

        return ($s['pending'] ?? false) && now()->getTimestamp() - ($s['queued_at'] ?? 0) < 3600;
    }

    public static function queued(): void
    {
        self::merge(['pending' => true, 'queued_at' => now()->getTimestamp()]);
    }

    public static function started(): void
    {
        self::merge(['pending' => false, 'running' => true, 'processed' => 0, 'started_at' => now()->getTimestamp(), 'finished_at' => null, 'counts' => null, 'error' => null]);
    }

    public static function progress(int $processed): void
    {
        self::merge(['processed' => $processed]);
    }

    public static function finished(array $counts): void
    {
        self::merge(['running' => false, 'finished_at' => now()->getTimestamp(), 'counts' => $counts]);
    }

    public static function failed(string $message): void
    {
        self::merge(['running' => false, 'pending' => false, 'finished_at' => now()->getTimestamp(), 'error' => $message]);
    }

    private static function merge(array $values): void
    {
        Cache::forever(self::KEY, $values + Cache::get(self::KEY, []));
    }
}
