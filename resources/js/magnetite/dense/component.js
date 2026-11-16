import Sortable from 'sortablejs';
import { Livewire } from '../../../../vendor/livewire/livewire/dist/livewire.esm';
import { send } from '../http';
import { actionFor, isTextTarget } from './keymap';
import { complete, parse, pick } from './commands';
import { DEMO_NOTE, demoAllows, demoAllowsCommand, demoHints } from './demo';
import { SHUFFLES, REPEATS } from '../order';
import { folderStats, folderTiles, summaryLine } from './folder';
import { findings, hasProblem } from './info';
import { appendPage, emptySearch, queueFrom, searchItems, wantsMore } from './search';
import { TRUTH_SORT_NAMES, nextTruthSort, truthRows } from './truth';
import { sourceMethods } from './source-methods';
import { SOURCE_LABELS, playQueue } from './sources';
import { titleCase } from './text';
import { ancestorIds, buildRows, clamp, closeAction, folderAndBelow, folderChoices, nextSort, openAction, parentIndex, rowOfAlbum, targetAlbums } from './rows';

const OPEN_KEY = (id) => `magnetite.folder.${id}`; // the same keys as the old library page, so open folders survive the change
const HOLD_MS = 500; // a long press on touch marks an album

function storage(fn) {
    try {
        return fn(window.localStorage);
    } catch {
        return null;
    }
}

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const running = (a) => ['queued', 'running'].includes(a?.state);

/**
 * The dense library: one Alpine component for the whole page. The pure parts (row model, navigation, keys) are in rows.js and
 * keymap.js; this file is the DOM, the requests and the player. The data is what LibraryController sends, as JSON.
 */
