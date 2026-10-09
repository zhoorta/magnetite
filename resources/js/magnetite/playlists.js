import { Alpine, Livewire } from '../../../vendor/livewire/livewire/dist/livewire.esm';
import { send } from './http';

/** The "add to playlist" dialog. A track row asks for it with `$dispatch('pick-playlist', track)`. */
export function playlistPicker(playlists) {
    return {
        playlists,
        track: null,
        name: '',
        note: '',

        open(track) {
            this.track = track;
            this.name = '';
            this.note = '';
        },

        close() {
            this.track = null;
        },

        async add(playlist) {
            const r = await send('POST', `/playlists/${playlist.id}/tracks`, { track_id: this.track.id });
            this.note = !r.ok ? 'Could not add it.' : r.data.added ? `Added to ${playlist.name}.` : `Already in ${playlist.name}.`;
            if (r.ok) setTimeout(() => this.close(), 900);
        },

        async create() {
            const name = this.name.trim();
            if (!name) return;

            const r = await send('POST', '/playlists', { name, track_id: this.track.id });
            if (!r.ok) {
                this.note = 'Could not create it.';

                return;
            }

            this.playlists = [...this.playlists, { id: r.data.id, name: r.data.name }].sort((a, b) => a.name.localeCompare(b.name));
            this.note = `Added to ${r.data.name}.`;
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
