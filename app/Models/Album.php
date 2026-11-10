<?php

namespace App\Models;

use App\Support\AlbumKey;
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

    protected static function booted(): void
    {
        // The comparison form of the title, kept with the title wherever an album is created or renamed.
        static::saving(function (Album $album) {
            $album->title_key = AlbumKey::title($album->title);
            $album->search_key = mb_substr(AlbumKey::searchKey($album->title, $album->artist), 0, 600);
        });
    }

    protected function casts(): array
    {
        return ['cover_tried' => 'array'];
    }

    /** A date as MusicBrainz and the edit form write it: `2001`, `2001-03` or `2001-03-12`. */
    public const DATE_PATTERN = '/^\d{4}(-(0[1-9]|1[0-2])(-(0[1-9]|[12]\d|3[01]))?)?$/';

    /**
     * Library order: by the artist that is shown (the tagged album artist, else the single artist of the tracks, else "Various
     * artists", the same rule as `display_artist`), then year, then title. Sorting on the `artist` column alone put every
     * album without an album-artist tag first, whatever its tracks' artist was.
     */
    public function scopeInLibraryOrder(Builder $query): void
    {
        $tracks = '(select count(distinct t.artist) from tracks t where t.album_id = albums.id)';
        $first = '(select min(t.artist) from tracks t where t.album_id = albums.id)';
        $query->orderByRaw("lower(coalesce(albums.artist, case when {$tracks} > 1 then 'Various artists' else {$first} end, ''))")
            ->orderBy('albums.year')->orderByRaw('lower(albums.title)');
    }

    /** Albums in the shared area, in library order, with who shares them. The listing of /shared and of `GET /api/v1/shared`. */
    public function scopeShared(Builder $query): void
    {
        $query->with('user')->whereHas('share')->inLibraryOrder();
    }

    /** What the pages show for when the album came out: the full date when known, else the year. */
    public function getReleasedAttribute(): ?string
    {
        return $this->release_date ?? ($this->year !== null ? (string) $this->year : null);
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
