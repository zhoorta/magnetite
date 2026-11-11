<!DOCTYPE html>
<html lang="en">
<head>
    <x-layouts.head :title="$title ?? 'Magnetite'" />
</head>
@php
    // The bar, the pages and the player share one left edge; only the landing page is narrower and centred.
    $maxWidth = ($wide ?? false) === true ? 'max-w-5xl' : 'max-w-7xl';
@endphp
<body class="min-h-screen bg-bg text-text antialiased">
    {{-- The top bar, as in the mockup: small, on the surface colour, the page you are on underlined. --}}
    @php
        $link = fn (string $route) => request()->routeIs($route) ? 'border-b border-accent text-text' : 'text-muted hover:text-accent';
    @endphp
    <header class="border-b border-white/15 bg-surface" x-data="{ menu: false }" @keydown.escape.window="menu = false">
        <nav class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-5 gap-y-2 px-4 py-2 text-xs sm:flex-nowrap">
            <a href="{{ route('welcome') }}" wire:navigate class="font-bold text-accent">Magnetite</a>
            <button type="button" class="-mr-1 ml-auto rounded-theme p-1.5 text-muted hover:text-accent sm:hidden" aria-label="Menu" :aria-expanded="menu" @click="menu = ! menu">
                <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true">
                    <path x-show="! menu" d="M4 6h16M4 12h16M4 18h16"/>
                    <path x-show="menu" x-cloak d="M6 6l12 12M18 6L6 18"/>
                </svg>
            </button>
            <div :class="menu ? 'flex' : 'hidden'" class="basis-full -mx-2 flex-col gap-1 sm:contents [&_a]:block [&_a]:rounded-theme [&_a]:px-2 [&_a]:py-2 [&_a:hover]:bg-white/10 sm:[&_a]:p-0 sm:[&_a]:rounded-none sm:[&_a:hover]:bg-transparent [&_button]:rounded-theme [&_button]:px-2 [&_button]:py-2 [&_button:hover]:bg-white/10 sm:[&_button]:p-0 sm:[&_button:hover]:bg-transparent">
                <a href="{{ route('library') }}" wire:navigate class="{{ request()->routeIs('library', 'shared', 'playlists*') ? 'border-b border-accent text-text' : 'text-muted hover:text-accent' }}">Library</a>
                @auth
                    <a href="{{ route('upload') }}" wire:navigate class="{{ $link('upload') }}">Upload</a>
                @endauth
                <a href="{{ route('themes') }}" wire:navigate class="{{ $link('themes') }}">Themes</a>
                @auth
                    <a href="{{ route('scenes') }}" wire:navigate class="{{ $link('scenes') }}">Scenes</a>
                @endauth
                @auth
                    <a href="{{ route('storage') }}" wire:navigate class="{{ $link('storage') }}">Storage</a>
                @endauth
                <div class="sm:ml-auto"><x-themes-menu /></div>
                @auth
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-muted hover:text-accent">Sign out</button>
                    </form>
                @endauth
            </div>
        </nav>
    </header>

    <main x-data class="mx-auto {{ $maxWidth }} px-4 pt-6" style="padding-bottom: calc(var(--dock-h, 13rem) + var(--status-h, 0px))">
        {{ $slot }}
    </main>

    {{-- Survives wire:navigate: the <audio> element and its Web Audio graph are never recreated. --}}
    @persist('player')
        <div x-data
             x-init="$store.player.mount($refs.audio, $refs.canvas)"
             :class="$store.player.expanded ? 'inset-0 flex flex-col bg-bg' : 'inset-x-0 bg-bg px-[max(1rem,calc((100vw-80rem)/2+1rem))] pb-1 pt-3'"
             :style="$store.player.expanded ? '' : 'bottom: var(--status-h, 0px)'"
             class="fixed z-10">
            <audio x-ref="audio" preload="metadata"></audio>

            {{-- Docked, as in docs/mockups/dense-library.html: a "Now playing" box with the track on the left, the meters on the right, and the progress and buttons under both. Full screen: the meters fill the screen. --}}
            <div :class="$store.player.expanded ? 'flex min-h-0 flex-1 flex-col' : 'relative grid grid-cols-1 gap-x-4 rounded-theme border border-white/15 px-3 md:grid-cols-2'">
            <span x-show="! $store.player.expanded" class="absolute -top-2.5 left-3 bg-bg px-1.5 text-xs text-muted">Now playing</span>

            <canvas x-ref="canvas" x-show="$store.player.panels.length"
                    :class="$store.player.expanded ? 'flex-1' : 'order-2 my-2 h-28 md:order-none md:col-start-2 md:row-start-1 md:my-3'"
                    class="w-full"></canvas>

            {{-- No meters: the player is just the small-cover row below, so the page keeps its space; the big centred cover only in full screen. --}}
            <div x-show="! $store.player.panels.length && $store.player.expanded" x-cloak
                 class="mx-auto flex min-h-0 w-full max-w-3xl flex-1 flex-col items-center justify-center gap-6 px-6 text-center">
                <div class="aspect-square max-h-[50vh] w-[min(80vw,28rem)] shrink-0 overflow-hidden rounded-theme bg-surface shadow-lg shadow-black/30">
                    <img x-show="$store.player.current?.cover" :src="$store.player.current?.cover" alt="" class="h-full w-full object-cover">
                </div>
                <div class="min-w-0">
                    <p x-text="$store.player.current?.title ?? 'Nothing playing'"
                       class="truncate text-2xl font-semibold sm:text-4xl"></p>
                    <p x-show="$store.player.current?.artist" x-text="$store.player.current?.artist"
                       class="truncate text-lg text-accent"></p>
                    <p x-show="$store.player.current?.album" x-text="[$store.player.current?.album, $store.player.position].filter(Boolean).join(' · ')" class="truncate text-sm text-muted"></p>
                    <p x-show="$store.player.dock.line" x-text="$store.player.dock.line" class="truncate text-sm"></p>
                </div>
            </div>

            {{-- The app's own confirm and notice boxes ($store.dialog), instead of the browser's. --}}
            <div x-show="$store.dialog.open" x-cloak x-transition.opacity
                 class="fixed inset-0 z-40 flex items-center justify-center bg-black/60 p-4"
                 @click.self="$store.dialog.answer(false)"
                 @keydown.escape.window="$store.dialog.open && ($event.preventDefault(), $store.dialog.answer(false))"
                 @keydown.tab="if ($store.dialog.open) { const b = [...$el.querySelectorAll('button')].filter((x) => x.offsetParent); const i = b.indexOf(document.activeElement); $event.preventDefault(); b[($event.shiftKey ? i - 1 + b.length : i + 1) % b.length].focus(); }"
                 role="alertdialog" aria-modal="true" :aria-label="$store.dialog.title || 'Message'">
                <div class="w-full max-w-sm rounded-theme border border-white/10 bg-surface p-5 text-sm shadow-xl">
                    <h2 x-show="$store.dialog.title" x-text="$store.dialog.title" class="mb-2 font-semibold"></h2>
                    <p x-text="$store.dialog.message" class="text-muted"></p>
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" data-dialog-cancel x-show="$store.dialog.asking"
                                class="rounded-theme border border-white/10 px-3 py-1 hover:text-accent" @click="$store.dialog.answer(false)">Cancel</button>
                        <button type="button" data-dialog-ok
                                :class="$store.dialog.danger ? 'bg-red-600 text-white' : 'bg-accent text-bg'"
                                class="rounded-theme px-3 py-1 font-medium" @click="$store.dialog.answer(true)"
                                x-text="$store.dialog.confirmLabel"></button>
                    </div>
                </div>
            </div>

            {{-- Keyboard shortcuts, opened with ? --}}
            <div x-show="$store.player.helpOpen" x-cloak x-transition.opacity
                 class="fixed inset-0 z-30 flex items-center justify-center bg-black/60 p-4"
                 @click.self="$store.player.helpOpen = false" role="dialog" aria-label="Keyboard shortcuts">
                <div class="w-full max-w-sm rounded-theme border border-white/10 bg-surface p-5 text-sm shadow-xl">
                    <h2 class="mb-3 font-semibold">Keyboard shortcuts</h2>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5">
                        @foreach ([
                            'Space' => 'Play or pause',
                            '← →' => 'Back or forward 5 s',
                            'Shift + ← →' => 'Back or forward 30 s',
                            '0 – 9' => 'Jump to 0 – 90 % of the track',
                            'n / p' => 'Next track / previous (or restart)',
                            ', .' => 'Back or forward 10 s',
                            'Shift + I' => 'How much the lists and the player say about the files: all, DR only, nothing',
                            'm / M' => 'Next / previous meter on the active panel (none shown: bring one back)',
                            '+ / -' => 'Add / remove a meter panel (0 to 4)',
                            '[ / ]' => 'Choose the panel m changes (Tab in full screen)',
                            'c' => 'Meter colours from the playing cover',
                            't / T' => 'Next / previous theme',
                            'F' => 'Full screen',
                            'Esc' => 'Close full screen or this list',
                            '?' => 'This list',
                        ] as $keys => $what)
                            <dt><kbd class="rounded-theme border border-white/10 bg-bg px-1.5 py-0.5 text-xs">{{ $keys }}</kbd></dt>
                            <dd class="text-muted">{{ $what }}</dd>
                        @endforeach
                    </dl>
                    {{-- The library's own keys, while its page is open (resources/js/magnetite/dense/keymap.js) --}}
                    <div x-show="$store.player.libraryKeys" x-cloak class="mt-5 border-t border-white/10 pt-4">
                        <h3 class="mb-2 text-xs uppercase tracking-wide text-muted">In the library</h3>
                        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5">
                            @foreach ([
                                'j k · h l' => 'Move · close or open a folder (h on an album: its folder)',
                                'g G · Tab' => 'Top, bottom · the other pane',
                                'Enter' => 'Play, or open or close a folder',
                                'x · v' => 'Mark an album · move the marked (or selected) to a folder; u undoes a move',
                                'N r d' => 'New folder · rename · delete (asks first)',
                                'e · i · C' => 'Edit table · look up online · find a cover',
                                'A · S' => 'Analyse · share',
                                's · o' => 'Order (title, year, DR) · only files with findings',
                                '⌫' => 'Back: from an album to its folder, from a folder to the one above',
                                'w · W' => 'Source: Library, Shared, Playlists (or :shared, :playlists)',
                                'U' => 'Upload zips (or drop them on the library)',
                                'z' => 'Flat list: all albums without folders, again to go back',
                                'y' => 'Copy the selected shared album or track to your library, or a shared playlist to yours',
                                'J K (playlist)' => 'Move the selected track down or up; d removes it; N a new playlist',
                                'l (tracks)' => 'The measurements of a file',
                                '/ · :' => 'Search (year: dr: codec: artist: album:) · command line, Tab completes',
                                ':truth' => 'Every file, the worst first',
                            ] as $keys => $what)
                                <dt><kbd class="rounded-theme border border-white/10 bg-bg px-1.5 py-0.5 text-xs">{{ $keys }}</kbd></dt>
                                <dd class="text-muted">{{ $what }}</dd>
                            @endforeach
                        </dl>
                    </div>
                    <button type="button" class="mt-4 text-xs text-muted hover:text-accent" @click="$store.player.helpOpen = false">Close</button>
                </div>
            </div>

            <div :class="$store.player.expanded ? 'mx-auto w-full max-w-3xl px-4 pt-3' : 'order-1 py-3 ' + ($store.player.panels.length ? 'md:col-start-1 md:row-start-1' : 'md:col-span-2 md:row-start-1')"
                 class="flex flex-col gap-2">
                <div class="flex min-h-10 items-center justify-between text-sm">
                    <div x-show="$store.player.panels.length || ! $store.player.expanded" class="flex min-w-0 items-center gap-3">
                        <div :class="$store.player.expanded ? 'h-16 w-16' : 'h-24 w-24'" class="shrink-0 overflow-hidden rounded-theme bg-surface">
                            <img x-show="$store.player.current?.cover" :src="$store.player.current?.cover" alt="" class="h-full w-full object-cover">
                        </div>
                        <div class="min-w-0 leading-snug">
                            <p class="truncate">
                                <span x-text="$store.player.current?.title ?? 'Nothing playing'" class="font-bold" :class="$store.player.current?.album_id && 'cursor-pointer hover:text-accent'" :title="$store.player.current?.album_id ? 'Show in the library' : null" @click="$store.player.showInLibrary()"></span>
                                <span x-show="$store.player.current" class="ml-1 inline-flex align-middle" role="img" :aria-label="$store.player.playing ? 'Playing' : 'Paused'">
                                    <x-icon name="pause" x-show="$store.player.playing" class="size-3 text-amber-500" />
                                    <x-icon name="play" x-show="! $store.player.playing" x-cloak class="size-3 text-amber-500" />
                                </span>
                            </p>
                            <p x-show="$store.player.current?.artist" x-text="$store.player.current?.artist" class="truncate text-accent"></p>
                            <p x-show="$store.player.current?.album" class="truncate text-xs text-muted" :class="$store.player.current?.album_id && 'cursor-pointer hover:text-accent'" @click="$store.player.showInLibrary()" x-text="[$store.player.current?.album, $store.player.current?.year, $store.player.position].filter(Boolean).join(' · ')"></p>
                            {{-- Format, DR and loudness (info level 1 and up), then the findings (level 2): see dense/info.js --}}
                            <p x-show="$store.player.dock.line" class="truncate text-xs" x-text="$store.player.dock.line"></p>
                            <p x-show="$store.player.note" x-cloak x-text="$store.player.note" class="truncate text-xs text-accent2" aria-live="polite"></p>
                            <p x-show="$store.player.dock.findings.length" class="truncate text-xs">
                                <template x-for="f in $store.player.dock.findings" :key="f.code">
                                    <span class="mr-3" :title="f.detail"><span :class="f.level === 'problem' ? 'text-red-500' : 'text-amber-500'">!</span> <span x-text="f.label"></span></span>
                                </template>
                            </p>
                        </div>
                    </div>
                    {{-- The mockup's dock has no buttons: on the library page the meters and full screen are in its toolbar and on keys. Other pages keep them, and full screen shows them (to get out of it). --}}
                    <div x-show="$store.player.expanded || ! $store.player.libraryKeys" class="ml-auto flex items-center gap-2">
                        <x-menu up label="Meters" width="w-56">
                            <x-slot:trigger>
                                <button type="button" x-ref="trigger" @click="toggle()" :aria-expanded="open" aria-haspopup="menu"
                                        title="Choose the meters (m changes the active one, + and - add and remove)" aria-label="Meters"
                                        class="flex items-center gap-1.5 rounded-theme border border-white/10 px-2 py-1 text-xs hover:text-accent"
                                        :class="$store.player.panels.length && 'text-accent'">
                                    <svg viewBox="0 0 16 16" class="h-3.5 w-3.5" fill="currentColor" aria-hidden="true">
                                        <rect x="1" y="8" width="3" height="7" rx=".5"/><rect x="6.5" y="2" width="3" height="13" rx=".5"/><rect x="12" y="5" width="3" height="10" rx=".5"/>
                                    </svg>
                                    <span class="tabular-nums" x-text="$store.player.panels.length"></span>
                                </button>
                            </x-slot:trigger>
                            <p class="px-3 pb-1 pt-0.5 text-xs text-muted" x-text="'Up to ' + $store.player.maxPanels + ' at once'"></p>
                            <template x-for="v in $store.player.visualizerList" :key="v.id">
                                <button type="button" role="menuitemcheckbox" tabindex="-1" :aria-checked="$store.player.hasMeter(v.id)"
                                        :disabled="! $store.player.hasMeter(v.id) && $store.player.meterLimitReached"
                                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-white/5 focus-visible:bg-white/5 focus-visible:outline-none disabled:opacity-40"
                                        @click.stop="$store.player.toggleMeter(v.id)">
                                    <span class="w-4 text-accent" x-text="$store.player.hasMeter(v.id) ? '✓' : ''"></span>
                                    <span x-text="v.label"></span>
                                </button>
                            </template>
                        </x-menu>
                        <button type="button" class="rounded-theme border border-white/10 p-1.5 hover:text-accent"
                                :title="$store.player.expanded ? 'Close full screen (F)' : 'Full screen (F)'"
                                :aria-label="$store.player.expanded ? 'Close full screen' : 'Full screen'"
                                @click="$store.player.expanded = ! $store.player.expanded">
                            <svg viewBox="0 0 16 16" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path x-show="! $store.player.expanded" d="M2 6V2h4M10 2h4v4M14 10v4h-4M6 14H2v-4"/>
                                <path x-show="$store.player.expanded" x-cloak d="M6 2v4H2M14 6h-4V2M10 14v-4h4M2 10h4v4"/>
                            </svg>
                        </button>
                        <button type="button" title="Keyboard shortcuts (?)" aria-label="Keyboard shortcuts"
                                class="hidden rounded-theme border border-white/10 px-2 py-1 text-xs hover:text-accent sm:block"
                                @click="$store.player.helpOpen = true">?</button>
                    </div>
                </div>

                <p x-show="$store.player.error" x-text="$store.player.error" class="text-xs text-accent2"></p>
                <p x-show="$store.player.playing && ['suspended', 'interrupted'].includes($store.player.audioState)"
                   class="text-xs text-accent2">Sound is blocked by the browser. Click anywhere on the page to enable it.</p>

            </div>

            <div :class="$store.player.expanded ? 'mx-auto w-full max-w-3xl px-4 pb-3' : 'order-3 pb-3 md:col-span-2 md:row-start-2'" class="flex flex-col gap-3">
                <div class="flex items-center gap-3 text-xs text-muted">
                    <span x-text="$store.player.fmt($store.player.time)" class="w-10 text-right tabular-nums"></span>
                    <input type="range" min="0" max="1000" class="seek flex-1" aria-label="Position in the track"
                           :style="'--p: ' + ($store.player.duration ? Math.min(100, $store.player.time / $store.player.duration * 100) : 0) + '%'"
                           :value="$store.player.duration ? ($store.player.time / $store.player.duration) * 1000 : 0"
                           @input="$store.player.seek($event.target.value / 1000)">
                    <span x-text="$store.player.fmt($store.player.duration)" class="w-10 tabular-nums"></span>
                </div>

                {{-- Previous, play or pause, next: as in the mockup. --}}
                <div class="flex items-center justify-center gap-2 text-sm">
                    <button type="button" class="inline-flex items-center rounded-theme border border-white/15 px-4 py-1 hover:border-accent hover:text-accent" title="Previous (p)" aria-label="Previous" @click="$store.player.prev()"><x-icon name="prev" /></button>
                    {{-- Shows the state, like the icons in the lists: the play icon while it plays, the pause icon (in amber) while it is paused. --}}
                    <button type="button" class="inline-flex items-center rounded-theme border px-4 py-1 hover:border-accent hover:text-accent" :class="$store.player.playing ? 'border-accent text-accent' : 'border-amber-500 text-amber-500'"
                            :title="$store.player.playing ? 'Pause (space)' : 'Play (space)'" :aria-label="$store.player.playing ? 'Pause' : 'Play'" :aria-pressed="$store.player.playing" @click="$store.player.toggle()">
                        <x-icon name="pause" x-show="$store.player.playing" />
                        <x-icon name="play" x-show="! $store.player.playing" x-cloak />
                    </button>
                    <button type="button" class="inline-flex items-center rounded-theme border border-white/15 px-4 py-1 hover:border-accent hover:text-accent" title="Next (n)" aria-label="Next" @click="$store.player.next()"><x-icon name="next" /></button>
                </div>
            </div>
            </div>
        </div>
    @endpersist
</body>
</html>
