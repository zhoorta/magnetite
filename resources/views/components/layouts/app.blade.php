<!DOCTYPE html>
<html lang="en">
<head>
    <x-layouts.head :title="$title ?? 'Magnetite'" />
</head>
<body class="min-h-screen bg-bg text-text antialiased">
    <header class="border-b border-white/10">
        <nav class="mx-auto flex {{ ($wide ?? false) ? 'max-w-5xl' : 'max-w-3xl' }} items-center gap-6 px-4 py-4 text-sm">
            <a href="{{ route('welcome') }}" wire:navigate class="font-semibold tracking-wide text-accent">Magnetite</a>
            <a href="{{ route('library') }}" wire:navigate class="hover:text-accent">Library</a>
            <a href="{{ route('themes') }}" wire:navigate class="hover:text-accent">Themes</a>
            <a href="{{ route('about') }}" wire:navigate class="hover:text-accent">About</a>
            @auth
                <a href="{{ route('upload') }}" wire:navigate class="hover:text-accent">Upload</a>
                <form method="POST" action="{{ route('logout') }}" class="ml-auto">
                    @csrf
                    <button type="submit" class="text-muted hover:text-accent">Sign out</button>
                </form>
            @endauth
        </nav>
    </header>

    <main class="mx-auto {{ ($wide ?? false) ? 'max-w-5xl' : 'max-w-3xl' }} px-4 pb-72 pt-8">
        {{ $slot }}
    </main>

    {{-- Survives wire:navigate: the <audio> element and its Web Audio graph are never recreated. --}}
    @persist('player')
        <div x-data
             x-init="$store.player.mount($refs.audio, $refs.canvas)"
             :class="$store.player.expanded ? 'inset-0 bg-bg' : 'inset-x-0 bottom-0 border-t border-white/10 bg-surface'"
             class="fixed z-10 flex flex-col">
            <audio x-ref="audio" preload="metadata"></audio>

            <canvas x-ref="canvas"
                    :class="$store.player.expanded ? 'flex-1' : 'h-28'"
                    class="w-full"></canvas>

            <div class="mx-auto flex w-full max-w-3xl flex-col gap-3 px-4 py-3">
                <div class="flex items-center justify-between text-sm">
                    <div class="flex min-w-0 items-center gap-3">
                        <img x-show="$store.player.current?.cover" :src="$store.player.current?.cover" alt=""
                             class="h-10 w-10 shrink-0 rounded-theme object-cover">
                        <div class="min-w-0 truncate">
                            <span x-text="$store.player.current?.title ?? 'Nothing playing'" class="font-medium"></span>
                            <span x-show="$store.player.current?.artist" x-text="' · ' + $store.player.current?.artist" class="text-muted"></span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <template x-for="(panel, i) in $store.player.panels" :key="i">
                            <span class="flex items-center">
                                <select class="rounded-theme border border-white/10 bg-bg px-2 py-1 text-xs"
                                        :value="panel.id"
                                        @change="$store.player.setPanel(i, $event.target.value)">
                                    <template x-for="v in $store.player.optionsFor(i)" :key="v.id">
                                        <option :value="v.id" x-text="v.label" :selected="v.id === panel.id"></option>
                                    </template>
                                </select>
                                <button type="button" x-show="$store.player.panels.length > 1" title="Remove this meter"
                                        class="px-1 text-xs text-muted hover:text-accent"
                                        @click="$store.player.removePanel(i)">&times;</button>
                            </span>
                        </template>
                        <button type="button" x-show="$store.player.canAddPanel" title="Show another meter"
                                class="rounded-theme border border-white/10 px-2 py-1 text-xs hover:text-accent"
                                @click="$store.player.addPanel()">+</button>
                        <button type="button" class="rounded-theme border border-white/10 px-2 py-1 text-xs hover:text-accent"
                                @click="$store.player.expanded = ! $store.player.expanded"
                                x-text="$store.player.expanded ? 'Close' : 'Full screen'"></button>
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
