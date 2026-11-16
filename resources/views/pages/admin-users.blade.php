<x-layouts.app title="Users · Magnetite">
    @php
        $size = fn (int $bytes) => $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 1).' GB' : ($bytes >= 1024 ** 2 ? round($bytes / 1024 ** 2, 1).' MB' : round($bytes / 1024).' KB');
    @endphp

    <div class="max-w-3xl text-sm">
        <h1 class="mb-2 text-2xl font-semibold">Users</h1>
        <p class="mb-6 max-w-prose text-muted">
            The accounts on this server and the disk space each library uses (the size of the audio files;
            covers and converted files are not counted). What a user has in their library is not shown here. A limit stops uploads and copies that would go over it; empty means no limit. Users are added with <code>php artisan magnetite:user</code>.
        </p>

        @if (session('status'))
            <p class="mb-4 text-xs text-accent" role="status">{{ session('status') }}</p>
        @endif

        <table class="w-full border-collapse text-left tabular-nums">
            <thead class="text-xs text-muted">
                <tr class="border-b border-white/10">
                    <th class="py-2 pr-4 font-normal">User</th>
                    <th class="py-2 pr-4 text-right font-normal">Disk space</th>
                    <th class="py-2 pr-4 text-right font-normal">Limit</th>
                    <th class="py-2 font-normal">Since</th>
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
                        <td class="py-2 text-muted">{{ $u->created_at?->toDateString() }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td class="py-2 pr-4 text-muted">{{ $users->count() }} {{ $users->count() === 1 ? 'user' : 'users' }}, {{ number_format($totalTracks, 0, '.', ' ') }} {{ $totalTracks === 1 ? 'track' : 'tracks' }} in all</td>
                    <td class="py-2 pr-4 text-right">{{ $size($totalBytes) }}</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</x-layouts.app>
