{{-- One of the owner's folders: a collapsible header with a ⋯ menu, then its subfolders and albums. Expects `$folder`, `$childrenOf`, `$albumsIn`, `$albumCount`. --}}
<section x-data="{
             folderOpen: false,
             init() { this.folderOpen = this.folderIsOpen({{ $folder->id }}); },
             toggle() { this.folderOpen = ! this.folderOpen; this.rememberFolder({{ $folder->id }}, this.folderOpen); },
         }">
    <div class="flex items-center gap-2 rounded-theme border border-white/10 bg-surface px-3 py-2">
        <button type="button" class="flex min-w-0 flex-1 items-center gap-2 text-left" :aria-expanded="folderOpen" @click="toggle()">
            <span class="w-3 shrink-0 text-xs text-muted" x-text="folderOpen ? '▾' : '▸'"></span>
            <span class="truncate font-semibold">{{ $folder->name }}</span>
            <span class="shrink-0 text-xs text-muted">{{ $albumCount[$folder->id] ?? 0 }} {{ Str::plural('album', $albumCount[$folder->id] ?? 0) }}</span>
        </button>
        <x-menu label="Actions for this folder" width="w-48">
            <x-menu.item @click="openFolder('create', { parent_id: {{ $folder->id }} })">New subfolder…</x-menu.item>
            <x-menu.item @click="openFolder('rename', { id: {{ $folder->id }}, name: {{ Illuminate\Support\Js::from($folder->name) }} })">Rename…</x-menu.item>
            <x-menu.item @click="openFolder('move', { id: {{ $folder->id }}, parent_id: {{ $folder->parent_id ?? 'null' }} })">Move to…</x-menu.item>
            <hr class="my-1 border-white/10">
            <x-menu.item danger title="Albums and subfolders move up one level; no music is deleted"
                         @click="removeFolder({{ $folder->id }}, {{ Illuminate\Support\Js::from($folder->name) }})">Delete folder</x-menu.item>
        </x-menu>
    </div>

    <div x-show="folderOpen" x-cloak class="mt-4 space-y-8 border-l border-white/10 pl-4">
        @foreach ($childrenOf[$folder->id] ?? [] as $child)
            @include('pages.partials.folder', ['folder' => $child])
        @endforeach
        @foreach ($albumsIn[$folder->id] ?? [] as $album)
            @include('pages.partials.album-section', ['album' => $album])
        @endforeach
        @if (empty($childrenOf[$folder->id]) && empty($albumsIn[$folder->id]))
            <p class="text-xs text-muted">Empty. Use “Move to folder…” in an album's ⋯ menu to put albums here.</p>
        @endif
    </div>
</section>
