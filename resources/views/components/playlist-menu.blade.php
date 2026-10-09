{{-- The "+" of a track or album: a dropdown of the user's playlists. `item` is a JS expression, `{ id, title }` or `{ kind: 'album', id, title }`.
     "New playlist…" opens the dialog of the playlist-picker partial (include it on the page). --}}
@props(['item', 'label' => 'Add to a playlist'])
<x-menu :label="$label" width="w-56">
    <x-slot:trigger>
        <button type="button" x-ref="trigger" @click="toggle()" :aria-expanded="open" aria-haspopup="menu" title="{{ $label }}" aria-label="{{ $label }}"
                class="shrink-0 px-1 text-sm leading-none text-muted hover:text-accent focus-visible:text-accent">+</button>
    </x-slot:trigger>
    <div x-data="{ note: '' }">
        <p x-show="note" x-text="note" class="px-3 py-1.5 text-xs text-accent2"></p>
        <div x-show="! note" class="max-h-56 overflow-y-auto">
            <template x-for="p in $store.playlists.items" :key="p.id">
                <button type="button" role="menuitem" tabindex="-1" x-text="p.name"
                        class="block w-full truncate px-3 py-1.5 text-left hover:bg-white/5 hover:text-accent focus-visible:bg-white/5 focus-visible:outline-none"
                        @click.stop="note = await $store.playlists.add(p, {{ $item }}); setTimeout(() => { note = ''; close(); }, 900)"></button>
            </template>
            <p x-show="$store.playlists.items.length === 0" class="px-3 py-1.5 text-xs text-muted">No playlists yet.</p>
        </div>
        <div x-show="! note">
            <hr class="my-1 border-white/10">
            <x-menu.item @click="$dispatch('pick-playlist', {{ $item }})">New playlist…</x-menu.item>
        </div>
    </div>
</x-menu>
