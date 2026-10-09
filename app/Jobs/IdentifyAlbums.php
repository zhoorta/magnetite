<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AlbumIdentifier;
use App\Support\IdentifyStatus;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Looks at the folders of one user's tracks without an album tag and keeps a suggestion for each (a few requests per folder, one a second). */
class IdentifyAlbums implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public int $maxExceptions = 1;

    public function __construct(public int $userId, public bool $retry = false) {}

    /** Ask for a look. One already waiting in the queue will see every folder, so nothing is added. */
    public static function request(User $user, bool $retry = false): void
    {
        if (IdentifyStatus::pending($user->id) && config('queue.default') !== 'sync') {
            return;
        }

        IdentifyStatus::queued($user->id);
        static::dispatch($user->id, $retry);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("magnetite-identify-{$this->userId}"))->releaseAfter(15)->expireAfter(6 * 3600)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function handle(AlbumIdentifier $identifier): void
    {
        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }

        $folders = $identifier->pending($user, $this->retry);
        IdentifyStatus::started($user->id, $folders->count());

        $found = 0;
        $done = 0;
        foreach ($folders as $key => $tracks) {
            $suggestion = $identifier->suggest($user, $key, $tracks);
            $found += count($suggestion->candidates) > 1 ? 1 : 0;
            IdentifyStatus::progress($user->id, ++$done, $found);
        }

        IdentifyStatus::finished($user->id);
    }

    public function failed(Throwable $e): void
    {
        IdentifyStatus::finished($this->userId);
    }
}
