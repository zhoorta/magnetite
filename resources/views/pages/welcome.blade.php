<x-layouts.app title="Magnetite · the look is the product" :wide="true"
    description="A music player for your own server, where the look is the product: themes in one small file, meters that measure properly (LUFS, true peak, correlation), file truth, and a keyboard-first library. Web, terminal, Android and iOS."
    image="/screenshots/library.png">
    <section class="grid items-center gap-10 py-6 md:grid-cols-2 md:py-12">
        <div>
            <p class="mb-3 text-xs uppercase tracking-[0.3em] text-muted">Music player for the web</p>
            <h1 class="text-4xl font-semibold leading-tight md:text-5xl">The look is <span class="text-accent">the product.</span></h1>
            <p class="mt-5 max-w-prose text-lg text-muted">
                A player for your own music server. It keeps playing while you move around, its meters measure properly,
                it tells you what your files really are, and a theme is one small file: palette, font, corners and a look for each meter.
            </p>
            <div class="mt-8 flex flex-wrap gap-3 text-sm">
                <a href="{{ route('library') }}" class="rounded-theme bg-accent px-5 py-2 font-medium text-bg">Open the library</a>
                <a href="#themes" class="rounded-theme border border-white/10 px-5 py-2 hover:text-accent">Try a theme</a>
            </div>
            <p class="mt-6 text-xs text-muted">Press play on anything: the meters in the dock below are the real ones.</p>
        </div>
        <img src="/logo.svg" alt="Magnetite: two wide stereo meters above the name" width="512" height="512"
             class="mx-auto w-full max-w-sm border border-white/10">
    </section>

    <section class="pb-10">
        <figure>
            <img src="/screenshots/library.png" alt="The library: folders and albums on the left, the tracks with their dynamic range on the right, and the player with two meters below" width="1600" height="1000"
                 class="w-full rounded-theme border border-white/10" fetchpriority="high">
            <figcaption class="mt-2 text-xs text-muted">The library, with the Terminal theme: two panes, a keyboard for everything, the dynamic range of every file, and the player with its meters.</figcaption>
        </figure>
    </section>

    <section id="clients" class="scroll-mt-8 border-t border-white/10 py-10">
        <h2 class="text-2xl font-semibold">One server, four ways to listen</h2>
        <p class="mt-2 max-w-prose text-muted">Every client signs in to your own Magnetite server, sees the same library and shares the list of meters you chose.</p>
        <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Web', 'The library, the player and the theme studio in any browser. Keyboard first, with a toolbar and touch for everything else.'],
                ['Terminal', 'A Rust client with native decoding, all nine meters, the file-truth table, search with filters, loudness matching and A/B compare. Built from the source in tui/.'],
                ['Android', 'Phone, tablet and TV in one app, with lock-screen controls and a D-pad layout. Download it below.'],
                ['iOS', 'A SwiftUI app for iPhone and iPad with background playback and all the meters. Built from the source in ios/; no store release yet.'],
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
                A native app for your own Magnetite server, on phone, tablet and Android TV: library, folders, playlists, search,
                nine meters and playback with lock-screen controls. Enter your server's address when you sign in.
            </p>
            <div class="mt-5 flex flex-wrap items-center gap-4 text-sm">
                <a href="/downloads/magnetite-{{ $apkVersion }}.apk" download class="rounded-theme bg-accent px-5 py-2 font-medium text-bg">Download the APK</a>
                <span class="text-muted">Version {{ $apkVersion }} · {{ number_format(filesize($apk) / 1048576, 1) }} MB · Android 8 or newer</span>
            </div>
            <p class="mt-4 max-w-prose text-xs text-muted">
                Not on the Play Store: open the file after downloading and allow installs from your browser when Android asks.
                Play Protect may warn about an unknown developer; choose "Install anyway".
            </p>
            <p class="mt-2 break-all font-mono text-xs text-muted">SHA-256 {{ Cache::rememberForever('apk.sha256.'.filemtime($apk), fn () => hash_file('sha256', $apk)) }}</p>
        </section>
    @endif

    <section class="border-t border-white/10 py-10">
        <figure>
            <img src="/screenshots/truth.png" alt="The truth view: every file in one list, worst first, with its dynamic range and loudness, and what is wrong with the selected file on the right" width="1600" height="1000"
                 class="w-full rounded-theme border border-white/10" loading="lazy">
            <figcaption class="mt-2 max-w-prose text-xs text-muted">File truth: every file decoded once, the worst first. Here three files are clipped and flat-topped, which no tag would tell you.</figcaption>
        </figure>
    </section>

    @if ($demo)
        <section id="demo" x-data="{ tracks: @js(array_map(fn ($t) => \App\Support\DemoTracks::toPlayer($t), $demo)) }" x-init="$store.player.preload(tracks)" class="scroll-mt-8 border-t border-white/10 py-10">
            <h2 class="text-2xl font-semibold">Try it with free music</h2>
            <p class="mt-2 max-w-prose text-muted">
                {{ count($demo) }} short excerpts of music that anyone may use (all CC0), from very quiet piano to a heavily limited master.
                Press play, then change the theme or the meters and watch the dock: the numbers on the right are what the file truth measured.
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
                Excerpts of {{ count($demo) }} works, cut to about a minute with a short fade. The Goldberg Variations are from the Open Goldberg
                Variations (Kimiko Ishizaka), the others from OpenGameArt; each is released under CC0, no credit is needed and we credit it anyway.
            </p>
        </section>
    @endif

    <section id="themes" x-data class="scroll-mt-8 py-10">
        <h2 class="text-2xl font-semibold">Pick a look, the whole page follows</h2>
        <p class="mt-2 max-w-prose text-muted">
            <span x-text="$store.player.themes.length"></span> themes ship with it. Click one and the colours, the font, the
            corners and the meters in the dock change at once. It is remembered on this browser.
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
            <h2 class="text-2xl font-semibold">A theme is one file</h2>
            <p class="mt-2 max-w-prose text-muted">
                Colours, radius, font and a look for each meter, down to the colour of a needle or a red zone. Small enough
                to paste in a chat, so a look can be shared without sharing anything else. The theme studio edits a copy
                with a live meter preview and exports or imports the JSON.
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
        <h2 class="text-2xl font-semibold">What is inside</h2>
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['A library for the keyboard', 'Two panes, folders and albums on the left and tracks on the right, a command line (press :), an edit table, search with filters and a truth view that lists the worst files first. Every key has a button for touch.'],
                ['Never stops', 'The player lives in a persistent block. Moving between pages does not recreate the audio element or the audio graph, so the music and the meters carry on.'],
                ['Meters that measure', 'Momentary, short-term and integrated LUFS, true peak, phase correlation and a goniometer, computed sample by sample in an AudioWorklet and checked against known test tones.'],
                ['Up to four at once', 'Show a pair of wide VU meters next to a scope, or a third-octave analyser above a spectrogram. Each one draws in its own cell, in the dock or in full screen.'],
                ['Vintage and instrument', 'Needle dials, LED meters, a magic eye tube, wide cassette-deck meters. Each is a small module that draws on one canvas.'],
                ['File truth', 'Decodes every file once and says what it really is: likely lossy source, upsampled, clipped, very compressed, over 0 dBTP, duplicates. Dynamic range per track and album.'],
                ['Your files, your rules', 'Files stream with range support and strict headers, and ALAC is converted to FLAC on first play and cached. Uploads accept only audio and covers, never overwrite anything and refuse unsafe paths.'],
                ['Your own library', 'Every person signs in to a library of their own. Tidy it with folders, edit titles, artist, year and label, add a missing cover from the web, or delete what you no longer want.'],
                ['Names for untagged albums', 'Folders without tags get suggestions from the folder and file names, from MusicBrainz and, if you want, from an audio fingerprint. You see the proposal before anything changes.'],
                ['Playlists and sharing', 'Private playlists with drag-and-drop ordering. Share an album, a track or a playlist with another person: they play what they are allowed to, nothing else.'],
                ['Picks up where you left off', 'The queue, the track and the position come back after a reload, paused. Space, arrows, number keys, m for meters, f for full screen and t for the theme work from any page.'],
                ['Pictures as meters', 'Upload a picture and let the music drive it: the kick pulses one layer, the bands move others, with hotspots you place yourself.'],
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

    <footer class="mt-8 border-t border-white/10 pt-6 text-xs text-muted">
        A personal project, made for fun by the maker of Ferrite. Free software under the
        <a href="https://www.gnu.org/licenses/agpl-3.0.html" class="underline hover:text-accent">GNU AGPL v3 or later</a>: if you run a
        modified version as a service, you offer its source to the people who use it. Not a hosted service: it plays the files on your own
        server. The Android app is a beta; there is no public release of the server yet.
    </footer>
</x-layouts.app>
