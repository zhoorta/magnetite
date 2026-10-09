{{-- One entry of <x-menu>. `danger` marks a destructive action (red, set apart by a rule above it). --}}
@props(['danger' => false])
<button type="button" role="menuitem" tabindex="-1"
        {{ $attributes->class([
            'flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-white/5 focus-visible:bg-white/5 focus-visible:outline-none',
            'hover:text-red-500 focus-visible:text-red-500 text-muted' => $danger,
            'hover:text-accent focus-visible:text-accent' => ! $danger,
        ]) }}>{{ $slot }}</button>
