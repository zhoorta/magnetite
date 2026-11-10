{{-- The library: the dense, keyboard-first view (docs/dense-library.md). One Alpine component (resources/js/magnetite/dense/) draws it from `$data`. --}}
@php
    $btn = 'shrink-0 rounded-theme border border-white/15 px-2.5 py-0.5 text-sm hover:border-accent hover:text-accent disabled:opacity-40 disabled:hover:border-white/15 disabled:hover:text-text pointer-coarse:min-h-11 pointer-coarse:px-3.5';
@endphp
<x-layouts.app title="Library · Magnetite" wide="full">
    <div x-data="dense(@js($data))" tabindex="0" @keydown="onKey($event)" role="application" aria-label="Library"
         class="text-sm outline-none focus-visible:[&>.panes]:outline-none">

        <div class="mb-3 flex flex-wrap items-center gap-1.5" role="toolbar" aria-label="Actions on the selection">
            <button type="button" class="{{ $btn }}" title="Play (Enter)" :disabled="! tracks.length" @click="pane === 'tracks' ? playTrack(tsel) : playTrack(0, false); focusRoot()">▶ Play</button>
            <button type="button" class="{{ $btn }}" :class="selectMode && 'border-accent text-accent'" :aria-pressed="selectMode" title="Mark several albums (x)" @click="toggleSelectMode()">
                <span x-text="selectMode ? '✓ Done' : '☐ Select'"></span><span x-show="marks.length" x-text="' · ' + marks.length"></span>
            </button>
            <button type="button" class="{{ $btn }}" title="Move to a folder (v)" :disabled="! row || row.kind === 'loose'" @click="openMover()">Move…</button>
            <button type="button" class="{{ $btn }}" title="New folder (N)" @click="newFolder()">New folder</button>
            <div x-show="playlistItem" x-cloak class="flex items-center rounded-theme border border-white/15 px-1 pointer-coarse:min-h-11"><x-playlist-menu item="playlistItem" label="Add to a playlist" /></div>
            <button type="button" class="{{ $btn }}" title="Measure dynamic range, clipping and loudness (A)" :disabled="! row || row.kind === 'loose'" @click="analyseSelected(); focusRoot()">Analyse</button>
            <button type="button" class="{{ $btn }}" title="Correct titles, artist, date or label (e)" :disabled="! (pane === 'tracks' ? tracks[tsel] : row?.kind === 'album')" @click="editSelected()">Edit</button>
            <button type="button" class="{{ $btn }}" title="Look the album up online: release date, label, track titles (i)" :disabled="! (row?.kind === 'album' || row?.kind === 'loose')" @click="openDetails()">Details…</button>
            <button type="button" class="{{ $btn }}" title="Find a cover (C)" :disabled="row?.kind !== 'album'" @click="findCover(); focusRoot()">Cover</button>
            <button type="button" class="{{ $btn }}" title="Command line (:)" @click="openCmd()">› Command</button>
            <button type="button" class="{{ $btn }}" title="Share with everyone who has an account (S)" :disabled="! (pane === 'tracks' ? tracks[tsel] : row?.kind === 'album')"
                    @click="shareSelected(); focusRoot()"
                    x-text="(pane === 'tracks' ? tracks[tsel] && isShared('tracks', tracks[tsel].id) : row?.kind === 'album' && isShared('albums', row.id)) ? 'Stop sharing' : 'Share'"></button>
            <button type="button" class="{{ $btn }}" :class="onlyProblems && 'border-accent text-accent'" :aria-pressed="onlyProblems" title="Only albums and tracks with findings (o)" @click="toggleProblems(); focusRoot()">Problems</button>
            <button type="button" class="{{ $btn }}" title="How much to show about the files: all, DR only, nothing (I)" @click="$store.ui.cycleInfo(); focusRoot()" x-text="$store.ui.label()"></button>
            <button type="button" class="{{ $btn }} hover:!border-red-500 hover:!text-red-500" title="Delete (d)" :disabled="! row || row.kind === 'loose' && pane !== 'tracks'" @click="deleteSelected()">Delete</button>
        </div>

        <p x-show="! hasAnything" x-cloak class="text-muted">Nothing here yet. <a href="{{ route('upload') }}" wire:navigate class="text-accent">Upload a zip</a> of your music, or ask the person who runs this server to put it in your folder and scan it.</p>

        <div x-show="hasAnything" class="panes grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
            {{-- Folders and albums --}}
            <div class="relative rounded-theme border pt-3" :class="pane === 'tree' ? 'border-accent' : 'border-white/15'">
                <span class="absolute -top-2.5 left-3 bg-bg px-1.5" :class="pane === 'tree' ? 'text-accent' : 'text-muted'" x-text="'Library · ' + albums.length + (albums.length === 1 ? ' album' : ' albums')"></span>
                <ul role="tree" aria-label="Folders and albums" class="h-[34dvh] min-h-48 md:h-[calc(100dvh-30rem)] md:min-h-56 overflow-y-auto pb-1">
                    <template x-for="(r, i) in rows" :key="r.key">
                        <li role="treeitem" :aria-selected="i === cur" :data-selected="i === cur"
                            class="flex cursor-pointer touch-manipulation select-none items-baseline gap-2 px-2 pointer-coarse:min-h-11 pointer-coarse:items-center"
                            :class="[i === cur ? (pane === 'tree' ? 'bg-accent text-bg' : 'bg-white/15') : 'hover:bg-white/5', dropOn === i && 'outline-dashed outline-1 -outline-offset-1 outline-accent2']"
                            :draggable="r.kind === 'album'"
                            @click="rowClick($event, i)" @dblclick="rowDblClick(i)"
                            @pointerdown="holdStart($event, i)" @pointerup="holdEnd()" @pointercancel="holdEnd()" @pointerleave="holdEnd()"
                            @dragstart="dragStart($event, i)" @dragover="dragOver($event, i)" @dragleave="dropOn = null" @drop="drop($event, i)">
                            <span class="w-3 shrink-0 text-center" :class="i === cur && pane === 'tree' ? '' : 'text-accent2'" x-text="r.kind === 'album' ? (isMarked(r.id) ? '●' : selectMode ? '○' : '') : ''"></span>
                            <span class="min-w-0 flex-1 truncate" :style="'padding-left:' + (r.depth * 1.2) + 'rem'">
                                <span x-show="r.kind === 'folder'" class="mr-1 inline-block w-3 opacity-70" x-text="openIds.includes(r.id) ? '▾' : '▸'"></span>
                                <span :class="r.kind !== 'album' && 'font-semibold'" x-text="r.kind === 'folder' ? r.folder.name : r.kind === 'album' ? r.album.title : 'Without album tag'"></span>
                                <span x-show="(r.kind === 'album' && r.id === playingAlbumId) || (r.kind === 'folder' && holdsPlaying(r.id))" x-cloak class="ml-1 text-xs" title="Playing from here">▶</span>
                                <span x-show="r.kind === 'album' && isShared('albums', r.id)" x-cloak class="ml-1 text-xs opacity-70" title="Shared with everyone who has an account">shared</span>
                                <span x-show="r.kind === 'album' && analysisNote(r.id)" x-cloak class="ml-1 text-xs opacity-70" x-text="analysisNote(r.id)"></span>
                            </span>
                            <span class="hidden min-w-0 max-w-[32%] shrink truncate text-xs opacity-70 sm:inline" x-show="r.kind === 'album'" x-text="r.album?.artist"></span>
                            <span class="w-4 shrink-0 text-center text-xs font-bold text-amber-500" :class="i === cur && pane === 'tree' && '!text-bg'" x-show="$store.ui.info >= 2 && r.kind === 'album' && problemCount(r.album) > 0" x-cloak
                                  :title="problemCount(r.album) + ' track(s) with findings'">!</span>
                            <span class="w-11 shrink-0 text-right text-xs font-medium tabular-nums" x-show="$store.ui.info > 0 && ((r.kind === 'album' && r.album.dr !== null) || (r.kind === 'folder' && r.dr !== null))" x-cloak
                                  :class="i === cur && pane === 'tree' ? '' : (r.kind === 'album' ? drClass(r.album?.dr_class) : 'opacity-70')" x-text="'DR' + (r.kind === 'album' ? r.album?.dr : r.dr)"></span>
                            <span class="w-20 shrink-0 whitespace-nowrap text-right text-xs tabular-nums opacity-70"
                                  x-text="r.kind === 'folder' ? r.count + (r.count === 1 ? ' album' : ' albums') : r.kind === 'loose' ? r.count + ' tracks' : (r.album.year ?? '')"></span>
                        </li>
                    </template>
                </ul>
            </div>

            {{-- The tracks of the selected album, and under them the measurements of the selected track (l) --}}
            <div class="relative rounded-theme border pt-3" :class="pane === 'tracks' ? 'border-accent' : 'border-white/15'">
                <span class="absolute -top-2.5 left-3 max-w-[85%] truncate bg-bg px-1.5" :class="pane === 'tracks' ? 'text-accent' : 'text-muted'" x-text="paneTitle"></span>
                <div class="flex h-[34dvh] min-h-48 flex-col md:h-[calc(100dvh-30rem)] md:min-h-56">
                    <div x-show="shown" class="flex items-center gap-2 px-2 pb-1">
                        <div x-show="shown && ! shown.loose" class="size-12 shrink-0 overflow-hidden rounded-theme bg-white/5">
                            <img x-show="shown?.cover && ! $store.ui.pixelated" :src="shown?.cover" alt="" class="size-full object-cover">
                            <canvas x-show="shown?.cover && $store.ui.pixelated" x-effect="$store.ui.paint($el, shown?.cover)" class="size-full [image-rendering:pixelated]" aria-hidden="true"></canvas>
                            <button type="button" x-show="! shown?.cover" class="flex size-full items-center justify-center p-0.5 text-center text-[10px] leading-tight text-muted hover:text-accent" title="Look a cover up online (C)" @click="findCover(); focusRoot()">Find cover</button>
                        </div>
                        <p class="min-w-0 truncate text-xs text-muted" x-text="shown ? [shown.artist, shown.year, shown.label, summary(tracks)].filter(Boolean).join(' · ') : ''"></p>
                    </div>

                    {{-- Tracks without an album tag: what the folder and file names and MusicBrainz suggest. Nothing changes until "Use this". --}}
                    <div x-show="shown?.loose" x-cloak class="max-h-56 shrink-0 overflow-y-auto px-2 pb-2 text-xs">
                        <div class="mb-1 flex items-center gap-2">
                            <button type="button" x-show="toIdentify > 0" class="rounded-theme border border-white/15 px-2 py-0.5 hover:border-accent hover:text-accent" :disabled="['queued', 'running'].includes(identify.state)" @click="findAlbums()" title="Look at the folders of untagged tracks: only guessed names are sent to MusicBrainz">Suggest albums (i)</button>
                            <span class="text-muted" x-text="identifyNote"></span>
                            <span x-show="trackMarks.length" class="text-muted" x-text="trackMarks.length + ' marked · :group names an album for them'"></span>
                        </div>
                        <template x-for="sug in suggestions" :key="sug.id">
                            <div class="mb-2 rounded-theme border border-white/15 p-2">
                                <div class="mb-1 flex items-center gap-2"><span class="min-w-0 flex-1 truncate text-muted" :title="sug.folder" x-text="'Folder ' + sug.folder.split('/').pop() + ' · ' + sug.candidates[0].tracks.length + ' track(s)'"></span>
                                    <button type="button" class="shrink-0 rounded-theme border border-white/15 px-2 hover:text-accent" @click="answerSuggestion(sug.id, false)">Dismiss</button></div>
                                <template x-for="(c, ci) in sug.candidates" :key="ci">
                                    <details class="mb-1 rounded-theme border border-white/10 px-2 py-1">
                                        <summary class="flex cursor-pointer items-center gap-2">
                                            <span class="min-w-0 flex-1 truncate"><strong x-text="c.title"></strong><span x-text="(c.artist ? ' · ' + c.artist : '') + (c.year ? ' · ' + c.year : '')"></span></span>
                                            <span class="shrink-0 text-muted" x-text="c.source === 'musicbrainz' ? 'MusicBrainz · ' + c.matched + '/' + c.tracks.length + ' fit' : 'from the names'"></span>
                                            <button type="button" class="shrink-0 rounded-theme border border-white/15 px-2 hover:border-accent hover:text-accent" @click.prevent="answerSuggestion(sug.id, true, ci)">Use this</button>
                                        </summary>
                                        <ol class="mt-1 space-y-0.5 text-muted">
                                            <template x-for="t in c.tracks" :key="t.id">
                                                <li class="flex gap-2"><span class="w-6 shrink-0 text-right tabular-nums" x-text="t.number ?? '–'"></span><span class="min-w-0 flex-1 truncate"><span x-text="t.from"></span> → <span class="text-text" x-text="t.title"></span></span><span x-show="t.matched !== null" :class="t.matched ? 'text-emerald-500' : 'text-amber-500'" x-text="t.matched ? 'length fits' : 'length differs'"></span></li>
                                            </template>
                                        </ol>
                                    </details>
                                </template>
                            </div>
                        </template>
                    </div>
                    <p x-show="row?.kind === 'folder'" x-cloak class="px-2 py-1 text-muted" x-text="row?.kind === 'folder' ? row.count + (row.count === 1 ? ' album' : ' albums') + ' in this folder and below. l opens it, Enter opens or closes it.' : ''"></p>
                    <ul aria-label="Tracks" class="min-h-0 flex-1 overflow-y-auto pb-1" :class="shown ? '' : 'hidden'">
                        <template x-for="(t, i) in tracks" :key="t.id">
                            <li :data-selected="pane === 'tracks' && i === tsel"
                                class="flex cursor-pointer touch-manipulation select-none items-baseline gap-2 px-2 pointer-coarse:min-h-11 pointer-coarse:items-center"
                                :class="pane === 'tracks' && i === tsel ? 'bg-accent text-bg' : 'hover:bg-white/5'"
                                @click="trackClick(i)" @dblclick="playTrack(i)">
                                <span class="w-3 shrink-0 text-center" :class="pane === 'tracks' && i === tsel ? '' : 'text-accent2'" x-text="shown?.loose && trackMarks.includes(t.id) ? '●' : ''"></span>
                                <span class="w-6 shrink-0 text-right text-xs tabular-nums opacity-70" x-text="i + 1"></span>
                                <span class="min-w-0 flex-1 truncate" :class="$store.player.current?.id === t.id && 'font-bold'">
                                    <span x-text="t.title"></span>
                                    <span x-show="$store.player.current?.id === t.id" x-cloak class="ml-1 text-xs" x-text="$store.player.playing ? '▶ playing' : 'paused'"></span>
                                    <span x-show="isShared('tracks', t.id)" x-cloak class="ml-1 text-xs opacity-70" title="Shared on its own">shared</span>
                                    <span x-show="! t.playable" x-cloak class="ml-1 text-xs opacity-70" title="This browser cannot decode ALAC and ffmpeg is not available to convert it">ALAC</span>
                                </span>
                                <template x-if="$store.ui.info >= 2">
                                    <span class="hidden shrink-0 gap-2 text-xs sm:flex">
                                        <template x-for="f in flags(t)" :key="f.code">
                                            <span :class="pane === 'tracks' && i === tsel ? '' : levelClass(f.level)" :title="f.detail"><span class="font-bold">!</span> <span x-text="f.label"></span></span>
                                        </template>
                                    </span>
                                </template>
                                <span class="w-11 shrink-0 text-right text-xs font-medium tabular-nums" x-show="$store.ui.info > 0 && t.truth.dr !== null && t.truth.dr !== undefined" x-cloak
                                      :class="pane === 'tracks' && i === tsel ? '' : drClass(t.truth.dr_class)" x-text="'DR' + t.truth.dr"></span>
                                <span class="w-10 shrink-0 text-right text-xs tabular-nums opacity-70" x-text="fmt(t.duration)"></span>
                            </li>
                        </template>
                    </ul>

                    {{-- What was measured for the selected track: numbers, the average spectrum and the findings. --}}
                    <div x-show="detailTrack" x-cloak class="max-h-[55%] shrink-0 overflow-y-auto border-t border-white/15 px-3 py-2 text-xs">
                        <template x-if="detailTrack">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                                    <dt class="text-muted">Dynamic range</dt><dd class="tabular-nums" x-text="detailTrack.truth.dr === null ? '–' : 'DR' + detailTrack.truth.dr"></dd>
                                    <dt class="text-muted">Loudness</dt><dd class="tabular-nums" x-text="db(detailTrack.truth.lufs, ' LUFS')"></dd>
                                    <dt class="text-muted">Loudness range</dt><dd class="tabular-nums" x-text="db(detailTrack.truth.lra, ' LU')"></dd>
                                    <dt class="text-muted">True peak</dt><dd class="tabular-nums" x-text="db(detailTrack.truth.true_peak, ' dBTP')"></dd>
                                    <dt class="text-muted">Sample peak</dt><dd class="tabular-nums" x-text="db(detailTrack.truth.sample_peak, ' dBFS')"></dd>
                                    <dt class="text-muted">Clipped peaks</dt><dd class="tabular-nums" x-text="detailTrack.truth.clipped_runs"></dd>
                                    <dt class="text-muted">Format</dt>
                                    <dd class="tabular-nums" x-text="[detailTrack.codec, detailTrack.truth.sample_rate ? (detailTrack.truth.sample_rate / 1000) + ' kHz' : null, detailTrack.truth.bits ? detailTrack.truth.bits + ' bit used' + (detailTrack.truth.declared_bits && detailTrack.truth.declared_bits !== detailTrack.truth.bits ? ' of ' + detailTrack.truth.declared_bits : '') : null].filter(Boolean).join(' · ')"></dd>
                                    <dt class="text-muted">Spectrum ends</dt>
                                    <dd class="tabular-nums" x-text="detailTrack.truth.cutoff_hz ? 'cut off at ' + khz(detailTrack.truth.cutoff_hz) : 'content up to ' + khz(detailTrack.truth.content_hz)"></dd>
                                </dl>
                                <div class="space-y-2">
                                    <svg viewBox="0 0 200 60" class="h-14 w-full" preserveAspectRatio="none" role="img" aria-label="Average spectrum from 0 Hz to the Nyquist frequency">
                                        <polyline :points="spark(detailTrack)" fill="none" stroke="var(--c-accent)" stroke-width="1.2" vector-effect="non-scaling-stroke"/>
                                        <line x-show="detailTrack.truth.cutoff_hz" :x1="cutX(detailTrack)" :x2="cutX(detailTrack)" y1="0" y2="60" stroke="#ef4444" stroke-dasharray="3 3" stroke-width="1" vector-effect="non-scaling-stroke"/>
                                    </svg>
                                    <p class="-mt-1 flex justify-between text-[10px] text-muted"><span>0</span><span>average spectrum</span><span x-text="khz(detailTrack.truth.spectrum.length * detailTrack.truth.step_hz)"></span></p>
                                    <p x-show="detailTrack.truth.flags.length === 0" class="text-muted">Nothing suspicious found.</p>
                                    <ul class="space-y-1">
                                        <template x-for="f in detailTrack.truth.flags" :key="f.code">
                                            <li><span class="mr-1 font-bold" :class="levelClass(f.level)" x-text="f.label"></span><span class="text-muted" x-text="f.detail"></span></li>
                                        </template>
                                    </ul>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        {{-- The command line: : opens it, Tab completes, Enter runs, Esc closes. --}}
        <div x-show="cmd" x-cloak class="mt-2 border-t border-accent2 pt-1.5 text-sm">
            <template x-if="cmd">
                <div>
                    <div class="flex items-baseline gap-2"><span class="text-accent2">:</span>
                        <input type="text" x-ref="cmdInput" x-model="cmd.text" @input="cmdTyped()" @keydown="cmdKey($event)" spellcheck="false" autocomplete="off" autocapitalize="off" aria-label="Command"
                               class="min-w-0 flex-1 bg-transparent outline-none" placeholder="analyze, edit, move jazz, theme graphite, help …"></div>
                    <p class="mt-1 truncate text-xs text-muted" x-text="cmd.list.length ? cmd.list.slice(0, 10).join('   ') : (cmd.text.trim() ? 'no match' : '')"></p>
                </div>
            </template>
        </div>
        <div x-show="! cmd" class="mt-2 flex flex-wrap items-baseline gap-x-4 gap-y-1 border-t border-white/10 pt-1.5 text-xs text-muted">
            <span><kbd class="text-accent2">j k</kbd> move</span>
            <span><kbd class="text-accent2">h l</kbd> folder</span>
            <span><kbd class="text-accent2">Enter</kbd> play</span>
            <span><kbd class="text-accent2">x</kbd> mark</span>
            <span><kbd class="text-accent2">v</kbd> move</span>
            <span><kbd class="text-accent2">N</kbd> new folder</span>
            <span><kbd class="text-accent2">A</kbd> analyse</span>
            <span><kbd class="text-accent2">e</kbd> edit</span>
            <span><kbd class="text-accent2">i</kbd> look up</span>
            <span><kbd class="text-accent2">:</kbd> command</span>
            <span><kbd class="text-accent2">o</kbd> problems</span>
            <span><kbd class="text-accent2">l</kbd> in tracks: details</span>
            <span><kbd class="text-accent2">I</kbd> info</span>
            <span><kbd class="text-accent2">Tab</kbd> pane</span>
            <span><kbd class="text-accent2">?</kbd> keys</span>
            <span class="ml-auto min-w-0 truncate text-accent2" aria-live="polite" x-text="progress || msg"></span>
        </div>

        {{-- Where to move the album(s) or the folder: j k and Enter, or a click. --}}
        <div x-show="mover" x-cloak x-transition.opacity class="fixed inset-0 z-30 flex items-start justify-center bg-black/50 p-4 pt-24" @click.self="mover = null; focusRoot()" role="dialog" aria-modal="true" aria-label="Move to a folder">
            <div class="relative w-full max-w-sm rounded-theme border border-accent bg-surface px-1 pb-2 pt-4 shadow-xl">
                <span class="absolute -top-2.5 left-3 max-w-[90%] truncate bg-surface px-1.5 text-accent" x-text="mover?.title"></span>
                <ul class="max-h-72 overflow-y-auto">
                    <template x-for="(c, i) in mover?.choices ?? []" :key="c.id ?? 'top'">
                        <li class="flex cursor-pointer items-center px-2 pointer-coarse:min-h-11" :data-mover="i === mover.index" :class="i === mover.index ? 'bg-accent text-bg' : 'hover:bg-white/5'" @click="chooseMove(i)">
                            <span class="truncate" :style="'padding-left:' + (c.depth ? (c.depth - 1) * 1.2 : 0) + 'rem'" x-text="(c.id === null ? '' : '▸ ') + c.name"></span>
                        </li>
                    </template>
                </ul>
                <p class="px-2 pt-2 text-xs text-muted">j k choose · Enter move · Esc cancel</p>
            </div>
        </div>

        {{-- New folder, rename. Nothing on disk changes. --}}
        <div x-show="fd" x-cloak x-transition.opacity @keydown.escape.window="fd && ! $store.dialog.open && (fd = null, focusRoot())"
             class="fixed inset-0 z-30 flex items-center justify-center bg-black/60 p-4" @click.self="fd = null; focusRoot()"
             role="dialog" aria-modal="true" aria-label="Folder">
            <form class="w-full max-w-sm rounded-theme border border-white/10 bg-surface p-5 text-sm shadow-xl" @submit.prevent="saveFd()">
                <h2 class="mb-4 font-semibold" x-text="fd?.mode === 'rename' ? 'Rename folder' : 'New folder'"></h2>
                <template x-if="fd">
                    <label class="block"><span class="mb-1 block text-xs text-muted" x-text="fd.mode === 'create' ? 'Name (inside ' + (folders.find((f) => f.id === fd.parent_id)?.name ?? 'the top level') + ')' : 'Name'"></span>
                        <input type="text" x-ref="folderName" x-model="fd.name" required maxlength="100" placeholder="Jazz" class="w-full rounded-theme border border-white/10 bg-bg px-2 py-1.5"></label>
                </template>
                <p x-show="fd?.error" x-text="fd?.error" class="mt-3 text-xs text-red-500"></p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-theme border border-white/10 px-3 py-1 hover:text-accent" @click="fd = null; focusRoot()">Cancel</button>
                    <button type="submit" :disabled="fd?.saving" class="rounded-theme bg-accent px-3 py-1 font-medium text-bg disabled:opacity-60">Save</button>
                </div>
            </form>
        </div>

        {{-- The edit table: the album's fields and every track in one grid, saved together. Library only; files and tags are never changed. --}}
        <div x-show="table" x-cloak x-transition.opacity @keydown="tableKey($event)" class="fixed inset-0 z-30 flex items-start justify-center overflow-y-auto bg-black/60 p-4 pt-8" @click.self="closeTable()"
             role="dialog" aria-modal="true" aria-label="Edit album">
            <div data-table class="relative flex max-h-[88vh] w-full max-w-3xl flex-col rounded-theme border border-accent bg-surface px-3 pb-3 pt-5 text-sm shadow-xl">
                <span class="absolute -top-2.5 left-3 max-w-[85%] truncate bg-surface px-1.5 text-accent" x-text="'Edit · ' + (table?.o.title ?? '')"></span>
                <template x-if="table">
                    <div class="flex min-h-0 flex-1 flex-col">
                        <div class="mb-2 flex flex-wrap gap-1.5 text-xs">
                            <button type="button" class="rounded-theme border border-white/15 px-2 py-0.5 hover:border-accent hover:text-accent" @click="tableTitleCase()">Title Case</button>
                            <button type="button" class="rounded-theme border border-white/15 px-2 py-0.5 hover:border-accent hover:text-accent" @click="tableClearArtists()">Clear track artists</button>
                            <button type="button" class="rounded-theme border border-white/15 px-2 py-0.5 hover:border-accent hover:text-accent" @click="tableReset()">Reset</button>
                        </div>
                        <div class="mb-3 grid gap-2 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_8.5rem_minmax(0,1fr)]">
                            <label class="block"><span class="mb-0.5 block text-[11px] text-muted">Album title</span>
                                <input type="text" data-r="-1" data-c="1" x-model="table.title" maxlength="255" class="w-full rounded-theme border bg-bg px-2 py-1" :class="tableDirty('title') ? 'border-accent2' : 'border-white/15'"></label>
                            <label class="block"><span class="mb-0.5 block text-[11px] text-muted">Album artist</span>
                                <input type="text" data-r="-1" data-c="2" x-model="table.artist" maxlength="255" class="w-full rounded-theme border bg-bg px-2 py-1" :class="tableDirty('artist') ? 'border-accent2' : 'border-white/15'"></label>
                            <label class="block"><span class="mb-0.5 block text-[11px] text-muted">Release date</span>
                                <input type="text" data-r="-1" data-c="3" x-model="table.release_date" maxlength="10" placeholder="YYYY-MM-DD" inputmode="numeric" class="w-full rounded-theme border bg-bg px-2 py-1" :class="tableDirty('release_date') ? 'border-accent2' : 'border-white/15'"></label>
                            <label class="block"><span class="mb-0.5 block text-[11px] text-muted">Record label</span>
                                <input type="text" data-r="-1" data-c="4" x-model="table.label" maxlength="255" class="w-full rounded-theme border bg-bg px-2 py-1" :class="tableDirty('label') ? 'border-accent2' : 'border-white/15'"></label>
                        </div>
                        <div class="mb-1 hidden grid-cols-[3rem_minmax(0,1.5fr)_minmax(0,1fr)_3rem] gap-2 px-0.5 text-[11px] text-muted sm:grid"><span class="text-right">#</span><span>Track title</span><span>Track artist (empty: the album's)</span><span class="text-right">time</span></div>
                        <div class="min-h-0 flex-1 space-y-1 overflow-y-auto pr-1">
                            <template x-for="(r, n) in table.rows" :key="r.id">
                                <div class="grid grid-cols-[3rem_minmax(0,1fr)_3rem] items-center gap-2 sm:grid-cols-[3rem_minmax(0,1.5fr)_minmax(0,1fr)_3rem]">
                                    <input type="text" :data-r="n" data-c="0" x-model="r.number" inputmode="numeric" maxlength="3" aria-label="Track number" class="w-full rounded-theme border bg-bg px-1 py-1 text-right tabular-nums" :class="tableDirty('number', r) ? 'border-accent2' : 'border-white/15'">
                                    <input type="text" :data-r="n" data-c="1" x-model="r.title" maxlength="255" :aria-label="'Title of track ' + (n + 1)" class="w-full min-w-0 rounded-theme border bg-bg px-2 py-1" :class="tableDirty('title', r) ? 'border-accent2' : 'border-white/15'">
                                    <input type="text" :data-r="n" data-c="2" x-model="r.artist" maxlength="255" placeholder="= album artist" :aria-label="'Artist of track ' + (n + 1)" class="hidden w-full min-w-0 rounded-theme border bg-bg px-2 py-1 sm:block" :class="tableDirty('artist', r) ? 'border-accent2' : 'border-white/15'">
                                    <span class="text-right text-xs tabular-nums text-muted" x-text="fmt(r.time)"></span>
                                </div>
                            </template>
                        </div>
                        <p x-show="table.error" x-text="table.error" class="mt-2 text-xs text-red-500"></p>
                        <div class="mt-3 flex items-center justify-end gap-2">
                            <span class="mr-auto text-xs text-muted" x-text="tableChanges ? tableChanges + (tableChanges === 1 ? ' change' : ' changes') : 'no changes'"></span>
                            <button type="button" class="rounded-theme border border-white/15 px-3 py-1 hover:text-accent pointer-coarse:min-h-11" @click="closeTable()">Cancel</button>
                            <button type="button" :disabled="table.saving" class="rounded-theme bg-accent px-3 py-1 font-medium text-bg disabled:opacity-60 pointer-coarse:min-h-11" @click="saveTable()">Save</button>
                        </div>
                        <p class="mt-2 text-[11px] text-muted">↑ ↓ and Enter move between cells · Tab next field · Ctrl+Enter saves · Esc cancels</p>
                    </div>
                </template>
            </div>
        </div>

        {{-- Details found online for an album: release date, record label and track titles, each applied only if ticked. --}}
        <div x-show="titles" x-cloak x-transition.opacity @keydown.escape.window="titles && ! $store.dialog.open && closeTitles()" class="fixed inset-0 z-30 flex items-center justify-center bg-black/60 p-4" @click.self="closeTitles()"
             role="dialog" aria-modal="true" aria-label="Album details found online">
            <div class="flex max-h-[85vh] w-full max-w-lg flex-col rounded-theme border border-white/15 bg-surface p-5 text-sm shadow-xl">
                <h2 class="mb-1 font-semibold">Details for “<span x-text="titles?.title"></span>”</h2>
                <p class="mb-3 text-xs text-muted">Looked up on MusicBrainz by album title and artist, and checked against the number and length of your files. Tick what to use. Your files are not touched.</p>
                <p x-show="titles?.loading" class="py-6 text-center text-muted">Searching…</p>
                <p x-show="titles && ! titles.loading && ! titles.error && titles.candidates.length === 0" class="py-4 text-muted">No release on MusicBrainz fits this album (title, artist, number of tracks and their lengths). Check the title and artist with Edit, then try again.</p>
                <template x-if="titles && titles.candidates.length">
                    <div class="flex min-h-0 flex-col gap-3">
                        <div x-show="titles.candidates.length > 1" class="flex flex-wrap gap-2">
                            <template x-for="(c, i) in titles.candidates" :key="i">
                                <button type="button" class="rounded-theme border px-2 py-0.5 text-xs" :class="titles.pick === i ? 'border-accent text-accent' : 'border-white/15 text-muted hover:text-accent'" @click="pickRelease(i)" x-text="c.title + (c.date ? ' (' + c.date + ')' : '') + ' · ' + c.matched + '/' + c.release_tracks"></button>
                            </template>
                        </div>
                        <p class="text-xs text-muted" x-text="titles.candidates[titles.pick].artist + ' · ' + titles.candidates[titles.pick].matched + ' of ' + titles.candidates[titles.pick].release_tracks + ' track(s) fit'"></p>
                        <div class="space-y-1.5 rounded-theme border border-white/15 p-2 text-xs">
                            <label class="flex cursor-pointer items-center gap-2" :class="! titles.candidates[titles.pick].date && 'opacity-50'"><input type="checkbox" x-model="titles.parts.date" :disabled="! titles.candidates[titles.pick].date" class="accent-[var(--c-accent)]"><span class="w-24 shrink-0 text-muted">Release date</span><span class="min-w-0 truncate"><span class="text-muted" x-text="titles.album.release_date || '–'"></span> → <span x-text="titles.candidates[titles.pick].date || 'not on this release'"></span></span></label>
                            <label class="flex cursor-pointer items-center gap-2" :class="! titles.candidates[titles.pick].label && 'opacity-50'"><input type="checkbox" x-model="titles.parts.label" :disabled="! titles.candidates[titles.pick].label" class="accent-[var(--c-accent)]"><span class="w-24 shrink-0 text-muted">Record label</span><span class="min-w-0 truncate"><span class="text-muted" x-text="titles.album.label || '–'"></span> → <span x-text="titles.candidates[titles.pick].label || 'not on this release'"></span></span></label>
                            <label class="flex cursor-pointer items-center gap-2"><input type="checkbox" x-model="titles.parts.titles" class="accent-[var(--c-accent)]"><span class="w-24 shrink-0 text-muted">Track titles</span><span class="min-w-0 truncate" x-text="titles.candidates[titles.pick].tracks.filter((r) => r.matched && r.title !== r.current).length + ' to rename (only where the length fits)'"></span></label>
                        </div>
                        <ol class="min-h-0 divide-y divide-white/5 overflow-y-auto rounded-theme border border-white/15 text-xs" :class="! titles.parts.titles && 'opacity-50'">
                            <template x-for="(row, i) in titles.candidates[titles.pick].tracks" :key="row.id">
                                <li class="flex items-center gap-2 px-2 py-1.5"><span class="w-6 shrink-0 text-right tabular-nums text-muted" x-text="row.number ?? i + 1"></span><span class="min-w-0 flex-1 truncate text-muted" x-text="row.current"></span><span class="shrink-0 text-muted">→</span><span class="min-w-0 flex-1 truncate" :class="row.matched ? '' : 'text-muted line-through'" x-text="row.matched ? row.title : '(length differs)'"></span></li>
                            </template>
                        </ol>
                    </div>
                </template>
                <p x-show="titles?.error" x-text="titles?.error" class="mt-3 text-xs text-red-500"></p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="rounded-theme border border-white/15 px-3 py-1 hover:text-accent" @click="closeTitles()">Close</button>
                    <button type="button" x-show="titles?.candidates.length" :disabled="titles?.saving || ! anyPart" @click="applyTitles()" class="rounded-theme bg-accent px-3 py-1 font-medium text-bg disabled:opacity-60">Use selected</button>
                </div>
            </div>
        </div>

        {{-- Name an album for tracks that have none. --}}
        <div x-show="gd" x-cloak x-transition.opacity @keydown.escape.window="gd && ! $store.dialog.open && (gd = null, focusRoot())" class="fixed inset-0 z-30 flex items-center justify-center bg-black/60 p-4" @click.self="gd = null; focusRoot()" role="dialog" aria-modal="true" aria-label="Name an album">
            <form class="w-full max-w-sm rounded-theme border border-white/15 bg-surface p-5 text-sm shadow-xl" @submit.prevent="saveGroup()">
                <h2 class="mb-1 font-semibold">Name an album</h2>
                <p class="mb-4 text-xs text-muted" x-text="(gd?.ids.length ?? 0) + ' track(s) without an album get this one. Only the library changes; the files and tags do not.'"></p>
                <template x-if="gd">
                    <div class="space-y-3">
                        <label class="block"><span class="mb-1 block text-xs text-muted">Album title</span><input type="text" x-ref="groupTitle" x-model="gd.title" required maxlength="255" class="w-full rounded-theme border border-white/15 bg-bg px-2 py-1.5"></label>
                        <label class="block"><span class="mb-1 block text-xs text-muted">Artist (optional)</span><input type="text" x-model="gd.artist" maxlength="255" class="w-full rounded-theme border border-white/15 bg-bg px-2 py-1.5"></label>
                        <label class="block"><span class="mb-1 block text-xs text-muted">Year (optional)</span><input type="number" x-model="gd.year" min="1000" max="2100" class="w-full rounded-theme border border-white/15 bg-bg px-2 py-1.5"></label>
                    </div>
                </template>
                <p x-show="gd?.error" x-text="gd?.error" class="mt-3 text-xs text-red-500"></p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-theme border border-white/15 px-3 py-1 hover:text-accent" @click="gd = null; focusRoot()">Cancel</button>
                    <button type="submit" :disabled="gd?.saving" class="rounded-theme bg-accent px-3 py-1 font-medium text-bg disabled:opacity-60">Make album</button>
                </div>
            </form>
        </div>

        {{-- Edit one track without an album (an album's tracks are in the edit table). --}}
        <div x-show="edit" x-cloak x-transition.opacity @keydown.escape.window="edit && ! $store.dialog.open && (edit = null, focusRoot())"
             class="fixed inset-0 z-30 flex items-center justify-center bg-black/60 p-4" @click.self="edit = null; focusRoot()" role="dialog" aria-modal="true" aria-label="Edit track">
            <form class="w-full max-w-sm rounded-theme border border-white/10 bg-surface p-5 text-sm shadow-xl" @submit.prevent="saveEdit()">
                <h2 class="mb-1 font-semibold">Edit track</h2>
                <p class="mb-4 text-xs text-muted">Only what this library shows changes. Your files and their tags are not touched, and a rescan keeps these edits.</p>
                <template x-if="edit">
                    <div class="space-y-3">
                        <label class="block"><span class="mb-1 block text-xs text-muted">Track title</span><input type="text" x-ref="editFirst" x-model="edit.fields.title" required maxlength="255" class="w-full rounded-theme border border-white/10 bg-bg px-2 py-1.5"></label>
                        <label class="block"><span class="mb-1 block text-xs text-muted">Artist (empty: the album's)</span><input type="text" x-model="edit.fields.artist" maxlength="255" class="w-full rounded-theme border border-white/10 bg-bg px-2 py-1.5"></label>
                        <label class="block"><span class="mb-1 block text-xs text-muted">Track number</span><input type="number" x-model="edit.fields.track_number" min="1" max="999" class="w-full rounded-theme border border-white/10 bg-bg px-2 py-1.5"></label>
                    </div>
                </template>
                <p x-show="edit?.error" x-text="edit?.error" class="mt-3 text-xs text-red-500"></p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-theme border border-white/10 px-3 py-1 hover:text-accent" @click="edit = null; focusRoot()">Cancel</button>
                    <button type="submit" :disabled="edit?.saving" class="rounded-theme bg-accent px-3 py-1 font-medium text-bg disabled:opacity-60">Save</button>
                </div>
            </form>
        </div>
    </div>

    @include('pages.partials.playlist-picker')
</x-layouts.app>
