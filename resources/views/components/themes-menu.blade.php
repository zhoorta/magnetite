{{-- Quick theme switch for the top bar: a palette button and a list of the themes with their colours; the page has the rest. --}}
<x-menu label="Theme" width="w-60">
    <x-slot:trigger>
        <button type="button" x-ref="trigger" @click="toggle()" :aria-expanded="open" aria-haspopup="menu" aria-label="Theme" title="Theme (t and T step through them)"
                class="inline-flex items-center text-muted hover:text-accent focus-visible:text-accent"><x-icon name="palette" /></button>
    </x-slot:trigger>
    <template x-for="t in $store.player.themes" :key="t.id">
        <x-menu.item class="px-3! py-1.5! rounded-none! hover:bg-white/5!" @click="$store.player.setTheme(t.id)" ::aria-current="$store.player.themeId === t.id">
            <span class="flex shrink-0 gap-0.5" aria-hidden="true">
                <template x-for="c in ['bg', 'accent', 'accent2', 'muted']" :key="c">
                    <span class="size-3 rounded-full border border-white/25" :style="{ background: t.colors[c] }"></span>
                </template>
            </span>
            <span class="min-w-0 flex-1 truncate" x-text="t.name"></span>
            <span class="text-accent" x-text="$store.player.themeId === t.id ? '✓' : ''"></span>
        </x-menu.item>
    </template>
    <hr class="my-1 border-white/10">
    <a href="{{ route('themes') }}" wire:navigate role="menuitem" tabindex="-1" class="block px-3! py-1.5! rounded-none! text-muted hover:bg-white/5! hover:text-accent focus-visible:bg-white/5 focus-visible:outline-none">Preview, edit, import…</a>
</x-menu>
