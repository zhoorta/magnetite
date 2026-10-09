<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AlbumResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'artist' => $this->artist,
            'year' => $this->year,
            'release_date' => $this->release_date,
            'label' => $this->label,
            'cover_url' => $this->coverUrl(),
            'track_count' => $this->tracks_count ?? $this->whenLoaded('tracks', fn () => $this->tracks->count()),
            'tracks' => TrackResource::collection($this->whenLoaded('tracks')),
        ];
    }
}
