import { Alpine, Livewire } from '../../../vendor/livewire/livewire/dist/livewire.esm';
import Sortable from 'sortablejs';
import { send } from './http';

/**
 * The "add to playlist" dialog. A track row asks for it with `$dispatch('pick-playlist', { id, title })`, an album header with
 * `{ kind: 'album', id, title }`.
 */
export function playlistPicker(playlists) {
    return {
        playlists,
        item: null,
        name: '',
        note: '',

        open(item) {
            this.item = item;
            this.name = '';
            this.note = '';
        },

        close() {
            this.item = null;
        },

        /** What goes to the server: an album adds all its tracks you can play. */
        get target() {
            return this.item.kind === 'album' ? { album_id: this.item.id } : { track_id: this.item.id };
        },

        /** What the note says after adding: `added` is true or false for a track, a count for an album. */
        said(added, name) {
            if (this.item.kind !== 'album') return added ? `Added to ${name}.` : `Already in ${name}.`;

            return added ? `Added ${added} ${added === 1 ? 'track' : 'tracks'} to ${name}.` : `Already in ${name}.`;
        },

        async add(playlist) {
            const r = await send('POST', `/playlists/${playlist.id}/tracks`, this.target);
            this.note = r.ok ? this.said(r.data.added, playlist.name) : 'Could not add it.';
            if (r.ok) setTimeout(() => this.close(), 900);
        },

        async create() {
            const name = this.name.trim();
            if (!name) return;

            const r = await send('POST', '/playlists', { name, ...this.target });
            if (!r.ok) {
                this.note = 'Could not create it.';

                return;
            }

            this.playlists = [...this.playlists, { id: r.data.id, name: r.data.name }].sort((a, b) => a.name.localeCompare(b.name));
            this.note = `Created ${r.data.name}.`;
            setTimeout(() => this.close(), 900);
        },
    };
}

/** One playlist's page: play, reorder, remove, rename and delete. `tracks` are in the player's shape. */
export function playlistView(id, name, tracks, shared) {
    return {
        id,
        name,
        tracks,
        shared,
        note: '',
        editing: false,
        draft: name,
        sortable: null,

        // Alpine calls init() and destroy() by themselves (on creation and when the page is left).
        init() {
            this.$nextTick(() => {
                if (!this.$refs.list) return;

                this.sortable = Sortable.create(this.$refs.list, {
                    handle: '[data-handle]',
                    draggable: '[data-row]',
                    animation: 150,
                    ghostClass: 'opacity-40',
                    // The browser's own drag and drop does not work with touch; this works with mouse, pen and finger alike.
                    forceFallback: true,
                    onEnd: (e) => this.dropped(e),
                });
            });
        },

        destroy() {
            this.sortable?.destroy();
        },

        /**
         * A row was dropped. Sortable has moved the element itself; put it back and change the array instead, so Alpine
         * stays the one that renders the list.
         */
        async dropped({ item, from, oldIndex, oldDraggableIndex, newDraggableIndex }) {
            from.removeChild(item);
            from.insertBefore(item, from.children[oldIndex] ?? null);

            if (oldDraggableIndex === newDraggableIndex) return;

            const before = [...this.tracks];
            const [moved] = this.tracks.splice(oldDraggableIndex, 1);
            this.tracks.splice(newDraggableIndex, 0, moved);

            const r = await send('PUT', `/playlists/${this.id}/order`, { ids: this.tracks.map((t) => t.id) });
            if (!r.ok) this.tracks = before;
        },

        async remove(i) {
            const [track] = this.tracks.splice(i, 1);
            const r = await send('DELETE', `/playlists/${this.id}/tracks/${track.id}`);
            if (!r.ok) this.tracks.splice(i, 0, track);
        },

        async move(i, by) {
            const j = i + by;
            if (j < 0 || j >= this.tracks.length) return;

            [this.tracks[i], this.tracks[j]] = [this.tracks[j], this.tracks[i]];
            await send('PUT', `/playlists/${this.id}/order`, { ids: this.tracks.map((t) => t.id) });
        },

        /** Tracks of yours in this playlist that others cannot play yet. */
        get unshared() {
            return this.tracks.filter((t) => t.closed).length;
        },

        async toggleShare() {
            const r = await send(this.shared ? 'DELETE' : 'POST', `/share/playlists/${this.id}`);
            if (r.ok) this.shared = r.data.shared;
        },

        async shareTracks() {
            const r = await send('POST', `/playlists/${this.id}/share-tracks`);
            if (!r.ok) return;

            this.tracks.forEach((t) => (t.closed = false));
            this.note = `${r.data.shared} ${r.data.shared === 1 ? 'track' : 'tracks'} shared.`;
        },

        async rename() {
            const draft = this.draft.trim();
            this.editing = false;
            if (!draft || draft === this.name) return;

            const r = await send('PATCH', `/playlists/${this.id}`, { name: draft });
            if (r.ok) this.name = r.data.name;
        },

        // Not called destroy(): Alpine runs a method of that name when the component is removed, i.e. on every navigation.
        async deletePlaylist() {
            if (!confirm(`Delete the playlist "${this.name}"? The music stays in the library.`)) return;

            const r = await send('DELETE', `/playlists/${this.id}`);
            if (r.ok) Livewire.navigate('/playlists');
        },
    };
}

/** A shared playlist of someone else, read only: play it, or copy it to your own playlists. */
export function sharedPlaylistView(id, tracks) {
    return {
        id,
        tracks,
        note: '',

        get playable() {
            return this.tracks.filter((t) => t.url);
        },

        play(track = null) {
            const queue = [...this.playable];
            const i = track ? queue.findIndex((t) => t.id === track.id) : 0;
            if (i >= 0) Alpine.store('player').setQueue(queue, i);
        },

        async copy() {
            const r = await send('POST', `/playlists/${this.id}/copy`);
            if (r.ok) Livewire.navigate(r.data.url);
            else this.note = 'Could not copy it.';
        },
    };
}

/** The list page: create a playlist from a name. */
export function playlistCreate() {
    return {
        name: '',
        error: '',

        async create() {
            const name = this.name.trim();
            if (!name) return;

            const r = await send('POST', '/playlists', { name });
            if (r.ok) Livewire.navigate(r.data.url);
            else this.error = 'Could not create it.';
        },
    };
}
