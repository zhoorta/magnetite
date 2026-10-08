<x-layouts.app title="Library · Magnetite">
    <h1 class="mb-6 text-2xl font-semibold">Library</h1>

    @if ($albums->isEmpty() && $loose->isEmpty())
        <p class="text-muted">Nothing here yet. Set <code>MAGNETITE_MUSIC_PATH</code> and run <code>php artisan magnetite:scan</code>.</p>
    @endif

    <div class="space-y-8">
        @foreach ($albums as $album)
            <section x-data="{ tracks: @js($album->tracks->map->toPlayer()->values()) }" class="flex gap-4">
                <div class="h-24 w-24 shrink-0 overflow-hidden rounded-theme bg-surface">
                    @if ($album->cover)
                        <img src="{{ route('cover', $album) }}" alt="" loading="lazy" class="h-full w-full object-cover">
                    @endif
                </div>

                <div class="min-w-0 flex-1">
                    <h2 class="truncate font-semibold">{{ $album->title }}</h2>
                    <p class="mb-2 truncate text-sm text-muted">{{ $album->displayArtist }}@if ($album->year) · {{ $album->year }}@endif</p>

                    <div class="divide-y divide-white/10 rounded-theme border border-white/10 bg-surface">
                        <template x-for="(t, i) in tracks" :key="t.id">
                            <button type="button"
                                    class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:text-accent"
                                    @click="$store.player.setQueue(tracks, i)">
                                <span class="w-5 text-xs text-muted" x-text="i + 1"></span>
                                <span class="flex-1 truncate" x-text="t.title"></span>
                                <span class="text-xs text-accent2" x-show="$store.player.current?.id === t.id"
                                      x-text="$store.player.playing ? 'playing' : 'paused'"></span>
                                <span class="text-xs text-muted" x-show="! t.playable" title="This browser cannot decode ALAC and ffmpeg is not available to convert it">ALAC</span>
                                <span class="w-10 text-right text-xs tabular-nums text-muted" x-text="$store.player.fmt(t.duration)"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </section>
        @endforeach

        @if ($loose->isNotEmpty())
            <section x-data="{ tracks: @js($loose->map->toPlayer()->values()) }">
                <h2 class="mb-2 font-semibold">Without album tag</h2>
                <div class="divide-y divide-white/10 rounded-theme border border-white/10 bg-surface">
                    <template x-for="(t, i) in tracks" :key="t.id">
                        <button type="button"
                                class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:text-accent"
                                @click="$store.player.setQueue(tracks, i)">
                            <span class="w-5 text-xs text-muted" x-text="i + 1"></span>
                            <span class="flex-1 truncate" x-text="t.title"></span>
                            <span class="text-xs text-accent2" x-show="$store.player.current?.id === t.id"
                                  x-text="$store.player.playing ? 'playing' : 'paused'"></span>
                            <span class="w-10 text-right text-xs tabular-nums text-muted" x-text="$store.player.fmt(t.duration)"></span>
                        </button>
                    </template>
                </div>
            </section>
        @endif
    </div>
</x-layouts.app>
