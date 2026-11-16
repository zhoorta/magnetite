<x-layouts.app title="Users · Magnetite">
    @php
        $size = fn (int $bytes) => $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 1).' GB' : ($bytes >= 1024 ** 2 ? round($bytes / 1024 ** 2, 1).' MB' : round($bytes / 1024).' KB');
    @endphp

    <div class="max-w-3xl text-sm">
        <h1 class="mb-2 text-2xl font-semibold">Users</h1>
        <p class="mb-6 max-w-prose text-muted">
            The accounts on this server and the disk space each library uses (the size of the audio files;
            covers and converted files are not counted). What a user has in their library is not shown here. A limit stops uploads and copies that would go over it; empty means no limit. New people join with an invitation (below) or with <code>php artisan magnetite:user</code>.
        </p>

        @if (session('reset_link'))
            <div class="mb-4 rounded-theme border border-accent/40 bg-surface p-3" x-data="{ done: false }">
                <p class="mb-2 text-xs text-muted">Password reset link for <strong class="text-text">{{ session('reset_link')['user'] }}</strong>, shown only now. It works once and for 24 hours; opening it signs that person out everywhere. Send it to them:</p>
                <div class="flex items-center gap-2">
                    <input type="text" readonly value="{{ session('reset_link')['url'] }}" aria-label="Password reset link" @focus="$el.select()"
                           class="min-w-0 flex-1 rounded-theme border border-white/10 bg-bg px-2 py-1 text-xs">
                    <button type="button" class="rounded-theme border border-white/10 px-2 py-1 text-xs text-muted hover:text-accent"
                            @click="navigator.clipboard?.writeText(@js(session('reset_link')['url'])).then(() => done = true)" x-text="done ? 'Copied' : 'Copy'"></button>
                </div>
            </div>
        @endif

        @error('confirm')
            <p class="mb-4 text-xs text-accent2" role="alert">{{ $message }}</p>
        @enderror

        @if (session('status'))
            <p class="mb-4 text-xs text-accent" role="status">{{ session('status') }}</p>
        @endif

        <table class="w-full border-collapse text-left tabular-nums">
            <thead class="text-xs text-muted">
                <tr class="border-b border-white/10">
                    <th class="py-2 pr-4 font-normal">User</th>
                    <th class="py-2 pr-4 text-right font-normal">Disk space</th>
                    <th class="py-2 pr-4 text-right font-normal">Limit</th>
                    <th class="py-2 pr-4 font-normal">Since</th>
                    <th class="py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $u)
                    @php($bytes = (int) $u->tracks_sum_size)
                    <tr class="border-b border-white/5">
                        <td class="py-2 pr-4">
                            {{ $u->username }}
                            @if ($u->is_admin)
                                <span class="ml-1 text-xs text-muted">admin</span>
                            @endif
                        </td>
                        <td class="py-2 pr-4 text-right {{ $u->quota_mb !== null && $bytes > $u->quota_mb * 1024 ** 2 ? 'text-accent2' : '' }}">{{ $size($bytes) }}</td>
                        <td class="py-1 pr-4 text-right">
                            <form method="POST" action="{{ route('admin.users.quota', $u) }}" class="flex items-center justify-end gap-1">
                                @csrf
                                @method('PATCH')
                                <input type="number" name="quota_gb" min="0" step="any" inputmode="decimal" placeholder="none" aria-label="Limit of {{ $u->username }} in GB"
                                       value="{{ $u->quota_mb !== null ? round($u->quota_mb / 1024, 2) : '' }}"
                                       class="w-20 rounded-theme border border-white/10 bg-surface px-2 py-1 text-right text-sm placeholder:text-muted focus:border-accent focus:outline-none">
                                <span class="text-xs text-muted">GB</span>
                                <button type="submit" class="rounded-theme border border-white/10 px-2 py-1 text-xs text-muted hover:text-accent">Save</button>
                            </form>
                        </td>
                        <td class="py-2 pr-4 text-muted">{{ $u->created_at?->toDateString() }}</td>
                        <td class="py-1 text-right">
                            <div class="flex flex-wrap items-center justify-end gap-1">
                                <form method="POST" action="{{ route('admin.users.reset', $u) }}">
                                    @csrf
                                    <button type="submit" class="rounded-theme border border-white/10 px-2 py-1 text-xs text-muted hover:text-accent" title="Make a one-time link for a new password">Reset password</button>
                                </form>
                                @unless ($u->is(auth()->user()))
                                    <form method="POST" action="{{ route('admin.users.role', $u) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="is_admin" value="{{ $u->is_admin ? 0 : 1 }}">
                                        <button type="submit" class="rounded-theme border border-white/10 px-2 py-1 text-xs text-muted hover:text-accent">{{ $u->is_admin ? 'Remove admin' : 'Make admin' }}</button>
                                    </form>
                                    <details class="relative">
                                        <summary class="cursor-pointer list-none rounded-theme border border-white/10 px-2 py-1 text-xs text-muted hover:text-accent">Delete…</summary>
                                        <form method="POST" action="{{ route('admin.users.destroy', $u) }}" class="absolute right-0 z-10 mt-1 w-64 rounded-theme border border-white/10 bg-surface p-3 text-left shadow-lg">
                                            @csrf
                                            @method('DELETE')
                                            <p class="mb-2 text-xs text-muted">Removes the account, its library entries, playlists and shares. The music files stay on the disk. Type <strong class="text-text">{{ $u->username }}</strong> to confirm.</p>
                                            <input type="text" name="confirm" autocomplete="off" aria-label="Type the username to confirm" required
                                                   class="mb-2 w-full rounded-theme border border-white/10 bg-bg px-2 py-1 text-sm focus:border-accent focus:outline-none">
                                            <button type="submit" class="rounded-theme bg-accent2 px-2 py-1 text-xs font-medium text-bg">Delete {{ $u->username }}</button>
                                        </form>
                                    </details>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td class="py-2 pr-4 text-muted">{{ $users->count() }} {{ $users->count() === 1 ? 'user' : 'users' }}, {{ number_format($totalTracks, 0, '.', ' ') }} {{ $totalTracks === 1 ? 'track' : 'tracks' }} in all</td>
                    <td class="py-2 pr-4 text-right">{{ $size($totalBytes) }}</td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>

        <h2 class="mb-2 mt-10 text-lg font-semibold">Invitations</h2>
        <p class="mb-4 max-w-prose text-muted">
            An invitation is a link that works once and lets one person choose their own username and password.
            Send it to them yourself; Magnetite sends no mail.
        </p>

        @if (session('invite_link'))
            <div class="mb-4 rounded-theme border border-accent/40 bg-surface p-3" x-data="{ done: false }">
                <p class="mb-2 text-xs text-muted">The link, shown only now. Copy it and send it:</p>
                <div class="flex items-center gap-2">
                    <input type="text" readonly value="{{ session('invite_link') }}" aria-label="Invitation link" @focus="$el.select()"
                           class="min-w-0 flex-1 rounded-theme border border-white/10 bg-bg px-2 py-1 text-xs">
                    <button type="button" class="rounded-theme border border-white/10 px-2 py-1 text-xs text-muted hover:text-accent"
                            @click="navigator.clipboard?.writeText(@js(session('invite_link'))).then(() => done = true)" x-text="done ? 'Copied' : 'Copy'"></button>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.invitations.store') }}" class="mb-4 flex flex-wrap items-center gap-2">
            @csrf
            <input type="text" name="label" maxlength="100" placeholder="Who is it for? (optional)" aria-label="Who the invitation is for"
                   class="w-56 rounded-theme border border-white/10 bg-surface px-2 py-1 text-sm placeholder:text-muted focus:border-accent focus:outline-none">
            <select name="days" aria-label="Valid for" class="rounded-theme border border-white/10 bg-surface px-2 py-1 text-sm">
                <option value="1">1 day</option>
                <option value="7" selected>7 days</option>
                <option value="30">30 days</option>
            </select>
            <button type="submit" class="rounded-theme bg-accent px-3 py-1 text-sm font-medium text-bg">Make an invitation</button>
        </form>

        @error('days')
            <p class="mb-2 text-xs text-accent2" role="alert">{{ $message }}</p>
        @enderror

        @if ($invitations->isNotEmpty())
            <ul class="max-w-xl">
                @foreach ($invitations as $i)
                    <li class="flex items-center justify-between gap-3 border-b border-white/5 py-2">
                        <span>{{ $i->label ?? 'No name' }} <span class="text-xs text-muted">valid until {{ $i->expires_at->toDateString() }}</span></span>
                        <form method="POST" action="{{ route('admin.invitations.destroy', $i) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-muted hover:text-accent">Withdraw</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-xs text-muted">No open invitations.</p>
        @endif

        @if ($resets->isNotEmpty())
            <h2 class="mb-2 mt-10 text-lg font-semibold">Password resets waiting</h2>
            <ul class="max-w-xl">
                @foreach ($resets as $r)
                    <li class="flex items-center justify-between gap-3 border-b border-white/5 py-2">
                        <span>{{ $r->user->username }} <span class="text-xs text-muted">valid until {{ $r->expires_at->format('Y-m-d H:i') }}</span></span>
                        <form method="POST" action="{{ route('admin.invitations.destroy', $r) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-muted hover:text-accent">Withdraw</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-layouts.app>
