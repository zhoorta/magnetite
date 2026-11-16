<x-layouts.bare title="Sign in · Magnetite">
    <form method="POST" action="{{ route('login.store') }}" class="flex w-full max-w-xs flex-col items-stretch gap-4">
        @csrf
        <a href="{{ config('magnetite.public_site') ? route('welcome') : route('login') }}" aria-label="Magnetite, back to the start page">
            <img src="/logo.svg" alt="Magnetite" width="512" height="512" class="mx-auto w-full border border-white/10">
        </a>

        <input type="text" name="username" value="{{ old('username') }}" placeholder="Username" aria-label="Username"
               autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus
               class="rounded-theme border border-white/10 bg-surface px-3 py-2 text-sm placeholder:text-muted focus:border-accent focus:outline-none">
        <input type="password" name="password" placeholder="Password" aria-label="Password"
               autocomplete="current-password" required
               class="rounded-theme border border-white/10 bg-surface px-3 py-2 text-sm placeholder:text-muted focus:border-accent focus:outline-none">

        @error('username')
            <p class="text-xs text-accent2" role="alert">{{ $message }}</p>
        @enderror
        @error('password')
            <p class="text-xs text-accent2" role="alert">{{ $message }}</p>
        @enderror

        <button type="submit" class="rounded-theme bg-accent px-4 py-2 text-sm font-medium text-bg">Sign in</button>
    </form>
</x-layouts.bare>
