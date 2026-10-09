@php
    // Players get the plain shape: no file-truth findings here, so a hash can never reveal a file someone keeps privately.
    $rows = fn ($tracks) => $tracks->map(fn ($t) => $t->toPlayer())->values();
    $owner = fn ($user) => $user->id === $me->id ? 'you' : $user->username;
@endphp
<x-layouts.app title="Shared · Magnetite">
    <div x-data="{
            async unshare(kind, id, el) {
                const r = await fetch('/share/' + kind + '/' + id, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                });
                if (r.ok) el.remove();
            },
        }">
        <h1 class="mb-1 text-2xl font-semibold">Shared</h1>
        <p class="mb-6 text-sm text-muted">Music that people on this server share with everyone who has an account. It stays in their library: if they take it away, it disappears here.</p>

        @if ($albums->isEmpty() && $singles->isEmpty() && $playlists->isEmpty())
            <p class="text-muted">Nothing is shared yet. Use the Share button in <a href="{{ route('library') }}" wire:navigate class="text-accent">your library</a>.</p>
        @endif

        <div class="space-y-8">
            @if ($playlists->isNotEmpty())
                <section>
                    <h2 class="mb-2 font-semibold">Playlists</h2>
                    <ul class="divide-y divide-white/10 rounded-theme border border-white/10 bg-surface">
                        @foreach ($playlists as $playlist)
                            <li class="flex items-center gap-3 pr-3">
                                <a href="{{ route('playlists.show', ['playlist' => $playlist, 'from' => 'shared']) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3 px-3 py-3 text-sm hover:text-accent">
                                    <span class="truncate">{{ $playlist->name }}</span>
                                    <span class="shrink-0 text-xs text-muted">{{ $playlist->tracks_count }} {{ Str::plural('track', $playlist->tracks_count) }}</span>
                                    <span class="ml-auto shrink-0 text-xs text-muted">shared by {{ $owner($playlist->user) }}</span>
                                </a>
                                @if ($playlist->user_id === $me->id)
                                    <button type="button" class="shrink-0 rounded-theme border border-white/10 px-2 py-0.5 text-xs hover:text-accent"
                                            @click="unshare('playlists', {{ $playlist->id }}, $el.closest('li'))">Unshare</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @foreach ($albums as $album)
                <section x-data="{ tracks: @js($rows($album->tracks)), expanded: false }" class="flex gap-4">
                    <div class="h-24 w-24 shrink-0 overflow-hidden rounded-theme bg-surface">
                        @if ($album->cover)
                            <img src="{{ $album->coverUrl() }}" alt="" loading="lazy" class="h-full w-full object-cover">
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        <h2 class="cursor-pointer truncate font-semibold" @click="expanded = ! expanded">{{ $album->title }}</h2>
                        <div class="mb-1 flex items-center gap-2 text-sm text-muted">
                            <span class="min-w-0 truncate">{{ $album->displayArtist }}@if ($album->year) · {{ $album->year }}@endif @if ($album->label) · {{ $album->label }}@endif</span>
                            <span class="ml-auto shrink-0 text-xs">shared by {{ $owner($album->user) }}</span>
                            <x-playlist-menu :item="'{ kind: \'album\', id: '.$album->id.', title: '.\Illuminate\Support\Js::from($album->title).' }'"
                                             label="Add every track of this album to a playlist" />
                            @if ($album->user_id === $me->id)
                                <button type="button" class="shrink-0 rounded-theme border border-white/10 px-2 py-0.5 text-xs hover:text-accent"
                                        @click="unshare('albums', {{ $album->id }}, $el.closest('section'))">Unshare</button>
                            @endif
                        </div>

                        <button type="button" class="mb-2 flex items-center gap-1 text-xs text-muted hover:text-accent" :aria-expanded="expanded"
                                @click="expanded = ! expanded">
                            <span x-text="expanded ? '▾' : '▸'"></span><span x-text="$store.player.summary(tracks)"></span>
                        </button>

                        <div x-show="expanded" x-cloak>
                            @include('pages.partials.shared-track-list')
                        </div>
                    </div>
                </section>
            @endforeach

            @if ($singles->isNotEmpty())
                <section>
                    <h2 class="mb-2 font-semibold">Single tracks</h2>
                    <div class="divide-y divide-white/10 rounded-theme border border-white/10 bg-surface">
                        @foreach ($singles as $track)
                            <div x-data="{ tracks: @js([$track->toPlayer()]) }" class="flex items-center gap-2 pr-3 text-sm">
                                <button type="button" class="flex min-w-0 flex-1 items-center gap-3 py-2 pl-3 text-left hover:text-accent"
                                        @click="$store.player.setQueue(tracks, 0)">
                                    <span class="flex-1 truncate" :class="$store.player.current?.id === tracks[0].id && 'font-bold'">{{ $track->title }}<span class="text-muted">@if ($track->artist ?? $track->album?->artist) · {{ $track->artist ?? $track->album?->artist }}@endif</span></span>
                                    <span class="shrink-0 text-xs text-accent2" x-show="$store.player.current?.id === tracks[0].id"
                                          x-text="$store.player.playing ? 'playing' : 'paused'"></span>
                                </button>
                                <x-playlist-menu :item="'{ id: '.$track->id.', title: '.\Illuminate\Support\Js::from($track->title).' }'" />
                                <span class="shrink-0 text-xs text-muted">shared by {{ $owner($track->user) }}</span>
                                @if ($track->user_id === $me->id)
                                    <button type="button" class="shrink-0 text-xs text-muted hover:text-accent"
                                            @click="unshare('tracks', {{ $track->id }}, $el.parentElement)">Unshare</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    </div>

    @include('pages.partials.playlist-picker')
</x-layouts.app>
