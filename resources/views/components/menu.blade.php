{{-- A "more actions" popover: a trigger and a list of <x-menu.item>s. Esc closes it and returns focus, ↑ ↓ move, a click outside closes.
     `up` opens it above the trigger (for the player bar). `trigger` replaces the default ⋯ button; it gets x-ref="trigger" and the toggle. --}}
@props(['label' => 'More actions', 'up' => false, 'width' => 'w-44'])
<div x-data="{
         open: false,
         items() { return [...this.$refs.panel.querySelectorAll('[role=menuitem]:not([disabled]), [role=menuitemcheckbox]:not([disabled])')].filter((el) => el.offsetParent !== null); },
         toggle() { this.open = ! this.open; if (this.open) this.$nextTick(() => this.items()[0]?.focus()); },
         close(back = false) { this.open = false; if (back) this.$refs.trigger.focus(); },
         step(d) { const items = this.items(); if (items.length) items[(items.indexOf(document.activeElement) + d + items.length) % items.length].focus(); },
     }"
     class="relative shrink-0"
     @keydown.escape="if (open) { $event.stopPropagation(); $event.preventDefault(); close(true); }"
     @keydown.arrow-down="if (open) { $event.preventDefault(); step(1); }"
     @keydown.arrow-up="if (open) { $event.preventDefault(); step(-1); }"
     @click.outside="close()">
    @isset($trigger)
        {{ $trigger }}
    @else
        <button type="button" x-ref="trigger" @click="toggle()" :aria-expanded="open" aria-haspopup="menu" aria-label="{{ $label }}" title="{{ $label }}"
                class="rounded-theme px-1.5 py-0.5 text-sm leading-none text-muted hover:text-accent focus-visible:text-accent">⋯</button>
    @endisset

    <div x-ref="panel" x-show="open" x-cloak x-transition.opacity.duration.100ms role="menu" aria-label="{{ $label }}"
         @click="if ($event.target.closest('[role=menuitem]')) close()"
         class="absolute right-0 z-20 {{ $width }} rounded-theme border border-white/10 bg-surface py-1 text-sm shadow-lg shadow-black/40 {{ $up ? 'bottom-full mb-1' : 'top-full mt-1' }}">
        {{ $slot }}
    </div>
</div>
