<x-layouts.app :title="$playlist->name.' · Magnetite'">
    <div x-data="sharedPlaylistView({{ $playlist->id }}, @js($tracks->map(fn ($t) => $t->open ? $t->toPlayer() : ['id' => $t->id, 'title' => $t->title, 'artist' => $t->artist ?? $t->album?->artist, 'duration' => $t->duration])->values()))">
        <p class="mb-2 text-sm"><a href="{{ route('shared') }}" wire:navigate class="text-muted hover:text-accent">← Shared</a></p>

        <div class="mb-1 flex flex-wrap items-center gap-3">
            <h1 class="min-w-0 flex-1 truncate text-2xl font-semibold">{{ $playlist->name }}</h1>
            <button type="button" class="rounded-theme bg-accent px-4 py-1.5 text-sm font-medium text-bg disabled:opacity-40"
                    :disabled="playable.length === 0" @click="play()">Play</button>
            <button type="button" class="rounded-theme border border-white/10 px-3 py-1.5 text-sm hover:text-accent"
                    title="Make a playlist of your own with the tracks you can play" @click="copy()">Copy to my playlists</button>
        </div>
        <p class="mb-6 text-sm text-muted">A playlist shared by {{ $playlist->user_id === auth()->id() ? 'you' : $playlist->user->username }}. It changes when they change it.</p>
        <p x-show="note" x-text="note" class="mb-4 text-xs text-accent2"></p>

        <div class="divide-y divide-white/10 rounded-theme border border-white/10 bg-surface">
            <template x-for="(t, i) in tracks" :key="t.id">
                <div class="flex items-center gap-2 pr-3 text-sm" :class="! t.url && 'opacity-40'">
                    <button type="button" class="flex min-w-0 flex-1 items-center gap-3 py-2 pl-3 text-left enabled:hover:text-accent"
                            :disabled="! t.url" @click="play(t)">
                        <span class="w-5 shrink-0 text-xs text-muted" x-text="i + 1"></span>
                        <span class="min-w-0 flex-1 truncate"><span :class="t.url && $store.player.current?.id === t.id && 'font-bold'" x-text="t.title"></span><span class="text-muted" x-show="t.artist" x-text="' · ' + t.artist"></span></span>
                        <span class="shrink-0 text-xs text-muted" x-show="! t.url" title="This track is not shared with you">not shared</span>
                        <span class="shrink-0 text-xs text-accent2" x-show="t.url && $store.player.current?.id === t.id"
                              x-text="$store.player.playing ? 'playing' : 'paused'"></span>
                    </button>
                    <span class="w-10 shrink-0 text-right text-xs tabular-nums text-muted" x-text="$store.player.fmt(t.duration)"></span>
                    <button type="button" x-show="t.url" class="shrink-0 text-xs text-muted hover:text-accent" title="Add to a playlist"
                            @click="$dispatch('pick-playlist', { id: t.id, title: t.title })">+ Playlist</button>
                </div>
            </template>
        </div>
    </div>

    @include('pages.partials.playlist-picker')
</x-layouts.app>
