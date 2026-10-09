<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\SharedItem;
use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Puts an album or a track of the signed-in user into the shared area, or takes it out. Only the owner can. */
class ShareController extends Controller
{
    public function store(Request $request, string $kind, int $id): JsonResponse
    {
        [$column, $item] = $this->find($request, $kind, $id);

        SharedItem::firstOrCreate([$column => $item->id], ['user_id' => $request->user()->id]);

        return response()->json(['shared' => true]);
    }

    public function destroy(Request $request, string $kind, int $id): JsonResponse
    {
        [$column, $item] = $this->find($request, $kind, $id);

        SharedItem::where($column, $item->id)->delete();

        return response()->json(['shared' => false]);
    }

    /** @return array{0: string, 1: Album|Track}; 404 for anything that is not the user's own. */
    private function find(Request $request, string $kind, int $id): array
    {
        $model = match ($kind) {
            'albums' => Album::class,
            'tracks' => Track::class,
            default => abort(404),
        };

        $item = $model::findOrFail($id);
        abort_unless($request->user()->can('share', $item), 404);

        return [$kind === 'albums' ? 'album_id' : 'track_id', $item];
    }
}
