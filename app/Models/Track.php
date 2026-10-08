<?php

namespace App\Models;

use App\Services\TranscodeCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Track extends Model
{
    use HasFactory;

    protected $guarded = [];

    /** Codecs the major browsers cannot decode (Safari can play ALAC, Chrome and Firefox cannot). */
    public const UNPLAYABLE_IN_CHROME = ['alac'];

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    /** Playable in any browser: not ALAC, or ALAC that the stream endpoint converts to FLAC. */
    public function getPlayableAttribute(): bool
    {
        return ! in_array($this->codec, self::UNPLAYABLE_IN_CHROME, true)
            || app(TranscodeCache::class)->available();
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
