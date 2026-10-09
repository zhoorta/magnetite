{{-- "Add to playlist" dialog, opened by `$dispatch('pick-playlist', { id, title })` from a track row, or `{ kind: 'album', id, title }` from an album. --}}
<div x-data="playlistPicker(@js(auth()->user()->playlists()->orderBy('name')->get(['id', 'name'])))"
     @pick-playlist.window="open($event.detail)" @keydown.escape.window="close()"
     x-show="item" x-cloak
     class="fixed inset-0 z-20 flex items-center justify-center bg-black/60 p-4" @click.self="close()"
     role="dialog" aria-label="Add to a playlist">
    <div class="w-full max-w-sm rounded-theme border border-white/10 bg-surface p-5 text-sm shadow-xl">
        <h2 class="mb-1 font-semibold" x-text="item?.kind === 'album' ? 'Add the album to a playlist' : 'Add to a playlist'"></h2>
        <p class="mb-3 truncate text-xs text-muted" x-text="item?.title"></p>

        <ul class="mb-3 max-h-56 divide-y divide-white/10 overflow-y-auto rounded-theme border border-white/10">
            <template x-for="p in playlists" :key="p.id">
                <li><button type="button" class="w-full px-3 py-2 text-left hover:text-accent" @click="add(p)" x-text="p.name"></button></li>
            </template>
            <li x-show="playlists.length === 0" class="px-3 py-2 text-muted">No playlists yet. Make the first one below.</li>
        </ul>

        <form class="flex gap-2" @submit.prevent="create()">
            <input type="text" x-model="name" maxlength="120" placeholder="New playlist"
                   class="min-w-0 flex-1 rounded-theme border border-white/10 bg-bg px-2 py-1">
            <button type="submit" class="rounded-theme bg-accent px-3 py-1 font-medium text-bg">Create</button>
        </form>

        <p x-show="note" x-text="note" class="mt-3 text-xs text-accent2"></p>
        <button type="button" class="mt-3 text-xs text-muted hover:text-accent" @click="close()">Close</button>
    </div>
</div>
