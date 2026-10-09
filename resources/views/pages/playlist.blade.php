<x-layouts.app :title="$playlist->name.' · Magnetite'">
    <div x-data="playlistView({{ $playlist->id }}, @js($playlist->name), @js($tracks->values()), @js($shared))">
        <p class="mb-2 text-sm">@if (request('from') === 'shared')
                <a href="{{ route('shared') }}" wire:navigate class="text-muted hover:text-accent">← Shared</a>
            @else
                <a href="{{ route('playlists') }}" wire:navigate class="text-muted hover:text-accent">← Playlists</a>
            @endif</p>

        <div class="mb-6 flex flex-wrap items-center gap-3">
            <h1 x-show="! editing" class="min-w-0 flex-1 truncate text-2xl font-semibold" x-text="name"></h1>
            <form x-show="editing" x-cloak class="flex min-w-0 flex-1 gap-2" @submit.prevent="rename()">
                <input type="text" x-model="draft" maxlength="120" x-ref="draft" aria-label="Playlist name"
                       class="min-w-0 flex-1 rounded-theme border border-white/10 bg-surface px-2 py-1 text-lg">
                <button type="submit" class="rounded-theme bg-accent px-3 text-sm font-medium text-bg">Save</button>
            </form>

            <button type="button" class="rounded-theme bg-accent px-4 py-1.5 text-sm font-medium text-bg disabled:opacity-40"
                    :disabled="tracks.length === 0" @click="$store.player.setQueue([...tracks], 0)">Play</button>
            <button type="button" class="text-xs text-muted hover:text-accent" x-show="! editing"
                    @click="editing = true; draft = name; $nextTick(() => $refs.draft.select())">Rename</button>
            <button type="button" class="rounded-theme border border-white/10 px-2 py-0.5 text-xs hover:text-accent"
                    :class="shared && 'text-accent'" @click="toggleShare()"
                    :title="shared ? 'Shared with everyone who has an account. Click to stop sharing.' : 'Share this playlist with everyone who has an account'"
                    x-text="shared ? 'Shared' : 'Share'"></button>
            <button type="button" class="text-xs text-muted hover:text-accent" @click="deletePlaylist()">Delete</button>
        </div>

        <div x-show="shared && unshared > 0" x-cloak class="mb-4 flex flex-wrap items-center gap-3 rounded-theme border border-white/10 bg-surface px-3 py-2 text-xs">
            <span class="text-muted" x-text="unshared + (unshared === 1 ? ' track in this playlist is' : ' tracks in this playlist are') + ' not shared, so others see them greyed out and cannot play them.'"></span>
            <button type="button" class="rounded-theme border border-white/10 px-2 py-0.5 hover:text-accent" @click="shareTracks()">Share those tracks too</button>
        </div>
        <p x-show="note" x-text="note" class="mb-4 text-xs text-accent2"></p>

        @if ($hidden > 0)
            <p class="mb-4 rounded-theme border border-white/10 bg-surface px-3 py-2 text-xs text-muted">
                {{ $hidden }} {{ Str::plural('track', $hidden) }} in this playlist can no longer be played by you, because they are not shared any more. They come back if they are shared again.
            </p>
        @endif

        <p x-show="tracks.length === 0" class="text-muted">Empty. Add tracks with the + button in <a href="{{ route('library') }}" wire:navigate class="text-accent">your library</a> or in the <a href="{{ route('shared') }}" wire:navigate class="text-accent">shared music</a>.</p>

        <div x-show="tracks.length" x-ref="list" class="divide-y divide-white/10 rounded-theme border border-white/10 bg-surface">
            <template x-for="(t, i) in tracks" :key="t.id">
                <div data-row class="flex items-center gap-2 pr-3 text-sm">
                    <span data-handle class="shrink-0 cursor-grab touch-none select-none py-2 pl-3 text-muted hover:text-accent" title="Drag to reorder" aria-hidden="true">⠿</span>
                    <button type="button" class="flex min-w-0 flex-1 items-center gap-3 py-2 text-left hover:text-accent"
                            @click="$store.player.setQueue([...tracks], i)">
                        <span class="w-5 shrink-0 text-xs text-muted" x-text="i + 1"></span>
                        <span class="min-w-0 flex-1 truncate"><span :class="$store.player.current?.id === t.id && 'font-bold'" x-text="t.title"></span><span class="text-muted" x-show="t.artist" x-text="' · ' + t.artist"></span></span>
                        <span class="shrink-0 text-xs text-muted" x-show="shared && t.closed" title="Only you can play this track: it is not shared">private</span>
                        <span class="shrink-0 text-xs text-accent2" x-show="$store.player.current?.id === t.id"
                              x-text="$store.player.playing ? 'playing' : 'paused'"></span>
                    </button>
                    <span class="w-10 shrink-0 text-right text-xs tabular-nums text-muted" x-text="$store.player.fmt(t.duration)"></span>
                    <button type="button" class="shrink-0 px-1 text-xs text-muted hover:text-accent disabled:opacity-30" title="Move up" :disabled="i === 0" @click="move(i, -1)">↑</button>
                    <button type="button" class="shrink-0 px-1 text-xs text-muted hover:text-accent disabled:opacity-30" title="Move down" :disabled="i === tracks.length - 1" @click="move(i, 1)">↓</button>
                    <button type="button" class="shrink-0 px-1 text-xs text-muted hover:text-accent" title="Remove from this playlist" @click="remove(i)">&times;</button>
                </div>
            </template>
        </div>
    </div>
</x-layouts.app>
