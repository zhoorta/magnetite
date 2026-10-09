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
            'folder_id' => $this->when($this->user_id === $request->user()?->id, $this->folder_id),
            // The album's dynamic range (mean of its tracks' scores): the owner's, and only once the tracks are loaded and analysed.
            'dr' => $this->when($this->user_id === $request->user()?->id && $this->relationLoaded('tracks'), fn () => $this->dr),
            'cover_url' => $this->coverUrl(),
            'track_count' => $this->tracks_count ?? $this->whenLoaded('tracks', fn () => $this->tracks->count()),
            'tracks' => TrackResource::collection($this->whenLoaded('tracks')),
        ];
    }
}
