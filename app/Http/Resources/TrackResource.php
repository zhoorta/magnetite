<?php

namespace App\Http\Resources;

use App\Models\Track;
use App\Support\FileTruth;
use App\Support\SharedFlags;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A track as the app sees it. Never a path; `stream_url` is only handed out for tracks the viewer may play. */
class TrackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $open = $this->resource->getAttribute('open') ?? true;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'artist' => $this->artist ?? $this->album?->artist,
            'album' => $this->album?->title,
            'album_id' => $this->album_id,
            'number' => $this->track_number,
            'disc' => $this->disc_number,
            'duration' => $this->duration,
            'codec' => $this->codec,
            'cover_url' => $this->album?->coverUrl(),
            'bitrate' => $this->bitrate,
            'size' => $this->size,
            // The owner's own details: what can be edited, as stored, and the measurements (without the long spectrum).
            'edit' => $this->when($this->user_id === $request->user()?->id, fn () => ['title' => $this->title, 'artist' => $this->artist, 'track_number' => $this->track_number]),
            'truth' => $this->when($this->user_id === $request->user()?->id, fn () => $this->truth($request)),
            'shared' => $this->when($this->user_id === $request->user()?->id, fn () => SharedFlags::for($request)['tracks']->has($this->id)),
            'open' => (bool) $open,
            'stream_url' => $open ? route('stream', $this->id) : null,
        ];
    }

    /**
     * The measurements and findings, with the duplicates found across the owner's whole library (worked out once per request).
     * The long spectrum curve is only sent when asked for (`?spectrum=1`, on one track).
     */
    private function truth(Request $request): array
    {
        $copies = $request->attributes->get('truth_copies');
        if ($copies === null) {
            $copies = FileTruth::copies(Track::where('user_id', $request->user()->id)->get(['id', 'title', 'artist', 'path', 'duration', 'pcm_sha1']));
            $request->attributes->set('truth_copies', $copies);
        }

        $truth = $this->resource->toTruth($copies[$this->id] ?? []);

        return $request->boolean('spectrum') ? $truth : array_diff_key($truth, ['spectrum' => 1, 'step_hz' => 1]);
    }
}
