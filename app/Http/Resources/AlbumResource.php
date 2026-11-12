<?php

namespace App\Http\Resources;

use App\Support\SharedFlags;
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
            // Is it in the shared area (the owner's albums only; one query per request).
            'shared' => $this->when($this->user_id === $request->user()?->id, fn () => SharedFlags::for($request)['albums']->has($this->id)),
            'folder_id' => $this->when($this->user_id === $request->user()?->id, $this->folder_id),
            // The album's dynamic range (mean of its tracks' scores): the owner's, and only once the tracks are loaded and analysed.
            // In a list the average comes from the query (`dr_avg`), with the tracks loaded from the tracks themselves.
            'dr' => $this->when($this->user_id === $request->user()?->id && ($this->relationLoaded('tracks') || isset($this->dr_avg)), fn () => $this->relationLoaded('tracks') ? $this->dr : (int) round($this->dr_avg)),
            // Tracks with a problem or a warning; only in the list, where the tracks are not loaded.
            'problems' => $this->when($this->user_id === $request->user()?->id && isset($this->problems), fn () => (int) $this->problems),
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
