<?php

namespace App\Http\Resources;

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
            'truth' => $this->when($this->user_id === $request->user()?->id, fn () => array_diff_key($this->resource->toTruth(), ['spectrum' => 1])),
            'open' => (bool) $open,
            'stream_url' => $open ? route('stream', $this->id) : null,
        ];
    }
}
