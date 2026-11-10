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
            // What to show: the album artist if tagged, else the one artist of its tracks, else "Various artists" (like the web's list).
            'display_artist' => $this->displayArtist(),
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

    /** Without a tagged album artist, from the track counts of the list query, or from the loaded tracks; never a query per album. */
    private function displayArtist(): ?string
    {
        if ($this->artist !== null) {
            return $this->artist;
        }

        if (isset($this->artist_count)) {
            return match (true) {
                (int) $this->artist_count === 1 => $this->first_artist,
                (int) $this->artist_count > 1 => 'Various artists',
                default => null,
            };
        }

        return $this->relationLoaded('tracks') ? $this->display_artist : null;
    }
}
