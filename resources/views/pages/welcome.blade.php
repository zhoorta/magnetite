<x-layouts.app title="Magnetite · a self-hosted music player" :wide="true"
    description="A self-hosted music player for the browser. LUFS and true-peak meters, file analysis that finds clipped and lossy files, themes as small JSON files and a library that works from the keyboard. Clients for the web, the terminal, Android and iOS."
    image="/screenshots/library.png">
    <section class="grid items-center gap-10 py-6 md:grid-cols-2 md:py-12">
        <div>
            <p class="mb-3 text-xs uppercase tracking-[0.3em] text-muted">Self-hosted music player</p>
            <h1 class="text-4xl font-semibold leading-tight md:text-5xl">A music player with <span class="text-accent">proper meters.</span></h1>
            <p class="mt-5 max-w-prose text-lg text-muted">
                Magnetite is a self-hosted music player that runs in the browser. Its meters show loudness (LUFS), true peak and phase.
                It analyses every file and flags the ones that are clipped, upsampled or from a lossy source. A theme is a small
                JSON file that can be pasted into a chat.
            </p>
            <div class="mt-8 flex flex-wrap gap-3 text-sm">
                <a href="{{ route('library') }}" class="rounded-theme bg-accent px-5 py-2 font-medium text-bg">Open the library</a>
                <a href="#themes" class="rounded-theme border border-white/10 px-5 py-2 hover:text-accent">Try a theme</a>
            </div>
            <p class="mt-6 text-xs text-muted">The player at the bottom keeps going when another page is opened. The demo tracks are further down.</p>
        </div>
        <img src="/logo.svg" alt="Magnetite: two wide stereo meters above the name" width="512" height="512"
             class="mx-auto w-full max-w-sm border border-white/10">
    </section>

    <section class="pb-10">
        <figure>
            <img src="/screenshots/library.png" alt="The library: folders and albums on the left, the tracks with their dynamic range on the right, and the player with two meters below" width="1600" height="1000"
                 class="w-full rounded-theme border border-white/10" fetchpriority="high">
            <figcaption class="mt-2 text-xs text-muted">The library in the Terminal theme: folders and albums on the left, tracks with their dynamic range on the right, the player with two meters below.</figcaption>
        </figure>
    </section>

    <section id="clients" class="scroll-mt-8 border-t border-white/10 py-10">
        <h2 class="text-2xl font-semibold">Clients</h2>
        <p class="mt-2 max-w-prose text-muted">The web app, a terminal client, an Android app and an iOS app all talk to the same server. They show the same library and the same list of meters.</p>
        <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Web', 'The library, the player and the theme studio in a browser. Everything has a key, a toolbar button, or both.'],
                ['Terminal', 'Written in Rust. It decodes the audio itself, so the meters read the actual samples. It has the same nine meters, the file-truth table, search with filters, loudness matching and A/B compare. Build it from tui/.'],
                ['Android', 'One app for phones, tablets and Android TV: lock-screen controls on the phone, D-pad navigation on the TV. Download it below.'],
                ['iOS', 'A SwiftUI app for iPhone and iPad with background playback and the same meters. Build it from ios/; it is not in the App Store.'],
            ] as [$client, $text])
                <div class="rounded-theme border border-white/10 bg-surface p-5">
                    <h3 class="font-semibold text-accent">{{ $client }}</h3>
                    <p class="mt-2 text-sm text-muted">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </section>

    @php($apkVersion = config('magnetite.apk_version'))
    @php($apk = public_path("downloads/magnetite-{$apkVersion}.apk"))
    @if (is_file($apk))
        <section id="android" class="scroll-mt-8 border-t border-white/10 py-10">
            <h2 class="text-2xl font-semibold">Android app <span class="ml-2 text-xs uppercase tracking-widest text-muted">beta</span></h2>
            <p class="mt-2 max-w-prose text-muted">
                Works on phones, tablets and Android TV: library, folders, playlists, search, nine meters and lock-screen controls.
                Sign in with the address of the server.
            </p>
            <div class="mt-5 flex flex-wrap items-center gap-4 text-sm">
                <a href="/downloads/magnetite-{{ $apkVersion }}.apk" download class="rounded-theme bg-accent px-5 py-2 font-medium text-bg">Download the APK</a>
                <span class="text-muted">Version {{ $apkVersion }} · {{ number_format(filesize($apk) / 1048576, 1) }} MB · Android 8 or newer</span>
            </div>
            <p class="mt-4 max-w-prose text-xs text-muted">
                Not on the Play Store: open the downloaded file and allow installs from the browser when Android asks.
                Play Protect may warn about an unknown developer; choose "Install anyway".
            </p>
            <p class="mt-2 break-all font-mono text-xs text-muted">SHA-256 {{ Cache::rememberForever('apk.sha256.'.filemtime($apk), fn () => hash_file('sha256', $apk)) }}</p>
        </section>
    @endif

    <section class="border-t border-white/10 py-10">
        <figure>
            <img src="/screenshots/truth.png" alt="The truth view: every file in one list, worst first, with its dynamic range and loudness, and what is wrong with the selected file on the right" width="1600" height="1000"
                 class="w-full rounded-theme border border-white/10" loading="lazy">
            <figcaption class="mt-2 max-w-prose text-xs text-muted">The truth view lists every file, worst first. Three of these are clipped, and nothing in their tags says so.</figcaption>
        </figure>
    </section>

    @if ($demo)
        <section id="demo" x-data="{ tracks: @js(array_map(fn ($t) => \App\Support\DemoTracks::toPlayer($t), $demo)) }" x-init="$store.player.preload(tracks)" class="scroll-mt-8 border-t border-white/10 py-10">
            <h2 class="text-2xl font-semibold">Demo tracks</h2>
            <p class="mt-2 max-w-prose text-muted">
                {{ count($demo) }} excerpts of free music (all CC0), from a quiet piano recording to a heavily limited master.
                Press play, then change the theme or the meters. The numbers on the right come from the file analysis.
            </p>
            <ol class="mt-6 divide-y divide-white/10 rounded-theme border border-white/10 bg-surface">
                @foreach ($demo as $i => $t)
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-1 p-3 text-sm sm:flex-nowrap">
                        <button type="button" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-theme border border-white/15 hover:border-accent hover:text-accent"
                                :class="$store.player.current?.id === '{{ $t['id'] }}' ? 'border-accent text-accent' : ''"
                                @click="$store.player.current?.id === '{{ $t['id'] }}' ? $store.player.toggle() : $store.player.setQueue([...tracks], {{ $i }})"
                                aria-label="Play {{ $t['title'] }}">
                            <span x-show="!($store.player.current?.id === '{{ $t['id'] }}' && $store.player.playing)">▶</span>
                            <span x-show="$store.player.current?.id === '{{ $t['id'] }}' && $store.player.playing" x-cloak>❚❚</span>
                        </button>
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-semibold">{{ $t['title'] }} <span class="font-normal text-muted">· {{ $t['artist'] }}</span></div>
                            <div class="truncate text-xs text-muted">{{ $t['note'] }}</div>
                        </div>
                        <div class="shrink-0 text-right text-xs text-muted">
                            <div><span class="text-text">DR{{ $t['dr'] }}</span> · {{ number_format($t['lufs'], 1) }} LUFS</div>
                            <div>
                                <a href="{{ $t['licence_url'] }}" class="underline hover:text-accent" rel="noopener">{{ $t['licence'] }}</a>
                                · <a href="{{ $t['source_url'] }}" class="underline hover:text-accent" rel="noopener">source</a>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
            <p class="mt-3 max-w-prose text-xs text-muted">
                Each excerpt is about a minute long, with a short fade. The two Goldberg tracks are from the Open Goldberg Variations by
                Kimiko Ishizaka; the others are from OpenGameArt. All are CC0, which needs no credit, but they are credited anyway.
            </p>
        </section>
    @endif

    <section id="themes" x-data class="scroll-mt-8 py-10">
        <h2 class="text-2xl font-semibold">Themes</h2>
        <p class="mt-2 max-w-prose text-muted">
            <span x-text="$store.player.themes.length"></span> themes come with it. Click one to change the colours, font, corners and meters at once. The choice is saved in this browser.
        </p>
        <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <template x-for="t in $store.player.themes" :key="t.id">
                <button type="button"
                        class="rounded-theme border p-4 text-left"
                        :class="$store.player.themeId === t.id ? 'border-accent' : 'border-white/10'"
                        :style="{ background: t.colors.surface, color: t.colors.text, fontFamily: t.font, borderRadius: t.radius }"
                        @click="$store.player.setTheme(t.id)">
                    <div class="mb-3 font-semibold" x-text="t.name"></div>
                    <div class="flex gap-1">
                        <template x-for="c in ['bg', 'accent', 'accent2', 'muted']" :key="c">
                            <span class="h-5 w-5 rounded-full border border-black/20" :style="{ background: t.colors[c] }"></span>
                        </template>
                    </div>
                    <div class="mt-3 text-xs" :style="{ color: t.colors.muted }"
                         x-text="$store.player.styles(t).map((v) => v.id).join(' + ')"></div>
                </button>
            </template>
        </div>
    </section>

    <section class="grid gap-10 py-10 md:grid-cols-2">
        <div>
            <h2 class="text-2xl font-semibold">A theme is a JSON file</h2>
            <p class="mt-2 max-w-prose text-muted">
                It holds the colours, the corner radius, the font and optional settings for each meter, such as the colour of a needle or
                where the red zone starts. It is small enough to paste into a chat. The theme studio edits a copy with a live meter
                preview, and exports or imports the JSON.
            </p>
        </div>
