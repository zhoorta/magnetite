<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Album extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cover_tried' => 'array'];
    }

    /** The cover's address. `v` changes with the picture, so the browser (which may keep a cover for a year) fetches a replaced one. */
    public function coverUrl(): ?string
    {
        return $this->cover ? route('cover', ['album' => $this->id, 'v' => substr($this->cover, 0, 10)]) : null;
    }

    /** Album artist if tagged, else the single track artist, else "Various artists". */
    public function getDisplayArtistAttribute(): ?string
    {
        if ($this->artist !== null) {
            return $this->artist;
        }

        $artists = $this->tracks->pluck('artist')->filter()->unique();

        return match (true) {
            $artists->count() === 1 => $artists->first(),
            $artists->count() > 1 => 'Various artists',
            default => null,
        };
    }

    /** Album dynamic range: the rounded mean of the track scores, as the DR database does. Null until analysed. */
    public function getDrAttribute(): ?int
    {
        $scores = $this->tracks->pluck('dr')->filter(fn ($v) => $v !== null);

        return $scores->isEmpty() ? null : (int) round($scores->avg());
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class)->orderByRaw('coalesce(disc_number, 1)')->orderBy('track_number')->orderBy('title');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function share(): HasOne
    {
        return $this->hasOne(SharedItem::class);
    }

    /** Albums the user may use: their own, and the ones in the shared area. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('albums.user_id', $user->id)
            ->orWhereHas('share')
            ->orWhereHas('tracks', fn (Builder $t) => $t->whereHas('share')));
    }
}
