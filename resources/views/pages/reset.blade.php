<x-layouts.bare title="New password · Magnetite">
    <form method="POST" action="{{ route('reset.accept', $token) }}" class="flex w-full max-w-xs flex-col items-stretch gap-4">
        @csrf
        <img src="/logo.svg" alt="Magnetite" width="512" height="512" class="mx-auto w-full border border-white/10">

        <p class="text-xs text-muted">Choose a new password for <strong class="text-text">{{ $username }}</strong>. Every device is signed out when you save.</p>

        <input type="text" value="{{ $username }}" autocomplete="username" readonly hidden aria-hidden="true">
        <input type="password" name="password" placeholder="New password (at least 8 characters)" aria-label="New password"
               autocomplete="new-password" minlength="8" required autofocus
               class="rounded-theme border border-white/10 bg-surface px-3 py-2 text-sm placeholder:text-muted focus:border-accent focus:outline-none">
        <input type="password" name="password_confirmation" placeholder="Repeat the password" aria-label="Repeat the password"
               autocomplete="new-password" required
               class="rounded-theme border border-white/10 bg-surface px-3 py-2 text-sm placeholder:text-muted focus:border-accent focus:outline-none">

        @error('password')
            <p class="text-xs text-accent2" role="alert">{{ $message }}</p>
        @enderror

        <button type="submit" class="rounded-theme bg-accent px-4 py-2 text-sm font-medium text-bg">Save the password</button>
    </form>
</x-layouts.bare>
