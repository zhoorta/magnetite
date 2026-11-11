import { Livewire } from '../../../../vendor/livewire/livewire/dist/livewire.esm';
import { send } from '../http';
import { actionFor, isTextTarget } from './keymap';
import { complete, parse, pick } from './commands';
import { findings, hasProblem } from './info';
import { appendPage, emptySearch, queueFrom, searchItems, wantsMore } from './search';
import { TRUTH_SORT_NAMES, nextTruthSort, truthRows } from './truth';
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
        trashOnly: data.trash_only,

        rows: [],
        openIds: [], // ids of the open folders
        marks: [], // ids of the marked albums
        selectMode: false, // tap rows to mark them (touch)
        cur: 0, // selected row of the tree
        tsel: 0, // selected track of the shown album
        pane: 'tree',
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
        cmd: null, // the command line: { text, list, index }
        table: null, // the edit table
        titles: null, // details found online for an album
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
            this.openIds = this.folders.filter((f) => storage((s) => s.getItem(OPEN_KEY(f.id))) === '1').map((f) => f.id);
            // Start on the album that is playing, if there is one, with the way to it open.
            const playing = this.$store.player.current?.album_id;
            const album = this.albums.find((a) => a.id === playing);
            if (album) this.reveal(album.folder_id, false);
            this.rebuild();
            if (album) this.cur = Math.max(0, rowOfAlbum(this.rows, album.id));
            this.$nextTick(() => this.focusRoot()); // the keys work as soon as the page is there
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
            document.documentElement.style.removeProperty('--status-h');
            document.documentElement.style.removeProperty('--panes-top');
        },

        // ---- what is shown ----

        rebuild() {
            this.rows = buildRows({ folders: this.folders, albums: this.albums, loose: this.loose }, new Set(this.openIds), this.sort);
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
            if (r?.kind === 'loose') return { id: null, title: 'Without album tag', artist: null, tracks: this.loose, loose: true };

            return null;
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
            const a = this.row?.kind === 'album' ? this.row.album : null;

            return a ? { kind: 'album', id: a.id, title: a.title } : null;
        },

        get playingAlbumId() {
            return this.$store.player.current?.album_id ?? null;
        },

        /** Does this folder, or one below it, hold the album that is playing? */
        holdsPlaying(folderId) {
            const album = this.albums.find((a) => a.id === this.playingAlbumId);

            return !!album && ancestorIds(this.folders, album.folder_id).includes(folderId);
        },

        /** What the selection is, for the actions menu: 'folder', 'album', 'loose' (tracks without an album), 'track' (in the tracks pane), or null. */
        get ctx() {
            if (this.mode !== 'tree') return null;
            if (this.pane === 'tracks' && this.tracks[this.tsel]) return 'track';
            const r = this.row;

            return r ? r.kind : null;
        },

        /** Right-click on a track: select it and open the actions menu (for the track). */
        trackContext(i) {
            this.focusRoot();
            this.pane = 'tracks';
            this.tsel = i;
            this.afterUpdate((root) => root.querySelector('[data-actions]')?.click());
        },

        /** Right-click on a row: select it and open the actions menu. */
        rowContext(i) {
            this.focusRoot();
            this.pane = 'tree';
            this.cur = i;
            this.tsel = 0;
            this.afterUpdate((root) => root.querySelector('[data-actions]')?.click());
        },

        get hasAnything() {
            return this.albums.length > 0 || this.loose.length > 0;
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
            if (this.fd || this.edit || this.table || this.titles || this.gd || this.cmd || this.typing) return;
            const action = actionFor(this.mode === 'tree' ? this.pane : this.mode, e);
            if (!action) return;
            e.preventDefault();
            this.run(action);
        },

        run(action) {
            if (this.mode === 'search') return this.runSearch(action);
            if (this.mode === 'truth') return this.runTruth(action);
            if (action === 'search') return this.openSearch();
            const tree = this.pane === 'tree';
            const move = (to) => {
                if (tree) {
                    this.cur = clamp(this.rows, to);
                    this.tsel = 0;
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
                case 'detail': return this.toggleDetail();
                case 'problems': return this.toggleProblems();
                case 'play': return this.playTrack(this.tsel);
                case 'analyse': return this.analyseSelected();
                case 'undo': return this.undoLast();
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
            if (!this.tracks.length) return this.say(this.row?.kind === 'folder' ? 'Open the folder with l and pick an album' : 'No tracks here');
            this.pane = 'tracks';
            this.tsel = clamp(this.tracks, this.tsel);
        },

        // ---- folders and the tree ----

        setOpen(id, on) {
            this.openIds = on ? [...new Set([...this.openIds, id])] : this.openIds.filter((x) => x !== id);
            storage((s) => (on ? s.setItem(OPEN_KEY(id), '1') : s.removeItem(OPEN_KEY(id))));
            const key = this.row?.key;
            this.rebuild();
            this.cur = Math.max(0, this.rows.findIndex((r) => r.key === key));
        },

        /** Open a folder and the ones around it, so a moved album is where the eye lands. */
        reveal(folderId, rebuild = true) {
            for (const id of ancestorIds(this.folders, folderId)) {
                if (!this.openIds.includes(id)) this.openIds.push(id);
                storage((s) => s.setItem(OPEN_KEY(id), '1'));
            }
            if (rebuild) this.rebuild();
        },

        enter() {
            const r = this.row;
            if (r?.kind === 'folder') return this.setOpen(r.id, !this.openIds.includes(r.id));
            if (this.tracks.length) this.playTrack(0, false);
        },

        playTrack(i, focus = true) {
            if (!this.tracks[i]) return;
            if (focus && this.pane === 'tracks') this.tsel = i;
            // a copy: the page edits its own array, the player keeps the queue it was given
            this.$store.player.setQueue([...this.tracks], i);
        },

        toggleMark() {
            const r = this.row;
            if (r?.kind !== 'album') return this.say('Mark albums, not folders: select a folder\'s albums and press x on each');
            this.marks = this.isMarked(r.id) ? this.marks.filter((id) => id !== r.id) : [...this.marks, r.id];
            this.cur = clamp(this.rows, this.cur + 1);
            this.scrollToSelection();
        },

        toggleSelectMode() {
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
            const r = this.rows[i];
            if (r.kind === 'folder') this.setOpen(r.id, !this.openIds.includes(r.id));
            else if (r.kind === 'album' && (this.selectMode || ev.ctrlKey || ev.metaKey)) {
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
            if (ev.pointerType === 'mouse' || this.rows[i].kind !== 'album') return;
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

        trackClick(i) {
            this.focusRoot();
            this.pane = 'tracks';
            this.tsel = i;
        },

        dragStart(ev, i) {
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
            const what = kind === 'albums' ? `“${title}” and its ${count} file(s)` : `“${title}”`;
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
            this.afterUpdate((root) => root.querySelector('[x-ref="cmdInput"]')?.focus());
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
            const player = this.$store.player;
            const ui = this.$store.ui;
            switch (name) {
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
                case 'tint': return ui.toggleTint();
                case 'theme': {
                    const ids = player.themes.map((t) => t.id);
                    const names = player.themes.map((t) => t.name);
                    const hit = pick(arg, ids) ?? player.themes.find((t) => t.name === pick(arg, names))?.id;
                    if (!hit) return this.say(`themes: ${ids.join(', ')}`);
                    player.setTheme(hit);

                    return this.say(`theme: ${hit}`);
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
            const r = this.row;
            if (r?.kind === 'loose') return this.findAlbums();
            if (r?.kind !== 'album') return this.say('Select an album to look up (i)');
            this.findTitles(r.album.id, r.album.title);
        },

        async findTitles(id, title) {
            this.titles = { id, title, loading: true, album: {}, candidates: [], pick: 0, parts: { date: false, label: false, titles: false }, error: '', saving: false };
            const t = this.titles;
            const r = await send('POST', `/library/albums/${id}/titles/find`);
            if (r.ok) {
                t.album = r.data.album;
                t.candidates = r.data.candidates;
                this.pickRelease(0);
            } else t.error = r.data?.message || 'Could not search.';
            t.loading = false;
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
                message: `Join ${others.length} ${others.length === 1 ? 'album' : 'albums'} into “${into.title}”. Their tracks move into it and the emptied albums go away. Your files are not touched.`,
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
            this.mode = 'search';
            this.typing = true;
            if (text) this.search.q = text;
            this.focusSearch();
            if (text) this.afterUpdate(() => this.searchTyped());
        },

        /** The box is shown by x-show in the same update that changes the mode: ask again a moment later if it was not there yet. */
        focusSearch() {
            this.afterUpdate((root) => {
                const input = root.querySelector('[x-ref="searchInput"]');
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
            this.pane = 'tree';
            this.focusRoot();
            this.scrollToSelection();
        },

        // ---- the truth view ----

        /** The Truth view (every file) or, with `only`, the Findings view (the files with a finding): two views of the same list. */
        openTruth(only = false) {
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
    };
}