export default function dense(data) {
    return {
        folders: data.folders,
        albums: data.albums,
        loose: data.loose,
        shares: data.shared,
        source: 'library', // 'library', 'shared' or 'playlists' (docs/dense-library.md, "Sources"): what the tree and the tracks show
        sourceLabels: SOURCE_LABELS,
        own: null, // your library while another source is shown: { folders, albums, loose, shares }
        cache: {}, // what was fetched of the other sources, shown at once when they are opened again
        cursors: {}, // the selected row of each source
        uploadOpen: false, // the upload dialog
        fileDrag: false, // files are being dragged over the page
        libraryStale: false, // something changed the library from another source (a copy): reload it when it is shown again
        trashOnly: data.trash_only,
        demo: !!data.demo, // the demo library of a visitor who is not signed in: read only (demo.js)

        rows: [],
        openIds: [], // ids of the open folders
        marks: [], // ids of the marked albums
        selectMode: false, // tap rows to mark them (touch)
        cur: 0, // selected row of the tree
        tsel: 0, // selected track of the shown album
        pane: 'tree', // 'tree', 'tracks' or 'grid' (the tiles of a selected folder)
        gsel: 0, // selected tile
        gridLimit: 60, // tiles drawn: more are added as the selection nears the end
        sort: 'library',
        onlyProblems: false, // the truth view lists only the files with a finding (the Findings view); off: every file (the Truth view)
        detail: false, // the measurements of the selected track, under the list
        msg: '',
        dropOn: null, // the folder row an album is being dragged over
        dragIds: null,
        undo: null, // the last move of albums, to put back with u: { prev: [[albumId, folderId]], until }
        lpFlag: false,

        mode: 'tree', // what the left pane shows: 'tree', 'search' (results) or 'truth' (every file, worst first)
        search: emptySearch(),
        scur: 0, // selected search result
        typing: false, // the cursor is in the search box
        truthSort: 'findings',
        truth: [],
        truthLimit: 300, // rows drawn: 12,000 files would take seconds to draw at once; more are added as the selection nears the end
        spectra: {}, // average spectra fetched for the measurements panel: { trackId: { spectrum, step_hz } }
        tcur: 0, // selected row of the truth view
        ctxMenu: null, // the right-click menu: { x, y } in window pixels
        cmd: null, // the command line: { text, list, index }
        table: null, // the edit table
        titles: null, // details found online for an album
        lookup: null, // what AcoustID knows about the selected track
        gd: null, // the "name an album" dialog for tracks without one
        trackMarks: [], // marked tracks without an album (to group)
        suggestions: data.suggestions,
        toIdentify: data.to_identify,
        identify: { state: 'idle', done: 0, total: 0, found: 0 },

        mover: null, // the "move to a folder" list: { kind, ids, title, choices, index }
        fd: null, // the new-folder and rename dialog
        edit: null, // the edit-details dialog (replaced by the edit table later)
        analysis: { state: 'idle', done: 0, total: 0, failed: 0 },
        analysisError: null,

        init() {
            // `$root` is the root of whichever x-data is running a handler: inside a menu it is the menu. The library's own root is kept here.
            this.libraryRoot = this.$el;
            // Safari does not always say "touch" on the click event; a touchstart just before it is the sure sign of a finger
            this.$el.addEventListener('touchstart', () => (this.touchAt = Date.now()), { passive: true, capture: true });
            this.own = { folders: this.folders, albums: this.albums, loose: this.loose, shares: this.shares };
            this.openIds = this.folders.filter((f) => this.storedOpen(f.id)).map((f) => f.id);
            // Start on the album that is playing, if there is one, with the way to it open.
            const playing = this.$store.player.current?.album_id;
            const album = this.albums.find((a) => a.id === playing);
            if (album) this.reveal(album.folder_id, false);
            this.rebuild();
            if (album) this.cur = Math.max(0, rowOfAlbum(this.rows, album.id));
            // /shared and /playlists open the same page on that source (the data came with the page); /playlists/{id} selects one playlist
            if (data.source && data.sources?.[data.source]) {
                this.cache[data.source] = data.sources[data.source];
                this.useSource(data.source, this.cache[data.source], data.select ? `a${data.select}` : null);
                if (data.select) this.toTracks();
            }
            this.$nextTick(() => this.focusRoot()); // the keys work as soon as the page is there
            this.onReveal = () => this.revealPlaying();
            window.addEventListener('magnetite:reveal', this.onReveal);
            this.onScanned = () => (this.source === 'library' ? this.reload() : (this.libraryStale = true)); // an upload's scan finished: the new albums appear
            window.addEventListener('magnetite:scanned', this.onScanned);
            if (this.$store.player.reveal) this.$nextTick(() => this.revealPlaying());
            this.$store.player.libraryKeys = true; // the ? list shows the library's keys while this page is open

            // The key hints, the search box and the command line sit under the player: the player and the page leave room for them.
            const bar = this.libraryRoot.querySelector('[x-ref="statusBar"]');
            if (bar) {
                this.statusObserver = new ResizeObserver(() => document.documentElement.style.setProperty('--status-h', `${bar.offsetHeight}px`));
                this.statusObserver.observe(bar);
            }

            // The two panes fill what is left between the toolbar and the player, so the player follows right after them: --panes-top is
            // where they start (it changes when the toolbar wraps onto another line).
            this.measure = () => {
                const panes = this.libraryRoot.querySelector('.panes');
                if (panes) document.documentElement.style.setProperty('--panes-top', `${panes.getBoundingClientRect().top + window.scrollY}px`);
            };
            this.layoutObserver = new ResizeObserver(() => this.measure());
            this.layoutObserver.observe(this.libraryRoot);
            window.addEventListener('resize', this.measure);
            this.afterUpdate(() => this.measure(), 100);

            if (this.demo) return; // nothing of a visitor's is being analysed or scanned

            // An analysis or a scan started before this page loaded carries on showing its progress.
            fetch('/analysis', { headers: { Accept: 'application/json' } }).then((r) => (r.ok ? r.json() : null)).then((a) => {
                if (running(a)) {
                    this.analysis = a;
                    this.followAnalysis();
                }
            }).catch(() => {});
            fetch('/scan', { headers: { Accept: 'application/json' } }).then((r) => (r.ok ? r.json() : null)).then(async (s) => {
                while (running(s)) {
                    await wait(2000);
                    const p = await fetch('/scan', { headers: { Accept: 'application/json' } });
                    if (!p.ok) return;
                    s = await p.json();
                    if (!running(s)) await this.reload();
                }
            }).catch(() => {});
        },

        /** Alpine calls this when the page goes (also on every wire:navigate). */
        destroy() {
            this.$store.player.libraryKeys = false;
            this.statusObserver?.disconnect();
            this.layoutObserver?.disconnect();
            window.removeEventListener('resize', this.measure);
            window.removeEventListener('magnetite:reveal', this.onReveal);
            window.removeEventListener('magnetite:scanned', this.onScanned);
            this.sortable?.destroy();
            document.documentElement.style.removeProperty('--status-h');
            document.documentElement.style.removeProperty('--panes-top');
        },

        // ---- what is shown ----

        /** The tree as one list of albums, without folders (the person's choice; playlists are lists already). */
        get flat() {
            return this.$store.ui.flat && this.source !== 'playlists';
        },

        toggleFlat() {
            if (this.source === 'playlists') return this.say('Playlists have no folders');
            const id = this.row?.kind === 'album' ? this.row.id : null;
            this.$store.ui.toggleFlat();
            this.rebuild();
            if (id !== null) this.cur = Math.max(0, rowOfAlbum(this.rows, id));
            this.scrollToSelection();

            return this.say(this.$store.ui.flat ? 'flat list: albums only (z goes back to folders)' : 'folders');
        },

        rebuild() {
            this.rows = buildRows({ folders: this.folders, albums: this.albums, loose: this.loose }, new Set(this.openIds), this.sort, null, this.flat);
            this.cur = clamp(this.rows, this.cur);
        },

        get row() {
            return this.rows[this.cur] ?? null;
        },

        /** The album, or the loose tracks, whose tracks the right pane shows (null for a folder). */
        get shown() {
            if (this.mode === 'truth') return null;
            if (this.mode === 'search') {
                const h = this.hit;
                const id = h?.kind === 'album' ? h.album.id : h?.track?.album_id;

                return (id && this.albums.find((a) => a.id === id)) || null;
            }
            const r = this.row;
            if (r?.kind === 'album') return r.album;
            if (r?.kind === 'loose') return { id: null, title: this.looseTitle, artist: null, tracks: this.loose, loose: true };

            return null;
        },

        /** The tiles of the selected folder: its subfolders, then its albums. */
        get tiles() {
            const r = this.row;

            return r?.kind === 'folder' ? folderTiles(this.folders, this.albums, r.id, this.sort) : [];
        },

        get tilesShown() {
            return this.tiles.slice(0, this.gridLimit);
        },

        get folderSummary() {
            const r = this.row;

            return r?.kind === 'folder' ? summaryLine(folderStats(this.folders, this.albums, r.id), this.$store.ui.info) : '';
        },

        /** The folder the shown album lives in (null for loose tracks and albums at the top level). */
        get albumFolder() {
            const a = this.shown;

            return a && !a.loose && a.folder_id != null ? this.folders.find((f) => f.id === a.folder_id) ?? null : null;
        },

        /** The player's title or album was clicked: show that album with the tracks pane on the playing track. */
        revealPlaying() {
            const want = this.$store.player.reveal;
            this.$store.player.reveal = null;
            if (!want) return;
            this.goToAlbum(want.album_id);
            if (this.row?.kind !== 'album' || this.row.id !== want.album_id) return;
            this.toTracks();
            this.tsel = Math.max(0, this.tracks.findIndex((t) => t.id === want.id));
            this.scrollToSelection();
        },

        /** Back from an album: the tiles of its folder, with the album's tile selected. */
        toFolder() {
            const f = this.albumFolder;
            if (!f) return this.row?.kind === 'folder' ? this.toParentFolder() : this.say('This album is not in a folder');
            const albumId = this.shown.id;
            this.detail = false;
            this.cur = Math.max(0, this.rows.findIndex((r) => r.key === `f${f.id}`));
            this.pane = 'grid';
            this.gridLimit = 60;
            this.gsel = Math.max(0, this.tiles.findIndex((t) => t.kind === 'album' && t.album.id === albumId));
            this.gridLimit = Math.max(60, this.gsel + 60);
            this.focusRoot();
            this.scrollToSelection();
        },

        /** Back from a folder: its parent folder (the tree's cursor moves there and the right pane shows its tiles). */
        toParentFolder() {
            const id = this.folders.find((f) => f.id === this.row?.id)?.parent_id;
            if (id == null) return this.say('Already at the top');
            this.cur = Math.max(0, this.rows.findIndex((r) => r.key === `f${id}`));
            this.pane = 'tree';
            this.gsel = 0;
            this.gridLimit = 60;
            this.focusRoot();
            this.scrollToSelection();
        },

        /** The key hints for what has the cursor: the keys that do something here, not all of them (`?` lists everything). */
        get hintList() {
            if (!this.$store.ui.hints) return [['?', 'keys']];
            if (this.demo) return demoHints(this.pane, [[':', 'command'], ['?', 'keys']]);
            const common = [['w', 'source'], ['z', this.flat ? 'folders' : 'flat'], ['/', 'search'], [':', 'command'], ['?', 'keys']];
            if (this.source !== 'library' && this.pane === 'grid') return [['h j k l', 'move'], ['Enter', 'open'], ['⌫', 'up'], ['Tab', 'tree'], ...common];
            if (this.source === 'shared') return this.pane === 'tracks'
                ? [['j k', 'move'], ['Enter', 'play'], ['y', 'copy'], ['S', 'stop sharing'], ['⌫', 'back'], ...common]
                : [['j k', 'move'], ['h l', 'fold'], ['Enter', 'play'], ['Tab', 'tracks'], ['y', 'copy to my library'], ['S', 'stop sharing'], ...common];
            if (this.source === 'playlists') {
                const mine = !!this.shown?.mine;
                if (this.pane === 'tracks') return [['j k', 'move'], ['Enter', 'play'], ...(mine ? [['J K', 'reorder'], ['d', 'remove']] : []), ['⌫', 'back'], ...common];

                return [['j k', 'move'], ['Enter', 'play'], ['Tab', 'tracks'], ['N', 'new'], ...(mine ? [['r', 'rename'], ['S', 'share'], ['d', 'delete']] : [['y', 'copy']]), ...common];
            }
            if (this.pane === 'grid') return [['h j k l', 'move'], ['Enter', 'open'], ['x', 'mark'], ['v', 'move to'], ['⌫', 'up'], ['Tab', 'tree'], ...common];
            if (this.pane === 'tracks') return [['j k', 'move'], ['Enter', 'play'], ['l', 'details'], ['e', 'edit'], ['i', 'look up'], ['A', 'analyse'], ['o', 'problems'], ['⌫', 'back'], ['Esc', 'tree'], ...common];
            const r = this.row;
            if (r?.kind === 'folder') return [['j k', 'move'], ['h l', 'fold'], ['Tab', 'tiles'], ['N', 'new folder'], ['r', 'rename'], ['v', 'move'], ['d', 'delete'], ['⌫', 'up'], ...common];
            if (r?.kind === 'album') return [['j k', 'move'], ['Enter', 'play'], ['Tab', 'tracks'], ['x', 'mark'], ['v', 'move'], ['e', 'edit'], ['i', 'look up'], ['A', 'analyse'], ['S', 'share'], ['⌫', 'folder'], ...common];

            return [['j k', 'move'], ['Enter', 'play'], ['Tab', 'tracks'], ...common];
        },

        get tracks() {
            return this.shown?.tracks ?? [];
        },

        /** The track whose measurements the detail panel shows, when it is measured. */
        get detailTrack() {
            if (this.mode === 'truth') {
                const t = this.truth[this.tcur]?.track;

                return t?.truth?.analyzed ? t : null;
            }
            const t = this.detail && this.pane === 'tracks' ? this.tracks[this.tsel] : null;

            return t?.truth?.analyzed ? t : null;
        },

        get paneTitle() {
            if (this.mode === 'truth') return this.truth[this.tcur]?.track.title ?? 'Nothing to show';
            if (this.mode === 'search') return this.hit ? (this.hit.kind === 'album' ? this.hit.album.title : this.hit.track.title) : 'Search';
            const r = this.row;
            if (!r) return 'Tracks';
            if (r.kind === 'folder') return r.folder.name;

            return this.shown?.title ?? 'Tracks';
        },

        /** What the toolbar's "+ playlist" adds: the selected track in the tracks pane, else the album. */
        get playlistItem() {
            const t = this.pane === 'tracks' ? this.tracks[this.tsel] : null;
            if (t) return { id: t.id, title: t.title };
            const a = this.row?.kind === 'album' && !this.row.album.playlist ? this.row.album : null;

            return a ? { kind: 'album', id: a.id, title: a.title } : null;
        },

        get playingAlbumId() {
            return this.source === 'playlists' ? null : this.$store.player.current?.album_id ?? null;
        },

        /** Is what the toolbar's Play button would start the thing that is loaded in the player (the selected track, or the selected album)? */
        get playHere() {
            const current = this.$store.player.current;
            if (!current) return false;
            if (this.pane === 'tracks') return this.tracks[this.tsel]?.id === current.id;

            return this.playingAlbumId !== null && this.playingAlbumId === this.shown?.id;
        },

        /** Does this folder, or one below it, hold the album that is playing? */
        holdsPlaying(folderId) {
            const album = this.albums.find((a) => a.id === this.playingAlbumId);

            return !!album && ancestorIds(this.folders, album.folder_id).includes(folderId);
        },

        /** What the selection is, for the actions menu: 'folder', 'album', 'loose' (tracks without an album), 'track' (in the tracks pane), or null. */
        get ctx() {
            if (this.mode !== 'tree') return null;
            if (this.demo) return this.pane === 'tracks' && this.tracks[this.tsel] ? 'ro-track' : this.row ? 'ro-' + this.row.kind : null; // only Play is offered
            if (this.source !== 'library') {
                // the other sources have their own entries in the menu (library-actions.blade.php)
                const pl = this.source === 'playlists';
                if (this.pane === 'tracks' && this.tracks[this.tsel]) return pl ? (this.shown?.mine ? 'pl-track' : 'ro-track') : 'ro-track';
                const r = this.row;
                if (r?.kind === 'album') return pl ? (r.album.mine ? 'pl-mine' : 'pl-other') : (r.album.mine ? 'sh-mine' : 'sh-other');

                return r ? 'ro-' + r.kind : null;
            }
            if (this.pane === 'tracks' && this.tracks[this.tsel]) return 'track';
            const r = this.row;

            return r ? r.kind : null;
        },

        /** Right-click on a row of the tree (`kind` 'row') or a track ('track'): select it and show the actions at the pointer. */
        openContext(ev, kind, i) {
            this.focusRoot();
            if (kind === 'track') {
                this.pane = 'tracks';
                this.tsel = i;
            } else {
                this.pane = 'tree';
                this.cur = i;
                this.tsel = 0;
                this.gsel = 0;
                this.gridLimit = 60;
            }
            this.ctxMenu = { x: ev.clientX, y: ev.clientY };
            this.afterUpdate((root) => {
                const menu = root.querySelector('[data-ctxmenu]');
                if (!menu) return;
                // keep the menu inside the window: flip to the other side of the pointer when it would run out
                const r = menu.getBoundingClientRect();
                const x = Math.max(4, Math.min(this.ctxMenu.x, window.innerWidth - r.width - 4));
                const y = this.ctxMenu.y + r.height > window.innerHeight - 4 ? Math.max(4, this.ctxMenu.y - r.height) : this.ctxMenu.y;
                this.ctxMenu = { x, y };
                menu.querySelector('[role=menuitem]:not([disabled])')?.focus();
            });
        },

        closeContext(back = true) {
            if (!this.ctxMenu) return;
            this.ctxMenu = null;
            if (back) this.focusRoot();
        },

        stepContext(d) {
            const items = [...this.libraryRoot.querySelectorAll('[data-ctxmenu] [role=menuitem]')].filter((el) => el.offsetParent !== null && !el.disabled);
            if (items.length) items[(items.indexOf(document.activeElement) + d + items.length) % items.length].focus();
        },

        // ---- the tiles of a selected folder ----

        /** How many tiles fit in a row: what the browser made of the grid. */
        gridCols() {
            const g = this.libraryRoot.querySelector('[data-list="grid"]');

            return g ? Math.max(1, getComputedStyle(g).gridTemplateColumns.split(' ').length) : 1;
        },

        runGrid(action) {
            const n = this.tiles.length;
            const cols = this.gridCols();
            const move = (to) => {
                this.gsel = clamp(this.tiles, to);
                if (this.gsel >= this.gridLimit - cols) this.gridLimit = Math.min(n, this.gsel + 60);
                this.scrollToSelection();
            };
            switch (action) {
                case 'left': return move(this.gsel - 1);
                case 'right': return move(this.gsel + 1);
                case 'up': return move(this.gsel - cols);
                case 'down': return move(this.gsel + cols);
                case 'top': return move(0);
                case 'bottom': return move(n - 1);
                case 'open': return this.openTile(this.gsel);
                case 'mark': return this.markTile(this.gsel);
                case 'move': return this.openMover();
                case 'tree': this.pane = 'tree'; return;
                case 'folder': return this.toParentFolder();
                case 'command': return this.openCmd();
            }
        },

        /** An album tile opens the album in the tree (its tracks on the right); a folder tile opens that folder. */
        openTile(i) {
            const t = this.tiles[i];
            if (!t) return;
            if (t.kind === 'album') return this.goToAlbum(t.album.id);
            if (t.kind !== 'up') this.setOpen(t.folder.id, true);
            this.cur = Math.max(0, this.rows.findIndex((r) => r.key === t.key));
            this.pane = 'tree';
            this.gsel = 0;
            this.gridLimit = 60;
            this.scrollToSelection();
        },

        markTile(i) {
            const t = this.tiles[i];
            if (t?.kind !== 'album') return this.say('Mark albums, not folders');
            this.marks = this.isMarked(t.album.id) ? this.marks.filter((id) => id !== t.album.id) : [...this.marks, t.album.id];
            this.gsel = clamp(this.tiles, i + 1);
        },

        tileClick(ev, i) {
            this.focusRoot();
            const t = this.tiles[i];
            if (t?.kind === 'album' && (this.selectMode || ev.ctrlKey || ev.metaKey)) {
                this.pane = 'grid';
                this.gsel = i;
                this.marks = this.isMarked(t.album.id) ? this.marks.filter((id) => id !== t.album.id) : [...this.marks, t.album.id];

                return;
            }
            this.openTile(i);
        },

        tileDblClick(i) {
            const t = this.tiles[i];
            if (t?.kind !== 'album') return;
            this.goToAlbum(t.album.id);
            this.playTrack(0, false);
        },

        tileContext(ev, i) {
            const t = this.tiles[i];
            if (t?.kind !== 'album') return this.openTile(i);
            this.goToAlbum(t.album.id);
            this.openContext(ev, 'row', this.cur);
        },

        get hasAnything() {
            return this.source !== 'library' || this.albums.length > 0 || this.loose.length > 0;
        },

        /** What the loose row is called: tracks without an album tag in the library, single tracks that are shared. */
        get looseTitle() {
            return this.source === 'shared' ? 'Single tracks' : 'Without album tag';
        },

        /** Your own playlist is shown: its tracks can be dragged, moved (Shift+J, Shift+K) and removed. */
        get canReorder() {
            return this.source === 'playlists' && !!this.shown?.playlist && !!this.shown.mine && this.mode === 'tree';
        },

        get emptyNote() {
            if (this.source === 'shared') return 'Nothing is shared yet. Use Share in your library (S).';
            if (this.source === 'playlists') return 'No playlists yet. N makes one; + on an album or a track adds to one.';

            return '';
        },

        say(text, ms = 4000) {
            this.msg = text;
            clearTimeout(this.sayTimer);
            this.sayTimer = setTimeout(() => (this.msg = ''), ms);
        },

        /**
         * Run `fn(root)` after the next update, and again after `delay` ms when given. `$root` and `$refs` belong to the element that
         * is running the handler: one inside a template that has gone by then (the command line) would find nothing, so the root is taken now.
         */
        afterUpdate(fn, delay = 0) {
            const root = this.libraryRoot;
            this.$nextTick(() => fn(root));
            if (delay) setTimeout(() => fn(root), delay);
        },

        focusRoot() {
            this.libraryRoot.focus({ preventScroll: true });
        },

        isMarked(id) {
            return this.marks.includes(id);
        },

        // ---- keyboard ----

        onKey(e) {
            // A control that has focus keeps its own keys (Enter on a button presses it); fields keep every key; a dialog has the keyboard.
            if (e.defaultPrevented || e.target !== this.libraryRoot || isTextTarget(e.target) || this.$store.dialog.open || this.$store.player.expanded) return; // full screen has the keyboard
            if (this.mover) return this.moverKey(e);
            if (this.fd || this.edit || this.table || this.titles || this.lookup || this.gd || this.cmd || this.typing) return;
            const action = actionFor(this.mode === 'tree' ? this.pane : this.mode, e);
            if (!action) return;
            e.preventDefault();
            this.run(action);
        },

        run(action) {
            if (this.demo && !demoAllows(action)) return this.say(DEMO_NOTE);
            if (action === 'upload') return this.openUpload();
            if (action === 'source') return this.cycleSource(1);
            if (action === 'source-back') return this.cycleSource(-1);
            if (this.mode === 'search') return this.runSearch(action);
            if (this.mode === 'truth') return this.runTruth(action);
            if (action === 'search') return this.openSearch();
            if (this.source !== 'library' && this.runSource(action)) return;
            if (this.pane === 'grid') return this.runGrid(action);
            const tree = this.pane === 'tree';
            const move = (to) => {
                if (tree) {
                    this.cur = clamp(this.rows, to);
                    this.tsel = 0;
                    this.gsel = 0;
                    this.gridLimit = 60;
                } else this.tsel = clamp(this.tracks, to);
                this.scrollToSelection();
            };
            const at = tree ? this.cur : this.tsel;
            const last = (tree ? this.rows : this.tracks).length - 1;
            const open = new Set(this.openIds);

            switch (action) {
                case 'down': return move(at + 1);
                case 'up': return move(at - 1);
                case 'pagedown': return move(at + 10);
                case 'pageup': return move(at - 10);
                case 'top': return move(0);
                case 'bottom': return move(last);
                case 'open': {
                    const what = openAction(this.rows, this.cur, open);
                    if (what === 'open-folder') this.setOpen(this.row.id, true);
                    else if (what === 'tracks') this.toTracks();
                    return;
                }
                case 'close': {
                    const what = closeAction(this.rows, this.cur, open);
                    if (what === 'close-folder') this.setOpen(this.row.id, false);
                    else if (what === 'parent') move(parentIndex(this.rows, this.cur));
                    return;
                }
                case 'enter': return this.enter();
                case 'flat': return this.toggleFlat();
                case 'mark': return this.toggleMark();
                case 'move': return this.openMover();
                case 'new-folder': return this.newFolder();
                case 'rename': return this.row?.kind === 'folder' ? this.renameFolder() : this.editSelected();
                case 'delete': return this.deleteSelected();
                case 'sort': this.sort = nextSort(this.sort, { withDr: this.$store.ui.info > 0 }); this.rebuild(); return this.say(`order: ${{ library: 'artist, year, title', title: 'title', year: 'year', dr: 'dynamic range, the most compressed first' }[this.sort]}`);
                case 'tracks': return this.toTracks();
                case 'tree': this.detail = false; this.pane = 'tree'; return;
                case 'back':
                    if (this.detail) this.detail = false;
                    else this.pane = 'tree';
                    return;
                case 'folder': return this.toFolder();
                case 'detail': return this.toggleDetail();
                case 'problems': return this.toggleProblems();
                case 'play': return this.playTrack(this.tsel);
                case 'analyse': return this.analyseSelected();
                case 'undo': return this.undoLast();
                case 'copy': return this.say('Already yours: y copies from Shared and Playlists');
                case 'edit': return this.editSelected();
                case 'details': return this.openDetails();
                case 'cover': return this.findCover();
                case 'command': return this.openCmd();
                case 'mark-track': return this.toggleTrackMark();
                case 'share': return this.shareSelected();
                case 'clear':
                    if (this.marks.length || this.selectMode) {
                        this.marks = [];
                        this.selectMode = false;
                    }
            }
        },

        scrollToSelection() {
            // the selected row of the list that has the cursor (the tree's row is "selected" also while the tracks have it)
            const list = this.mode === 'tree' ? this.pane : this.mode;
            this.afterUpdate((root) => root.querySelector(`[data-list="${list}"] [data-selected="true"]`)?.scrollIntoView({ block: 'nearest' }));
        },

        toggleDetail() {
            const t = this.tracks[this.tsel];
            if (!t?.truth?.analyzed) return this.say('This file has not been measured yet: A analyses it');
            this.detail = !this.detail;
        },

        /** o and :problems: the Findings view. From anywhere else it opens it; inside the truth view it switches between every file and the ones with findings. */
        toggleProblems() {
            if (this.mode !== 'truth') return this.openTruth(true);
            this.onlyProblems = !this.onlyProblems;
            this.tcur = 0;
            this.truthLimit = 300;
            this.rebuildTruth();
            this.say(this.onlyProblems ? 'Findings: only the files with a finding' : 'Truth: every file');
        },

        toTracks() {
            if (this.row?.kind === 'folder') {
                if (!this.tiles.length) return this.say('This folder is empty');
                this.pane = 'grid';
                this.gsel = clamp(this.tiles, this.gsel);

                return;
            }
            if (!this.tracks.length) return this.say('No tracks here');
            this.pane = 'tracks';
            this.tsel = clamp(this.tracks, this.tsel);
        },

        // ---- folders and the tree ----

        storedOpen(id) {
            return storage((s) => s.getItem(OPEN_KEY(id))) === '1';
        },

        setOpen(id, on) {
            this.openIds = on ? [...new Set([...this.openIds, id])] : this.openIds.filter((x) => x !== id);
            if (this.source === 'library') storage((s) => (on ? s.setItem(OPEN_KEY(id), '1') : s.removeItem(OPEN_KEY(id))));
            const key = this.row?.key;
            this.rebuild();
            this.cur = Math.max(0, this.rows.findIndex((r) => r.key === key));
        },

        /** Open a folder and the ones around it, so a moved album is where the eye lands. */
        reveal(folderId, rebuild = true) {
            for (const id of ancestorIds(this.folders, folderId)) {
                if (!this.openIds.includes(id)) this.openIds.push(id);
                if (this.source === 'library') storage((s) => s.setItem(OPEN_KEY(id), '1'));
            }
            if (rebuild) this.rebuild();
        },

        enter() {
            const r = this.row;
            if (r?.kind === 'folder') return this.setOpen(r.id, !this.openIds.includes(r.id));
            if (this.tracks.length) this.playTrack(0, false);
        },

        playTrack(i, focus = true) {
            const track = this.tracks[i];
            if (!track) return;
            if (track.open === false && focus) return this.say('Not shared with you: you cannot play this track');
            // only what this person may play, as a copy: the page edits its own array, the player keeps the queue it was given
            const q = playQueue(this.tracks, i);
            if (!q) return this.say('Nothing in here can be played by you');
            if (focus && this.pane === 'tracks') this.tsel = i;
            this.$store.player.setQueue(q.queue, q.index);
        },

        toggleMark() {
            const r = this.row;
            if (r?.kind !== 'album') return this.say('Mark albums, not folders: select a folder\'s albums and press x on each');
            this.marks = this.isMarked(r.id) ? this.marks.filter((id) => id !== r.id) : [...this.marks, r.id];
            this.cur = clamp(this.rows, this.cur + 1);
            this.scrollToSelection();
        },

        toggleSelectMode() {
            if (this.demo) return this.say(DEMO_NOTE);
            this.selectMode = !this.selectMode;
            this.say(this.selectMode ? 'Select mode: tap albums to mark them' : 'Select mode off');
            this.focusRoot();
        },

        // ---- mouse and touch ----

        rowClick(ev, i) {
            if (this.lpFlag) return (this.lpFlag = false);
            this.focusRoot();
            this.pane = 'tree';
            this.cur = i;
            this.tsel = 0;
            this.gsel = 0;
            this.gridLimit = 60;
            const r = this.rows[i];
            if (r.kind === 'folder') this.setOpen(r.id, !this.openIds.includes(r.id));
            else if (r.kind === 'album' && !this.demo && (this.selectMode || ev.ctrlKey || ev.metaKey)) {
                this.marks = this.isMarked(r.id) ? this.marks.filter((id) => id !== r.id) : [...this.marks, r.id];
            }
        },

        rowDblClick(i) {
            const r = this.rows[i];
            if (r.kind === 'folder') return; // the single click already toggled it twice: leave it as it was
            this.cur = i;
            this.playTrack(0, false);
        },

        holdStart(ev, i) {
            if (this.demo || ev.pointerType === 'mouse' || this.rows[i].kind !== 'album') return;
            clearTimeout(this.holdTimer);
            this.holdTimer = setTimeout(() => {
                this.selectMode = true;
                this.cur = i;
                if (!this.isMarked(this.rows[i].id)) this.marks = [...this.marks, this.rows[i].id];
                this.lpFlag = true;
                setTimeout(() => (this.lpFlag = false), 700);
                this.say('Select mode: tap albums to mark them');
            }, HOLD_MS);
        },

        holdEnd() {
            clearTimeout(this.holdTimer);
        },

        /**
         * A finger, not a mouse. Touch browsers (Safari on the iPad above all) do not send `dblclick` for rows that are
         * `touch-action: manipulation`, so on touch a tap has to do what a double click does on a mouse.
         */
        isTouch(ev) {
            const type = ev?.pointerType;
            if (type === 'touch' || Date.now() - (this.touchAt || 0) < 1000) return true;
            if (type) return false; // a mouse or a pen: the double click works
            return !!window.matchMedia?.('(pointer: coarse)').matches;
        },

        trackClick(i, ev) {
            this.focusRoot();
            this.pane = 'tracks';
            this.tsel = i;
            if (this.isTouch(ev)) this.playTrack(i);
        },

        dragStart(ev, i) {
            if (this.source !== 'library') return ev.preventDefault(); // albums of others, and playlists, are not moved
            const r = this.rows[i];
            this.dragIds = this.isMarked(r.id) ? [...this.marks] : [r.id];
            ev.dataTransfer.setData('text/plain', String(r.id));
            ev.dataTransfer.effectAllowed = 'move';
        },

        dragOver(ev, i) {
            if (this.rows[i].kind === 'folder' && this.dragIds?.length) {
                ev.preventDefault();
                this.dropOn = i;
            }
        },

        async drop(ev, i) {
            ev.preventDefault();
            this.dropOn = null;
            const r = this.rows[i];
            if (r.kind !== 'folder' || !this.dragIds?.length) return;
            await this.moveAlbums(this.dragIds, r.id);
            this.dragIds = null;
        },

        // ---- moving ----

        /** The albums to move (marked, else the selected one) or the selected folder. */
        /** U / :upload: the upload panel as a dialog (the page /upload is the same panel). */
        openUpload() {
            if (this.demo) return this.say(DEMO_NOTE);
            this.uploadOpen = true;
            this.$store.uploads.watch();
            this.$nextTick(() => this.libraryRoot.querySelector('[data-upload-panel]')?.focus());
        },

        closeUpload() {
            this.uploadOpen = false;
            this.focusRoot();
        },

        /** Zips dropped anywhere on the page start uploading at once (a drag of an album inside the library has no files in it). */
        isFileDrag(e) {
            return !this.demo && [...(e.dataTransfer?.types ?? [])].includes('Files');
        },

        dropFiles(e) {
            if (!this.isFileDrag(e)) return;
            e.preventDefault();
            this.fileDrag = false;
            if (this.$store.uploads.pick(e.dataTransfer.files)) this.$store.uploads.send();
            else this.say(this.$store.uploads.error ?? 'Drop zip files to upload them');
        },

        openMover() {
            this.focusRoot(); // the list is driven from the keyboard, also when it was opened with the mouse
            const r = this.row;
            if (r?.kind === 'folder' && !this.marks.length) {
                const exclude = folderAndBelow(this.folders, r.id);
                this.mover = { kind: 'folder', ids: [r.id], title: `Move the folder “${r.folder.name}” to`, choices: folderChoices(this.folders, exclude), index: 0 };
            } else {
                const ids = targetAlbums(this.rows, this.cur, new Set(this.marks), this.folders, this.albums).map((a) => a.id);
                if (!ids.length) return this.say('Select an album, or mark some with x, first');
                this.mover = { kind: 'albums', ids, title: ids.length === 1 ? 'Move the album to' : `Move ${ids.length} albums to`, choices: folderChoices(this.folders), index: 0 };
            }
            this.afterUpdate((root) => root.querySelector('[data-mover="true"]')?.scrollIntoView({ block: 'nearest' }));
        },

        moverKey(e) {
            const m = this.mover;
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            if (['j', 'ArrowDown'].includes(e.key)) m.index = Math.min(m.choices.length - 1, m.index + 1);
            else if (['k', 'ArrowUp'].includes(e.key)) m.index = Math.max(0, m.index - 1);
            else if (e.key === 'Enter') this.chooseMove(m.index);
            else if (e.key === 'Escape') this.mover = null;
            else return;
            e.preventDefault();
        },

        async chooseMove(i) {
            const m = this.mover;
            const to = m.choices[i].id;
            this.mover = null;
            if (m.kind === 'folder') {
                const r = await send('PATCH', `/library/folders/${m.ids[0]}`, { parent_id: to });
                if (!r.ok) return this.say(r.data?.message ?? 'Could not move the folder.');
                if (to !== null) this.reveal(to, false);
                await this.reload();

                return this.say('Folder moved');
            }
            await this.moveAlbums(m.ids, to);
            this.focusRoot();
        },

        async moveAlbums(ids, folderId) {
            let moved = 0;
            const prev = [];
            for (const id of ids) {
                const a = this.albums.find((x) => x.id === id);
                const before = a?.folder_id ?? null;
                const r = await send('PUT', `/library/albums/${id}/folder`, { folder_id: folderId });
                if (r.ok) {
                    moved++;
                    prev.push([id, before]);
                    if (a) a.folder_id = folderId;
                }
            }
            this.undo = prev.length ? { prev, until: Date.now() + 60000 } : null;
            this.marks = [];
            this.selectMode = false;
            if (folderId !== null) this.reveal(folderId, false);
            const key = this.row?.key;
            this.rebuild();
            const at = this.rows.findIndex((r) => r.key === key);
            if (at >= 0) this.cur = at;
            this.say((moved === ids.length ? `Moved ${moved} ${moved === 1 ? 'album' : 'albums'}` : `Moved ${moved} of ${ids.length}`) + (this.undo ? ' · u undoes it' : ''), 8000);
        },

        /** u: put the albums of the last move back where they were (for a minute). */
        async undoLast() {
            const u = this.undo;
            if (!u || Date.now() > u.until) return this.say('Nothing to undo');
            this.undo = null;
            for (const [id, folderId] of u.prev) await send('PUT', `/library/albums/${id}/folder`, { folder_id: folderId });
            await this.reload();
            this.say(`Move undone (${u.prev.length})`);
        },

        // ---- folder dialog: new folder, rename ----

        newFolder() {
            const r = this.row;
            const parent = r?.kind === 'folder' ? r.id : r?.kind === 'album' ? r.album.folder_id : null;
            this.openFd('create', { parent_id: parent });
        },

        renameFolder() {
            const r = this.row;
            if (r?.kind === 'folder') this.openFd('rename', { id: r.id, name: r.folder.name });
        },

        openFd(mode, fields = {}) {
            this.fd = { mode, id: null, name: '', parent_id: null, ...fields, error: '', saving: false };
            this.afterUpdate((root) => root.querySelector('[x-ref="folderName"]')?.focus());
        },

        async saveFd() {
            if (this.fd.mode.startsWith('playlist-')) return this.savePlaylistDialog();
            const d = this.fd;
            d.saving = true;
            d.error = '';
            const r = d.mode === 'create'
                ? await send('POST', '/library/folders', { name: d.name, parent_id: d.parent_id })
                : await send('PATCH', `/library/folders/${d.id}`, { name: d.name });
            d.saving = false;
            if (!r.ok) {
                d.error = (r.data?.errors && Object.values(r.data.errors)[0][0]) || r.data?.message || 'Could not save.';

                return;
            }
            if (d.mode === 'create' && d.parent_id !== null) this.reveal(d.parent_id, false);
            const created = r.data?.folder?.id;
            this.fd = null;
            await this.reload();
            if (created) this.cur = Math.max(0, this.rows.findIndex((x) => x.key === `f${created}`));
            this.focusRoot();
        },

        // ---- delete, share, edit ----

        deleteSelected() {
            if (this.pane === 'tracks') return this.removeTrack(this.tracks[this.tsel]);
            const r = this.row;
            if (r?.kind === 'folder') return this.removeFolder(r.folder);
            if (r?.kind === 'album') return this.removeAlbum(r.album);
        },

        async removeFolder(folder) {
            const sure = await this.$store.dialog.confirm({
                title: 'Delete this folder?',
                message: `“${folder.name}” goes away. Its albums and subfolders move up one level; no music is deleted.`,
                confirmLabel: 'Delete folder',
                danger: true,
            });
            if (!sure) return;
            const r = await send('DELETE', `/library/folders/${folder.id}`);
            if (r.ok) {
                this.openIds = this.openIds.filter((id) => id !== folder.id);
                await this.reload();
            }
        },

        async removeAlbum(album) {
            await this.remove('albums', album.id, album.title, album.tracks.length);
        },

        async removeTrack(track) {
            if (track) await this.remove('tracks', track.id, track.title, 1);
        },

        async remove(kind, id, title, count) {
            const what = kind === 'albums' ? `“${title}” and its ${count} ${count === 1 ? 'file' : 'files'}` : `“${title}”`;
            const sure = await this.$store.dialog.confirm({
                title: kind === 'albums' ? 'Delete this album?' : 'Delete this track?',
                message: this.trashOnly
                    ? `Move ${what} to the trash in Ferrite? You can restore them there.`
                    : `Delete ${what} from the music folder? This cannot be undone.`,
                confirmLabel: this.trashOnly ? 'Move to trash' : 'Delete',
                danger: true,
            });
            if (!sure) return;
            const r = await send('DELETE', `/library/${kind}/${id}`);
            if (!r.ok || r.data?.error) await this.$store.dialog.notice(r.data?.error || r.data?.message || 'Could not remove it.', 'Could not delete');
            if (r.ok || r.data?.removed) await this.reload();
            this.pane = this.pane === 'tracks' && this.tracks.length ? 'tracks' : 'tree';
        },

        isShared(kind, id) {
            return this.shares[kind].includes(id);
        },

        async toggleShare(kind, id) {
            const on = this.isShared(kind, id);
            const r = await send(on ? 'DELETE' : 'POST', `/share/${kind}/${id}`);
            if (r.ok) this.shares[kind] = on ? this.shares[kind].filter((x) => x !== id) : [...this.shares[kind], id];
            else this.say('Could not change sharing.');
        },

        shareSelected() {
            if (this.pane === 'tracks') {
                const t = this.tracks[this.tsel];
                if (t) this.toggleShare('tracks', t.id);
            } else if (this.row?.kind === 'album') this.toggleShare('albums', this.row.id);
        },

        /** Edit details of the selected track (tracks pane) or album. */
        editSelected() {
            const album = this.row?.kind === 'album' ? this.row.album : null;
            if (album) return this.openTable(this.pane === 'tracks' ? this.tsel : null);
            // tracks without an album have no table: one track at a time
            const t = this.pane === 'tracks' ? this.tracks[this.tsel] : null;
            if (t) this.openEdit('tracks', t.id, t.edit);
            else this.say('Select an album to edit it (e)');
        },

        openEdit(kind, id, fields) {
            this.edit = { kind, id, fields: { ...fields }, error: '', saving: false };
            this.afterUpdate((root) => root.querySelector('[x-ref="editFirst"]')?.focus());
        },

        async saveEdit() {
            const e = this.edit;
            e.saving = true;
            e.error = '';
            const r = await send('PATCH', `/library/${e.kind}/${e.id}`, e.fields);
            e.saving = false;
            if (r.ok) {
                this.edit = null;
                await this.reload();
                this.focusRoot();

                return;
            }
            e.error = (r.data?.errors && Object.values(r.data.errors)[0][0]) || r.data?.message || 'Could not save.';
        },

        // ---- analysis ----

        analyseSelected() {
            if (this.pane === 'tracks') {
                const t = this.tracks[this.tsel];
                if (t) this.analyse({ track_id: t.id });
            } else {
                const albums = targetAlbums(this.rows, this.cur, new Set(this.marks), this.folders, this.albums);
                this.analyseAlbums(albums);
            }
        },

        async analyseAlbums(albums) {
            if (!albums.length) return this.say('Select an album, a folder or some marked albums first');
            for (const a of albums) await this.analyse({ album_id: a.id });
        },

        /** `scope` is { album_id } or { track_id }: measure just that. Requests queue behind a run that is going. */
        async analyse(scope) {
            this.analysisError = null;
            const r = await send('POST', '/analysis', scope);
            if (!r.ok) {
                this.analysisError = r.data?.message || 'Could not start the analysis.';
                this.say(this.analysisError);

                return;
            }
            this.analysis = r.data;
            this.followAnalysis();
        },

        async followAnalysis() {
            if (this.following) return; // a request made meanwhile is followed by the loop that is already running
            this.following = true;
            const shown = new Set();
            const finished = () => Object.entries(this.analysis.albums || {}).filter(([id, a]) => ['done', 'failed'].includes(a.state) && !shown.has(id)).map(([id]) => id);
            do {
                while (running(this.analysis)) {
                    await wait(2000);
                    const p = await fetch('/analysis', { headers: { Accept: 'application/json' } }).catch(() => null);
                    if (!p || !p.ok) {
                        this.following = false;

                        return;
                    }
                    this.analysis = await p.json();
                    const ids = finished();
                    if (ids.length) {
                        ids.forEach((id) => shown.add(id));
                        await this.reload(); // the finished album's DR and findings appear while the rest of the queue goes on
                    }
                }
                const rest = finished();
                if (rest.length || !Object.keys(this.analysis.albums || {}).length) await this.reload();
                if (!running(this.analysis)) {
                    const p = await fetch('/analysis', { headers: { Accept: 'application/json' } }).catch(() => null);
                    if (p && p.ok) this.analysis = await p.json();
                }
            } while (running(this.analysis));
            this.following = false;
            this.analysis = { state: 'idle', done: 0, total: 0, failed: 0, albums: {} };
        },

        /** Where an album is in the analysis queue, as a few words for its row. */
        analysisNote(id) {
            const a = this.analysis.albums?.[id];
            if (!a) return '';
            if (a.state === 'queued') return 'queued';
            if (a.state === 'running') return `analysing ${a.done}/${a.total}`;

            return a.failed ? `analysed, ${a.failed} failed` : 'analysed';
        },

        get progress() {
            const a = this.analysis;
            if (a.state === 'queued') return 'Analysis: waiting for the queue worker…';
            if (a.state === 'running') return `Analysing ${a.done}/${a.total}${a.current ? ` · ${a.current}` : ''}${a.failed ? ` · ${a.failed} failed` : ''}`;

            return '';
        },

        // ---- fetching the library again ----

        /** Fetch the library as JSON and swap it in, keeping the selection and what is open or marked where it still exists. */
        async reload() {
            if (this.source !== 'library') return this.refreshSource();
            const r = await fetch('/library', { cache: 'no-store', headers: { Accept: 'application/json', 'Cache-Control': 'no-cache' } }).catch(() => null);
            if (!r || !r.ok) return Livewire.navigate(window.location.href);
            const d = await r.json();
            const key = this.row?.key;
            this.folders = d.folders;
            this.albums = d.albums;
            this.loose = d.loose;
            this.shares = d.shared;
            this.trashOnly = d.trash_only;
            this.suggestions = d.suggestions;
            this.toIdentify = d.to_identify;
            this.trackMarks = this.trackMarks.filter((id) => d.loose.some((t) => t.id === id));
            this.marks = this.marks.filter((id) => d.albums.some((a) => a.id === id));
            this.openIds = this.openIds.filter((id) => d.folders.some((f) => f.id === id));
            this.rebuild();
            const at = this.rows.findIndex((x) => x.key === key);
            if (at >= 0) this.cur = at;
            this.tsel = clamp(this.tracks, this.tsel);
            if (!this.tracks.length) this.pane = 'tree';
            if (this.mode === 'truth') this.rebuildTruth();
        },

        // ---- command line ----

        get cmdContext() {
            return { folders: this.folders.map((f) => f.name), themes: this.$store.player.themes.map((t) => t.id) };
        },

        openCmd(text = '') {
            this.cmd = { text, list: complete(text, this.cmdContext), index: 0 };
            // The box is made by x-if in the same update: ask again a moment later if it was not there yet (as the search box does).
            this.afterUpdate((root) => {
                const input = root.querySelector('[x-ref="cmdInput"]');
                if (input && document.activeElement !== input) input.focus();
            }, 60);
        },

        closeCmd() {
            this.cmd = null;
            this.focusRoot();
        },

        cmdTyped() {
            this.cmd.list = complete(this.cmd.text, this.cmdContext);
            this.cmd.index = 0;
        },

        cmdKey(e) {
            const c = this.cmd;
            if (e.key === 'Escape') this.closeCmd();
            else if (e.key === 'Tab') {
                const hit = c.list[c.index];
                if (hit) {
                    c.text = hit + (parse(hit).name && !hit.includes(' ') ? ' ' : '');
                    this.cmdTyped();
                }
            } else if (e.key === 'ArrowDown') c.index = Math.min(c.list.length - 1, c.index + 1);
            else if (e.key === 'ArrowUp') c.index = Math.max(0, c.index - 1);
            else if (e.key === 'Enter') {
                const line = c.text;
                this.closeCmd();
                this.runCmd(line);
            } else return;
            e.preventDefault();
        },

        /** One typed line. Every command is also a key or a button; the ones that have no key live only here. */
        async runCmd(line) {
            const { name, arg, typed, ambiguous } = parse(line);
            if (!typed) return;
            if (!name) return this.say(ambiguous.length ? `which one: ${ambiguous.join(', ')}?` : `no such command: ${typed}`);
            if (this.demo && !demoAllowsCommand(name)) return this.say(DEMO_NOTE);
            const player = this.$store.player;
            const ui = this.$store.ui;
            if (this.source !== 'library' && this.runSourceCommand(name, arg)) return;
            switch (name) {
                case 'library': case 'shared': case 'playlists': return this.setSource(name);
                case 'copy': return this.say('Copy works from Shared and Playlists (y)');
                case 'sharetracks': return this.say('That is for one of your shared playlists');
                case 'analyze': return this.analyseSelected();
                case 'details': return this.openDetails();
                case 'edit': return this.editSelected();
                case 'cover': return this.findCover();
                case 'merge': return this.mergeMarked();
                case 'group': return this.openGroup();
                case 'suggest': return this.findAlbums();
                case 'move': {
                    if (!arg) return this.openMover();
                    const names = this.folders.map((f) => f.name);
                    const hit = /^(top|\/)$/i.test(arg) ? null : pick(arg, names);
                    if (hit === null && !/^(top|\/)$/i.test(arg)) return this.say(`no folder (or more than one) called “${arg}”: ${names.join(', ') || 'make one with new'}`);

                    return this.moveSelectionTo(hit === null ? null : this.folders.find((f) => f.name === hit).id);
                }
                case 'new': {
                    if (!arg) return this.newFolder();
                    const r = this.row;

                    return this.createFolder(arg, r?.kind === 'folder' ? r.id : r?.kind === 'album' ? r.album.folder_id : null);
                }
                case 'rename': {
                    if (this.row?.kind !== 'folder') return this.say('Select a folder to rename');
                    if (!arg) return this.renameFolder();
                    const res = await send('PATCH', `/library/folders/${this.row.id}`, { name: arg });
                    if (!res.ok) return this.say(res.data?.message ?? 'Could not rename it.');
                    await this.reload();

                    return this.say(`renamed to ${arg}`);
                }
                case 'delete': return this.deleteSelected();
                case 'share': return this.setShared(true);
                case 'unshare': return this.setShared(false);
                case 'sort': {
                    const want = pick(arg, ['library', 'title', 'year', 'dr']);
                    if (!want) return this.say('sort library, title, year or dr');
                    if (want === 'dr' && ui.info === 0) return this.say('DR is not shown (info is off): press I first');
                    this.sort = want;
                    this.rebuild();

                    return this.say(`order: ${want}`);
                }
                case 'problems': return this.toggleProblems();
                case 'search': return this.openSearch(arg);
                case 'truth': return this.mode === 'truth' && !this.onlyProblems ? this.closeView() : this.openTruth(false);
                case 'tree': return this.closeView();
                case 'info': {
                    const level = Number(arg);
                    if (!['0', '1', '2'].includes(arg.trim())) return this.say('info 0, 1 or 2');
                    ui.info = level;
                    ui.save({ info: level });

                    return this.say(`info level ${level}`);
                }
                case 'flat': return this.toggleFlat();
                case 'upload': return this.openUpload();
                case 'tint': return ui.toggleTint();
                case 'hints': ui.toggleHints(); return this.say(ui.hints ? 'key hints on' : 'key hints off');
                case 'theme': {
                    const ids = player.themes.map((t) => t.id);
                    const names = player.themes.map((t) => t.name);
                    const hit = pick(arg, ids) ?? player.themes.find((t) => t.name === pick(arg, names))?.id;
                    if (!hit) return this.say(`themes: ${ids.join(', ')}`);
                    player.setTheme(hit);

                    return this.say(`theme: ${hit}`);
                }
                case 'shuffle': {
                    if (!arg.trim()) return player.cycleShuffle();
                    const hit = pick(arg, SHUFFLES);

                    return hit ? player.setShuffle(hit) : this.say('shuffle off, tracks or albums');
                }
                case 'repeat': {
                    if (!arg.trim()) return player.cycleRepeat();
                    const hit = pick(arg, REPEATS);

                    return hit ? player.setRepeat(hit) : this.say('repeat off, all or one');
                }
                case 'full': player.expanded = !player.expanded; return;
                case 'help': player.helpOpen = true;
            }
        },

        /** Move the marked albums, the selected album or the selected folder into a folder (null: the top level). */
        async moveSelectionTo(folderId) {
            const r = this.row;
            if (r?.kind === 'folder' && !this.marks.length) {
                if (folderAndBelow(this.folders, r.id).includes(folderId)) return this.say('A folder cannot go inside itself');
                const res = await send('PATCH', `/library/folders/${r.id}`, { parent_id: folderId });
                if (!res.ok) return this.say(res.data?.message ?? 'Could not move the folder.');
                if (folderId !== null) this.reveal(folderId, false);
                await this.reload();

                return this.say('Folder moved');
            }
            const ids = targetAlbums(this.rows, this.cur, new Set(this.marks), this.folders, this.albums).map((a) => a.id);
            if (!ids.length) return this.say('Select an album, or mark some with x, first');
            await this.moveAlbums(ids, folderId);
        },

        async createFolder(name, parentId) {
            const r = await send('POST', '/library/folders', { name, parent_id: parentId });
            if (!r.ok) return this.say((r.data?.errors && Object.values(r.data.errors)[0][0]) || r.data?.message || 'Could not make the folder.');
            if (parentId !== null) this.reveal(parentId, false);
            await this.reload();
            this.say(`folder ${name} made`);
        },

        async setShared(on) {
            const items = this.pane === 'tracks'
                ? [['tracks', this.tracks[this.tsel]]]
                : targetAlbums(this.rows, this.cur, new Set(this.marks), this.folders, this.albums).map((a) => ['albums', a]);
            let n = 0;
            for (const [kind, x] of items) {
                if (x && this.isShared(kind, x.id) !== on) {
                    await this.toggleShare(kind, x.id);
                    n++;
                }
            }
            this.say(n ? `${on ? 'shared' : 'no longer shared'}: ${n}` : `already ${on ? 'shared' : 'not shared'}`);
        },

        // ---- the edit table ----

        openTable(track = null) {
            const album = this.row?.kind === 'album' ? this.row.album : null;
            if (!album) return;
            const e = album.edit;
            this.table = {
                id: album.id,
                o: { title: e.title ?? '', artist: e.artist ?? '', release_date: e.release_date ?? '', label: e.label ?? '' },
                title: e.title ?? '', artist: e.artist ?? '', release_date: e.release_date ?? '', label: e.label ?? '',
                rows: album.tracks.map((t) => ({ id: t.id, title: t.title, ot: t.title, artist: t.edit.artist ?? '', oa: t.edit.artist ?? '', number: t.edit.track_number ?? '', on: t.edit.track_number ?? '', time: t.duration })),
                error: '',
                saving: false,
            };
            this.afterUpdate((root) => {
                const at = root.querySelector(track === null ? '[data-table] [data-r="-1"][data-c="1"]' : `[data-table] [data-r="${track}"][data-c="1"]`);
                at?.focus();
                at?.select();
            });
        },

        get tableChanges() {
            const t = this.table;
            if (!t) return 0;
            let n = ['title', 'artist', 'release_date', 'label'].filter((k) => t[k] !== t.o[k]).length;
            for (const r of t.rows) n += (r.title !== r.ot ? 1 : 0) + (r.artist !== r.oa ? 1 : 0) + (String(r.number) !== String(r.on) ? 1 : 0);

            return n;
        },

        tableDirty(field, row = null) {
            const t = this.table;
            if (!t) return false;
            if (!row) return t[field] !== t.o[field];
            const map = { title: ['title', 'ot'], artist: ['artist', 'oa'], number: ['number', 'on'] }[field];

            return String(row[map[0]]) !== String(row[map[1]]);
        },

        tableMove(el, d) {
            const r = Number(el.dataset.r);
            const c = Number(el.dataset.c);
            const to = r + d;
            if (to < -1 || to >= this.table.rows.length) return;
            const col = to === -1 || r === -1 ? (c === 2 ? 2 : 1) : c; // the header has title, artist, date, label; a row has number, title, artist
            const next = this.libraryRoot.querySelector(`[data-table] [data-r="${to}"][data-c="${col}"]`);
            next?.focus();
            next?.select();
        },

        tableKey(e) {
            const t = e.target;
            if (e.key === 'Escape') {
                e.preventDefault();
                this.closeTable();
            } else if ((e.ctrlKey || e.metaKey) && (e.key === 'Enter' || e.key === 's')) {
                e.preventDefault();
                this.saveTable();
            } else if (t.tagName === 'INPUT' && (e.key === 'ArrowDown' || e.key === 'Enter')) {
                e.preventDefault();
                this.tableMove(t, 1);
            } else if (t.tagName === 'INPUT' && e.key === 'ArrowUp') {
                e.preventDefault();
                this.tableMove(t, -1);
            }
        },

        closeTable() {
            this.table = null;
            this.focusRoot();
        },

        tableTitleCase() {
            for (const r of this.table.rows) r.title = titleCase(r.title);
        },

        tableClearArtists() {
            for (const r of this.table.rows) r.artist = '';
        },

        tableReset() {
            const t = this.table;
            Object.assign(t, t.o);
            for (const r of t.rows) Object.assign(r, { title: r.ot, artist: r.oa, number: r.on });
        },

        async saveTable() {
            const t = this.table;
            t.saving = true;
            t.error = '';
            const changed = t.rows.filter((r) => r.title !== r.ot || r.artist !== r.oa || String(r.number) !== String(r.on));
            const r = await send('PATCH', `/library/albums/${t.id}/details`, {
                title: t.title, artist: t.artist, release_date: t.release_date, label: t.label,
                tracks: changed.map((x) => ({ id: x.id, title: x.title, artist: x.artist, track_number: x.number === '' ? null : Number(x.number) })),
            });
            t.saving = false;
            if (!r.ok) {
                t.error = (r.data?.errors && Object.values(r.data.errors)[0][0]) || r.data?.message || 'Could not save.';

                return;
            }
            const n = this.tableChanges;
            this.table = null;
            await this.reload();
            this.focusRoot();
            this.say(n ? `saved ${n} ${n === 1 ? 'change' : 'changes'}` : 'nothing changed');
        },

        // ---- details found online (MusicBrainz): a diff to tick through ----

        openDetails() {
            // With a track selected in the tracks pane `i` is about that track; the album is looked up from the tree.
            const track = this.pane === 'tracks' ? this.tracks[this.tsel] : null;
            if (track) return this.findTrack(track);
            const r = this.row;
            if (r?.kind === 'loose') return this.findAlbums();
            if (r?.kind !== 'album') return this.say('Select an album to look up (i)');
            this.findTitles(r.album.id, r.album.title);
        },

        async findTitles(id, title) {
            this.titles = { id, title, loading: true, album: {}, candidates: [], report: [], progress: '', pick: 0, parts: { date: false, label: false, titles: false }, error: '', saving: false };
            const t = this.titles;
            // The search is one request that can take many seconds (fingerprints); ask what it is doing meanwhile.
            const poll = setInterval(async () => {
                const p = await send('GET', `/library/albums/${id}/titles/progress`);
                if (p.ok && t.loading) t.progress = p.data.text || '';
            }, 1000);
            const r = await send('POST', `/library/albums/${id}/titles/find`);
            clearInterval(poll);
            if (r.ok) {
                t.album = r.data.album;
                t.candidates = r.data.candidates;
                t.report = r.data.report || [];
                this.pickRelease(0);
            } else t.error = r.data?.message || 'Could not search.';
            t.loading = false;
        },

        // ---- one track: what AcoustID knows (title, artist, number), applied only where ticked ----

        async findTrack(track) {
            this.lookup = { id: track.id, title: track.title, loading: true, progress: '', candidates: [], track: {}, pick: 0, release: 0, parts: { title: false, artist: false, number: false }, error: '', saving: false };
            const l = this.lookup;
            const poll = setInterval(async () => {
                const p = await send('GET', `/library/tracks/${track.id}/lookup/progress`);
                if (p.ok && l.loading) l.progress = p.data.text || '';
            }, 1000);
            const r = await send('POST', `/library/tracks/${track.id}/lookup`);
            clearInterval(poll);
            if (r.ok) {
                l.track = r.data.track;
                l.candidates = r.data.candidates;
                this.pickRecording(0);
            } else l.error = r.data?.message || 'Could not look the track up.';
            l.loading = false;
        },

        /** Choosing a recording picks its first release and ticks what differs from the track as it is. */
        pickRecording(i) {
            const l = this.lookup;
            const c = l.candidates[i];
            l.pick = i;
            l.release = 0;
            if (!c) return;
            l.parts = { title: c.title !== l.track.title, artist: !!c.artist && c.artist !== (l.track.artist || ''), number: false };
            this.pickTrackRelease(0);
        },

        pickTrackRelease(j) {
            const l = this.lookup;
            const rel = l.candidates[l.pick]?.releases[j];
            l.release = j;
            l.parts.number = !!rel && rel.number != null && rel.number !== l.track.track_number;
        },

        get lookupHasParts() {
            return !!this.lookup && Object.values(this.lookup.parts).some(Boolean);
        },

        async applyLookup() {
            const l = this.lookup;
            l.saving = true;
            l.error = '';
            const r = await send('POST', `/library/tracks/${l.id}/lookup/apply`, { candidate: l.pick, release: l.release, parts: Object.keys(l.parts).filter((k) => l.parts[k]) });
            l.saving = false;
            if (r.ok) {
                this.lookup = null;
                await this.reload();
                this.focusRoot();

                return this.say('track updated');
            }
            l.error = r.data?.message || 'Could not save.';
        },

        closeLookup() {
            this.lookup = null;
            this.focusRoot();
        },

        /** Choosing a release ticks what it would change: a different date or label, and track titles that differ. */
        pickRelease(i) {
            const t = this.titles;
            const c = t.candidates[i];
            if (!c) return;
            t.pick = i;
            t.parts = {
                date: !!c.date && c.date !== t.album.release_date,
                label: !!c.label && c.label !== t.album.label,
                titles: c.tracks.some((row) => row.matched && row.title !== row.current),
            };
        },

        get anyPart() {
            return !!this.titles && Object.values(this.titles.parts).some(Boolean);
        },

        async applyTitles() {
            const t = this.titles;
            t.saving = true;
            t.error = '';
            const r = await send('POST', `/library/albums/${t.id}/titles`, { candidate: t.pick, parts: Object.keys(t.parts).filter((k) => t.parts[k]) });
            t.saving = false;
            if (r.ok) {
                this.titles = null;
                await this.reload();
                this.focusRoot();

                return this.say('details applied');
            }
            t.error = r.data?.message || 'Could not save.';
        },

        closeTitles() {
            this.titles = null;
            this.focusRoot();
        },

        // ---- tracks without an album: suggestions and grouping ----

        async findAlbums() {
            if (!this.toIdentify) return this.say('No folders of tracks without an album tag to look at');
            const r = await send('POST', '/identify');
            if (!r.ok) return this.say('Could not start the search.');
            this.identify = r.data;
            while (running(this.identify)) {
                await wait(2000);
                const p = await fetch('/identify', { headers: { Accept: 'application/json' } }).catch(() => null);
                if (!p || !p.ok) return;
                this.identify = await p.json();
            }
            await this.reload();
            this.say(`${this.suggestions.length} suggestion${this.suggestions.length === 1 ? '' : 's'}`);
        },

        async answerSuggestion(id, accept, candidate) {
            const r = await send(accept ? 'POST' : 'DELETE', `/identify/${id}${accept ? '/accept' : ''}`, accept ? { candidate } : undefined);
            if (r.ok) {
                await this.reload();
                this.say(accept ? 'album named' : 'dismissed');
            }
        },

        get identifyNote() {
            const i = this.identify;
            if (i.state === 'queued') return 'Waiting for the queue worker…';
            if (i.state === 'running') return `Looking ${i.done}/${i.total}…`;

            return '';
        },

        toggleTrackMark() {
            if (!this.shown?.loose) return this.say('Mark tracks without an album, to name an album for them (:group)');
            const t = this.tracks[this.tsel];
            if (!t) return;
            this.trackMarks = this.trackMarks.includes(t.id) ? this.trackMarks.filter((id) => id !== t.id) : [...this.trackMarks, t.id];
            this.tsel = clamp(this.tracks, this.tsel + 1);
        },

        openGroup() {
            if (!this.loose.length) return this.say('No tracks without an album');
            const ids = this.trackMarks.length ? this.trackMarks : this.loose.map((t) => t.id);
            this.gd = { ids, title: '', artist: '', year: '', error: '', saving: false };
            this.afterUpdate((root) => root.querySelector('[x-ref="groupTitle"]')?.focus());
        },

        async saveGroup() {
            const g = this.gd;
            g.saving = true;
            g.error = '';
            const r = await send('POST', '/library/albums', { title: g.title, artist: g.artist || null, year: g.year === '' ? null : Number(g.year), track_ids: g.ids });
            g.saving = false;
            if (!r.ok) {
                g.error = (r.data?.errors && Object.values(r.data.errors)[0][0]) || r.data?.message || 'Could not make the album.';

                return;
            }
            const id = r.data?.data?.id ?? r.data?.id;
            this.gd = null;
            this.trackMarks = [];
            await this.reload();
            const at = this.rows.findIndex((x) => x.key === `a${id}`);
            if (at >= 0) this.cur = at;
            this.focusRoot();
            this.say('album made');
        },

        // ---- covers and merging ----

        async findCover() {
            const album = this.row?.kind === 'album' ? this.row.album : null;
            if (!album) return this.say('Select an album to find a cover for (C)');
            this.say('Looking for a cover…', 15000);
            const r = await send('POST', `/cover/${album.id}/find`);
            if (!r.ok) return this.say(r.data?.message || 'Could not search for a cover.');
            if (!r.data.source) return this.say(album.cover ? 'No other match found' : 'No cover found');
            await this.reload();
            this.say(r.data.another ? 'Cover replaced' : `Cover found (${r.data.source})`);
        },

        /** The marked albums join the selected one (one album that was split in several). */
        async mergeMarked() {
            const into = this.row?.kind === 'album' ? this.row.album : null;
            const others = this.marks.filter((id) => id !== into?.id);
            if (!into || !others.length) return this.say('Mark the albums to join (x), then select the one they join and merge');
            const sure = await this.$store.dialog.confirm({
                title: 'Merge albums?',
                message: `Join ${others.length} ${others.length === 1 ? 'album' : 'albums'} into “${into.title}”. Their tracks move into it and the emptied albums go away. Changes the library only, not the files or their tags.`,
                confirmLabel: 'Merge',
            });
            if (!sure) return;
            let done = 0;
            for (const id of others) {
                const r = await send('POST', `/library/albums/${id}/merge`, { into: into.id });
                if (r.ok) done++;
            }
            this.marks = [];
            this.selectMode = false;
            await this.reload();
            this.say(`merged ${done} ${done === 1 ? 'album' : 'albums'}`);
        },

        // ---- search ----

        get hit() {
            return searchItems(this.search)[this.scur] ?? null;
        },

        get searchList() {
            return searchItems(this.search);
        },

        openSearch(text = '') {
            // The box is already in the page (hidden from sight while closed): focus it right here, inside the click or tap. Safari
            // only opens the keyboard for a focus() made by the gesture itself, never for one made after a delay.
            this.libraryRoot.querySelector('[x-ref="searchInputTop"]')?.focus({ preventScroll: true });
            this.useLibrary(); // the search shows your library's rows
            this.mode = 'search';
            this.typing = true;
            if (text) this.search.q = text;
            this.focusSearch();
            // on a phone the list must be at the top of the screen, above the keyboard
            if (!window.matchMedia('(min-width: 768px)').matches) this.afterUpdate((root) => root.querySelector('.panes')?.scrollIntoView({ block: 'start', behavior: 'smooth' }), 80);
            if (text) this.afterUpdate(() => this.searchTyped());
        },

        /** The box is shown by x-show in the same update that changes the mode: ask again a moment later if it was not there yet. */
        focusSearch() {
            this.afterUpdate((root) => {
                const input = root.querySelector('[x-ref="searchInputTop"]');
                if (input && document.activeElement !== input) input.focus();
            }, 60);
        },

        closeView() {
            this.mode = 'tree';
            this.typing = false;
            this.search = emptySearch();
            this.scur = 0;
            this.focusRoot();
        },

        /** The box changed: ask the server (two letters or more; the answer for an older text is dropped). */
        async searchTyped() {
            const q = this.search.q.trim();
            const stamp = (this.searchStamp = (this.searchStamp ?? 0) + 1);
            if (q.length < 2) {
                this.search = { ...emptySearch(), q: this.search.q };
                this.scur = 0;

                return;
            }
            this.search.loading = true;
            this.search.error = '';
            const data = await this.fetchSearch(q, 1);
            if (stamp !== this.searchStamp) return;
            this.search = data ? { ...appendPage(emptySearch(), data), q: this.search.q } : { ...emptySearch(), q: this.search.q, error: 'Search failed' };
            this.scur = 0;
        },

        async fetchSearch(q, page) {
            const r = await fetch(`/search?${new URLSearchParams({ q, page })}`, { headers: { Accept: 'application/json' } }).catch(() => null);

            return r && r.ok ? r.json() : null;
        },

        /** Near the end of the list: the next page is added. */
        async moreSearch() {
            const s = this.search;
            if (!wantsMore(s, this.scur, this.searchList.length)) return;
            s.loading = true;
            const data = await this.fetchSearch(s.q.trim(), s.page + 1);
            this.search.loading = false;
            if (data) this.search = { ...appendPage(this.search, data), q: this.search.q };
        },

        /** Keys in the box: Enter or the down arrow go to the results, Esc closes the search. */
        searchKey(e) {
            if (e.key === 'Escape') this.closeView();
            else if ((e.key === 'Enter' || e.key === 'ArrowDown') && this.searchList.length) {
                this.typing = false;
                this.focusRoot();
            } else return;
            e.preventDefault();
        },

        runSearch(action) {
            const n = this.searchList.length;
            const move = (to) => {
                this.scur = clamp(this.searchList, to);
                this.scrollToSelection();
                this.moreSearch();
            };
            switch (action) {
                case 'down': return move(this.scur + 1);
                case 'up': return move(this.scur - 1);
                case 'pagedown': return move(this.scur + 10);
                case 'pageup': return move(this.scur - 10);
                case 'top': return move(0);
                case 'bottom': return move(n - 1);
                case 'enter': return this.enterHit();
                case 'query': this.typing = true; this.focusSearch(); return;
                case 'command': return this.openCmd();
                case 'close': return this.closeView();
            }
        },

        /** A track hit plays (the track hits are the queue); an album hit of yours opens in the tree, one that is shared goes to the shared page. */
        enterHit() {
            const h = this.hit;
            if (!h) return;
            if (h.kind === 'track') {
                const [queue, index] = queueFrom(this.search, h.track.id);
                this.$store.player.setQueue([...queue], index);

                return;
            }
            if (h.album.mine) return this.goToAlbum(h.album.id);
            Livewire.navigate('/shared');
        },

        goToAlbum(id) {
            const album = this.albums.find((a) => a.id === id);
            if (!album) return this.say('That album is not in your library');
            this.mode = 'tree';
            this.search = emptySearch();
            this.reveal(album.folder_id, false);
            this.rebuild();
            this.cur = Math.max(0, rowOfAlbum(this.rows, id));
            this.tsel = 0;
            this.gsel = 0;
            this.gridLimit = 60;
            this.pane = 'tree';
            this.focusRoot();
            this.scrollToSelection();
        },

        // ---- the truth view ----

        /** The Truth view (every file) or, with `only`, the Findings view (the files with a finding): two views of the same list. */
        openTruth(only = false) {
            this.useLibrary();
            this.mode = 'truth';
            this.onlyProblems = only;
            this.detail = false;
            this.tcur = 0;
            this.truthLimit = 300;
            this.rebuildTruth();
            this.focusRoot();
            this.say(`${only ? 'files with findings' : 'every file'}, ${TRUTH_SORT_NAMES[this.truthSort]}`);
        },

        get truthShown() {
            return this.truth.slice(0, this.truthLimit);
        },

        rebuildTruth() {
            this.truth = truthRows(this.albums, this.loose, { sort: this.truthSort, only: this.onlyProblems });
            this.tcur = clamp(this.truth, this.tcur);
        },

        runTruth(action) {
            const move = (to) => {
                this.tcur = clamp(this.truth, to);
                if (this.tcur >= this.truthLimit - 20) this.truthLimit = Math.min(this.truth.length, this.tcur + 300);
                this.scrollToSelection();
            };
            switch (action) {
                case 'down': return move(this.tcur + 1);
                case 'up': return move(this.tcur - 1);
                case 'pagedown': return move(this.tcur + 10);
                case 'pageup': return move(this.tcur - 10);
                case 'top': return move(0);
                case 'bottom': return move(this.truth.length - 1);
                case 'play': return this.playTruth(this.tcur);
                case 'sort': this.truthSort = nextTruthSort(this.truthSort); this.rebuildTruth(); return this.say(`order: ${TRUTH_SORT_NAMES[this.truthSort]}`);
                case 'problems': return this.toggleProblems();
                case 'analyse': {
                    const t = this.truth[this.tcur]?.track;
                    if (t) this.analyse({ track_id: t.id });

                    return;
                }
                case 'search': return this.openSearch();
                case 'command': return this.openCmd();
                case 'close': return this.closeView();
            }
        },

        /** Play from this file: the list as shown is the queue. */
        playTruth(i) {
            if (!this.truth[i]) return;
            this.tcur = i;
            this.$store.player.setQueue(this.truth.map((r) => r.track), i);
        },

        flagCount(t) {
            return findings(t, 99).length;
        },

        // ---- looks ----

        /** The findings worth a label on a track row: problems and warnings, worst first. */
        flags(t) {
            return findings(t, 2);
        },

        /** Problems among an album's tracks (the mark in the tree). */
        problemCount(album) {
            return album ? album.tracks.filter(hasProblem).length : 0;
        },

        levelClass(l) {
            return { problem: 'text-red-500', warn: 'text-amber-500', note: 'text-muted' }[l] ?? 'text-muted';
        },

        db(v, unit = '') {
            return v === null || v === undefined ? '–' : (v > 0 ? '+' : '') + v.toFixed(1) + unit;
        },

        khz(hz) {
            return hz === null || hz === undefined ? '–' : (hz / 1000).toFixed(1) + ' kHz';
        },

        /** The points of the average-spectrum line (200 x 60 box, -130 to -20 dB). */
        /** The long spectrum curve is not in the page data: fetch it once, when a track's measurements are opened. */
        async ensureSpectrum(t) {
            if (this.spectra[t.id]) return;
            if (t.truth.spectrum) return (this.spectra = { ...this.spectra, [t.id]: { spectrum: t.truth.spectrum, step_hz: t.truth.step_hz } }); // the demo tracks carry it
            this.spectra = { ...this.spectra, [t.id]: { spectrum: [], step_hz: t.truth.step_hz } }; // asked once
            const r = await fetch(`/library/tracks/${t.id}/spectrum`, { headers: { Accept: 'application/json' } }).catch(() => null);
            if (r && r.ok) this.spectra = { ...this.spectra, [t.id]: await r.json() };
        },

        spark(t) {
            const s = this.spectra[t.id]?.spectrum ?? [];
            if (s.length < 2) return '';

            return s.map((v, i) => (i / (s.length - 1) * 200).toFixed(1) + ',' + (60 - (Math.max(-130, Math.min(-20, v)) + 130) / 110 * 60).toFixed(1)).join(' ');
        },

        cutX(t) {
            const n = t.truth.spectrum_points;

            return n ? (t.truth.cutoff_hz / (t.truth.step_hz * n)) * 200 : 0;
        },

        drClass(c) {
            return { excellent: 'text-emerald-500', good: 'text-lime-500', fair: 'text-amber-500', poor: 'text-red-500' }[c] ?? 'text-muted';
        },

        fmt(s) {
            return this.$store.player.fmt(s);
        },

        summary(tracks) {
            return this.$store.player.summary(tracks);
        },

        /** The tracks list takes drags while one of your playlists is shown: Sortable is made when first needed and switched with the state. */
        syncSortable(on) {
            const list = this.libraryRoot?.querySelector('[data-list="tracks"]');
            if (!this.sortable && list) {
                this.sortable = Sortable.create(list, {
                    handle: '[data-handle]',
                    draggable: 'li',
                    animation: 150,
                    ghostClass: 'opacity-40',
                    // The browser's own drag and drop does not work with touch; this works with mouse, pen and finger alike.
                    forceFallback: true,
                    onEnd: (e) => this.dropped(e),
                });
            }
            this.sortable?.option('disabled', !on);
        },

        ...sourceMethods,
    };
}

