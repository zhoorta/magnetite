{{-- A 16 px line icon in the current text colour. `name` picks the drawing. Always give the button around it a title and an aria-label. --}}
@props(['name'])
<svg width="16" height="16" {{ $attributes->merge(['class' => 'shrink-0']) }} viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('play') <path d="M5 3.5v9l7-4.5z" fill="currentColor" stroke="none"/> @break
        @case('pause') <rect x="4" y="3.5" width="3" height="9" fill="currentColor" stroke="none"/><rect x="9" y="3.5" width="3" height="9" fill="currentColor" stroke="none"/> @break
        @case('prev') <path d="M12 3.5v9l-6.5-4.5z" fill="currentColor" stroke="none"/><rect x="3" y="3.5" width="1.8" height="9" fill="currentColor" stroke="none"/> @break
        @case('next') <path d="M4 3.5v9l6.5-4.5z" fill="currentColor" stroke="none"/><rect x="11.2" y="3.5" width="1.8" height="9" fill="currentColor" stroke="none"/> @break
        @case('back') <path d="M9.5 3.5L5 8l4.5 4.5M5 8h8"/> @break
        @case('select') <rect x="2.5" y="2.5" width="11" height="11" rx="1"/><path d="M5.5 8l2 2 3.5-4"/> @break
        @case('move') <path d="M2 4.5a1 1 0 0 1 1-1h3l1.5 1.5H13a1 1 0 0 1 1 1V11a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1z"/><path d="M6 8.5h5M9.5 7l1.5 1.5L9.5 10"/> @break
        @case('plus') <path d="M8 3.5v9M3.5 8h9"/> @break
        @case('dots') <circle cx="3.5" cy="8" r="1" fill="currentColor"/><circle cx="8" cy="8" r="1" fill="currentColor"/><circle cx="12.5" cy="8" r="1" fill="currentColor"/> @break
        @case('search') <circle cx="7" cy="7" r="4"/><path d="M10 10l3.5 3.5"/> @break
        @case('truth') <path d="M2.5 4h8M2.5 8h11M2.5 12h6"/><path d="M12 11.5l1 1 2-2.5" stroke-width="1.3"/> @break
        @case('warn') <path d="M8 2.5l6 10.5H2z"/><path d="M8 6.5v3M8 11.2v.1"/> @break
        @case('info') <circle cx="8" cy="8" r="5.5"/><path d="M8 7.5v3.5M8 5.2v.1"/> @break
        @case('meters') <rect x="2" y="8" width="3" height="6" rx=".5"/><rect x="6.5" y="2" width="3" height="12" rx=".5"/><rect x="11" y="5" width="3" height="9" rx=".5"/> @break
        @case('full') <path d="M2.5 6V2.5H6M10 2.5h3.5V6M13.5 10v3.5H10M6 13.5H2.5V10"/> @break
        @case('command') <path d="M3 4.5L7 8l-4 3.5M8.5 12h4.5"/> @break
    @endswitch
</svg>
