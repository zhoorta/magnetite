<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Album extends Model
{
    use HasFactory;

    protected $guarded = [];

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

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class)->orderByRaw('coalesce(disc_number, 1)')->orderBy('track_number')->orderBy('title');
    }
}
