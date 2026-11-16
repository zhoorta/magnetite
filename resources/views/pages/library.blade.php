{{-- The library: the dense, keyboard-first view (docs/dense-library.md). One Alpine component (resources/js/magnetite/dense/) draws it from `$data`. --}}
@php
    $btn = 'shrink-0 rounded-theme border border-white/15 px-2.5 py-0.5 text-sm hover:border-accent hover:text-accent disabled:opacity-40 disabled:hover:border-white/15 disabled:hover:text-text pointer-coarse:min-h-11 pointer-coarse:px-3.5';
@endphp
<x-layouts.app :title="($data['demo'] ?? false) ? 'Demo library · Magnetite' : (($data['source'] ?? 'library') === 'shared' ? 'Shared · Magnetite' : (($data['source'] ?? 'library') === 'playlists' ? 'Playlists · Magnetite' : 'Library · Magnetite'))" wide="full">
    {{-- The page data as JSON in a script block: in an attribute every quote would be escaped and the page twice as big. --}}
    <script type="application/json" id="library-data">{!! json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) !!}</script>
    <div x-data="dense(JSON.parse(document.getElementById('library-data').textContent))" tabindex="0" @keydown="onKey($event)" role="application" aria-label="Library"
         :aria-activedescendant="mode === 'tree' && rows[cur] ? 'row-' + rows[cur].key : null"
         class="text-sm outline-none focus-visible:[&>.panes]:outline-none"
         @dragenter.window="isFileDrag($event) && (fileDrag = true)" @dragover.window="isFileDrag($event) && $event.preventDefault()"
         @dragleave.window="$event.relatedTarget === null && (fileDrag = false)" @drop.window="dropFiles($event)">

        {{-- The toolbar: what applies to the selection on the left (the rest is in the ⋯ menu, which also opens on a right-click), the views on the right as small icons. --}}
        <div class="mb-3 flex flex-wrap items-center gap-1.5" role="toolbar" aria-label="Actions on the selection">
            {{-- The sources: your library, what everybody shares, playlists (w cycles them). --}}
            <div x-show="mode === 'tree' && ! demo" class="flex shrink-0 rounded-theme border border-white/15 p-px" role="group" aria-label="Source">
                <template x-for="name in ['library', 'shared', 'playlists']" :key="name">
                    <button type="button" class="inline-flex items-center rounded-theme px-2 py-1 text-sm pointer-coarse:min-h-11 pointer-coarse:px-3" :class="source === name ? 'bg-accent text-bg' : 'text-muted hover:text-accent'" :aria-pressed="source === name"
                            :aria-label="sourceLabels[name]" :title="sourceLabels[name] + (name === 'library' ? ' (your own music)' : name === 'shared' ? ' (shared by everyone on this server)' : ' (yours and the shared ones)') + ' (w cycles)'" @click="setSource(name)">
                        <span x-show="name === 'library'"><x-icon name="library" /></span>
                        <span x-show="name === 'shared'" x-cloak><x-icon name="people" /></span>
                        <span x-show="name === 'playlists'" x-cloak><x-icon name="playlist" /></span>
                    </button>
                </template>
            </div>
            <button type="button" x-show="mode === 'tree' && source !== 'playlists'" class="{{ $btn }} inline-flex items-center max-md:hidden" :class="flat && '!border-accent !text-accent'" :aria-pressed="flat" title="Albums as one list, without folders (z)" aria-label="Flat list" @click="toggleFlat(); focusRoot()"><x-icon name="flat" /></button>
            <button type="button" x-show="mode !== 'tree'" x-cloak class="{{ $btn }} inline-flex items-center gap-1.5" title="Back to the folders and albums (Esc)" aria-label="Back" @click="closeView()"><x-icon name="back" /></button>

            <button type="button" x-show="mode === 'tree' && tracks.length" x-cloak class="{{ $btn }} inline-flex items-center" :class="playHere && '!border-accent !text-accent'"
                    :title="$store.player.playing ? 'Pause (space)' : playHere ? 'Resume (space)' : 'Play the selection (Enter)'" :aria-label="$store.player.playing ? 'Pause' : playHere ? 'Resume' : 'Play'"
                    @click="$store.player.playing || playHere ? $store.player.toggle() : (pane === 'tracks' ? playTrack(tsel) : playTrack(0, false)); focusRoot()">
                <x-icon name="play" x-show="! $store.player.playing" /><x-icon name="pause" x-show="$store.player.playing" x-cloak />
            </button>
            <button type="button" x-show="mode === 'tree' && source === 'library' && ! demo" class="{{ $btn }} inline-flex items-center gap-1.5 max-md:hidden" :class="selectMode && '!border-accent !text-accent'" :aria-pressed="selectMode" title="Mark several albums (x), again to finish" aria-label="Select" @click="toggleSelectMode()">
                <x-icon name="select" /><span x-show="marks.length" x-cloak x-text="marks.length"></span>
            </button>
            @auth
            <div x-show="mode === 'tree' && playlistItem" x-cloak class="flex items-center rounded-theme border border-white/15 px-1 pointer-coarse:min-h-11"><x-playlist-menu item="playlistItem" label="Add to a playlist" /></div>
            @endauth

            {{-- Everything else, for what is selected: the key is shown beside each. Right-click a row opens it too. --}}
            <x-menu label="Actions" width="w-60">
                <x-slot:trigger>
                    <button type="button" x-show="mode === 'tree' && ! demo" data-actions x-ref="trigger" @click="toggle()" :aria-expanded="open" aria-haspopup="menu" class="{{ $btn }} inline-flex items-center gap-1.5" title="More actions for the selection"><x-icon name="dots" /><span class="sr-only">Actions</span></button>
                </x-slot:trigger>
                @include('pages.partials.library-actions')
            </x-menu>

            {{-- The views and filters, as icons (the name and the key are in the tooltip). --}}
            <div class="ml-auto flex flex-wrap items-center gap-1.5">
                <button type="button" x-show="! demo" class="{{ $btn }} inline-flex items-center max-md:hidden" :class="($store.uploads.active || uploadOpen) && '!border-accent !text-accent'" title="Upload zips (U), or drop them on this page" aria-label="Upload" @click="uploadOpen ? closeUpload() : openUpload()"><x-icon name="upload" /></button>
                <button type="button" x-show="! demo" class="{{ $btn }} inline-flex items-center" :class="mode === 'search' && '!border-accent !text-accent'" title="Search your music and what is shared: words, year:1983 dr:<8 codec:flac artist: album: (/)" aria-label="Search" @click="mode === 'search' ? closeView() : openSearch()"><x-icon name="search" /></button>
                <button type="button" class="{{ $btn }} inline-flex items-center max-md:hidden" :class="mode === 'truth' && ! onlyProblems && '!border-accent !text-accent'" :aria-pressed="mode === 'truth' && ! onlyProblems" title="Truth view: every file, the worst first (:truth)" aria-label="Truth view" @click="mode === 'truth' && ! onlyProblems ? closeView() : openTruth(false); focusRoot()"><x-icon name="truth" /></button>
                <button type="button" class="{{ $btn }} inline-flex items-center max-md:hidden" :class="mode === 'truth' && onlyProblems && '!border-accent !text-accent'" :aria-pressed="mode === 'truth' && onlyProblems" title="Findings view: only the files with a finding (o)" aria-label="Findings view" @click="mode === 'truth' && onlyProblems ? closeView() : openTruth(true); focusRoot()"><x-icon name="warn" /></button>
                <button type="button" class="{{ $btn }} inline-flex items-center gap-1.5 max-md:hidden" :class="$store.ui.info > 0 && 'text-accent'" title="How much to show about the files: all, DR only, nothing (I)" :aria-label="$store.ui.label()" @click="$store.ui.cycleInfo(); focusRoot()"><x-icon name="info" /><span class="hidden text-xs sm:inline" x-text="['off', 'DR', 'all'][$store.ui.info]"></span></button>
                <button type="button" class="{{ $btn }} inline-flex items-center max-md:hidden" :class="cmd && '!border-accent !text-accent'" :aria-pressed="!! cmd" title="Command line (:)" aria-label="Command line" @click="cmd ? closeCmd() : openCmd()"><x-icon name="command" /></button>
                            {{-- On a phone: the views and tools that do not fit in one row, in one menu (the keys are shown beside each). --}}
                <div class="md:hidden">
                    <x-menu label="Views and tools" width="w-60">
                        <x-slot:trigger>
                            <button type="button" x-ref="trigger" @click="toggle()" :aria-expanded="open" aria-haspopup="menu" class="{{ $btn }} inline-flex items-center" title="Views and tools" aria-label="Views and tools"><x-icon name="menu" /></button>
                        </x-slot:trigger>
                        <x-menu.item x-show="mode === 'tree' && source !== 'playlists'" @click="toggleFlat(); focusRoot()"><span x-text="flat ? 'Folders' : 'Flat list (no folders)'"></span> <span class="ml-auto text-xs opacity-60">z</span></x-menu.item>
                        <x-menu.item x-show="mode === 'tree' && source === 'library' && ! demo" @click="toggleSelectMode()"><span x-text="selectMode ? 'Finish selecting' : 'Select albums'"></span><span x-show="marks.length" x-cloak x-text="'(' + marks.length + ')'"></span> <span class="ml-auto text-xs opacity-60">x</span></x-menu.item>
                        <x-menu.item @click="mode === 'truth' && ! onlyProblems ? closeView() : openTruth(false); focusRoot()">Truth view <span class="ml-auto text-xs opacity-60">:truth</span></x-menu.item>
                        <x-menu.item @click="mode === 'truth' && onlyProblems ? closeView() : openTruth(true); focusRoot()">Findings view <span class="ml-auto text-xs opacity-60">o</span></x-menu.item>
                        <x-menu.item @click="$store.ui.cycleInfo(); focusRoot()">Info: <span x-text="['off', 'DR only', 'all'][$store.ui.info]"></span> <span class="ml-auto text-xs opacity-60">I</span></x-menu.item>
                        <x-menu.item x-show="! demo" @click="uploadOpen ? closeUpload() : openUpload()">Upload <span class="ml-auto text-xs opacity-60">U</span></x-menu.item>
                        <x-menu.item @click="cmd ? closeCmd() : openCmd()">Command line <span class="ml-auto text-xs opacity-60">:</span></x-menu.item>
                    </x-menu>
                </div>
            </div>
        </div>

        <p x-show="! hasAnything" x-cloak class="text-muted">The library is empty. <a href="{{ route('upload') }}" wire:navigate class="text-accent" @click.prevent="openUpload()">Upload a zip</a>, or ask the server's admin to copy the files into the library folder and run a scan.</p>

        <div x-show="hasAnything" class="panes grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
            {{-- Folders and albums --}}
            <div class="relative rounded-theme border pt-3" :class="mode !== 'tree' || pane === 'tree' ? 'border-accent' : 'border-white/15'">
                <span class="absolute -top-2.5 left-3 max-w-[90%] truncate bg-bg px-1.5" :class="mode !== 'tree' || pane === 'tree' ? 'text-accent' : 'text-muted'"
                      x-text="mode === 'search' ? 'Search' + (search.q.trim() ? ' · ' + searchList.length + (search.more.albums || search.more.tracks ? '+' : '') + ' found' : '') : mode === 'truth' ? (onlyProblems ? 'Findings · ' : 'Truth · ') + truth.length + ' files · ' + truthSort : (demo ? 'Demo' : sourceLabels[source]) + ' · ' + albums.length + (source === 'playlists' ? (albums.length === 1 ? ' playlist' : ' playlists') : albums.length === 1 ? ' album' : ' albums')"></span>

                {{-- The search box is here, at the top of the results, on every screen size (easier to see than a bar at the bottom, and on a phone the keyboard would cover the list). It is always in the page (hidden from sight while closed) so a tap on the search button can focus it at once, which is what makes Safari open the keyboard. --}}
                <div :class="mode === 'search' ? 'mx-2 mb-1 mt-1 flex items-center gap-2 border-b border-accent2 pb-1.5 text-sm' : 'sr-only'">
                    <span class="text-accent2" aria-hidden="true">/</span>
                    <input type="text" x-ref="searchInputTop" x-model="search.q" @input.debounce.250ms="searchTyped()" @keydown="searchKey($event)" @focus="typing = true"
                           :tabindex="mode === 'search' ? 0 : -1" spellcheck="false" autocomplete="off" autocapitalize="off" enterkeyhint="search" aria-label="Search"
                           class="min-w-0 flex-1 bg-transparent text-base outline-none md:text-sm" placeholder="words, year:1983, dr:<8, codec:flac, artist:bowie, album:heroes">
                    <span class="hidden shrink-0 text-xs text-muted md:inline" x-text="typing ? 'Enter: results · Esc: close' : '/ : edit · Enter: play or open · Esc: close'"></span>
                    <button type="button" class="shrink-0 rounded-theme border border-white/15 px-2 py-0.5 text-muted pointer-coarse:min-h-9" aria-label="Close search" @click="closeView()">✕</button>
                </div>

                {{-- Search results: the albums, then the tracks. A track plays, an album of yours opens in the tree. --}}
                <ul x-show="mode === 'search'" x-cloak data-list="search" aria-label="Search results" class="h-[34dvh] min-h-48 overflow-y-auto pb-1 md:h-[calc(100dvh_-_var(--panes-top,10rem)_-_var(--dock-h,13rem)_-_var(--status-h,2rem)_-_1.25rem)] md:min-h-56">
                    <li x-show="! searchList.length" class="px-2 py-1 text-muted" x-text="search.loading ? 'Searching…' : search.error || (search.q.trim().length < 2 ? 'Type two letters or more. Filters: year:1983  year:1960..1969  dr:<8  codec:flac  artist:bowie  album:heroes' : 'Nothing found')"></li>
                    <template x-for="(it, i) in searchList" :key="it.key">
                        <li :data-selected="i === scur && ! typing" class="flex cursor-pointer touch-manipulation select-none items-baseline gap-2 px-2 pointer-coarse:min-h-11 pointer-coarse:items-center"
                            :class="i === scur && ! typing ? 'bg-accent text-bg' : 'hover:bg-white/5'" @click="scur = i; typing = false; focusRoot(); isTouch($event) && enterHit()" @dblclick="enterHit()">
                            <span class="w-12 shrink-0 text-xs opacity-70" x-text="it.kind === 'album' ? 'album' : 'track'"></span>
                            <span class="min-w-0 flex-1 truncate" x-text="it.kind === 'album' ? it.album.title : it.track.title"></span>
                            <span class="hidden min-w-0 max-w-[40%] shrink truncate text-xs opacity-70 sm:inline" x-text="it.kind === 'album' ? it.album.artist : [it.track.artist, it.track.album].filter(Boolean).join(' · ')"></span>
                            <span class="w-11 shrink-0 text-right text-xs tabular-nums" x-show="$store.ui.info > 0 && (it.kind === 'album' ? it.album.dr : it.track.dr) != null" x-cloak x-text="'DR' + (it.kind === 'album' ? it.album.dr : it.track.dr)"></span>
                            <span class="w-9 shrink-0 text-right text-xs tabular-nums opacity-70" x-text="(it.kind === 'album' ? it.album.year : it.track.year) ?? ''"></span>
                            <span class="w-4 shrink-0 text-xs opacity-70" :title="(it.kind === 'album' ? it.album.mine : it.track.mine) ? '' : 'Shared by someone else'"><x-icon name="people" class="size-3" x-show="! (it.kind === 'album' ? it.album.mine : it.track.mine)" x-cloak role="img" aria-label="Shared by someone else" /></span>
                        </li>
                    </template>
                    <li x-show="search.loading && searchList.length" class="px-2 py-1 text-xs text-muted">Searching…</li>
                </ul>

                {{-- The truth view: every file, worst first. s changes the order, o keeps the ones with findings, Enter plays from here. --}}
                <ul x-show="mode === 'truth'" x-cloak data-list="truth" aria-label="Every file" class="h-[34dvh] min-h-48 overflow-y-auto pb-1 md:h-[calc(100dvh_-_var(--panes-top,10rem)_-_var(--dock-h,13rem)_-_var(--status-h,2rem)_-_1.25rem)] md:min-h-56">
                    <li x-show="! truth.length" class="px-2 py-1 text-muted">No files measured yet. A analyses the library.</li>
                    <template x-for="(r, i) in truthShown" :key="r.track.id">
                        <li :data-selected="i === tcur" class="flex cursor-pointer touch-manipulation select-none items-baseline gap-2 px-2 pointer-coarse:min-h-11 pointer-coarse:items-center"
                            :class="i === tcur ? 'bg-accent text-bg' : 'hover:bg-white/5'" @click="tcur = i; focusRoot(); isTouch($event) && playTruth(i)" @dblclick="playTruth(i)">
                            <span class="w-5 shrink-0 text-center text-xs font-bold" :class="i === tcur ? '' : 'text-amber-500'" x-text="flagCount(r.track) ? flagCount(r.track) + '!' : ''"></span>
                            <span class="min-w-0 flex-1 truncate" :class="$store.player.current?.id === r.track.id && 'font-bold'">
                                <span x-text="r.track.title"></span>
                                <span class="text-xs opacity-70" x-text="r.album ? ' · ' + r.album.title : ''"></span>
                            </span>
                            <span class="hidden shrink-0 text-xs sm:inline" x-show="$store.ui.info >= 2" x-text="flags(r.track).map((f) => f.label).join(', ')"></span>
                            <span class="w-11 shrink-0 text-right text-xs font-medium tabular-nums" x-show="$store.ui.info > 0 && r.track.truth.dr != null" x-cloak :class="i === tcur ? '' : drClass(r.track.truth.dr_class)" x-text="'DR' + r.track.truth.dr"></span>
                            <span class="hidden w-20 shrink-0 text-right text-xs tabular-nums opacity-70 sm:inline" x-text="r.track.truth.lufs != null ? db(r.track.truth.lufs, ' LUFS') : ''"></span>
                            <span class="w-10 shrink-0 text-right text-xs tabular-nums opacity-70" x-text="fmt(r.track.duration)"></span>
                        </li>
                    </template>
                </ul>

                <ul role="tree" x-show="mode === 'tree'" data-list="tree" aria-label="Folders and albums" class="h-[34dvh] min-h-48 md:h-[calc(100dvh_-_var(--panes-top,10rem)_-_var(--dock-h,13rem)_-_var(--status-h,2rem)_-_1.25rem)] md:min-h-56 overflow-y-auto pb-1">
                    <template x-for="(r, i) in rows" :key="r.key">
                        <li role="treeitem" :id="'row-' + r.key" :aria-selected="i === cur" :aria-level="r.depth + 1" :aria-expanded="r.kind === 'folder' ? openIds.includes(r.id) : null" :data-selected="i === cur"
                            class="flex cursor-pointer touch-manipulation select-none items-baseline gap-2 px-2 pointer-coarse:min-h-11 pointer-coarse:items-center"
                            :class="[i === cur ? (pane === 'tree' ? 'bg-accent text-bg' : 'bg-white/15') : 'hover:bg-white/5', dropOn === i && 'outline-dashed outline-1 -outline-offset-1 outline-accent2']"
                            :draggable="r.kind === 'album' && ! demo"
                            @click="rowClick($event, i)" @dblclick="rowDblClick(i)" @contextmenu.prevent="openContext($event, 'row', i)"
                            @pointerdown="holdStart($event, i)" @pointerup="holdEnd()" @pointercancel="holdEnd()" @pointerleave="holdEnd()"
                            @dragstart="dragStart($event, i)" @dragover="dragOver($event, i)" @dragleave="dropOn = null" @drop="drop($event, i)">
                            <span x-show="selectMode || marks.length" x-cloak class="w-3 shrink-0 text-center" :class="i === cur && pane === 'tree' ? '' : 'text-accent2'" x-text="r.kind === 'album' ? (isMarked(r.id) ? '●' : selectMode ? '○' : '') : ''"></span>
                            <span class="min-w-0 flex-1 truncate" :style="'padding-left:' + (r.depth * 1.2) + 'rem'">
                                <span x-show="r.kind === 'folder'" class="mr-1 inline-block w-3 opacity-70" x-text="openIds.includes(r.id) ? '▾' : '▸'"></span>
                                <span :class="r.kind !== 'album' && 'font-semibold'" x-text="r.kind === 'folder' ? r.folder.name : r.kind === 'album' ? r.album.title : looseTitle"></span>
                                {{-- playing (play icon) or paused (pause icon) from this album, or from an album inside this folder --}}
                                <span class="ml-1 inline-flex align-middle" :class="i === cur && pane === 'tree' ? '' : 'text-amber-500'"
                                      x-show="(r.kind === 'album' && r.id === playingAlbumId) || (r.kind === 'folder' && holdsPlaying(r.id))" x-cloak
                                      :title="$store.player.playing ? 'Playing from here' : 'Paused here'" role="img" :aria-label="$store.player.playing ? 'Playing from here' : 'Paused here'">
                                    <x-icon name="play" x-show="$store.player.playing" class="size-3" />
                                    <x-icon name="pause" x-show="! $store.player.playing" x-cloak class="size-3" />
                                </span>
                                <span x-show="r.kind === 'album' && isShared('albums', r.id)" x-cloak class="ml-1 inline-flex align-middle opacity-70" title="Shared with everyone on this server"><x-icon name="people" class="size-3" role="img" aria-label="Shared" /></span>
                                <span x-show="r.kind === 'album' && analysisNote(r.id)" x-cloak class="ml-1 text-xs opacity-70" x-text="analysisNote(r.id)"></span>
                            </span>
                            <span class="hidden min-w-0 max-w-[32%] shrink truncate text-right text-xs opacity-70 sm:inline" x-show="r.kind === 'album'" x-text="r.album?.artist"></span>
                            {{-- DR, with the findings mark hanging in the gap to its left, so no column is reserved for it --}}
                            <span class="relative w-9 shrink-0" x-show="$store.ui.info > 0" x-cloak>
                            <span class="block text-right text-xs font-medium tabular-nums" 
                                  :class="[i === cur && pane === 'tree' ? '' : (r.kind === 'album' ? drClass(r.album?.dr_class) : 'opacity-70'), ! ((r.kind === 'album' && r.album.dr !== null) || (r.kind === 'folder' && r.dr !== null)) && 'invisible']" x-text="'DR' + (r.kind === 'album' ? r.album?.dr : r.dr)"></span>
                                <span class="absolute right-full top-0 pr-1 text-xs font-bold text-amber-500" :class="i === cur && pane === 'tree' && '!text-bg'" x-show="$store.ui.info >= 2 && r.kind === 'album' && problemCount(r.album) > 0" x-cloak
                                      :title="problemCount(r.album) + (problemCount(r.album) === 1 ? ' track' : ' tracks') + ' with findings'">!</span>
                            </span>
                        </li>
                    </template>
                </ul>
            </div>

            {{-- The tracks of the selected album, and under them the measurements of the selected track (l) --}}
            <div class="relative rounded-theme border pt-3" :class="mode === 'tree' && (pane === 'tracks' || pane === 'grid') ? 'border-accent' : 'border-white/15'">
                <span class="absolute -top-2.5 left-3 max-w-[85%] truncate bg-bg px-1.5" :class="mode === 'tree' && (pane === 'tracks' || pane === 'grid') ? 'text-accent' : 'text-muted'" x-text="paneTitle"></span>
                <div class="flex h-[34dvh] min-h-48 flex-col md:h-[calc(100dvh_-_var(--panes-top,10rem)_-_var(--dock-h,13rem)_-_var(--status-h,2rem)_-_1.25rem)] md:min-h-56">
                    <div x-show="shown" class="flex items-center gap-2 px-2 pb-1">
                        <div x-show="shown && ! shown.loose && ! shown.playlist" class="size-12 shrink-0 overflow-hidden rounded-theme bg-white/5">
                            <img x-show="shown?.cover" :src="shown?.cover" alt="" class="size-full object-cover">
                            <button type="button" x-show="! shown?.cover && source === 'library'" class="flex size-full items-center justify-center p-0.5 text-center text-[10px] leading-tight text-muted hover:text-accent" title="Look a cover up online (C)" @click="findCover(); focusRoot()">Find cover</button>
                        </div>
                        <p class="min-w-0 truncate text-xs text-muted" x-text="shown ? [shown.artist, shown.year, shown.label, summary(tracks)].filter(Boolean).join(' · ') : ''"></p>
                    </div>

                    {{-- Playlists: who it is from, what is private, and what you cannot play. --}}
                    <div x-show="shown?.playlist" x-cloak class="space-y-1 px-2 pb-1 text-xs">
                        <p x-show="! shown?.mine" class="text-muted" x-text="'Shared by ' + shown?.artist + '; it follows their edits. y makes a copy with the tracks that are shared.'"></p>
                        <p x-show="shown?.mine && shown?.shared && shown?.unshared > 0" class="flex flex-wrap items-center gap-2 rounded-theme border border-white/10 bg-surface px-2 py-1">
                            <span class="text-muted" x-text="shown?.unshared + (shown?.unshared === 1 ? ' track is' : ' tracks are') + ' not shared, so others see them greyed out and cannot play them.'"></span>
                            <button type="button" class="rounded-theme border border-white/10 px-2 py-0.5 hover:text-accent" @click="shareTracksToo(); focusRoot()">Share those tracks too</button>
                        </p>
                        <p x-show="shown?.mine && shown?.hidden > 0" class="text-muted" x-text="shown?.hidden + (shown?.hidden === 1 ? ' track' : ' tracks') + (shown?.hidden === 1 ? ' is' : ' are') + ' hidden because the owner stopped sharing them. They return if shared again.'"></p>
                    </div>

                    {{-- Tracks without an album tag: what the folder and file names and MusicBrainz suggest. Nothing changes until "Use this". --}}
                    <div x-show="shown?.loose && source === 'library' && ! demo" x-cloak class="max-h-56 shrink-0 overflow-y-auto px-2 pb-2 text-xs">
                        <div class="mb-1 flex items-center gap-2">
                            <button type="button" x-show="toIdentify > 0" class="rounded-theme border border-white/15 px-2 py-0.5 hover:border-accent hover:text-accent" :disabled="['queued', 'running'].includes(identify.state)" @click="findAlbums()" title="Look at the folders of untagged tracks: only guessed names are sent to MusicBrainz">Suggest albums (i)</button>
                            <span class="text-muted" x-text="identifyNote"></span>
                            <span x-show="trackMarks.length" class="text-muted" x-text="trackMarks.length + ' marked · :group names an album for them'"></span>
                        </div>
                        <template x-for="sug in suggestions" :key="sug.id">
                            <div class="mb-2 rounded-theme border border-white/15 p-2">
                                <div class="mb-1 flex items-center gap-2"><span class="min-w-0 flex-1 truncate text-muted" :title="sug.folder" x-text="'Folder ' + sug.folder.split('/').pop() + ' · ' + sug.candidates[0].tracks.length + (sug.candidates[0].tracks.length === 1 ? ' track' : ' tracks')"></span>
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
                    <p x-show="mode === 'tree' && rows.length === 0 && emptyNote" x-cloak class="px-2 py-1 text-muted" x-text="emptyNote"></p>
                    <p x-show="mode === 'search' && ! shown && hit" x-cloak class="px-2 py-1 text-muted" x-text="hit?.kind === 'track' ? [hit.track.artist, hit.track.album, hit.track.year].filter(Boolean).join(' · ') + (hit.track.mine ? '' : ' · shared by someone else') : (hit?.album.mine ? '' : 'Shared by someone else: Enter opens the shared page')"></p>
                    {{-- A selected folder: what is in it, as tiles (Tab goes in, arrows move, Enter opens), and the totals of everything below it. --}}
                    <div x-show="mode === 'tree' && row?.kind === 'folder'" x-cloak class="flex min-h-0 flex-1 flex-col">
                        <p class="truncate px-2 pb-1 text-xs text-muted" x-text="folderSummary"></p>
                        <p x-show="! tiles.length" class="px-2 py-1 text-muted">Empty. Move albums here with Move or by dragging them onto the folder.</p>
                        <div data-list="grid" class="grid min-h-0 flex-1 content-start gap-2 overflow-y-auto px-2 pb-2" style="grid-template-columns: repeat(auto-fill, minmax(8.5rem, 1fr))">
                            <template x-for="(t, i) in tilesShown" :key="t.key">
                                <div :data-selected="pane === 'grid' && i === gsel" class="cursor-pointer select-none rounded-theme border p-1.5"
                                     :class="pane === 'grid' && i === gsel ? 'border-accent bg-white/10' : 'border-white/10 hover:border-white/30'"
                                     @click="tileClick($event, i)" @dblclick="tileDblClick(i)" @contextmenu.prevent="tileContext($event, i)">
                                    <div class="relative flex aspect-square items-center justify-center overflow-hidden rounded-theme bg-white/5 text-muted">
                                        <img x-show="t.kind === 'album' && t.album.cover" :src="t.kind === 'album' ? t.album.cover : null" alt="" loading="lazy" class="size-full object-cover">
                                        <span x-show="t.kind === 'album' && ! t.album.cover" class="px-1 text-center text-xs leading-tight" x-text="t.kind === 'album' ? t.album.title : ''"></span>
                                        <x-icon name="folder" x-show="t.kind === 'folder'" class="size-10" />
                                        <span x-show="t.kind === 'up'" x-cloak class="text-3xl">↰</span>
                                        <span x-show="t.kind === 'album' && isMarked(t.album.id)" x-cloak class="absolute left-1 top-0.5 text-accent2">●</span>
                                        <span x-show="t.kind === 'album' && t.album.id === playingAlbumId" x-cloak class="absolute right-1 top-1 inline-flex rounded-theme bg-bg/70 p-0.5 text-amber-500" :title="$store.player.playing ? 'Playing from here' : 'Paused here'"><x-icon name="play" x-show="$store.player.playing" class="size-3" /><x-icon name="pause" x-show="! $store.player.playing" x-cloak class="size-3" /></span>
                                    </div>
                                    <p class="mt-1 truncate text-sm" :class="t.kind === 'folder' && 'font-semibold'" x-text="t.kind === 'up' ? 'Back' : t.kind === 'folder' ? t.folder.name : t.album.title"></p>
                                    <p class="truncate text-xs text-muted" x-text="t.kind === 'up' ? t.folder.name : t.kind === 'folder' ? t.count + (t.count === 1 ? ' album' : ' albums') : [t.album.artist, t.album.year].filter(Boolean).join(' · ')"></p>
                                    <p x-show="t.kind === 'album' && $store.ui.info > 0 && (t.album?.dr != null || problemCount(t.album) > 0)" x-cloak class="flex gap-2 text-xs">
                                        <span x-show="t.album?.dr != null" :class="drClass(t.album?.dr_class)" x-text="'DR' + t.album?.dr"></span>
                                        <span x-show="$store.ui.info >= 2 && problemCount(t.album) > 0" class="font-bold text-amber-500" :title="problemCount(t.album) + (problemCount(t.album) === 1 ? ' track' : ' tracks') + ' with findings'">!</span>
                                    </p>
                                </div>
                            </template>
                            <button type="button" x-show="tiles.length > gridLimit" x-cloak class="col-span-full rounded-theme border border-white/15 px-2 py-1 text-xs hover:border-accent hover:text-accent" @click="gridLimit += 60; focusRoot()" x-text="'Show more (' + (tiles.length - gridLimit) + ' left)'"></button>
                        </div>
                    </div>
                    <button type="button" x-show="albumFolder" x-cloak class="flex shrink-0 items-center gap-2 border-y border-white/5 px-2 py-0.5 text-left text-xs text-muted hover:bg-white/5 hover:text-accent" :title="'Back to ' + (albumFolder?.name ?? '') + ' (Backspace)'" @click="toFolder()"><span class="w-6 shrink-0 text-right">↰</span><span class="min-w-0 flex-1 truncate" x-text="albumFolder?.name"></span><kbd class="text-[10px] opacity-60">⌫</kbd></button>
                    <ul aria-label="Tracks" data-list="tracks" class="min-h-0 flex-1 overflow-y-auto pb-1" :class="shown ? '' : 'hidden'" x-effect="syncSortable(canReorder)">
                        <template x-for="(t, i) in tracks" :key="t.id">
                            <li :data-selected="pane === 'tracks' && i === tsel"
                                class="flex cursor-pointer touch-manipulation select-none items-baseline gap-2 px-2 pointer-coarse:min-h-11 pointer-coarse:items-center"
                                :class="[pane === 'tracks' && i === tsel ? 'bg-accent text-bg' : 'hover:bg-white/5', t.open === false && 'opacity-40']"
                                @click="trackClick(i, $event)" @dblclick="playTrack(i)" @contextmenu.prevent="openContext($event, 'track', i)">
                                <span x-show="shown?.loose && source === 'library' && ! demo" x-cloak class="w-3 shrink-0 text-center" :class="pane === 'tracks' && i === tsel ? '' : 'text-accent2'" x-text="trackMarks.includes(t.id) ? '●' : ''"></span>
                                <span x-show="canReorder" x-cloak data-handle class="w-3 shrink-0 cursor-grab touch-none select-none text-center opacity-60" title="Drag to reorder (Shift+J, Shift+K)" aria-hidden="true">⠿</span>
                                {{-- the number, or on the loaded track the play (playing) or pause (paused) icon, both amber: one column, flush left --}}
                                <span class="inline-flex w-5 shrink-0 items-center text-xs tabular-nums" :class="$store.player.current?.id === t.id ? (pane === 'tracks' && i === tsel ? '' : 'text-amber-500') : 'opacity-70'"
                                      :aria-label="$store.player.current?.id !== t.id ? null : $store.player.playing ? 'Playing' : 'Paused'">
                                    <x-icon name="play" x-show="$store.player.current?.id === t.id && $store.player.playing" x-cloak class="size-3" />
                                    <x-icon name="pause" x-show="$store.player.current?.id === t.id && ! $store.player.playing" x-cloak class="size-3" />
                                    <span x-show="$store.player.current?.id !== t.id" x-text="i + 1"></span>
                                </span>
                                <span class="min-w-0 flex-1 truncate" :class="$store.player.current?.id === t.id && 'font-bold'">
                                    <span x-text="t.title"></span>
                                    <span x-show="isShared('tracks', t.id)" x-cloak class="ml-1 inline-flex align-middle opacity-70" title="Shared on its own"><x-icon name="people" class="size-3" role="img" aria-label="Shared" /></span>
                                    <span x-show="t.open === false" x-cloak class="ml-1 inline-flex align-middle opacity-70" title="This track is not shared with you"><x-icon name="unshared" class="size-3" role="img" aria-label="Not shared with you" /></span>
                                    <span x-show="t.closed && shown?.shared" x-cloak class="ml-1 inline-flex align-middle opacity-70" title="Only you can play this track: it is not shared"><x-icon name="lock" class="size-3" role="img" aria-label="Private" /></span>
                                    <span x-show="t.playable === false && t.open !== false" x-cloak class="ml-1 text-xs opacity-70" title="This browser cannot decode ALAC and ffmpeg is not available to convert it">ALAC</span>
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
                    <p x-show="mode === 'truth' && ! detailTrack" x-cloak class="px-3 py-2 text-xs text-muted">This file has not been measured yet: A analyses it.</p>
                    <div x-show="detailTrack" x-cloak x-effect="detailTrack && ensureSpectrum(detailTrack)" class="overflow-y-auto px-3 py-2 text-xs" :class="mode === 'truth' ? 'min-h-0 flex-1' : 'max-h-[55%] shrink-0 border-t border-white/15'">
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
                                    <p class="-mt-1 flex justify-between text-[10px] text-muted"><span>0</span><span>average spectrum</span><span x-text="khz(detailTrack.truth.spectrum_points * detailTrack.truth.step_hz)"></span></p>
                                    <p x-show="detailTrack.truth.flags.length === 0" class="text-muted">No findings.</p>
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

        {{-- Under the player, as in the mockup: the search box, the command line or the key hints. Fixed to the bottom; the player and the page make room for it (--status-h). --}}
        <div x-ref="statusBar" x-show="! $store.player.expanded" class="fixed inset-x-0 bottom-0 z-20 bg-bg px-[max(1rem,calc((100vw-80rem)/2+1rem))] pb-1">
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
        <div x-show="! cmd && mode === 'truth'" x-cloak class="mt-2 flex flex-wrap items-baseline gap-x-4 gap-y-1 border-t border-white/10 pt-1.5 text-xs text-muted max-md:mt-0 max-md:border-0 max-md:pt-0">
            <span class="max-md:hidden"><kbd class="text-accent2">j k</kbd> move</span>
            <span class="max-md:hidden"><kbd class="text-accent2">Enter</kbd> play from here</span>
            <span class="max-md:hidden"><kbd class="text-accent2">s</kbd> order</span>
            <span class="max-md:hidden"><kbd class="text-accent2">o</kbd> <span x-text="onlyProblems ? 'every file' : 'only findings'"></span></span>
            <span x-show="! demo" class="max-md:hidden"><kbd class="text-accent2">A</kbd> analyse</span>
            <span x-show="! demo" class="max-md:hidden"><kbd class="text-accent2">/</kbd> search</span>
            <span class="max-md:hidden"><kbd class="text-accent2">Esc</kbd> back</span>
