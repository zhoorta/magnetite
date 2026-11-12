<?php

namespace App\Support;

use App\Models\SharedItem;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Which of the signed-in user's albums and tracks are in the shared area, found once per request for the API resources. */
class SharedFlags
{
    /** @return array{albums: Collection<int, int>, tracks: Collection<int, int>} ids as keys */
    public static function for(Request $request): array
    {
        $flags = $request->attributes->get('shared_flags');
        if ($flags === null) {
            $items = SharedItem::where('user_id', $request->user()->id)->get(['album_id', 'track_id']);
            $flags = [
                'albums' => $items->pluck('album_id')->filter()->flip(),
                'tracks' => $items->pluck('track_id')->filter()->flip(),
            ];
            $request->attributes->set('shared_flags', $flags);
        }

        return $flags;
    }
}
