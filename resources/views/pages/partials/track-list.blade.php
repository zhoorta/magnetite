{{-- The tracks of one section. Expects `tracks` in the surrounding x-data and the helpers of the library page (including sharing). --}}
<div class="divide-y divide-white/10 rounded-theme border border-white/10 bg-surface">
    <template x-for="(t, i) in tracks" :key="t.id">
        <div x-show="shown(t)">
            <div class="flex items-center gap-2 pr-3 text-sm">
                <button type="button"
                        class="flex min-w-0 flex-1 items-center gap-3 py-2 pl-3 text-left hover:text-accent"
                        @click="$store.player.setQueue(tracks, i)">
                    <span class="w-5 shrink-0 text-xs text-muted" x-text="i + 1"></span>
                    <span class="flex-1 truncate" x-text="t.title"></span>
                    <span class="shrink-0 text-xs text-accent2" x-show="$store.player.current?.id === t.id"
                          x-text="$store.player.playing ? 'playing' : 'paused'"></span>
                    <span class="shrink-0 text-xs text-muted" x-show="! t.playable" title="This browser cannot decode ALAC and ffmpeg is not available to convert it">ALAC</span>
                </button>

                <template x-for="f in t.truth.flags.filter((f) => f.level !== 'note').slice(0, 2)" :key="f.code">
                    <span class="hidden shrink-0 rounded-theme px-1.5 py-0.5 text-xs sm:inline" :class="levelClass(f.level)" :title="f.detail" x-text="f.label"></span>
                </template>
                <span x-show="t.truth.dr !== null && t.truth.dr !== undefined"
                      class="shrink-0 rounded-theme px-1.5 py-0.5 text-xs font-medium tabular-nums"
                      :class="drClass(t.truth.dr_class)" title="Dynamic range (DR): higher is more dynamic, 7 or less is heavily compressed"
                      x-text="'DR' + t.truth.dr"></span>
                <span class="w-10 shrink-0 text-right text-xs tabular-nums text-muted" x-text="$store.player.fmt(t.duration)"></span>
                    <button type="button" class="shrink-0 text-xs text-muted hover:text-accent" :class="isShared('tracks', t.id) && 'text-accent'"
                            :title="isShared('tracks', t.id) ? 'Shared on its own. Click to stop sharing.' : 'Share just this track'"
                            @click="toggleShare('tracks', t.id)" x-text="isShared('tracks', t.id) ? 'Shared' : 'Share'"></button>
                <button type="button" x-show="t.truth.analyzed" class="shrink-0 text-xs text-muted hover:text-accent"
                        :aria-expanded="open === t.id" title="What the measurements say about this file"
                        @click="open = open === t.id ? null : t.id">
                    <span x-text="open === t.id ? '▾' : '▸'"></span>
                </button>
            </div>

            <div x-show="open === t.id && t.truth.analyzed" x-cloak class="grid gap-4 border-t border-white/10 px-3 py-3 text-xs sm:grid-cols-2">
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                    <dt class="text-muted">Dynamic range</dt><dd class="tabular-nums" x-text="t.truth.dr === null ? '–' : 'DR' + t.truth.dr"></dd>
                    <dt class="text-muted">Loudness</dt><dd class="tabular-nums" x-text="db(t.truth.lufs, ' LUFS')"></dd>
                    <dt class="text-muted">Loudness range</dt><dd class="tabular-nums" x-text="db(t.truth.lra, ' LU')"></dd>
                    <dt class="text-muted">True peak</dt><dd class="tabular-nums" x-text="db(t.truth.true_peak, ' dBTP')"></dd>
                    <dt class="text-muted">Sample peak</dt><dd class="tabular-nums" x-text="db(t.truth.sample_peak, ' dBFS')"></dd>
                    <dt class="text-muted">Clipped peaks</dt><dd class="tabular-nums" x-text="t.truth.clipped_runs"></dd>
                    <dt class="text-muted">Format</dt>
                    <dd class="tabular-nums" x-text="[t.codec, t.truth.sample_rate ? (t.truth.sample_rate / 1000) + ' kHz' : null, t.truth.bits ? t.truth.bits + ' bit used' + (t.truth.declared_bits && t.truth.declared_bits !== t.truth.bits ? ' of ' + t.truth.declared_bits : '') : null].filter(Boolean).join(' · ')"></dd>
                    <dt class="text-muted">Spectrum ends</dt>
                    <dd class="tabular-nums" x-text="t.truth.cutoff_hz ? 'cut off at ' + khz(t.truth.cutoff_hz) : 'content up to ' + khz(t.truth.content_hz)"></dd>
                </dl>

                <div class="space-y-3">
                    <svg viewBox="0 0 200 60" class="h-16 w-full" preserveAspectRatio="none" role="img"
                         aria-label="Average spectrum from 0 Hz to the Nyquist frequency">
                        <polyline :points="spark(t)" fill="none" stroke="var(--c-accent)" stroke-width="1.2" vector-effect="non-scaling-stroke"/>
                        <line x-show="t.truth.cutoff_hz" :x1="cutX(t)" :x2="cutX(t)" y1="0" y2="60" stroke="#ef4444" stroke-dasharray="3 3" stroke-width="1" vector-effect="non-scaling-stroke"/>
                    </svg>
                    <p class="-mt-2 flex justify-between text-[10px] text-muted"><span>0</span><span>average spectrum</span><span x-text="khz(t.truth.spectrum.length * t.truth.step_hz)"></span></p>
                    <p x-show="t.truth.flags.length === 0" class="text-muted">Nothing suspicious found.</p>
                    <ul class="space-y-1">
                        <template x-for="f in t.truth.flags" :key="f.code">
                            <li><span class="mr-1 rounded-theme px-1.5 py-0.5" :class="levelClass(f.level)" x-text="f.label"></span><span class="text-muted" x-text="f.detail"></span></li>
                        </template>
                    </ul>
                </div>
            </div>
        </div>
    </template>
</div>
