<?php

namespace App\Models;

use App\Services\TranscodeCache;
use App\Support\FileTruth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Track extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['analysis' => 'array', 'analyzed_at' => 'datetime', 'identified' => 'boolean'];
    }

    /** Codecs the major browsers cannot decode (Safari can play ALAC, Chrome and Firefox cannot). */
    public const UNPLAYABLE_IN_CHROME = ['alac'];

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function share(): HasOne
    {
        return $this->hasOne(SharedItem::class);
    }

    /**
     * The one rule for who may use a track (stream, cover, queue): their own tracks, the tracks of an album in the
     * shared area, and tracks shared one by one. Listings are separate; see docs/multi-user.md.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('tracks.user_id', $user->id)
            ->orWhereHas('share')
            ->orWhereHas('album', fn (Builder $a) => $a->whereHas('share')));
    }

    /** Playable in any browser: not ALAC, or ALAC that the stream endpoint converts to FLAC. */
    public function getPlayableAttribute(): bool
    {
        return ! in_array($this->codec, self::UNPLAYABLE_IN_CHROME, true)
            || app(TranscodeCache::class)->available();
    }

    /**
     * What the library page shows about the file: the measurements in plain units, and the findings from
     * FileTruth. `$copies` comes from FileTruth::copies() for the whole library.
     */
    public function toTruth(array $copies = []): array
    {
        $a = $this->analysis;
        if (! is_array($a)) {
            return ['analyzed' => false, 'flags' => []];
        }

        $spectrum = $a['spectrum'] ?? [];

        return [
            'analyzed' => true,
            'dr' => $this->dr,
            'dr_class' => FileTruth::drClass($this->dr),
            'lufs' => $a['loudness']['integrated'] ?? null,
            'lra' => $a['loudness']['range'] ?? null,
            'true_peak' => $a['loudness']['true_peak_db'] ?? null,
            'sample_peak' => $a['loudness']['sample_peak_db'] ?? null,
            'clipped_runs' => $a['clipping']['runs'] ?? 0,
            'sample_rate' => $a['sample_rate'] ?? null,
            'bits' => $a['effective_bits'] ?? null,
            'declared_bits' => $a['source']['declared_bits'] ?? null,
            'cutoff_hz' => $spectrum['cutoff_hz'] ?? null,
            'content_hz' => $spectrum['content_hz'] ?? null,
            'spectrum' => $spectrum['db'] ?? [],
            'step_hz' => $spectrum['step_hz'] ?? 500,
            'flags' => FileTruth::flags($this, $copies),
        ];
    }

    /** Player shape plus the file-truth findings, for the library page. */
    public function toLibrary(array $copies = []): array
    {
        return $this->toPlayer() + ['truth' => $this->toTruth($copies)];
    }

    /** Shape sent to the player. */
    public function toPlayer(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'artist' => $this->artist ?? $this->album?->artist,
            'album' => $this->album?->title,
            'cover' => $this->album?->cover ? route('cover', $this->album) : null,
            'duration' => $this->duration,
            'codec' => $this->codec,
            'playable' => $this->playable,
            'url' => route('stream', $this),
        ];
    }
}
