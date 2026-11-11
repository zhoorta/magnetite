import { Alpine } from '../../../../vendor/livewire/livewire/dist/livewire.esm';
import { send } from '../http';
import { clamp } from './rows';
import { LIBRARY_ONLY, moveItem, stepSource } from './sources';

/**
 * What the library does with its other sources, Shared and Playlists, merged into the component (plain methods only: a spread would
 * copy a getter's value, so the getters are in component.js). The data is what Sources.php sends; `this` is the component.
 */
export const sourceMethods = {
    /** Make `name` the shown source with `payload` ({ folders, albums, loose }); `select` is a row key to land on. */
    useSource(name, payload, select = null) {
        if (this.source === 'library' && name !== 'library') this.own = { folders: this.folders, albums: this.albums, loose: this.loose, shares: this.shares };
        this.source = name;
        this.folders = payload.folders;
        this.albums = payload.albums;
        this.loose = payload.loose ?? [];
        this.shares = name === 'library' ? payload.shares : { albums: payload.albums.filter((a) => a.playlist && a.mine && a.shared).map((a) => a.id), tracks: [] };
        this.marks = [];
        this.trackMarks = [];
        this.selectMode = false;
        this.detail = false;
        this.pane = 'tree';
        this.tsel = 0;
        this.gsel = 0;
        this.gridLimit = 60;
        // people are few: show them all open (not saved); the library keeps its own saved state
        if (name !== 'library') this.openIds = this.folders.map((f) => f.id);
        else this.openIds = this.folders.filter((f) => this.storedOpen(f.id)).map((f) => f.id);
        const key = select ?? this.cursors[name] ?? null;
        this.rebuild();
        if (key) {
            const at = this.rows.findIndex((r) => r.key === key);
            if (at >= 0) this.cur = at;
        } else this.cur = clamp(this.rows, 0);
        this.scrollToSelection();
    },

    /** Switch source: what was fetched before shows at once, and the server's answer replaces it a moment later. */
    async setSource(name, select = null) {
        if (name === this.source && !select) return;
        if (this.mode !== 'tree') this.closeView();
        this.cursors[this.source] = this.row?.key ?? null;
        if (name === 'library') {
            this.useSource('library', this.own, select);
            if (this.libraryStale) {
                this.libraryStale = false;
                await this.reload();
            }
        } else {
            this.useSource(name, this.cache[name] ?? { folders: [], albums: [], loose: [] }, select);
            await this.refreshSource(select);
        }
        this.focusRoot();
        // the address follows, so a reload (or a bookmark) opens the same source
        try {
            history.replaceState(history.state, '', { library: '/library', shared: '/shared', playlists: '/playlists' }[name]);
        } catch {}
    },

    cycleSource(delta) {
        return this.setSource(stepSource(this.source, delta));
    },

    /** Fetch the shown source again (after a change of yours, or on arriving), keeping the selection where it still exists. */
    async refreshSource(select = null) {
        const name = this.source;
        if (name === 'library') return this.reload();
        const r = await fetch(`/sources/${name}`, { cache: 'no-store', headers: { Accept: 'application/json' } }).catch(() => null);
        if (!r || !r.ok) return this.say('Could not load it');
        this.cache[name] = await r.json();
        if (this.source !== name) return;
        const key = select ?? this.row?.key ?? null;
        const pane = this.pane;
        const tsel = this.tsel;
        this.useSource(name, this.cache[name], key);
        if (!select && pane === 'tracks' && this.tracks.length) {
            this.pane = 'tracks';
            this.tsel = clamp(this.tracks, tsel);
        }
    },

    /** Switch to your own library without fetching (what search and the truth view need: they read it). */
    useLibrary() {
        if (this.source === 'library') return;
        this.cursors[this.source] = this.row?.key ?? null;
        this.useSource('library', this.own);
    },

    /** A key or command in a source other than the library: true when it was handled here. */
    runSource(action) {
        const album = this.shown;
        const playlists = this.source === 'playlists';
        switch (action) {
            case 'copy': this.copyToLibrary(); return true;
            case 'share': this.shareInSource(); return true;
            case 'new-folder':
                if (playlists) this.newPlaylist();
                else this.say('Read only: new folders are made in your library');

                return true;
            case 'rename': this.renamePlaylist(); return true;
            case 'delete':
                if (!playlists) this.say('Read only here. S stops sharing something of yours');
                else if (this.pane === 'tracks') this.removeFromPlaylist();
                else this.deletePlaylist();

                return true;
            case 'shift-down': this.moveTrack(1); return true;
            case 'shift-up': this.moveTrack(-1); return true;
            default:
                if (!LIBRARY_ONLY.has(action)) return false;
                if (action === 'mark' && playlists) return true;
                this.say(album?.mine || album?.playlist ? 'Only your own library can be changed that way' : 'Read only: copy it to your library first (y)');

                return true;
        }
    },

    /** A command line command in a source other than the library: true when handled. */
    runSourceCommand(name, arg) {
        switch (name) {
            case 'new': return this.source === 'playlists' ? (this.newPlaylist(arg), true) : (this.say('Read only: new folders are made in your library'), true);
            case 'rename': return this.source === 'playlists' ? (this.renamePlaylist(arg), true) : (this.say('Read only'), true);
            case 'delete': return this.runSource('delete');
            case 'share': return this.shareInSource(true), true;
            case 'unshare': return this.shareInSource(false), true;
            case 'copy': return this.copyToLibrary(), true;
            case 'sharetracks': return this.shareTracksToo(), true;
            default:
                return ['analyze', 'details', 'edit', 'cover', 'merge', 'group', 'suggest', 'move'].includes(name) ? (this.runSource('edit'), true) : false;
        }
    },

    // ---- Shared: copy to your library, stop sharing ----

    /** What `y` copies: the selected album or track of Shared into your library, or a shared playlist into your playlists. */
    async copyToLibrary() {
        const album = this.shown;
        if (this.source === 'playlists') {
            if (!album?.playlist) return this.say('Select a playlist');
            if (album.mine) return this.say('That is your own playlist');
            const r = await send('POST', `/playlists/${album.id}/copy`);
            if (!r.ok) return this.say('Could not copy it');
            await this.refreshSource(`a${r.data.id}`);

            return this.say(`Copied to your playlists (${r.data.tracks} ${r.data.tracks === 1 ? 'track' : 'tracks'} you can play)`);
        }
        let kind = null;
        let item = null;
        let mine = false;
        if (this.pane === 'tracks' && this.tracks[this.tsel]) {
            kind = 'tracks';
            item = this.tracks[this.tsel];
            mine = album?.loose ? item.mine : album?.mine;
        } else if (this.row?.kind === 'album') {
            kind = 'albums';
            item = this.row.album;
            mine = item.mine;
        }
        if (!item) return this.say('Select an album or a track to copy');
        if (mine) return this.say('That is already in your library');
        const r = await send('POST', `/copy/${kind}/${item.id}`);
        if (!r.ok) return this.say(r.data?.message || 'Could not copy');
        this.libraryStale = true;

        return this.say((r.data.added ? 'Copied to your library' : 'Already in your library') + (r.data.failed ? ` (${r.data.failed} failed)` : ''));
    },

    /** `S`: stop sharing your own album, track or playlist (Shared), or share or unshare a playlist of yours (Playlists). */
    async shareInSource(on = null) {
        const album = this.shown;
        if (this.source === 'playlists') {
            if (!album?.playlist || !album.mine) return this.say('Select a playlist of yours');
            const want = on ?? !album.shared;
            const r = await send(want ? 'POST' : 'DELETE', `/share/playlists/${album.id}`);
            if (!r.ok) return this.say('Could not change sharing');
            album.shared = r.data.shared;
            this.shares.albums = album.shared ? [...this.shares.albums, album.id] : this.shares.albums.filter((id) => id !== album.id);

            return this.say(album.shared ? 'Playlist shared with everybody' : 'Playlist is private again');
        }
        if (on === true) return this.say('This is already shared');
        let kind = null;
        let id = null;
        if (this.row?.kind === 'album' && this.row.album.mine) [kind, id] = ['albums', this.row.id];
        else if (this.row?.kind === 'loose' && this.pane === 'tracks' && this.tracks[this.tsel]?.mine) [kind, id] = ['tracks', this.tracks[this.tsel].id];
        if (!kind) return this.say('You can only stop sharing what is yours');
        const r = await send('DELETE', `/share/${kind}/${id}`);
        if (!r.ok) return this.say('Could not stop sharing');
        this.libraryStale = true;
        await this.refreshSource();

        return this.say('Not shared any more');
    },

    // ---- Playlists ----

    newPlaylist(name = null) {
        if (!name) return this.openFd('playlist-create');

        return this.createPlaylist(name);
    },

    async createPlaylist(name) {
        const r = await send('POST', '/playlists', { name });
        if (!r.ok) return (this.fd ? (this.fd.error = r.data?.errors?.name?.[0] || 'Could not create it') : this.say('Could not create it'), false);
        await this.refreshSource(`a${r.data.id}`);

        return true;
    },

    renamePlaylist(name = null) {
        const album = this.shown;
        if (!album?.playlist || !album.mine) return this.say('Select a playlist of yours');
        if (!name) return this.openFd('playlist-rename', { id: album.id, name: album.title });

        return this.savePlaylistName(album.id, name);
    },

    async savePlaylistName(id, name) {
        const r = await send('PATCH', `/playlists/${id}`, { name });
        if (!r.ok) return (this.fd ? (this.fd.error = r.data?.errors?.name?.[0] || 'Could not save it') : this.say('Could not rename it'), false);
        await this.refreshSource(`a${id}`);

        return true;
    },

    async deletePlaylist() {
        const album = this.shown;
        if (!album?.playlist || !album.mine) return this.say('Select a playlist of yours');
        const sure = await Alpine.store('dialog').confirm({
            title: 'Delete this playlist?',
            message: `“${album.title}” will be deleted. The music stays in the library.`,
            confirmLabel: 'Delete',
            danger: true,
        });
        if (!sure) return;
        const r = await send('DELETE', `/playlists/${album.id}`);
        if (!r.ok) return this.say('Could not delete it');
        this.cursors.playlists = null;
        await this.refreshSource();
    },

    async removeFromPlaylist() {
        const album = this.shown;
        const track = this.tracks[this.tsel];
        if (!album?.playlist || !album.mine || !track) return this.say('Select a track of a playlist of yours');
        const before = album.tracks;
        album.tracks = before.filter((t) => t.id !== track.id);
        album.unshared = album.tracks.filter((t) => t.closed).length;
        this.tsel = clamp(album.tracks, this.tsel);
        if (!album.tracks.length) this.pane = 'tree';
        const r = await send('DELETE', `/playlists/${album.id}/tracks/${track.id}`);
        if (!r.ok) {
            album.tracks = before;
            album.unshared = before.filter((t) => t.closed).length;
            this.say('Could not remove it');
        }
    },

    /** Move the selected track of a playlist one step (Shift+J, Shift+K), or to `to` (drag). */
    async moveTrack(by, to = null) {
        const album = this.shown;
        if (!album?.playlist || !album.mine || this.pane !== 'tracks') return this.say('Open your playlist\'s tracks (Tab) to reorder them');
        const from = this.tsel;
        const target = to ?? from + by;
        const next = moveItem(album.tracks, from, target);
        if (next === album.tracks) return;
        const before = album.tracks;
        album.tracks = next;
        this.tsel = clamp(next, target);
        this.scrollToSelection();
        const r = await send('PUT', `/playlists/${album.id}/order`, { ids: next.map((t) => t.id) });
        if (!r.ok) {
            album.tracks = before;
            this.say('Could not save the order');
        }
    },

    /** Sortable drops a row: put the element back and change the array instead, so Alpine stays the one that renders the list. */
    async dropped({ item, from, oldIndex, oldDraggableIndex, newDraggableIndex }) {
        from.removeChild(item);
        from.insertBefore(item, from.children[oldIndex] ?? null);
        if (oldDraggableIndex === newDraggableIndex) return;
        this.tsel = oldDraggableIndex;
        this.pane = 'tracks';
        await this.moveTrack(0, newDraggableIndex);
    },

    async shareTracksToo() {
        const album = this.shown;
        if (!album?.playlist || !album.mine) return this.say('Select a playlist of yours');
        const r = await send('POST', `/playlists/${album.id}/share-tracks`);
        if (!r.ok) return this.say('Could not share them');
        album.tracks.forEach((t) => (t.closed = false));
        album.unshared = 0;
        this.libraryStale = true;

        return this.say(`${r.data.shared} ${r.data.shared === 1 ? 'track' : 'tracks'} shared`);
    },

    /** The new-playlist and rename dialog (it shares the folder dialog). */
    async savePlaylistDialog() {
        const d = this.fd;
        d.saving = true;
        d.error = '';
        const name = d.name.trim();
        const ok = !name ? (d.error = 'A name is needed', false) : d.mode === 'playlist-create' ? await this.createPlaylist(name) : await this.savePlaylistName(d.id, name);
        d.saving = false;
        if (ok) {
            this.fd = null;
            this.focusRoot();
        }
    },
};
