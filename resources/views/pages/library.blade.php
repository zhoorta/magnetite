@php
    // One shape for both kinds of section: a list of tracks with their file-truth findings.
    $rows = fn ($tracks) => $tracks->map(fn ($t) => $t->toLibrary($copies[$t->id] ?? []))->values();
    $analysed = $albums->flatMap->tracks->merge($loose)->contains(fn ($t) => $t->analysis !== null);
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
                Run <code>php artisan magnetite:analyze</code> to measure dynamic range, clipping, loudness and the spectrum of every file, and to find fake hi-res, lossy files in disguise and duplicates.
            </p>
        @endif

        <div class="space-y-8">
            @foreach ($albums as $album)
                <section x-data="{ tracks: @js($rows($album->tracks)) }"
                         x-show="! onlyIssues || tracks.some((t) => worst(t) !== null)" class="flex gap-4">
                    <div class="h-24 w-24 shrink-0 overflow-hidden rounded-theme bg-surface">
                        @if ($album->cover)
                            <img src="{{ route('cover', $album) }}" alt="" loading="lazy" class="h-full w-full object-cover">
                        @endif
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
                    @include('pages.partials.track-list')
                </section>
            @endif
        </div>
    </div>
</x-layouts.app>
