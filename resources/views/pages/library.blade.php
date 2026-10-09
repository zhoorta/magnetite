@php
    // One shape for both kinds of section: a list of tracks with their file-truth findings.
    $rows = fn ($tracks) => $tracks->map(fn ($t) => $t->toLibrary($copies[$t->id] ?? []))->values();
    $analysed = $albums->flatMap->tracks->merge($loose)->contains(fn ($t) => $t->analysis !== null);
    $missing = $albums->whereNull('cover')->count();
@endphp
<x-layouts.app title="Library · Magnetite">
    <div x-data="{
            onlyIssues: false,
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
            csrf() { return document.querySelector('meta[name=csrf-token]').content; },
            covers: { state: 'idle', done: 0, total: 0, found: 0 },
            async findCovers() {
                const r = await fetch('/covers/find', { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' } });
                if (! r.ok) return;
                this.covers = await r.json();
                while (['queued', 'running'].includes(this.covers.state)) {
                    await new Promise((res) => setTimeout(res, 2000));
                    const p = await fetch('/covers/find', { headers: { 'Accept': 'application/json' } });
                    if (! p.ok) return;
                    this.covers = await p.json();
                }
                if (this.covers.found > 0) Livewire.navigate(window.location.href);
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
                Livewire.navigate(window.location.href);
            },
            async answer(id, accept, candidate) {
                const r = await fetch('/identify/' + id + (accept ? '/accept' : ''), {
                    method: accept ? 'POST' : 'DELETE',
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: accept ? JSON.stringify({ candidate }) : undefined,
                });
                if (r.ok) Livewire.navigate(window.location.href);
            },
            analysis: { state: 'idle', done: 0, total: 0, failed: 0 },
            analysisError: null,
            init() {
                // An analysis started before this page loaded (or before a reload) carries on showing its progress.
                fetch('/analysis', { headers: { 'Accept': 'application/json' } }).then((r) => r.ok ? r.json() : null).then((a) => {
                    if (a && ['queued', 'running'].includes(a.state)) { this.analysis = a; this.followAnalysis(); }
                }).catch(() => {});
            },
            async analyse() {
                this.analysisError = null;
                const r = await fetch('/analysis', { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' } });
                if (! r.ok) { this.analysisError = (await r.json().catch(() => ({}))).message || 'Could not start the analysis.'; return; }
                this.analysis = await r.json();
                await this.followAnalysis();
            },
            async followAnalysis() {
                while (['queued', 'running'].includes(this.analysis.state)) {
                    await new Promise((res) => setTimeout(res, 2000));
                    const p = await fetch('/analysis', { headers: { 'Accept': 'application/json' } });
                    if (! p.ok) return;
                    this.analysis = await p.json();
                }
                Livewire.navigate(window.location.href);
            },
            worst(t) { const l = t.truth.flags.map((f) => f.level); return l.includes('problem') ? 'problem' : l.includes('warn') ? 'warn' : null; },
            shown(t) { return ! this.onlyIssues || this.worst(t) !== null; },
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
            @if ($missing > 0)
                <button type="button" class="ml-auto rounded-theme border border-white/10 px-2 py-1 text-xs hover:text-accent disabled:opacity-60"
                        title="Look up the {{ $missing }} missing cover(s) online (MusicBrainz, iTunes). Only artist and album title are sent."
                        :disabled="['queued', 'running'].includes(covers.state)" @click="findCovers()">
                    <span x-show="! ['queued', 'running', 'done'].includes(covers.state)">Find missing covers</span>
                    <span x-show="covers.state === 'queued'" x-cloak>Waiting for the queue worker…</span>
                    <span x-show="covers.state === 'running'" x-cloak x-text="'Searching ' + covers.done + '/' + covers.total + '…'"></span>
                    <span x-show="covers.state === 'done'" x-cloak x-text="covers.found + ' of ' + covers.total + ' found'"></span>
                </button>
            @endif
            @if ($toIdentify > 0)
                <button type="button" class="rounded-theme border border-white/10 px-2 py-1 text-xs hover:text-accent disabled:opacity-60"
                        title="Suggest what the {{ $toIdentify }} folder(s) of tracks without an album tag might be, from the folder and file names and from MusicBrainz (track count and length). Only guessed names are sent. Nothing changes until you accept."
                        :disabled="['queued', 'running'].includes(identify.state)" @click="findAlbums()">
                    <span x-show="! ['queued', 'running'].includes(identify.state)">Suggest albums</span>
                    <span x-show="identify.state === 'queued'" x-cloak>Waiting for the queue worker…</span>
                    <span x-show="identify.state === 'running'" x-cloak x-text="'Looking ' + identify.done + '/' + identify.total + '…'"></span>
                </button>
            @endif
            @if ($toAnalyse > 0)
                <button type="button" class="rounded-theme border border-white/10 px-2 py-1 text-xs hover:text-accent disabled:opacity-60"
                        title="Measure dynamic range, clipping, loudness and the spectrum of {{ $toAnalyse }} file(s), and look for fake hi-res, lossy files in disguise and duplicates. Files are only read."
                        :disabled="['queued', 'running'].includes(analysis.state)" @click="analyse()">
                    <span x-show="! ['queued', 'running'].includes(analysis.state)">{{ $analysed ? 'Analyse '.$toAnalyse.' new file(s)' : 'Analyse files' }}</span>
                    <span x-show="analysis.state === 'queued'" x-cloak>Waiting for the queue worker…</span>
                    <span x-show="analysis.state === 'running'" x-cloak x-text="'Analysing ' + analysis.done + '/' + analysis.total + '…'"></span>
                </button>
                <span x-show="analysis.state === 'running'" x-cloak class="max-w-[16rem] truncate text-xs text-muted" x-text="(analysis.current ? analysis.current : '') + (analysis.failed ? ' · ' + analysis.failed + ' failed' : '')"></span>
            @endif
            @if ($analysed)
                <label class="flex cursor-pointer items-center gap-2 text-xs text-muted">
                    <input type="checkbox" x-model="onlyIssues" class="accent-[var(--c-accent)]">
                    Only files with problems
                </label>
            @endif
        </div>

        @if ($albums->isEmpty() && $loose->isEmpty())
            <p class="text-muted">Nothing here yet. <a href="{{ route('upload') }}" wire:navigate class="text-accent">Upload a zip</a> of your music, or ask the person who runs this server to put it in your folder and scan it.</p>
        @elseif (! $analysed)
            <p class="mb-6 rounded-theme border border-white/10 bg-surface px-3 py-2 text-xs text-muted">
                Use <strong>Analyse files</strong> above to measure dynamic range, clipping, loudness and the spectrum of every file, and to find fake hi-res, lossy files in disguise and duplicates.
            </p>
        @endif
        <p x-show="analysisError" x-cloak x-text="analysisError" class="mb-4 text-xs text-red-500"></p>

        <div class="space-y-8">
            @foreach ($albums as $album)
                <section x-data="{
                            tracks: @js($rows($album->tracks)),
                            cover: @js((bool) $album->cover), searching: false, noMatch: false,
                            async findCover() {
                                this.searching = true; this.noMatch = false;
                                const r = await fetch('{{ route('cover.find', $album) }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' } }).catch(() => null);
                                this.searching = false;
                                if (r && r.ok && (await r.json()).source) this.cover = true; else this.noMatch = true;
                            },
                         }"
                         x-show="! onlyIssues || tracks.some((t) => worst(t) !== null)" class="flex gap-4">
                    <div class="h-24 w-24 shrink-0 overflow-hidden rounded-theme bg-surface">
                        <template x-if="cover"><img src="{{ route('cover', $album) }}" alt="" loading="lazy" class="h-full w-full object-cover"></template>
                        @unless ($album->cover)
                            <button type="button" x-show="! cover" class="flex h-full w-full items-center justify-center p-1 text-center text-xs text-muted hover:text-accent"
                                    title="Look this cover up online (MusicBrainz, iTunes). Only artist and album title are sent."
                                    :disabled="searching" @click="findCover()"
                                    x-text="searching ? 'Searching…' : noMatch ? 'No match' : 'Find cover'"></button>
                        @endunless
                    </div>

                    <div class="min-w-0 flex-1">
                        <h2 class="truncate font-semibold">{{ $album->title }}</h2>
                        <p class="mb-2 flex items-center gap-2 truncate text-sm text-muted">
                            <span class="truncate">{{ $album->displayArtist }}@if ($album->year) · {{ $album->year }}@endif</span>
                            @if ($album->dr !== null)
                                <span class="shrink-0 rounded-theme px-1.5 py-0.5 text-xs font-medium tabular-nums"
                                      :class="drClass('{{ App\Support\FileTruth::drClass($album->dr) }}')"
                                      title="Album dynamic range: the average of the track scores. Higher is more dynamic.">DR{{ $album->dr }}</span>
                            @endif
                            <button type="button" class="ml-auto shrink-0 rounded-theme border border-white/10 px-2 py-0.5 text-xs hover:text-accent"
                                    title="Add every track of this album to a playlist"
                                    @click="$dispatch('pick-playlist', { kind: 'album', id: {{ $album->id }}, title: @js($album->title) })">+ Playlist</button>
                            <button type="button" class="shrink-0 rounded-theme border border-white/10 px-2 py-0.5 text-xs hover:text-accent"
                                    :class="isShared('albums', {{ $album->id }}) && 'text-accent'"
                                    :title="isShared('albums', {{ $album->id }}) ? 'Shared with everyone who has an account. Click to stop sharing.' : 'Share this album with everyone who has an account'"
                                    @click="toggleShare('albums', {{ $album->id }})"
                                    x-text="isShared('albums', {{ $album->id }}) ? 'Shared' : 'Share'"></button>
                        </p>

                        @include('pages.partials.track-list')
                    </div>
                </section>
            @endforeach

            @if ($loose->isNotEmpty())
                <section x-data="{ tracks: @js($rows($loose)) }" x-show="! onlyIssues || tracks.some((t) => worst(t) !== null)">
                    <h2 class="mb-2 font-semibold">Without album tag</h2>
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
    </div>

    @include('pages.partials.playlist-picker')
</x-layouts.app>
