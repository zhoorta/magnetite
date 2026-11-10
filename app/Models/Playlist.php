<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Playlist extends Model
{
    protected $guarded = [];

    /** Playlists in the shared area by name, with their owner and track count. */
    public function scopeShared(Builder $query): void
    {
        $query->with('user')->withCount('tracks')->whereHas('share')->orderBy('name');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function share(): HasOne
    {
        return $this->hasOne(SharedItem::class);
    }

    /** In playlist order. Use playableBy() for what a person may actually play. */
    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class)->withPivot('position')->orderByPivot('position');
    }

    /**
     * The tracks the user can still play. A track that was shared when it was added may have been unshared since;
     * it stays in the playlist but is left out here, so a playlist never reveals or plays what the user cannot use.
     */
    public function playableBy(User $user)
    {
        return $this->tracks()->visibleTo($user)->with('album')->get();
    }

    /**
     * The tracks in playlist order, each with `playable` for this person. A viewer sees the whole list, but only the
     * tracks they could play anyway are playable (own, or shared): sharing a playlist never opens a file.
     */
    public function tracksFor(User $user)
    {
        $tracks = $this->tracks()->with('album')->get();
        $open = Track::visibleTo($user)->whereIn('tracks.id', $tracks->modelKeys())->pluck('tracks.id')->flip();

        return $tracks->each(fn (Track $t) => $t->setAttribute('open', $open->has($t->id)));
    }

    /** Tracks of this playlist that belong to its owner and are not shared, so others cannot play them. */
    public function unsharedOwnTracks()
    {
        return $this->tracks()
            ->where('tracks.user_id', $this->user_id)
            ->whereDoesntHave('share')
            ->whereDoesntHave('album', fn ($q) => $q->whereHas('share'));
    }

    public function nextPosition(): int
    {
        return (int) $this->tracks()->max('position') + 1;
    }
}
