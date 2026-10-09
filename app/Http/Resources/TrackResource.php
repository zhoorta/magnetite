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
            'cover_url' => $this->album?->cover ? route('cover', $this->album_id) : null,
            'open' => (bool) $open,
            'stream_url' => $open ? route('stream', $this->id) : null,
        ];
    }
}
