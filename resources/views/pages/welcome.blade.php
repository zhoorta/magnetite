<x-layouts.app title="Magnetite · the look is the product" :wide="true">
    <section class="grid items-center gap-10 py-6 md:grid-cols-2 md:py-12">
        <div>
            <p class="mb-3 text-xs uppercase tracking-[0.3em] text-muted">Music player for the web</p>
            <h1 class="text-4xl font-semibold leading-tight md:text-5xl">The look is <span class="text-accent">the product.</span></h1>
            <p class="mt-5 max-w-prose text-lg text-muted">
                A player that keeps playing while you move around, with live meters that measure properly, and themes
                that are one small file: palette, font and corners, and a look for each meter.
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

    @php($apk = public_path('downloads/magnetite-0.3.0.apk'))
    @if (is_file($apk))
        <section id="android" class="scroll-mt-8 border-t border-white/10 py-10">
            <h2 class="text-2xl font-semibold">Android app <span class="ml-2 text-xs uppercase tracking-widest text-muted">beta</span></h2>
            <p class="mt-2 max-w-prose text-muted">
                A native app for your own Magnetite server: library, folders, playback with lock-screen controls.
                Enter your server's address when you sign in.
            </p>
            <div class="mt-5 flex flex-wrap items-center gap-4 text-sm">
                <a href="/downloads/magnetite-0.3.0.apk" download class="rounded-theme bg-accent px-5 py-2 font-medium text-bg">Download the APK</a>
                <span class="text-muted">Version 0.3.0 · {{ number_format(filesize($apk) / 1048576, 1) }} MB · Android 8 or newer</span>
            </div>
            <p class="mt-4 max-w-prose text-xs text-muted">
                Not on the Play Store: open the file after downloading and allow installs from your browser when Android asks.
                Play Protect may warn about an unknown developer; choose "Install anyway".
            </p>
            <p class="mt-2 break-all font-mono text-xs text-muted">SHA-256 {{ Cache::rememberForever('apk.sha256.'.filemtime($apk), fn () => hash_file('sha256', $apk)) }}</p>
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
                Colours, radius, font and a default set of meters with their parameters. Small enough to paste in a chat,
                so a look can be shared without sharing anything else.
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
                ['Never stops', 'The player lives in a persistent block. Moving between pages does not recreate the audio element or the audio graph, so the music and the meters carry on.'],
                ['Meters that measure', 'Momentary, short-term and integrated LUFS, true peak, phase correlation and a goniometer, computed sample by sample in an AudioWorklet and checked against known test tones.'],
                ['Up to four at once', 'Show a pair of wide VU meters next to a scope, or a third-octave analyser above a spectrogram. Each one draws in its own cell, in the dock or in full screen.'],
                ['Vintage and instrument', 'Needle dials, LED meters, a magic eye tube, wide cassette-deck meters. Each is a small module that draws on one canvas.'],
                ['File truth', 'Decodes every file once and says what it really is: likely lossy source, upsampled, clipped, very compressed, over 0 dBTP, duplicates. Dynamic range per track and album.'],
                ['Your files, your rules', 'Files stream with range support and strict headers, and ALAC is converted to FLAC on first play and cached. Uploads accept only audio and covers, never overwrite anything and refuse unsafe paths.'],
                ['Your own library', 'Every person signs in to a library of their own. Tidy it with folders, edit titles, artist, year and label, add a missing cover from the web, or delete what you no longer want.'],
                ['Names for untagged albums', 'Folders without tags get suggestions from the folder and file names, from MusicBrainz and, if you want, from an audio fingerprint. You see the proposal before anything changes.'],
                ['Playlists and sharing', 'Private playlists with drag-and-drop ordering. Share an album, a track or a playlist with another person: they play what they are allowed to, nothing else.'],
                ['Picks up where you left off', 'The queue, the track and the position come back after a reload, paused. Keyboard shortcuts for everything: space, arrows, number keys, N and P, M for meters, F for full screen.'],
                ['An app for your phone', 'A native Android app with the same library, folders and playback with lock-screen controls. Download it above and point it at your own server.'],
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
        A personal project, made for fun by the maker of Ferrite. Not a hosted service: it plays the files on your own
        server. The Android app is a beta; the server code has no public release yet.
    </footer>
</x-layouts.app>
