<x-layouts.bare title="Link a device · Magnetite">
    <div class="flex w-full max-w-xs flex-col items-stretch gap-4">
        <a href="{{ route('welcome') }}" aria-label="Magnetite, back to the start page">
            <img src="/logo.svg" alt="Magnetite" width="512" height="512" class="mx-auto w-full border border-white/10">
        </a>

        @if ($approved)
            <p class="text-sm" role="status">Done. The TV is signing in as {{ auth()->user()->username }}.</p>
            <a href="{{ route('library') }}" class="rounded-theme bg-accent px-4 py-2 text-center text-sm font-medium text-bg">Back to the library</a>
        @else
            <form method="POST" action="{{ route('device.store') }}" class="flex flex-col items-stretch gap-4">
                @csrf
                <p class="text-sm text-muted">Type the code shown on the TV to sign it in as {{ auth()->user()->username }}.</p>
                <input type="text" name="user_code" value="{{ old('user_code', $code) }}" placeholder="ABCD-EFGH" aria-label="Code from the TV"
                       autocomplete="off" autocapitalize="characters" spellcheck="false" required autofocus
                       class="rounded-theme border border-white/10 bg-surface px-3 py-2 text-center font-mono text-lg uppercase tracking-widest placeholder:text-muted focus:border-accent focus:outline-none">
                @error('user_code')
                    <p class="text-xs text-accent2" role="alert">{{ $message }}</p>
                @enderror
                <button type="submit" class="rounded-theme bg-accent px-4 py-2 text-sm font-medium text-bg">Sign in the TV</button>
            </form>
        @endif
    </div>
</x-layouts.bare>
