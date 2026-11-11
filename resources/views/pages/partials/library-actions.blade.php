{{-- The actions for what is selected (a folder, an album, a track, tracks without an album): used by the toolbar's ⋯ menu and by the right-click popup, so they cannot drift apart. The keys are shown beside each. --}}
        <x-menu.item x-show="ctx === 'track'" @click="playTrack(tsel); focusRoot()">Play <span class="ml-auto text-xs opacity-60">Enter</span></x-menu.item>
        <x-menu.item x-show="ctx === 'album' || ctx === 'track'" @click="editSelected()">Edit… <span class="ml-auto text-xs opacity-60">e</span></x-menu.item>
        <x-menu.item x-show="ctx === 'album' || ctx === 'loose'" title="Look the album up online: release date, label, track titles" @click="openDetails()"><span x-text="ctx === 'loose' ? 'Suggest albums…' : 'Look up online…'"></span> <span class="ml-auto text-xs opacity-60">i</span></x-menu.item>
        <x-menu.item x-show="ctx === 'album'" @click="findCover(); focusRoot()">Find cover <span class="ml-auto text-xs opacity-60">C</span></x-menu.item>
        <x-menu.item x-show="ctx === 'album' || ctx === 'folder' || ctx === 'track'" @click="analyseSelected(); focusRoot()">Analyse <span class="ml-auto text-xs opacity-60">A</span></x-menu.item>
        <x-menu.item x-show="ctx === 'album' || ctx === 'track'" @click="shareSelected(); focusRoot()"><span x-text="(ctx === 'track' ? tracks[tsel] && isShared('tracks', tracks[tsel].id) : row?.kind === 'album' && isShared('albums', row.id)) ? 'Stop sharing' : 'Share'"></span> <span class="ml-auto text-xs opacity-60">S</span></x-menu.item>
        <x-menu.item x-show="ctx === 'loose'" @click="openGroup()">Name an album… <span class="ml-auto text-xs opacity-60">:group</span></x-menu.item>
        <hr class="my-1 border-white/10" x-show="ctx !== null">
        <x-menu.item x-show="ctx !== 'track'" @click="newFolder()">New folder <span class="ml-auto text-xs opacity-60">N</span></x-menu.item>
        <x-menu.item x-show="ctx === 'folder'" @click="renameFolder()">Rename folder <span class="ml-auto text-xs opacity-60">r</span></x-menu.item>
        <x-menu.item danger x-show="ctx === 'album' || ctx === 'folder' || ctx === 'track'" @click="deleteSelected()">Delete <span class="ml-auto text-xs opacity-60">d</span></x-menu.item>
