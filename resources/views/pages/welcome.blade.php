<x-layouts.app title="Magnetite · a self-hosted music player" :wide="true"
    description="A self-hosted music player. LUFS and true-peak meters, file analysis that finds clipped and lossy files, themes stored as JSON and a keyboard-driven library. Clients for the web, the terminal, Android and iOS."
    image="/screenshots/library.png">
    <section class="grid items-center gap-10 py-6 md:grid-cols-2 md:py-12">
        <div>
            <p class="mb-3 text-xs uppercase tracking-[0.3em] text-muted">Self-hosted music player</p>
            <h1 class="text-4xl font-semibold leading-tight md:text-5xl">A music player with <span class="text-accent">loudness meters and file analysis.</span></h1>
            <p class="mt-5 max-w-prose text-lg text-muted">
                Magnetite is a self-hosted player for your own music collection. Its meters show loudness (LUFS), true peak and phase.
                It analyses your files and flags the ones that are clipped, probably upsampled or from a lossy source.
            </p>
            <div class="mt-8 flex flex-wrap gap-3 text-sm">
                <a href="{{ route('library') }}" class="rounded-theme bg-accent px-5 py-2 font-medium text-bg">Open the library</a>
                <a href="#themes" class="rounded-theme border border-white/10 px-5 py-2 hover:text-accent">Try a theme</a>
                <a href="{{ route('guide') }}" wire:navigate class="rounded-theme border border-white/10 px-5 py-2 hover:text-accent">Documentation</a>
            </div>
        </div>
        <img src="/logo.svg" alt="Magnetite: two wide stereo meters above the name" width="512" height="512"
             class="mx-auto w-full max-w-sm border border-white/10">
    </section>

    <section class="pb-10">
        <a href="{{ route('library') }}" class="block">
            <img src="/screenshots/library-hero.png" alt="The library in the Terminal theme: a folder tree with the dynamic range of each folder, the tracks of an album with their dynamic range, and the player with four meters below" width="1800" height="1252"
                 class="w-full rounded-theme border border-white/10" fetchpriority="high">
        </a>
    </section>

    @if ($demo)
        <section id="demo" x-data="{ tracks: @js(array_map(fn ($t) => \App\Support\DemoTracks::toPlayer($t, $demoAlbums[$t['id']] ?? null), $demo)) }" x-init="$store.player.preload(tracks)" class="scroll-mt-8 border-t border-white/10 py-10">
            <h2 class="text-2xl font-semibold">Demo tracks</h2>
            <p class="mt-2 max-w-prose text-muted">
                {{ count($demo) }} excerpts of free music (all CC0), from a quiet piano recording with wide dynamics to a heavily limited master.
                Change the theme and the meters while a track plays, and compare the numbers on the right: they come from the file analysis.
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
                Kimiko Ishizaka; the others are from OpenGameArt. All are CC0. The source of each is linked in the list.
            </p>
        </section>
    @endif

    {{-- The same pictures as the meter menu of the player: drawn in the browser with the theme's colours, redrawn when the theme changes. A click shows or hides the meter. --}}
    <section id="meters" x-data x-effect="$store.player.loadThumbs()" class="scroll-mt-8 border-t border-white/10 py-10">
        <h2 class="text-2xl font-semibold">Meters and posters</h2>
        <p class="mt-2 max-w-prose text-muted">
            Every meter is drawn from the audio that is playing, frame by frame. A demo track is loaded in the player at the bottom: press play,
            then click a picture here to show that meter, and again to hide it. Up to <span x-text="$store.player.maxPanels"></span> meters can show at once, next to the player or in full screen (F).
        </p>
        <h3 class="mt-6 text-xs uppercase tracking-widest text-muted">Meters · <span x-text="$store.player.visualizerList.filter((v) => ! v.group).length"></span></h3>
        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <template x-for="v in $store.player.visualizerList.filter((v) => ! v.group)" :key="v.id">
                <button type="button" :aria-pressed="$store.player.hasMeter(v.id)" :title="v.label"
                        :disabled="! $store.player.hasMeter(v.id) && $store.player.meterLimitReached"
                        class="flex min-w-0 flex-col gap-1 text-left disabled:opacity-40"
                        @click="$store.player.toggleMeter(v.id)">
                    <span class="relative block aspect-[7/4] overflow-hidden rounded-theme border bg-surface" :class="$store.player.hasMeter(v.id) ? 'border-accent' : 'border-white/10'">
                        <img :src="$store.player.thumbOf(v.id) || 'data:,'" alt="" class="h-full w-full object-cover" x-show="$store.player.thumbOf(v.id)">
                        <span x-show="$store.player.hasMeter(v.id)" x-cloak class="absolute right-1 top-1 rounded-full bg-accent px-1 text-[10px] leading-4 text-black" aria-hidden="true">✓</span>
                    </span>
                    <span class="truncate text-xs" :class="$store.player.hasMeter(v.id) ? 'text-accent' : 'text-muted'" x-text="v.label"></span>
                </button>
            </template>
        </div>
        <p class="mt-3 max-w-prose text-xs text-muted">
            The needle, LED and magic-eye meters follow the level of the left and right channels. The scope draws the waveform, the third-octave
            analyser and the spectrogram show the frequencies, the goniometer shows the stereo image, and the loudness meter reads LUFS and true peak.
        </p>
        <template x-for="g in $store.player.visualizerGroups" :key="g.name">
            <div>
                <h3 class="mt-8 text-xs uppercase tracking-widest text-muted"><span x-text="g.name"></span> · <span x-text="g.items.length"></span></h3>
                <div class="mt-3 grid grid-cols-3 gap-3 sm:grid-cols-5 lg:grid-cols-8">
                    <template x-for="v in g.items" :key="v.id">
                        <button type="button" :aria-pressed="$store.player.hasMeter(v.id)" :title="v.label"
                                :disabled="! $store.player.hasMeter(v.id) && $store.player.meterLimitReached"
                                class="flex min-w-0 flex-col gap-1 text-left disabled:opacity-40"
                                @click="$store.player.toggleMeter(v.id)">
                            <span class="relative block aspect-[3/4] overflow-hidden rounded-theme border bg-surface" :class="$store.player.hasMeter(v.id) ? 'border-accent' : 'border-white/10'">
                                <img :src="$store.player.thumbOf(v.id) || 'data:,'" alt="" class="h-full w-full object-cover" x-show="$store.player.thumbOf(v.id)">
                                <span x-show="$store.player.hasMeter(v.id)" x-cloak class="absolute right-1 top-1 rounded-full bg-accent px-1 text-[10px] leading-4 text-black" aria-hidden="true">✓</span>
                            </span>
                            <span class="truncate text-xs" :class="$store.player.hasMeter(v.id) ? 'text-accent' : 'text-muted'" x-text="v.short"></span>
                        </button>
                    </template>
                </div>
                <p x-show="g.name === 'Posters'" class="mt-3 max-w-prose text-xs text-muted">
                    Posters are drawn like printed posters, with the artist, title and album of the playing track on them. Their shapes move with
                    the music: in Arches, Fan and Pillars each shape is a frequency range that grows with its energy; in Lens the two discs are the
                    two channels, and they overlap more as the channels become more alike; Halftone and Facets scroll like a spectrogram.
                </p>
            </div>
        </template>

        <h3 class="mt-8 text-xs uppercase tracking-widest text-muted">Full screen</h3>
        <p class="mt-2 max-w-prose text-xs text-muted">
            F gives the meters the whole window. Up to four panels share it, and the controls hide after three seconds. Posters are upright, so two sit side by side.
        </p>
        <div x-data="{ still: window.matchMedia('(prefers-reduced-motion: reduce)').matches }" class="mt-3 grid gap-3 sm:grid-cols-2">
            <video class="w-full rounded-theme border border-white/10 sm:col-span-2" width="1280" height="770" poster="/media/fullscreen-meters.jpg"
                   muted loop playsinline preload="none" aria-label="Full-screen meters: wide meters, magic eyes, crest factor and loudness range, moving with the music"
                   x-init="still || new IntersectionObserver(([e]) => e.isIntersecting ? $el.play() : $el.pause(), { threshold: 0.3 }).observe($el)">
                <source src="/media/fullscreen-meters.mp4" type="video/mp4">
            </video>
            <video class="w-full rounded-theme border border-white/10" width="640" height="732" poster="/media/fullscreen-circuit.jpg"
                   muted loop playsinline preload="none" aria-label="The Circuit poster in full screen: a schematic whose straps fill with the frequency ranges"
                   x-init="still || new IntersectionObserver(([e]) => e.isIntersecting ? $el.play() : $el.pause(), { threshold: 0.3 }).observe($el)">
                <source src="/media/fullscreen-circuit.mp4" type="video/mp4">
            </video>
            <video class="w-full rounded-theme border border-white/10" width="640" height="732" poster="/media/fullscreen-overcome.jpg"
                   muted loop playsinline preload="none" aria-label="The Overcome poster in full screen: one line per third-octave band and two circles that follow the left and right peaks"
                   x-init="still || new IntersectionObserver(([e]) => e.isIntersecting ? $el.play() : $el.pause(), { threshold: 0.3 }).observe($el)">
                <source src="/media/fullscreen-overcome.mp4" type="video/mp4">
            </video>
        </div>
    </section>

    <section id="analysis" class="scroll-mt-8 border-t border-white/10 py-10">
        <h2 class="text-2xl font-semibold">File analysis</h2>
        <p class="mt-2 max-w-prose text-muted">
            The analysis looks at the audio itself, not only the tags and the file format. Each file is decoded once and measured: dynamic range, loudness, true peak,
            clipped peaks and where the spectrum ends. From these it flags probable upsampling, probable lossy sources and other problems.
            The truth view lists all files, worst first, and the findings of the selected file are on the right.
            The analysis runs on the server as its own step, after a scan, and never changes the files.
        </p>
        <figure class="mt-6">
            <img src="/screenshots/truth-crop.png" alt="The truth view: every file in one list, worst first, with its dynamic range and loudness, and what is wrong with the selected file on the right" width="1268" height="342"
                 class="w-full rounded-theme border border-white/10" loading="lazy">
        </figure>
    </section>

    <section id="themes" x-data class="scroll-mt-8 py-10">
        <h2 class="text-2xl font-semibold">Themes</h2>
        <p class="mt-2 max-w-prose text-muted">
            <span x-text="$store.player.themes.length"></span> themes are included. Click one to change the colours, the fonts and the corners of the whole page, the meters included. The choice is saved in this browser.
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
                </button>
            </template>
        </div>
    </section>

    <section class="grid gap-10 py-10 md:grid-cols-2">
        <div>
            <h2 class="text-2xl font-semibold">A theme is a JSON file</h2>
            <p class="mt-2 max-w-prose text-muted">
                It holds six colours, the corner radius, the font and, optionally, another font for the text on the meters. It is small
                enough to paste into a chat. The theme studio edits a copy with a live meter preview, and exports or imports the JSON.
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
    "muted": "#4f8a57",
    "accent": "#39ff6a",
    "accent2": "#b6ff3a"
  },
  "radius": "0rem",
  "font": "ui-monospace, 'SF Mono', Menlo, monospace"
}</code></pre>
@endverbatim
    </section>

    <section class="py-10">
        <h2 class="text-2xl font-semibold">Library and playback</h2>
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['Two-pane library', 'Folders, albums and tracks in two panes, with a command line, an edit table and search with filters.'],
                ['One library per person', 'Each account has its own library, folders and covers.'],
                ['Untagged albums', 'Suggests names from the folder and file names, MusicBrainz and, with an AcoustID key, audio fingerprints. Only fingerprints are sent, never audio.'],
                ['Playlists and sharing', 'Private playlists. Albums, tracks and playlists can be shared, play only.'],
                ['Playback keeps going', 'Music goes on while moving between pages. After a reload the queue and position are restored, paused.'],
                ['Storage in Ferrite', 'A library can live in Ferrite, a separate self-hosted file server, read through its API with a token limited to one folder.'],
            ] as [$title, $text])
                <div class="rounded-theme border border-white/10 bg-surface p-5">
                    <h3 class="font-semibold text-accent">{{ $title }}</h3>
                    <p class="mt-2 text-sm text-muted">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <section id="clients" class="scroll-mt-8 border-t border-white/10 py-10">
        <h2 class="text-2xl font-semibold">Clients</h2>
        <p class="mt-2 max-w-prose text-muted">The web app, a terminal client, an Android app and an iOS app all talk to the same server. They show the same library and the same list of meters; the posters are in the web app, and the terminal client draws most of them.</p>
        <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Web', 'The library, the player and the theme studio in a browser. Everything has a key, a toolbar button, or both.'],
                ['Terminal', 'Written in Rust. It decodes the audio itself, so the meters are computed from the decoded samples. It has the twelve plain meters, the file-truth table, search with filters, loudness matching and A/B compare. Build it from tui/.'],
                ['Android', 'One app for phones, tablets and Android TV, with lock-screen controls and the twelve plain meters. A beta; the APK is below.'],
                ['iOS', 'A SwiftUI app for iPhone and iPad with background playback and nine of the twelve plain meters. Build it from ios/; it is not in the App Store.'],
            ] as [$client, $text])
                <div class="rounded-theme border border-white/10 bg-surface p-5">
                    <h3 class="font-semibold text-accent">{{ $client }}</h3>
                    <p class="mt-2 text-sm text-muted">{{ $text }}</p>
                </div>
            @endforeach
        </div>

        @php($apkVersion = config('magnetite.apk_version'))
        @php($apk = public_path("downloads/magnetite-{$apkVersion}.apk"))
        @if (is_file($apk))
            <div id="android" class="mt-8 scroll-mt-8">
                <h3 class="text-lg font-semibold">Download for Android</h3>
                <p class="mt-2 max-w-prose text-muted">
                    Works on phones, tablets and Android TV: library, folders, playlists, search, meters and lock-screen controls.
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
            </div>
        @endif
    </section>
    <section id="install" class="scroll-mt-8 border-t border-white/10 py-10">
        <h2 class="text-2xl font-semibold">Install and documentation</h2>
        <p class="mt-2 max-w-prose text-muted">
            Magnetite runs in one Docker container, which is the easiest way to start, or directly with PHP 8.3 and Node. With Docker:
        </p>
        <pre class="mt-4 overflow-x-auto rounded-theme border border-white/10 bg-surface p-4 text-xs leading-relaxed text-muted"><code>docker run -d --name magnetite -p 8080:8080 \
  -v magnetite-data:/data -v magnetite-music:/music \
  ghcr.io/zhoorta/magnetite</code></pre>
        <p class="mt-4 max-w-prose text-sm text-muted">
            Open http://localhost:8080 and create your user in the browser. The two volumes keep the database and the music, so an update does not lose them. To use a folder that already has music, replace <code>magnetite-music</code> with its path. The image is built for amd64 and arm64.
            The <a href="{{ route('guide', 'install') }}" wire:navigate class="text-accent underline">install page</a> has the details
            (a chosen password, a reverse proxy, updating), and the
            <a href="{{ route('guide') }}" wire:navigate class="text-accent underline">documentation</a> covers the library, the meters, the file analysis, themes, sharing and the clients.
        </p>
        <p class="mt-4 max-w-prose text-sm text-muted">
            The source is on <a href="https://github.com/zhoorta/magnetite" class="text-accent underline" rel="noopener">GitHub</a> (AGPL-3.0-or-later).
            The terminal client for macOS and Linux and the signed Android APK are on the
            <a href="https://github.com/zhoorta/magnetite/releases" class="text-accent underline" rel="noopener">releases page</a>.
        </p>
    </section>
</x-layouts.app>
