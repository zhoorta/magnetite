<?php

namespace App\Jobs;

use App\Models\Album;
use App\Models\User;
use App\Services\CoverFinder;
use App\Support\CoverStatus;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Sleep;
use Throwable;

/** Looks up the missing covers of one user's albums online, one album per second (MusicBrainz asks for no more). */
class FindCovers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public int $maxExceptions = 1;

    public function __construct(public int $userId, public bool $retry = false) {}

    /** Ask for a search. One already waiting in the queue will see every album, so nothing is added. */
    public static function request(User $user, bool $retry = false): void
    {
        if (CoverStatus::pending($user->id) && config('queue.default') !== 'sync') {
            return;
        }

        CoverStatus::queued($user->id);
        static::dispatch($user->id, $retry);
    }

    /** Albums without a cover; unless asked to retry, the ones already searched are left alone. */
    public static function missing(User $user, bool $retry = false)
    {
        return Album::query()->where('user_id', $user->id)->whereNull('cover')
            ->when(! $retry, fn ($q) => $q->whereNull('cover_checked_at'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("magnetite-find-covers-{$this->userId}"))->releaseAfter(15)->expireAfter(6 * 3600)];
    }

    /** A job that finds the lock taken is released and tried again; without this it would fail on the first release (one attempt by default). */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function handle(CoverFinder $finder): void
    {
        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }

        $albums = static::missing($user, $this->retry)->with('tracks')->get();
        CoverStatus::started($user->id, $albums->count());

        $found = 0;
        foreach ($albums as $i => $album) {
            if ($i > 0) {
                Sleep::for(1)->second();
            }

            $found += $finder->fill($album) !== null ? 1 : 0;
            CoverStatus::progress($user->id, $i + 1, $found);
        }

        CoverStatus::finished($user->id);
    }

    public function failed(Throwable $e): void
    {
        CoverStatus::finished($this->userId);
    }
}
