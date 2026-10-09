<x-layouts.app title="Playlists · Magnetite">
    <h1 class="mb-1 text-2xl font-semibold">Playlists</h1>
    <p class="mb-6 text-sm text-muted">Only you see your playlists. Add tracks with the + Playlist button in your library or in the shared music.</p>

    <form x-data="playlistCreate()" @submit.prevent="create()" class="mb-6 flex gap-2">
        <input type="text" x-model="name" maxlength="120" placeholder="New playlist" aria-label="Name of the new playlist"
               class="min-w-0 flex-1 rounded-theme border border-white/10 bg-surface px-3 py-2 text-sm">
        <button type="submit" class="rounded-theme bg-accent px-4 py-2 text-sm font-medium text-bg">Create</button>
        <p x-show="error" x-text="error" class="self-center text-xs text-accent2"></p>
    </form>

    @if ($playlists->isEmpty())
        <p class="text-muted">No playlists yet.</p>
    @else
        <ul class="divide-y divide-white/10 rounded-theme border border-white/10 bg-surface">
            @foreach ($playlists as $playlist)
                <li>
                    <a href="{{ route('playlists.show', $playlist) }}" wire:navigate class="flex items-center justify-between px-4 py-3 hover:text-accent">
                        <span class="truncate">{{ $playlist->name }}@if ($playlist->share) <span class="ml-2 text-xs text-accent">shared</span>@endif</span>
                        <span class="shrink-0 text-xs text-muted">{{ $playlist->tracks_count }} {{ Str::plural('track', $playlist->tracks_count) }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.app>
