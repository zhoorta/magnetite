<!DOCTYPE html>
<html lang="en">
<head>
    <x-layouts.head :title="$title ?? 'Magnetite'" />
</head>
@php
    $maxWidth = ($wide ?? false) === 'full' ? 'max-w-7xl' : (($wide ?? false) ? 'max-w-5xl' : 'max-w-3xl');
@endphp
<body class="min-h-screen bg-bg text-text antialiased">
    <header class="border-b border-white/10" x-data="{ menu: false }" @keydown.escape.window="menu = false">
        <nav class="mx-auto flex {{ $maxWidth }} flex-wrap items-center gap-x-6 gap-y-3 px-4 py-4 text-sm sm:flex-nowrap">
            <a href="{{ route('welcome') }}" wire:navigate class="font-semibold tracking-wide text-accent">Magnetite</a>
            <button type="button" class="-mr-1 ml-auto rounded-theme p-1.5 text-muted hover:text-accent sm:hidden" aria-label="Menu" :aria-expanded="menu" @click="menu = ! menu">
                <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true">
                    <path x-show="! menu" d="M4 6h16M4 12h16M4 18h16"/>
                    <path x-show="menu" x-cloak d="M6 6l12 12M18 6L6 18"/>
                </svg>
            </button>
            <div :class="menu ? 'flex' : 'hidden'" class="basis-full -mx-2 flex-col gap-1 sm:contents [&_a]:block [&_a]:rounded-theme [&_a]:px-2 [&_a]:py-2 [&_a:hover]:bg-white/10 sm:[&_a]:p-0 sm:[&_a:hover]:bg-transparent [&_button]:rounded-theme [&_button]:px-2 [&_button]:py-2 [&_button:hover]:bg-white/10 sm:[&_button]:p-0 sm:[&_button:hover]:bg-transparent">
                <a href="{{ route('library') }}" wire:navigate class="hover:text-accent">Library</a>
                @auth
                    <a href="{{ route('shared') }}" wire:navigate class="hover:text-accent">Shared</a>
                    <a href="{{ route('playlists') }}" wire:navigate class="hover:text-accent">Playlists</a>
                @endauth
                <a href="{{ route('themes') }}" wire:navigate class="hover:text-accent">Themes</a>
                @auth
                    <a href="{{ route('upload') }}" wire:navigate class="hover:text-accent">Upload</a>
                    <a href="{{ route('storage') }}" wire:navigate class="hover:text-accent">Storage</a>
                    <form method="POST" action="{{ route('logout') }}" class="sm:ml-auto">
                        @csrf
                        <button type="submit" class="text-muted hover:text-accent">Sign out</button>
                    </form>
                @endauth
            </div>
        </nav>
    </header>

    <main x-data :class="$store.player.panels.length ? 'pb-80' : 'pb-52'" class="mx-auto {{ $maxWidth }} px-4 pt-8">
        {{ $slot }}
    </main>

    {{-- Survives wire:navigate: the <audio> element and its Web Audio graph are never recreated. --}}
    @persist('player')
        <div x-data
             x-init="$store.player.mount($refs.audio, $refs.canvas)"
             :class="$store.player.expanded ? 'inset-0 bg-bg' : 'inset-x-0 bottom-0 border-t border-white/10 bg-surface'"
             class="fixed z-10 flex flex-col">
            <audio x-ref="audio" preload="metadata"></audio>

            <canvas x-ref="canvas" x-show="$store.player.panels.length"
                    :class="$store.player.expanded ? 'flex-1' : 'h-28'"
                    class="w-full"></canvas>

            {{-- No meters: the player is just the small-cover row below, so the page keeps its space; the big centred cover only in full screen. --}}
            <div x-show="! $store.player.panels.length && $store.player.expanded" x-cloak
                 class="mx-auto flex min-h-0 w-full max-w-3xl flex-1 flex-col items-center justify-center gap-6 px-6 text-center">
                <div class="aspect-square max-h-[50vh] w-[min(80vw,28rem)] shrink-0 overflow-hidden rounded-theme bg-surface shadow-lg shadow-black/30">
                    <img x-show="$store.player.current?.cover && ! $store.ui.pixelated" :src="$store.player.current?.cover" alt="" class="h-full w-full object-cover">
                    <canvas x-show="$store.player.current?.cover && $store.ui.pixelated" x-effect="$store.ui.paint($el, $store.player.current?.cover)" class="h-full w-full [image-rendering:pixelated]" aria-hidden="true"></canvas>
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
                            'Shift + P' => 'Pixelate the covers, or show them as they are',
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
                    <button type="button" class="mt-4 text-xs text-muted hover:text-accent" @click="$store.player.helpOpen = false">Close</button>
                </div>
            </div>

            <div class="mx-auto flex w-full max-w-3xl flex-col gap-3 px-4 py-3">
                <div class="flex min-h-10 items-center justify-between text-sm">
                    <div x-show="$store.player.panels.length || ! $store.player.expanded" class="flex min-w-0 items-center gap-3">
                        <div class="h-16 w-16 shrink-0 overflow-hidden rounded-theme bg-surface">
                            <img x-show="$store.player.current?.cover && ! $store.ui.pixelated" :src="$store.player.current?.cover" alt="" class="h-full w-full object-cover">
                            {{-- The mosaic view of the same cover (P, or the theme's choice) --}}
                            <canvas x-show="$store.player.current?.cover && $store.ui.pixelated" x-effect="$store.ui.paint($el, $store.player.current?.cover)" class="h-full w-full [image-rendering:pixelated]" aria-hidden="true"></canvas>
                        </div>
                        <div class="min-w-0 leading-snug">
                            <p class="truncate">
                                <span x-text="$store.player.current?.title ?? 'Nothing playing'" class="font-medium"></span>
                                <span x-show="$store.player.current?.artist" x-text="' · ' + $store.player.current?.artist" class="text-muted"></span>
                            </p>
                            <p x-show="$store.player.current?.album" class="truncate text-xs text-muted" x-text="[$store.player.current?.album, $store.player.position].filter(Boolean).join(' · ')"></p>
                            {{-- Format, DR and loudness (info level 1 and up), then the findings (level 2): see dense/info.js --}}
                            <p x-show="$store.player.dock.line" class="truncate text-xs" x-text="$store.player.dock.line"></p>
                            <p x-show="$store.player.dock.findings.length" class="truncate text-xs">
                                <template x-for="f in $store.player.dock.findings" :key="f.code">
                                    <span class="mr-3" :title="f.detail"><span :class="f.level === 'problem' ? 'text-red-500' : 'text-amber-500'">!</span> <span x-text="f.label"></span></span>
                                </template>
                            </p>
                        </div>
                    </div>
                    <div class="ml-auto flex items-center gap-2">
                        <span x-show="$store.player.note" x-cloak x-text="$store.player.note" class="truncate text-xs text-accent2" aria-live="polite"></span>
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

                <div class="flex items-center gap-3 text-xs text-muted">
                    <span x-text="$store.player.fmt($store.player.time)" class="w-10 text-right tabular-nums"></span>
                    <input type="range" min="0" max="1000" class="h-1 flex-1 cursor-pointer accent-[var(--c-accent)]"
                           :value="$store.player.duration ? ($store.player.time / $store.player.duration) * 1000 : 0"
                           @input="$store.player.seek($event.target.value / 1000)">
                    <span x-text="$store.player.fmt($store.player.duration)" class="w-10 tabular-nums"></span>
                </div>

                <div class="flex items-center justify-center gap-3 text-sm">
                    <button type="button" class="rounded-theme border border-white/10 px-3 py-1 hover:text-accent" @click="$store.player.prev()">Prev</button>
                    <button type="button" class="rounded-theme bg-accent px-5 py-1 font-medium text-bg" @click="$store.player.toggle()"
                            x-text="$store.player.playing ? 'Pause' : 'Play'"></button>
                    <button type="button" class="rounded-theme border border-white/10 px-3 py-1 hover:text-accent" @click="$store.player.next()">Next</button>
                </div>
            </div>
        </div>
    @endpersist
</body>
</html>
