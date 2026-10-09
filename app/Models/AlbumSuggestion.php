<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a folder of untagged tracks might be: the guess from the path, and releases found online. See docs/identify-albums.md. */
class AlbumSuggestion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['guess' => 'array', 'candidates' => 'array', 'checked_at' => 'datetime', 'dismissed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
