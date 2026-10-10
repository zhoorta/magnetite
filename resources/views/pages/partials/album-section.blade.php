{{-- One album of the library page: cover, details, ⋯ menu and the collapsible track list. Expects `$album` and the page's helpers. --}}
<section x-data="{
            tracks: @js($rows($album->tracks)), expanded: false,
            cover: @js((bool) $album->cover), web: @js($album->cover_source !== null), v: @js(substr((string) $album->cover, 0, 10)),
            searching: false, noMatch: false, note: '',
            // No cover yet: look one up. A cover from the web: look for a different match (the old one stays when there is none).
            async findCover() {
                const another = this.cover;
                this.searching = true; this.noMatch = false; this.note = '';
                const r = await fetch('{{ route('cover.find', $album) }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' } }).catch(() => null);
                this.searching = false;
                const body = r && r.ok ? await r.json() : null;
                if (body && body.source) {
                    this.cover = true; this.web = true; this.v = String(Date.now());
                    this.note = another ? 'Cover replaced' : '';
                } else if (another) this.note = r && r.ok ? 'No other match found' : 'Could not search';
                else this.noMatch = true;
                if (this.note) setTimeout(() => this.note = '', 4000);
            },
         }"
         class="flex gap-4">
    <div class="h-24 w-24 shrink-0 overflow-hidden rounded-theme bg-surface">
        <template x-if="cover"><img :src="'{{ route('cover', $album) }}?v=' + v" alt="" loading="lazy" class="h-full w-full object-cover"></template>
        @unless ($album->cover)
            <button type="button" x-show="! cover" class="flex h-full w-full items-center justify-center p-1 text-center text-xs text-muted hover:text-accent"
                    title="Look this cover up online (MusicBrainz, iTunes). Only artist and album title are sent."
                    :disabled="searching" @click="findCover()"
                    x-text="searching ? 'Searching…' : noMatch ? 'No match' : 'Find cover'"></button>
        @endunless
    </div>

    <div class="min-w-0 flex-1">
        <h2 class="flex cursor-pointer items-center gap-1.5 font-semibold" @click="expanded = ! expanded"><span class="truncate">{{ $album->title }}</span>
            <x-playing-icon x-show="$store.player.current?.album_id === {{ $album->id }}" x-cloak title="Playing from this album" />
            <svg x-show="isShared('albums', {{ $album->id }})" x-cloak viewBox="0 0 16 16" class="h-3.5 w-3.5 shrink-0 text-muted" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Shared"><title>Shared with everyone who has an account</title><circle cx="4" cy="8" r="1.8"/><circle cx="12" cy="3.5" r="1.8"/><circle cx="12" cy="12.5" r="1.8"/><path d="M5.6 7.1l4.8-2.7M5.6 8.9l4.8 2.7"/></svg></h2>
        <div class="mb-1 flex items-center gap-2 text-sm text-muted">
            <span x-show="note" x-text="note" x-cloak class="shrink-0 text-xs text-accent2"></span>
            <span class="min-w-0 truncate">{{ $album->displayArtist }}@if ($album->year) · {{ $album->year }}@endif @if ($album->label) · {{ $album->label }}@endif</span>
            @if ($album->dr !== null)
                <span class="shrink-0 rounded-theme px-1.5 py-0.5 text-xs font-medium tabular-nums"
                      :class="drClass('{{ App\Support\FileTruth::drClass($album->dr) }}')"
                      title="Album dynamic range: the average of the track scores. Higher is more dynamic.">DR{{ $album->dr }}</span>
            @endif
            <div class="ml-auto shrink-0">
                <x-playlist-menu :item="'{ kind: \'album\', id: '.$album->id.', title: '.\Illuminate\Support\Js::from($album->title).' }'"
                                 label="Add every track of this album to a playlist" />
            </div>
            <x-menu label="Actions for this album" width="w-56">
                @if (! $album->cover || $album->cover_source)
                    <x-menu.item x-bind:disabled="searching" @click="findCover()"
                                 title="Look this cover up online (MusicBrainz, iTunes). Only artist and album title are sent. If the cover is not the right one, search again for another match."
                                 x-text="searching ? 'Searching…' : cover ? 'Find another cover' : 'Find cover'"></x-menu.item>
                @endif
                @if ($needsAnalysis($album))
                    <x-menu.item title="Measure dynamic range, clipping, loudness and the spectrum of this album's files. Files are only read."
                                 @click="analyse({ album_id: {{ $album->id }} })">Analyse album</x-menu.item>
                @endif
                <x-menu.item title="Look this album up on MusicBrainz: release date, record label and track titles. Only the album title and artist are sent. Nothing changes until you confirm."
                             @click="findTitles({{ $album->id }}, {{ Illuminate\Support\Js::from($album->title) }})">Find details online…</x-menu.item>
                <x-menu.item title="Correct the title, artist, release date or record label. Only what this library shows changes; the files are not touched."
                             @click="openEdit('albums', {{ $album->id }}, {{ Illuminate\Support\Js::from(['title' => $album->title, 'artist' => $album->artist, 'release_date' => $album->released, 'label' => $album->label]) }})">Edit details…</x-menu.item>
                <x-menu.item title="Put this album in one of your folders. Only how the library is arranged changes; the files stay where they are."
                                 @click="openFolder('album', { id: {{ $album->id }}, parent_id: {{ $album->folder_id ?? 'null' }} })">Move to folder…</x-menu.item>
                    <x-menu.item @click="toggleShare('albums', {{ $album->id }})"
                             title="Shared albums are visible to everyone who has an account"
                             x-text="isShared('albums', {{ $album->id }}) ? 'Stop sharing' : 'Share this album'"></x-menu.item>
                <hr class="my-1 border-white/10">
                <x-menu.item danger title="{{ $trashOnly ? 'Move this album to the trash in Ferrite' : 'Delete this album from the music folder' }}"
                             @click="remove('albums', {{ $album->id }}, {{ Illuminate\Support\Js::from($album->title) }}, {{ $album->tracks->count() }})">Delete album</x-menu.item>
            </x-menu>
        </div>

        <button type="button" class="mb-2 flex items-center gap-1 text-xs text-muted hover:text-accent" :aria-expanded="expanded"
                @click="expanded = ! expanded">
            <span x-text="expanded ? '▾' : '▸'"></span><span x-text="$store.player.summary(tracks)"></span>
        </button>

        <div x-show="expanded" x-cloak>
            @include('pages.partials.track-list')
        </div>
    </div>
</section>
