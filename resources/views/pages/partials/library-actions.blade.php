{{-- The actions for what is selected (a folder, an album, a track, tracks without an album): used by the toolbar's ⋯ menu and by the right-click popup, so they cannot drift apart. The keys are shown beside each. --}}
        <x-menu.item x-show="ctx === 'track' || ctx === 'pl-track' || ctx === 'ro-track'" @click="playTrack(tsel); focusRoot()">Play <span class="ml-auto text-xs opacity-60">Enter</span></x-menu.item>
        <x-menu.item x-show="ctx === 'album' || ctx === 'track'" @click="editSelected()">Edit… <span class="ml-auto text-xs opacity-60">e</span></x-menu.item>
        <x-menu.item x-show="ctx === 'album' || ctx === 'loose' || ctx === 'track'" :title="ctx === 'track' ? 'Identify the track by its fingerprint: title, artist, track number' : 'Look the album up online: release date, label, track titles'" @click="openDetails()"><span x-text="ctx === 'loose' ? 'Suggest albums…' : ctx === 'track' ? 'Look up this track…' : 'Look up online…'"></span> <span class="ml-auto text-xs opacity-60">i</span></x-menu.item>
        <x-menu.item x-show="ctx === 'album'" @click="findCover(); focusRoot()">Find cover <span class="ml-auto text-xs opacity-60">C</span></x-menu.item>
        <x-menu.item x-show="ctx === 'album' || ctx === 'folder' || ctx === 'track'" @click="analyseSelected(); focusRoot()">Analyse <span class="ml-auto text-xs opacity-60">A</span></x-menu.item>
        <x-menu.item x-show="ctx === 'album' || ctx === 'track'" @click="shareSelected(); focusRoot()"><span x-text="(ctx === 'track' ? tracks[tsel] && isShared('tracks', tracks[tsel].id) : row?.kind === 'album' && isShared('albums', row.id)) ? 'Stop sharing' : 'Share'"></span> <span class="ml-auto text-xs opacity-60">S</span></x-menu.item>
        <x-menu.item x-show="ctx === 'album' || ctx === 'folder'" @click="openMover()">Move to a folder… <span class="ml-auto text-xs opacity-60">v</span></x-menu.item>
        <x-menu.item x-show="ctx === 'loose'" @click="openGroup()">Name an album… <span class="ml-auto text-xs opacity-60">:group</span></x-menu.item>
        {{-- Shared and Playlists: what can be done there (docs/dense-library.md, "Sources") --}}
        <x-menu.item x-show="ctx === 'sh-other' || (source === 'shared' && ctx === 'ro-track')" @click="copyToLibrary(); focusRoot()">Copy to my library <span class="ml-auto text-xs opacity-60">y</span></x-menu.item>
        <x-menu.item x-show="ctx === 'sh-mine'" @click="shareInSource(); focusRoot()">Stop sharing <span class="ml-auto text-xs opacity-60">S</span></x-menu.item>
        <x-menu.item x-show="ctx === 'pl-other'" @click="copyToLibrary(); focusRoot()">Copy to my playlists <span class="ml-auto text-xs opacity-60">y</span></x-menu.item>
        <x-menu.item x-show="ctx === 'pl-mine'" @click="renamePlaylist(); focusRoot()">Rename playlist <span class="ml-auto text-xs opacity-60">r</span></x-menu.item>
        <x-menu.item x-show="ctx === 'pl-mine'" @click="shareInSource(); focusRoot()"><span x-text="shown?.shared ? 'Stop sharing' : 'Share with everyone on this server'"></span> <span class="ml-auto text-xs opacity-60">S</span></x-menu.item>
        <x-menu.item x-show="ctx === 'pl-track'" @click="moveTrack(-1); focusRoot()">Move up <span class="ml-auto text-xs opacity-60">K</span></x-menu.item>
        <x-menu.item x-show="ctx === 'pl-track'" @click="moveTrack(1); focusRoot()">Move down <span class="ml-auto text-xs opacity-60">J</span></x-menu.item>
        <x-menu.item x-show="source === 'playlists'" @click="newPlaylist()">New playlist <span class="ml-auto text-xs opacity-60">N</span></x-menu.item>
        <x-menu.item danger x-show="ctx === 'pl-mine'" @click="deletePlaylist()">Delete playlist <span class="ml-auto text-xs opacity-60">d</span></x-menu.item>
        <x-menu.item danger x-show="ctx === 'pl-track'" @click="removeFromPlaylist(); focusRoot()">Remove from playlist <span class="ml-auto text-xs opacity-60">d</span></x-menu.item>
        <hr class="my-1 border-white/10" x-show="ctx !== null">
        <x-menu.item x-show="source === 'library' && ! demo && ctx !== 'track'" @click="newFolder()">New folder <span class="ml-auto text-xs opacity-60">N</span></x-menu.item>
        <x-menu.item x-show="ctx === 'folder'" @click="renameFolder()">Rename folder <span class="ml-auto text-xs opacity-60">r</span></x-menu.item>
        <x-menu.item danger x-show="ctx === 'album' || ctx === 'folder' || ctx === 'track'" @click="deleteSelected()">Delete <span class="ml-auto text-xs opacity-60">d</span></x-menu.item>