@verbatim
        <pre class="overflow-x-auto rounded-theme border border-white/10 bg-surface p-4 text-xs leading-relaxed text-muted"><code>{
  "id": "terminal",
  "name": "Terminal",
  "colors": {
    "bg": "#000000",
    "surface": "#07140a",
    "text": "#b6ffbd",
    "accent": "#39ff6a",
    "accent2": "#b6ff3a"
  },
  "radius": "0rem",
  "font": "ui-monospace, 'SF Mono', Menlo, monospace",
  "visualizers": [
    { "id": "vu-wide",
      "params": { "ink": "#39ff6a" } },
    { "id": "scope" }
  ]
}</code></pre>
@endverbatim
    </section>

    <section class="py-10">
        <h2 class="text-2xl font-semibold">What it does</h2>
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['Keyboard library', 'Folders and albums on the left, tracks on the right. A command line (press :), a table to edit titles and tags, search with filters, and a truth view that lists the worst files first. Every key also has a button for touch.'],
                ['Playback across pages', 'The player keeps running across page changes. The audio element and the audio graph are not recreated, so the music and the meters carry on.'],
                ['Loudness meters', 'Momentary, short-term and integrated LUFS, true peak, phase correlation and a goniometer, computed sample by sample in an AudioWorklet and checked against known test tones.'],
                ['Up to four meters', 'Put two wide VU meters next to a scope, or a third-octave analyser over a spectrogram. Each one draws in its own cell, in the player or in full screen.'],
                ['Meter styles', 'Needle dials, LED bars, a magic-eye tube, wide cassette-deck meters and more. Each is a small module that draws on one canvas.'],
                ['File analysis', 'Decodes every file once and reports what it finds: lossy source, upsampled, clipped, heavily compressed, true peak over 0 dBTP, duplicates. Dynamic range for each track and album.'],
                ['How files are served', 'Files are served with range support and strict headers, and ALAC is converted to FLAC on first play and cached. Uploads accept only audio and cover images, never overwrite a file and refuse unsafe paths in a zip.'],
                ['One library per person', 'Each person signs in to their own library. Albums can be sorted into folders, edited (title, artist, year, label), given a missing cover from the web, or deleted.'],
                ['Untagged albums', 'For folders with no tags it suggests names from the folder and file names, from MusicBrainz and, optionally, from an audio fingerprint. The proposal is shown before anything changes.'],
                ['Playlists and sharing', 'Playlists are private and can be reordered by dragging. An album, a track or a playlist can be shared with someone else, who can play what was shared and nothing more.'],
                ['Resume after a reload', 'The queue, the track and the position come back after a reload, paused. Space, the arrow keys, the number keys, m for meters, f for full screen and t for the theme work on any page.'],
                ['Pictures as meters', 'Upload a picture and the music moves it: the kick pulses one layer, the frequency bands drive others, and the hotspots can be marked by hand.'],
            ] as [$title, $text])
                <div class="rounded-theme border border-white/10 bg-surface p-5">
                    <h3 class="font-semibold text-accent">{{ $title }}</h3>
                    <p class="mt-2 text-sm text-muted">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <section class="py-10">
        <h2 class="text-2xl font-semibold">The meters</h2>
        <div class="mt-4 flex flex-wrap gap-2 text-xs">
            @foreach (['Scope', 'Spectrogram', 'Third-octave', 'VU needle', 'VU LEDs', 'Wide meters', 'Magic eye', 'Goniometer', 'Loudness'] as $meter)
                <span class="rounded-full border border-white/10 px-3 py-1 text-muted">{{ $meter }}</span>
            @endforeach
        </div>
    </section>
</x-layouts.app>
