<x-layouts.bare title="Set up · Magnetite">
    <form method="POST" action="{{ route('setup.store') }}" class="flex w-full max-w-xs flex-col items-stretch gap-4">
        @csrf
        <img src="/logo.svg" alt="Magnetite" width="512" height="512" class="mx-auto w-full border border-white/10">

        <p class="text-xs text-muted">
            Welcome. The first person to finish this form becomes the administrator, so do it now, before sharing the address.
        </p>

        <input type="text" name="username" value="{{ old('username') }}" placeholder="Username" aria-label="Username"
               autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus
               class="rounded-theme border border-white/10 bg-surface px-3 py-2 text-sm placeholder:text-muted focus:border-accent focus:outline-none">
        <input type="password" name="password" placeholder="Password (at least 8 characters)" aria-label="Password"
               autocomplete="new-password" minlength="8" required
               class="rounded-theme border border-white/10 bg-surface px-3 py-2 text-sm placeholder:text-muted focus:border-accent focus:outline-none">
        <input type="password" name="password_confirmation" placeholder="Repeat the password" aria-label="Repeat the password"
               autocomplete="new-password" required
               class="rounded-theme border border-white/10 bg-surface px-3 py-2 text-sm placeholder:text-muted focus:border-accent focus:outline-none">
        @if ($tokenNeeded)
            <input type="password" name="setup_token" placeholder="Set-up token" aria-label="Set-up token"
                   autocomplete="off" required
                   class="rounded-theme border border-white/10 bg-surface px-3 py-2 text-sm placeholder:text-muted focus:border-accent focus:outline-none">
        @endif

        @foreach (['username', 'password', 'setup_token'] as $field)
            @error($field)
                <p class="text-xs text-accent2" role="alert">{{ $message }}</p>
            @enderror
        @endforeach

        <button type="submit" class="rounded-theme bg-accent px-4 py-2 text-sm font-medium text-bg">Create the administrator</button>
    </form>
</x-layouts.bare>