<button type="button" x-show="$store.uploads.note" x-cloak class="shrink-0 text-accent hover:underline" title="Upload (U)" @click="openUpload()" x-text="$store.uploads.note"></button>
                        <span class="ml-auto min-w-0 truncate text-accent2" aria-live="polite" x-text="progress || msg"></span>
        </div>
        <div x-show="! cmd && mode === 'tree'" class="mt-2 flex flex-wrap items-baseline gap-x-4 gap-y-1 border-t border-white/10 pt-1.5 text-xs text-muted max-md:mt-0 max-md:border-0 max-md:pt-0">
            <template x-for="h in hintList" :key="h[0]"><span class="max-md:hidden"><kbd class="text-accent2" x-text="h[0]"></kbd> <span x-text="h[1]"></span></span></template>
            <button type="button" class="hover:text-accent max-md:hidden" :title="$store.ui.hints ? 'Show only ? keys (command: hints)' : 'Show the key hints (command: hints)'" @click="$store.ui.toggleHints(); focusRoot()" x-text="$store.ui.hints ? 'hide hints' : 'hints'"></button>
<button type="button" x-show="$store.uploads.note" x-cloak class="shrink-0 text-accent hover:underline" title="Upload (U)" @click="openUpload()" x-text="$store.uploads.note"></button>
                        <span class="ml-auto min-w-0 truncate text-accent2" aria-live="polite" x-text="progress || msg"></span>
        </div>
        </div>

        @auth
        {{-- Upload: the same panel as the page /upload. Esc closes it; an upload goes on in the background and shows in the status line. --}}
        <div x-show="uploadOpen" x-cloak x-transition.opacity @keydown.escape.window="uploadOpen && ! $store.dialog.open && closeUpload()"
             class="fixed inset-0 z-30 flex items-start justify-center bg-black/60 p-4 pt-24" @click.self="closeUpload()" role="dialog" aria-modal="true" aria-label="Upload">
            <x-upload-panel data-upload-panel class="w-full max-w-2xl bg-surface">
                <button type="button" class="text-accent underline" @click="closeUpload()">Close</button>
            </x-upload-panel>
        </div>
        @endauth
        <div x-show="fileDrag" x-cloak class="pointer-events-none fixed inset-3 z-40 flex items-center justify-center rounded-theme border-2 border-dashed border-accent bg-bg/80 text-lg text-accent">Drop zips to upload them</div>

        {{-- The right-click menu: the same actions as the ⋯ button, at the pointer. Esc, a click elsewhere or a choice closes it. --}}
        <div x-show="ctxMenu" x-cloak data-ctxmenu role="menu" aria-label="Actions" class="fixed z-40 w-60 rounded-theme border border-white/10 bg-surface py-1 text-sm shadow-lg shadow-black/40"
             :style="ctxMenu ? 'left: ' + ctxMenu.x + 'px; top: ' + ctxMenu.y + 'px' : ''"
             @click.outside="closeContext(false)"
             @contextmenu.window="ctxMenu && ! $event.target.closest('[role=treeitem], [data-list=tracks] li') && closeContext(false)"
             @click="if ($event.target.closest('[role=menuitem]')) closeContext()"
             @keydown.escape.stop.prevent="closeContext()"
             @keydown.arrow-down.prevent="stepContext(1)" @keydown.arrow-up.prevent="stepContext(-1)"
             @keydown.j.prevent="stepContext(1)" @keydown.k.prevent="stepContext(-1)">
            @include('pages.partials.library-actions')
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
                <h2 class="mb-4 font-semibold" x-text="fd?.mode === 'rename' ? 'Rename folder' : fd?.mode === 'playlist-create' ? 'New playlist' : fd?.mode === 'playlist-rename' ? 'Rename playlist' : 'New folder'"></h2>
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
                <p class="mb-3 text-xs text-muted">Looked up on MusicBrainz by album title and artist, and compared with the number and length of the tracks. Tick what to apply. Changes the library only, not the files or their tags.</p>
                <p x-show="titles?.loading" class="py-6 text-center text-muted">Searching…</p>
                <p x-show="titles && ! titles.loading && ! titles.error && titles.candidates.length === 0" class="py-4 text-muted">No release on MusicBrainz fits this album (title, artist, number of tracks and their lengths). Check the title and artist with Edit, then try again.</p>
                <template x-if="titles && titles.candidates.length">
                    <div class="flex min-h-0 flex-col gap-3">
                        <div x-show="titles.candidates.length > 1" class="flex flex-wrap gap-2">
                            <template x-for="(c, i) in titles.candidates" :key="i">
                                <button type="button" class="rounded-theme border px-2 py-0.5 text-xs" :class="titles.pick === i ? 'border-accent text-accent' : 'border-white/15 text-muted hover:text-accent'" @click="pickRelease(i)" x-text="c.title + (c.date ? ' (' + c.date + ')' : '') + ' · ' + c.matched + '/' + c.release_tracks"></button>
                            </template>
                        </div>
                        <p class="text-xs text-muted" x-text="titles.candidates[titles.pick].artist + ' · ' + titles.candidates[titles.pick].matched + ' of ' + titles.candidates[titles.pick].release_tracks + (titles.candidates[titles.pick].release_tracks === 1 ? ' track fits' : ' tracks fit')"></p>
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
                <p class="mb-4 text-xs text-muted" x-text="'Puts ' + (gd?.ids.length ?? 0) + ((gd?.ids.length ?? 0) === 1 ? ' track' : ' tracks') + ' into a new album. Changes the library only, not the files or their tags.'"></p>
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
                <p class="mb-4 text-xs text-muted">Changes the library only, not the files or their tags. A rescan keeps these edits.</p>
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

    @auth
        @include('pages.partials.playlist-picker')
    @endauth
</x-layouts.app>
