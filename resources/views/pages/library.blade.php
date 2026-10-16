@php
    // One shape for both kinds of section: a list of tracks with their file-truth findings.
    $rows = fn ($tracks) => $tracks->map(fn ($t) => $t->toLibrary($copies[$t->id] ?? []))->values();
    $analysed = $albums->flatMap->tracks->merge($loose)->contains(fn ($t) => $t->analysis !== null);
    $needsAnalysis = fn ($album) => $album->tracks->contains(fn ($t) => $t->analysis_version === null || $t->analysis_version < App\Services\TrackAnalyzer::VERSION);
@endphp
<x-layouts.app title="Library · Magnetite">
    <div x-data="{
            open: null,
            shares: @js($shared),
            isShared(kind, id) { return this.shares[kind].includes(id); },
            async toggleShare(kind, id) {
                const on = this.isShared(kind, id);
                const r = await fetch('/share/' + kind + '/' + id, {
                    method: on ? 'DELETE' : 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                });
                if (r.ok) this.shares[kind] = on ? this.shares[kind].filter((x) => x !== id) : [...this.shares[kind], id];
            },
            async remove(kind, id, title, count) {
                const what = kind === 'albums' ? '“' + title + '” and its ' + count + ' file(s)' : '“' + title + '”';
                const trash = @js($trashOnly);
                const sure = await this.$store.dialog.confirm({
                    title: kind === 'albums' ? 'Delete this album?' : 'Delete this track?',
                    message: trash
                        ? 'Move ' + what + ' to the trash in Ferrite? You can restore them there.'
                        : 'Delete ' + what + ' from the music folder? This cannot be undone.',
                    confirmLabel: trash ? 'Move to trash' : 'Delete',
                    danger: true,
                });
                if (! sure) return;
                const r = await fetch('/library/' + kind + '/' + id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' } });
                const body = await r.json().catch(() => ({}));
                if (! r.ok || body.error) await this.$store.dialog.notice(body.error || body.message || 'Could not remove it.', 'Could not delete');
                if (r.ok || body.removed) await this.refresh();
            },
            csrf() { return document.querySelector('meta[name=csrf-token]').content; },
            // Details looked up online for an album that already has its real name:
            // { id, title, loading, album: { release_date, label }, candidates, pick, parts: { date, label, titles }, error, saving }.
            titles: null,
            async findTitles(id, title) {
                this.titles = { id, title, loading: true, album: {}, candidates: [], pick: 0, parts: { date: false, label: false, titles: false }, error: '', saving: false };
                const t = this.titles;
                const r = await fetch('/library/albums/' + id + '/titles/find', { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' } }).catch(() => null);
                const body = r ? await r.json().catch(() => ({})) : {};
                if (r && r.ok) { t.album = body.album; t.candidates = body.candidates; this.pickRelease(0); } else t.error = body.message || 'Could not search.';
                t.loading = false; // last, so nothing-found is never shown before the answer is in
            },
            // Choosing a release ticks what it would change: a different date or label, and track titles that differ.
            pickRelease(i) {
                const t = this.titles, c = t.candidates[i];
                if (! c) return;
                t.pick = i;
                t.parts = {
                    date: !! c.date && c.date !== t.album.release_date,
                    label: !! c.label && c.label !== t.album.label,
                    titles: c.tracks.some((row) => row.matched && row.title !== row.current),
                };
            },
            get anyPart() { return this.titles && Object.values(this.titles.parts).some(Boolean); },
            async applyTitles() {
                const t = this.titles;
                t.saving = true; t.error = '';
                const r = await fetch('/library/albums/' + t.id + '/titles', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ candidate: t.pick, parts: Object.keys(t.parts).filter((k) => t.parts[k]) }),
                }).catch(() => null);
                t.saving = false;
                if (r && r.ok) { this.titles = null; await this.refresh(); return; }
                t.error = (r && (await r.json().catch(() => ({}))).message) || 'Could not save.';
            },
            // The owner's folders (flat, with depth, for the pickers) and which are open, remembered in this browser.
            folderList: @js($folderList),
            albumFolder: @js($albumFolder),
            // Does this folder, or one below it, hold the album that is playing? Marks the way to it.
            holdsPlaying(id) {
                const album = Alpine.store('player').current?.album_id;
                for (let f = this.folderList.find((x) => x.id === this.albumFolder[album]); f; f = this.folderList.find((x) => x.id === f.parent_id)) if (f.id === id) return true;
                return false;
            },
            folderIsOpen(id) { try { return localStorage.getItem('magnetite.folder.' + id) === '1'; } catch (e) { return false; } },
            rememberFolder(id, open) { try { open ? localStorage.setItem('magnetite.folder.' + id, '1') : localStorage.removeItem('magnetite.folder.' + id); } catch (e) {} },
            // Open a folder and the ones around it, so a moved album is where the eye lands.
            reveal(id) {
                for (let f = this.folderList.find((x) => x.id === id); f; f = this.folderList.find((x) => x.id === f.parent_id)) this.rememberFolder(f.id, true);
            },
            // mode: 'create' | 'rename' | 'move' (a folder) | 'album'. parent_id is the folder chosen in the list (null: the top).
            fd: null,
            openFolder(mode, fields = {}) {
                const below = (id) => this.folderList.filter((f) => f.parent_id === id).flatMap((f) => [f.id, ...below(f.id)]);
                this.fd = { mode, id: null, name: '', ...fields, parent_id: fields.parent_id ?? '', exclude: mode === 'move' ? [fields.id, ...below(fields.id)] : [], error: '', saving: false };
                this.$nextTick(() => (this.$refs.folderName ?? this.$refs.folderParent)?.focus());
            },
            async saveFolder() {
                const d = this.fd;
                d.saving = true; d.error = '';
                const parent = d.parent_id === '' ? null : Number(d.parent_id);
                const req = {
                    create: ['/library/folders', 'POST', { name: d.name, parent_id: parent }],
                    rename: ['/library/folders/' + d.id, 'PATCH', { name: d.name }],
                    move: ['/library/folders/' + d.id, 'PATCH', { parent_id: parent }],
                    album: ['/library/albums/' + d.id + '/folder', 'PUT', { folder_id: parent }],
                }[d.mode];
                const r = await fetch(req[0], {
                    method: req[1],
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify(req[2]),
                }).catch(() => null);
                d.saving = false;
                if (r && r.ok) {
                    if (parent !== null && d.mode !== 'rename') this.reveal(parent);
                    this.fd = null;
                    // An album moved: only the list is fetched again, in the background (the folders and their counts change, the page does not).
                    if (d.mode === 'album') {
                        const { [d.id]: _, ...rest } = this.albumFolder;
                        this.albumFolder = parent === null ? rest : { ...rest, [d.id]: parent };
                        await this.refresh();
                        return;
                    }
                    Livewire.navigate(window.location.href); return;
                }
                const body = r ? await r.json().catch(() => ({})) : {};
                d.error = (body.errors && Object.values(body.errors)[0][0]) || body.message || 'Could not save.';
            },
            async removeFolder(id, name) {
                const sure = await this.$store.dialog.confirm({
                    title: 'Delete this folder?',
                    message: '“' + name + '” goes away. Its albums and subfolders move up one level; no music is deleted.',
                    confirmLabel: 'Delete folder', danger: true,
                });
                if (! sure) return;
                const r = await fetch('/library/folders/' + id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' } }).catch(() => null);
                if (r && r.ok) Livewire.navigate(window.location.href);
            },
            // Corrections to titles, artists, year, track number and label: { kind: 'albums' | 'tracks', id, fields, error, saving }.
            edit: null,
            openEdit(kind, id, fields) {
                this.edit = { kind, id, fields: { ...fields }, error: '', saving: false };
                this.$nextTick(() => this.$refs.editFirst?.focus());
            },
            async saveEdit() {
                const e = this.edit;
                e.saving = true; e.error = '';
                const r = await fetch('/library/' + e.kind + '/' + e.id, {
                    method: 'PATCH',
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify(e.fields),
                }).catch(() => null);
                e.saving = false;
                if (r && r.ok) { this.edit = null; await this.refresh(); return; }
                const body = r ? await r.json().catch(() => ({})) : {};
                e.error = (body.errors && Object.values(body.errors)[0][0]) || body.message || 'Could not save.';
            },
            identify: { state: 'idle', done: 0, total: 0, found: 0 },
            async findAlbums() {
                const r = await fetch('/identify', { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' } });
                if (! r.ok) return;
                this.identify = await r.json();
                while (['queued', 'running'].includes(this.identify.state)) {
                    await new Promise((res) => setTimeout(res, 2000));
                    const p = await fetch('/identify', { headers: { 'Accept': 'application/json' } });
                    if (! p.ok) return;
                    this.identify = await p.json();
                }
                await this.refresh();
            },
            async answer(id, accept, candidate) {
                const r = await fetch('/identify/' + id + (accept ? '/accept' : ''), {
                    method: accept ? 'POST' : 'DELETE',
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: accept ? JSON.stringify({ candidate }) : undefined,
                });
                if (r.ok) await this.refresh();
            },
            analysis: { state: 'idle', done: 0, total: 0, failed: 0 },
            analysisError: null,
            init() {
                // An analysis started before this page loaded (or before a reload) carries on showing its progress.
                fetch('/analysis', { headers: { 'Accept': 'application/json' } }).then((r) => r.ok ? r.json() : null).then((a) => {
                    if (a && ['queued', 'running'].includes(a.state)) { this.analysis = a; this.followAnalysis(); }
                }).catch(() => {});
                // A scan still going (after an upload, say) is followed too: its new albums appear when it ends.
                fetch('/scan', { headers: { 'Accept': 'application/json' } }).then((r) => r.ok ? r.json() : null).then(async (s) => {
                    if (! s || ! ['queued', 'running'].includes(s.state)) return;
                    while (['queued', 'running'].includes(s.state)) {
                        await new Promise((res) => setTimeout(res, 2000));
                        const p = await fetch('/scan', { headers: { 'Accept': 'application/json' } });
                        if (! p.ok) return;
                        s = await p.json();
                    }
                    await this.refresh();
                }).catch(() => {});
            },
            // `scope` is { album_id } or { track_id }: measure just that.
            async analyse(scope) {
                this.analysisError = null;
                const r = await fetch('/analysis', { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(scope) });
                if (! r.ok) { this.analysisError = (await r.json().catch(() => ({}))).message || 'Could not start the analysis.'; return; }
                this.analysis = await r.json();
                await this.followAnalysis();
            },
            following: false,
            async followAnalysis() {
                if (this.following) return; // a request made meanwhile is followed by the loop that is already running
                this.following = true;
                // An album is fetched again as soon as its own analysis ends, so its DR and findings show while the rest of the queue goes on.
                const shown = new Set();
                const finished = () => Object.entries(this.analysis.albums || {}).filter(([id, a]) => ['done', 'failed'].includes(a.state) && ! shown.has(id)).map(([id]) => id);
                const active = () => ['queued', 'running'].includes(this.analysis.state);
                do {
                    while (active()) {
                        await new Promise((res) => setTimeout(res, 2000));
                        const p = await fetch('/analysis', { headers: { 'Accept': 'application/json' } });
                        if (! p.ok) { this.following = false; return; }
                        this.analysis = await p.json();
                        const ids = finished();
                        if (ids.length) { ids.forEach((id) => shown.add(id)); await this.refresh(ids); }
                    }
                    // What is left (the last album, or a run with loose tracks only) is fetched again in the background: nothing jumps, and open albums stay open.
                    const rest = finished();
                    if (rest.length || ! Object.keys(this.analysis.albums || {}).length) await this.refresh(rest);
                    // Something was queued while the page was being refreshed: carry on with that, instead of dropping it.
                    if (! active()) {
                        const p = await fetch('/analysis', { headers: { 'Accept': 'application/json' } }).catch(() => null);
                        if (p && p.ok) this.analysis = await p.json();
                    }
                } while (active());
                this.following = false;
                this.analysis = { state: 'idle', done: 0, total: 0, failed: 0, albums: {} };
            },
            // Fetch the library page in the background and swap in the fresh album sections (`ids`), or the whole list when `ids` is empty or an album is new.
            // An album the person had opened stays open.
            async refresh(ids = []) {
                // Never from a cache: Safari reuses an earlier response for the same URL, which would hold the albums as they were before this run.
                const url = new URL(window.location.href);
                url.searchParams.set('_refresh', Date.now());
                const r = await fetch(url, { cache: 'no-store', headers: { 'Accept': 'text/html', 'Cache-Control': 'no-cache' } }).catch(() => null);
                if (! r || ! r.ok) return Livewire.navigate(window.location.href);
                const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
                const list = document.getElementById('library-list');
                const fresh = doc.getElementById('library-list');
                if (! list || ! fresh) return Livewire.navigate(window.location.href);
                const swap = (old, next) => {
                    const open = old.matches('[data-album]') ? window.Alpine.$data(old).expanded : null;
                    next = document.importNode(next, true);
                    old.replaceWith(next);
                    window.Alpine.initTree(next);
                    if (open !== null && next.matches('[data-album]')) window.Alpine.$data(next).expanded = open;
                };
                const sections = ids.map((id) => [list.querySelector('[data-album=\'' + id + '\']'), fresh.querySelector('[data-album=\'' + id + '\']')]);
                if (ids.length && sections.every(([a, b]) => a && b)) { sections.forEach(([a, b]) => swap(a, b)); return; }
                // Albums came or went: swap the list, keeping which albums were open.
                const opened = [...list.querySelectorAll('[data-album]')].filter((el) => window.Alpine.$data(el).expanded).map((el) => el.dataset.album);
                const next = document.importNode(fresh, true);
                list.replaceWith(next);
                window.Alpine.initTree(next);
                opened.forEach((id) => { const el = next.querySelector('[data-album=\'' + id + '\']'); if (el) window.Alpine.$data(el).expanded = true; });
            },
            drClass(c) { return { excellent: 'text-emerald-500 bg-emerald-500/15', good: 'text-lime-500 bg-lime-500/15', fair: 'text-amber-500 bg-amber-500/15', poor: 'text-red-500 bg-red-500/15' }[c]; },
            levelClass(l) { return { problem: 'text-red-500 bg-red-500/15', warn: 'text-amber-500 bg-amber-500/15', note: 'text-muted bg-white/5' }[l]; },
            db(v, unit = '') { return v === null || v === undefined ? '–' : (v > 0 ? '+' : '') + v.toFixed(1) + unit; },
            khz(hz) { return hz === null || hz === undefined ? '–' : (hz / 1000).toFixed(1) + ' kHz'; },
            spark(t) {
                const s = t.truth.spectrum;
                return s.map((v, i) => (i / (s.length - 1) * 200).toFixed(1) + ',' + (60 - (Math.max(-130, Math.min(-20, v)) + 130) / 110 * 60).toFixed(1)).join(' ');
            },
            cutX(t) { const n = t.truth.spectrum.length; return n ? (t.truth.cutoff_hz / (t.truth.step_hz * n)) * 200 : 0; },
        }">
        <div class="mb-6 flex items-center justify-between gap-4">
            <h1 class="text-2xl font-semibold">Library</h1>
            <div class="flex min-w-0 items-center justify-end gap-4">
                {{-- Progress of what the ⋯ menus started: measuring files, looking for albums. --}}
                {{-- Runs that touch albums show their status on the album's own line; this is only for loose tracks. --}}
                <p x-show="['queued', 'running'].includes(analysis.state) && ! Object.keys(analysis.albums || {}).length" x-cloak class="min-w-0 truncate text-xs text-muted">
                    <span x-show="analysis.state === 'queued'">Analysis: waiting for the queue worker…</span>
                    <span x-show="analysis.state === 'running'" x-text="'Analysing ' + analysis.done + '/' + analysis.total + (analysis.current ? ' · ' + analysis.current : '') + (analysis.failed ? ' · ' + analysis.failed + ' failed' : '')"></span>
                </p>
                <button type="button" class="shrink-0 rounded-theme border border-white/10 p-1.5 text-muted hover:text-accent" title="New folder" aria-label="New folder" @click="openFolder('create')">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v4"/><path d="M18 14v6M15 17h6"/></svg>
                </button>
            </div>
        </div>

        @if ($albums->isEmpty() && $loose->isEmpty())
            <p class="text-muted">Nothing here yet. <a href="{{ route('upload') }}" wire:navigate class="text-accent">Upload a zip</a> of your music, or ask the person who runs this server to put it in your folder and scan it.</p>
        @elseif (! $analysed)
            <p class="mb-6 rounded-theme border border-white/10 bg-surface px-3 py-2 text-xs text-muted">
                Use <strong>Analyse</strong> in the <strong>⋯</strong> menu of an album or a track to measure dynamic range, clipping, loudness and the spectrum of every file, and to find fake hi-res, lossy files in disguise and duplicates.
            </p>
        @endif
        <p x-show="analysisError" x-cloak x-text="analysisError" class="mb-4 text-xs text-red-500"></p>

        <div id="library-list" class="space-y-8">
            @foreach ($childrenOf[0] ?? [] as $folder)
                @include('pages.partials.folder', ['folder' => $folder])
            @endforeach

            @foreach ($albumsIn[0] ?? [] as $album)
                @include('pages.partials.album-section', ['album' => $album])
            @endforeach

            @if ($loose->isNotEmpty())
                <section x-data="{ tracks: @js($rows($loose)) }">
                    <div class="mb-2 flex items-center gap-2">
                        <h2 class="font-semibold">Without album tag</h2>
                        @if ($toIdentify > 0)
                            <p x-show="['queued', 'running'].includes(identify.state)" x-cloak class="text-xs text-muted">
                                <span x-show="identify.state === 'queued'">Waiting for the queue worker…</span>
                                <span x-show="identify.state === 'running'" x-text="'Looking ' + identify.done + '/' + identify.total + '…'"></span>
                            </p>
                            <div class="ml-auto">
                                <x-menu label="Actions for these tracks" width="w-64">
                                    <x-menu.item title="Suggest what the {{ $toIdentify }} folder(s) of tracks without an album tag might be, from the folder and file names and from MusicBrainz (track count and length). Only guessed names are sent. Nothing changes until you accept."
                                                 x-bind:disabled="['queued', 'running'].includes(identify.state)" @click="findAlbums()">Suggest albums</x-menu.item>
                                </x-menu>
                            </div>
                        @endif
                    </div>
                    @foreach ($suggestions as $s)
                        <div class="mb-4 rounded-theme border border-white/10 bg-surface p-3 text-sm">
                            <div class="mb-2 flex items-center gap-2">
                                <span class="min-w-0 flex-1 truncate text-muted" title="{{ $s->folder }}">Folder <strong class="text-text">{{ basename($s->folder) }}</strong> · {{ count($s->candidates[0]['tracks']) }} track(s)</span>
                                <button type="button" class="shrink-0 rounded-theme border border-white/10 px-2 py-0.5 text-xs hover:text-accent" @click="answer({{ $s->id }}, false)">Dismiss</button>
                            </div>
                            @foreach ($s->candidates as $i => $c)
                                <details class="mb-1 rounded-theme border border-white/5 px-2 py-1">
                                    <summary class="flex cursor-pointer items-center gap-2">
                                        <span class="min-w-0 flex-1 truncate"><strong>{{ $c['title'] }}</strong>@if ($c['artist']) · {{ $c['artist'] }}@endif @if ($c['year']) · {{ $c['year'] }}@endif</span>
                                        <span class="shrink-0 text-xs text-muted">
                                            @if ($c['source'] === 'musicbrainz')
                                                MusicBrainz{{ ['titles' => ' (by track titles)', 'fingerprint' => ' (by fingerprints)'][$c['via'] ?? ''] ?? '' }} · {{ $c['matched'] }}/{{ count($c['tracks']) }} track(s) fit @if ($c['release_tracks'] !== count($c['tracks'])) · release has {{ $c['release_tracks'] }}@endif
                                            @else
                                                From the folder and file names
                                            @endif
                                        </span>
                                        <button type="button" class="shrink-0 rounded-theme border border-white/10 px-2 py-0.5 text-xs hover:text-accent" @click.prevent="answer({{ $s->id }}, true, {{ $i }})">Use this</button>
                                    </summary>
                                    <ol class="mt-2 space-y-0.5 text-xs text-muted">
                                        @foreach ($c['tracks'] as $t)
                                            <li class="flex gap-2"><span class="w-8 shrink-0 text-right tabular-nums">{{ $t['number'] ?? '–' }}</span><span class="min-w-0 flex-1 truncate">{{ $t['from'] }} → <span class="text-text">{{ $t['title'] }}</span></span>@if ($t['matched'] !== null)<span class="{{ $t['matched'] ? 'text-emerald-500' : 'text-amber-500' }}">{{ $t['matched'] ? 'length fits' : 'length differs' }}</span>@endif</li>
                                        @endforeach
                                    </ol>
                                </details>
                            @endforeach
                        </div>
                    @endforeach
                    @include('pages.partials.track-list')
                </section>
            @endif
        </div>

        {{-- Details found online for an album: release date, record label and track titles, each applied only if ticked. --}}
        <div x-show="titles" x-cloak x-transition.opacity @keydown.escape.window="titles && ! $store.dialog.open && (titles = null)"
             class="fixed inset-0 z-30 flex items-center justify-center bg-black/60 p-4" @click.self="titles = null"
             role="dialog" aria-modal="true" aria-label="Album details found online">
            <div class="flex max-h-[85vh] w-full max-w-lg flex-col rounded-theme border border-white/10 bg-surface p-5 text-sm shadow-xl">
                <h2 class="mb-1 font-semibold">Details for “<span x-text="titles?.title"></span>”</h2>
                <p class="mb-3 text-xs text-muted">Looked up on MusicBrainz by album title and artist, and checked against the number and length of your files. Tick what to use. Your files are not touched.</p>

                <p x-show="titles?.loading" class="py-6 text-center text-muted">Searching…</p>
                <p x-show="titles && ! titles.loading && ! titles.error && titles.candidates.length === 0" class="py-4 text-muted">
                    No release on MusicBrainz fits this album (title, artist, number of tracks and their lengths). Check the album title and artist with Edit details, then try again.
                </p>

                <template x-if="titles && titles.candidates.length">
                    <div class="flex min-h-0 flex-col gap-3">
                        <div x-show="titles.candidates.length > 1" class="flex flex-wrap gap-2">
                            <template x-for="(c, i) in titles.candidates" :key="i">
                                <button type="button" class="rounded-theme border px-2 py-0.5 text-xs"
                                        :class="titles.pick === i ? 'border-accent text-accent' : 'border-white/10 text-muted hover:text-accent'"
                                        @click="pickRelease(i)" x-text="c.title + (c.date ? ' (' + c.date + ')' : '') + ' · ' + c.matched + '/' + c.release_tracks"></button>
                            </template>
                        </div>
                        <p class="text-xs text-muted" x-text="titles.candidates[titles.pick].artist + ' · ' + titles.candidates[titles.pick].matched + ' of ' + titles.candidates[titles.pick].release_tracks + ' track(s) fit'"></p>

                        <div class="space-y-1.5 rounded-theme border border-white/10 p-2 text-xs">
                            <label class="flex cursor-pointer items-center gap-2" :class="! titles.candidates[titles.pick].date && 'opacity-50'">
                                <input type="checkbox" x-model="titles.parts.date" :disabled="! titles.candidates[titles.pick].date" class="accent-[var(--c-accent)]">
                                <span class="w-24 shrink-0 text-muted">Release date</span>
                                <span class="min-w-0 truncate"><span class="text-muted" x-text="titles.album.release_date || '–'"></span> → <span x-text="titles.candidates[titles.pick].date || 'not on this release'"></span></span>
                            </label>
                            <label class="flex cursor-pointer items-center gap-2" :class="! titles.candidates[titles.pick].label && 'opacity-50'">
                                <input type="checkbox" x-model="titles.parts.label" :disabled="! titles.candidates[titles.pick].label" class="accent-[var(--c-accent)]">
                                <span class="w-24 shrink-0 text-muted">Record label</span>
                                <span class="min-w-0 truncate"><span class="text-muted" x-text="titles.album.label || '–'"></span> → <span x-text="titles.candidates[titles.pick].label || 'not on this release'"></span></span>
                            </label>
                            <label class="flex cursor-pointer items-center gap-2">
                                <input type="checkbox" x-model="titles.parts.titles" class="accent-[var(--c-accent)]">
                                <span class="w-24 shrink-0 text-muted">Track titles</span>
                                <span class="min-w-0 truncate" x-text="titles.candidates[titles.pick].tracks.filter((r) => r.matched && r.title !== r.current).length + ' to rename (only where the length fits)'"></span>
                            </label>
                        </div>

                        <ol class="min-h-0 divide-y divide-white/5 overflow-y-auto rounded-theme border border-white/10 text-xs" :class="! titles.parts.titles && 'opacity-50'">
                            <template x-for="(row, i) in titles.candidates[titles.pick].tracks" :key="row.id">
                                <li class="flex items-center gap-2 px-2 py-1.5">
                                    <span class="w-6 shrink-0 text-right tabular-nums text-muted" x-text="row.number ?? i + 1"></span>
                                    <span class="min-w-0 flex-1 truncate text-muted" x-text="row.current"></span>
                                    <span class="shrink-0 text-muted">→</span>
                                    <span class="min-w-0 flex-1 truncate" :class="row.matched ? '' : 'text-muted line-through'" x-text="row.matched ? row.title : '(length differs)'"></span>
                                </li>
                            </template>
                        </ol>
                    </div>
                </template>

                <p x-show="titles?.error" x-text="titles?.error" class="mt-3 text-xs text-red-500"></p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="rounded-theme border border-white/10 px-3 py-1 hover:text-accent" @click="titles = null">Close</button>
                    <button type="button" x-show="titles?.candidates.length" :disabled="titles?.saving || ! anyPart" @click="applyTitles()"
                            class="rounded-theme bg-accent px-3 py-1 font-medium text-bg disabled:opacity-60">Use selected</button>
                </div>
            </div>
        </div>

        {{-- Create, rename or move a folder, or move an album into one. Nothing on disk changes. --}}
        <div x-show="fd" x-cloak x-transition.opacity @keydown.escape.window="fd && ! $store.dialog.open && (fd = null)"
             class="fixed inset-0 z-30 flex items-center justify-center bg-black/60 p-4" @click.self="fd = null"
             role="dialog" aria-modal="true" aria-label="Folders">
            <form class="w-full max-w-sm rounded-theme border border-white/10 bg-surface p-5 text-sm shadow-xl" @submit.prevent="saveFolder()">
                <h2 class="mb-4 font-semibold" x-text="{ create: 'New folder', rename: 'Rename folder', move: 'Move folder', album: 'Move album to a folder' }[fd?.mode]"></h2>
                <template x-if="fd">
                    <div class="space-y-3">
                        <label class="block" x-show="['create', 'rename'].includes(fd.mode)"><span class="mb-1 block text-xs text-muted">Name</span>
                            <input type="text" x-ref="folderName" x-model="fd.name" :required="['create', 'rename'].includes(fd.mode)" maxlength="100" placeholder="Jazz" class="w-full rounded-theme border border-white/10 bg-bg px-2 py-1.5"></label>
                        <label class="block" x-show="fd.mode !== 'rename'"><span class="mb-1 block text-xs text-muted" x-text="fd.mode === 'album' ? 'Folder' : 'Inside'"></span>
                            <select x-ref="folderParent" x-model="fd.parent_id" class="w-full rounded-theme border border-white/10 bg-bg px-2 py-1.5">
                                <option value="" x-text="fd.mode === 'album' ? '(no folder)' : '(top level)'"></option>
                                <template x-for="f in folderList.filter((f) => ! fd.exclude.includes(f.id))" :key="f.id">
                                    <option :value="f.id" x-text="'\u00a0\u00a0'.repeat(f.depth - 1) + f.name"></option>
                                </template>
                            </select></label>
                    </div>
                </template>
                <p x-show="fd?.error" x-text="fd?.error" class="mt-3 text-xs text-red-500"></p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-theme border border-white/10 px-3 py-1 hover:text-accent" @click="fd = null">Cancel</button>
                    <button type="submit" :disabled="fd?.saving" class="rounded-theme bg-accent px-3 py-1 font-medium text-bg disabled:opacity-60">Save</button>
                </div>
            </form>
        </div>

        {{-- Edit details of an album or a track. Saved in the library only; the files and their tags are never changed. --}}
        <div x-show="edit" x-cloak x-transition.opacity @keydown.escape.window="edit && ! $store.dialog.open && (edit = null)"
             class="fixed inset-0 z-30 flex items-center justify-center bg-black/60 p-4" @click.self="edit = null"
             role="dialog" aria-modal="true" :aria-label="edit?.kind === 'albums' ? 'Edit album' : 'Edit track'">
            <form class="w-full max-w-sm rounded-theme border border-white/10 bg-surface p-5 text-sm shadow-xl" @submit.prevent="saveEdit()">
                <h2 class="mb-1 font-semibold" x-text="edit?.kind === 'albums' ? 'Edit album' : 'Edit track'"></h2>
                <p class="mb-4 text-xs text-muted">Only what this library shows changes. Your files and their tags are not touched, and a rescan keeps these edits.</p>

                <template x-if="edit">
                    <div class="space-y-3">
                        <label class="block"><span class="mb-1 block text-xs text-muted" x-text="edit.kind === 'albums' ? 'Album title' : 'Track title'"></span>
                            <input type="text" x-ref="editFirst" x-model="edit.fields.title" required maxlength="255" class="w-full rounded-theme border border-white/10 bg-bg px-2 py-1.5"></label>
                        <label class="block"><span class="mb-1 block text-xs text-muted" x-text="edit.kind === 'albums' ? 'Album artist' : 'Artist (empty: the album\'s)'"></span>
                            <input type="text" x-model="edit.fields.artist" maxlength="255" class="w-full rounded-theme border border-white/10 bg-bg px-2 py-1.5"></label>
                        <template x-if="edit.kind === 'albums'">
                            <div class="space-y-3">
                                <label class="block"><span class="mb-1 block text-xs text-muted">Release date (2001, 2001-03 or 2001-03-12)</span>
                                    <input type="text" x-model="edit.fields.release_date" maxlength="10" placeholder="YYYY-MM-DD" pattern="\d{4}(-\d{2}(-\d{2})?)?" inputmode="numeric" class="w-full rounded-theme border border-white/10 bg-bg px-2 py-1.5"></label>
                                <label class="block"><span class="mb-1 block text-xs text-muted">Record label</span>
                                    <input type="text" x-model="edit.fields.label" maxlength="255" class="w-full rounded-theme border border-white/10 bg-bg px-2 py-1.5"></label>
                            </div>
                        </template>
                        <template x-if="edit.kind === 'tracks'">
                            <label class="block"><span class="mb-1 block text-xs text-muted">Track number</span>
                                <input type="number" x-model="edit.fields.track_number" min="1" max="999" class="w-full rounded-theme border border-white/10 bg-bg px-2 py-1.5"></label>
                        </template>
                    </div>
                </template>

                <p x-show="edit?.error" x-text="edit?.error" class="mt-3 text-xs text-red-500"></p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-theme border border-white/10 px-3 py-1 hover:text-accent" @click="edit = null">Cancel</button>
                    <button type="submit" :disabled="edit?.saving" class="rounded-theme bg-accent px-3 py-1 font-medium text-bg disabled:opacity-60">Save</button>
                </div>
            </form>
        </div>
    </div>

    @include('pages.partials.playlist-picker')
</x-layouts.app>
