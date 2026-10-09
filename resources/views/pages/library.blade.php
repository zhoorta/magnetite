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
                const ask = @js($trashOnly)
                    ? 'Move ' + what + ' to the trash in Ferrite? You can restore them there.'
                    : 'Delete ' + what + ' from the music folder? This cannot be undone.';
                if (! confirm(ask)) return;
                const r = await fetch('/library/' + kind + '/' + id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' } });
                const body = await r.json().catch(() => ({}));
                if (! r.ok || body.error) alert(body.error || body.message || 'Could not remove it.');
                if (r.ok || body.removed) Livewire.navigate(window.location.href);
            },
            csrf() { return document.querySelector('meta[name=csrf-token]').content; },
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
            // `scope` is { album_id } or { track_id }: measure just that.
            async analyse(scope) {
                this.analysisError = null;
                const r = await fetch('/analysis', { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(scope) });
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
            {{-- Progress of what the ⋯ menus started: measuring files, looking for albums. --}}
            <p x-show="['queued', 'running'].includes(analysis.state)" x-cloak class="min-w-0 truncate text-xs text-muted">
                <span x-show="analysis.state === 'queued'">Analysis: waiting for the queue worker…</span>
                <span x-show="analysis.state === 'running'" x-text="'Analysing ' + analysis.done + '/' + analysis.total + (analysis.current ? ' · ' + analysis.current : '') + (analysis.failed ? ' · ' + analysis.failed + ' failed' : '')"></span>
            </p>
        </div>

        @if ($albums->isEmpty() && $loose->isEmpty())
            <p class="text-muted">Nothing here yet. <a href="{{ route('upload') }}" wire:navigate class="text-accent">Upload a zip</a> of your music, or ask the person who runs this server to put it in your folder and scan it.</p>
        @elseif (! $analysed)
            <p class="mb-6 rounded-theme border border-white/10 bg-surface px-3 py-2 text-xs text-muted">
                Use <strong>Analyse</strong> in the <strong>⋯</strong> menu of an album or a track to measure dynamic range, clipping, loudness and the spectrum of every file, and to find fake hi-res, lossy files in disguise and duplicates.
            </p>
        @endif
        <p x-show="analysisError" x-cloak x-text="analysisError" class="mb-4 text-xs text-red-500"></p>

        <div class="space-y-8">
            @foreach ($albums as $album)
                <section x-data="{
                            tracks: @js($rows($album->tracks)),
                            cover: @js((bool) $album->cover), web: @js($album->cover_source !== null), v: @js(substr((string) $album->cover, 0, 10)),
                            searching: false, noMatch: false, note: '',
                            // No cover yet: look one up. A cover from the web: look for a different match (the old one stays when there is none).
                            async findCover() {
                                const another = this.cover;
                                this.searching = true; this.noMatch = false; this.note = '';
                                const r = await fetch('{{ route('cover.find', $album) }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' } }).catch(() => null);
                                this.searching = false;
                                const body = r && r.ok ? await r.json() : null;
                                if (body && body.source) {
                                    this.cover = true; this.web = true; this.v = String(Date.now());
                                    this.note = another ? 'Cover replaced' : '';
                                } else if (another) this.note = r && r.ok ? 'No other match found' : 'Could not search';
                                else this.noMatch = true;
                                if (this.note) setTimeout(() => this.note = '', 4000);
                            },
                         }"
                         class="flex gap-4">
                    <div class="h-24 w-24 shrink-0 overflow-hidden rounded-theme bg-surface">
                        <template x-if="cover"><img :src="'{{ route('cover', $album) }}?v=' + v" alt="" loading="lazy" class="h-full w-full object-cover"></template>
                        @unless ($album->cover)
                            <button type="button" x-show="! cover" class="flex h-full w-full items-center justify-center p-1 text-center text-xs text-muted hover:text-accent"
                                    title="Look this cover up online (MusicBrainz, iTunes). Only artist and album title are sent."
                                    :disabled="searching" @click="findCover()"
                                    x-text="searching ? 'Searching…' : noMatch ? 'No match' : 'Find cover'"></button>
                        @endunless
                    </div>

                    <div class="min-w-0 flex-1">
                        <h2 class="flex items-center gap-1.5 font-semibold"><span class="truncate">{{ $album->title }}</span>
                            <svg x-show="isShared('albums', {{ $album->id }})" x-cloak viewBox="0 0 16 16" class="h-3.5 w-3.5 shrink-0 text-muted" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Shared"><title>Shared with everyone who has an account</title><circle cx="4" cy="8" r="1.8"/><circle cx="12" cy="3.5" r="1.8"/><circle cx="12" cy="12.5" r="1.8"/><path d="M5.6 7.1l4.8-2.7M5.6 8.9l4.8 2.7"/></svg></h2>
                        <div class="mb-2 flex items-center gap-2 text-sm text-muted">
                            <span x-show="note" x-text="note" x-cloak class="shrink-0 text-xs text-accent2"></span>
                            <span class="min-w-0 truncate">{{ $album->displayArtist }}@if ($album->year) · {{ $album->year }}@endif</span>
                            @if ($album->dr !== null)
                                <span class="shrink-0 rounded-theme px-1.5 py-0.5 text-xs font-medium tabular-nums"
                                      :class="drClass('{{ App\Support\FileTruth::drClass($album->dr) }}')"
                                      title="Album dynamic range: the average of the track scores. Higher is more dynamic.">DR{{ $album->dr }}</span>
                            @endif
                            <div class="ml-auto shrink-0">
                                <x-playlist-menu :item="'{ kind: \'album\', id: '.$album->id.', title: '.\Illuminate\Support\Js::from($album->title).' }'"
                                                 label="Add every track of this album to a playlist" />
                            </div>
                            <x-menu label="Actions for this album" width="w-56">
                                @if (! $album->cover || $album->cover_source)
                                    <x-menu.item x-bind:disabled="searching" @click="findCover()"
                                                 title="Look this cover up online (MusicBrainz, iTunes). Only artist and album title are sent. If the cover is not the right one, search again for another match."
                                                 x-text="searching ? 'Searching…' : cover ? 'Find another cover' : 'Find cover'"></x-menu.item>
                                @endif
                                @if ($needsAnalysis($album))
                                    <x-menu.item title="Measure dynamic range, clipping, loudness and the spectrum of this album's files. Files are only read."
                                                 @click="analyse({ album_id: {{ $album->id }} })">Analyse album</x-menu.item>
                                @endif
                                <x-menu.item @click="toggleShare('albums', {{ $album->id }})"
                                             title="Shared albums are visible to everyone who has an account"
                                             x-text="isShared('albums', {{ $album->id }}) ? 'Stop sharing' : 'Share this album'"></x-menu.item>
                                <hr class="my-1 border-white/10">
                                <x-menu.item danger title="{{ $trashOnly ? 'Move this album to the trash in Ferrite' : 'Delete this album from the music folder' }}"
                                             @click="remove('albums', {{ $album->id }}, @js($album->title), {{ $album->tracks->count() }})">Delete album</x-menu.item>
                            </x-menu>
                        </div>

                        @include('pages.partials.track-list')
                    </div>
                </section>
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
    </div>

    @include('pages.partials.playlist-picker')
</x-layouts.app>
