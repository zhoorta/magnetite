<x-layouts.app title="Password · Magnetite">
    <div class="max-w-xs text-sm">
        <h1 class="mb-2 text-2xl font-semibold">Password</h1>
        <p class="mb-6 text-muted">Changing it signs your other devices out, apps included.</p>

        @if (session('status'))
            <p class="mb-4 text-xs text-accent" role="status">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-3">
            @csrf
            @method('PUT')
            <input type="password" name="current_password" placeholder="Current password" aria-label="Current password" autocomplete="current-password" required
                   class="rounded-theme border border-white/10 bg-surface px-3 py-2 placeholder:text-muted focus:border-accent focus:outline-none">
            <input type="password" name="password" placeholder="New password (at least 8 characters)" aria-label="New password" autocomplete="new-password" minlength="8" required
                   class="rounded-theme border border-white/10 bg-surface px-3 py-2 placeholder:text-muted focus:border-accent focus:outline-none">
            <input type="password" name="password_confirmation" placeholder="Repeat the new password" aria-label="Repeat the new password" autocomplete="new-password" required
                   class="rounded-theme border border-white/10 bg-surface px-3 py-2 placeholder:text-muted focus:border-accent focus:outline-none">

            @foreach (['current_password', 'password'] as $field)
                @error($field)
                    <p class="text-xs text-accent2" role="alert">{{ $message }}</p>
                @enderror
            @endforeach

            <button type="submit" class="rounded-theme bg-accent px-4 py-2 font-medium text-bg">Change the password</button>
        </form>
    </div>
</x-layouts.app>
