<x-layouts.app :title="($page ? $pages[$page].' · ' : '').'Documentation · Magnetite'">
    <div class="grid gap-8 md:grid-cols-[13rem_minmax(0,1fr)]">
        <nav aria-label="Documentation" class="text-sm md:sticky md:top-6 md:self-start">
            <a href="{{ route('guide') }}" wire:navigate class="block py-1 {{ $page === null ? 'text-accent' : 'text-muted hover:text-accent' }}">Documentation</a>
            <ol class="mt-1 border-l border-white/10">
                @foreach ($pages as $slug => $title)
                    <li><a href="{{ route('guide', $slug) }}" wire:navigate @if ($slug === $page) aria-current="page" @endif
                           class="-ml-px block border-l py-1 pl-3 {{ $slug === $page ? 'border-accent text-accent' : 'border-transparent text-muted hover:text-accent' }}">{{ $title }}</a></li>
                @endforeach
            </ol>
        </nav>

        <div class="min-w-0">
            <article class="guide">{!! $html !!}</article>

            <div class="mt-10 flex max-w-[46rem] justify-between gap-4 border-t border-white/10 pt-4 text-sm">
                <span>@if ($neighbours[0])<a href="{{ route('guide', $neighbours[0][0]) }}" wire:navigate class="text-muted hover:text-accent">← {{ $neighbours[0][1] }}</a>@endif</span>
                <span>@if ($neighbours[1])<a href="{{ route('guide', $neighbours[1][0]) }}" wire:navigate class="text-muted hover:text-accent">{{ $neighbours[1][1] }} →</a>@endif</span>
            </div>
        </div>
    </div>
</x-layouts.app>
