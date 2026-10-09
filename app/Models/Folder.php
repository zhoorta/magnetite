<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A folder of the owner's own, for sorting albums into "Jazz" and its subfolders. Nothing on disk changes. */
class Folder extends Model
{
    /** Folders inside folders, at most this deep. */
    public const MAX_DEPTH = 6;

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function albums(): HasMany
    {
        return $this->hasMany(Album::class);
    }

    /** 1 for a folder at the top. */
    public function depth(): int
    {
        $depth = 1;
        for ($f = $this; $f->parent_id !== null && $depth <= self::MAX_DEPTH + 1; $depth++) {
            $f = $f->parent;
        }

        return $depth;
    }

    /** This folder's id and those of every folder below it. */
    public function selfAndDescendantIds(): array
    {
        $ids = [$this->id];
        $level = [$this->id];
        while ($level !== []) {
            $level = self::where('user_id', $this->user_id)->whereIn('parent_id', $level)->pluck('id')->all();
            $ids = array_merge($ids, $level);
        }

        return $ids;
    }

    /** How many levels of folders lie below this one (0 for a folder without subfolders). */
    public function heightBelow(): int
    {
        $height = 0;
        $level = [$this->id];
        while ($level = self::where('user_id', $this->user_id)->whereIn('parent_id', $level)->pluck('id')->all()) {
            $height++;
        }

        return $height;
    }
}
