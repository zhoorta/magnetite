{{-- A small speaker that says "this is playing". The sound waves fade slowly in turn; nothing moves. Pass `title`; add x-show etc. as attributes. --}}
@props(['title' => 'Playing'])
<svg {{ $attributes->merge(['class' => 'h-3.5 w-3.5 shrink-0 text-accent opacity-60']) }} viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="{{ $title }}">
    <title>{{ $title }}</title>
    <path d="M2.5 6v4h2.5l3 2.5v-9L5 6z" fill="currentColor" stroke="none"/>
    <path d="M10.2 6.2a2.6 2.6 0 0 1 0 3.6" class="animate-pulse"/>
    <path d="M12 4.5a5 5 0 0 1 0 7" class="animate-pulse [animation-delay:700ms]"/>
</svg>
