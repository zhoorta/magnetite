<x-layouts.app title="Themes · Magnetite">
    <h1 class="mb-6 text-2xl font-semibold">Themes</h1>

    <div x-data class="grid gap-4 sm:grid-cols-2">
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
                <div class="mt-3 text-xs" :style="{ color: t.colors.muted }" x-text="'visualizer: ' + t.visualizer.id"></div>
            </button>
        </template>
    </div>

    <p class="mt-6 text-sm text-muted">
        A theme is one JSON object: palette, radius, font and a default visualizer with its parameters.
    </p>
</x-layouts.app>
