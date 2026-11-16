<x-layouts.app title="Storage · Magnetite">
    @php
        $size = fn (int $bytes) => $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 1).' GB' : ($bytes >= 1024 ** 2 ? round($bytes / 1024 ** 2, 1).' MB' : round($bytes / 1024).' KB');
    @endphp

    <div x-data="storage(@js(route('storage.test')), @js(route('storage.cache.clear')), @js(csrf_token()), @js($cache))" class="grid gap-x-10 gap-y-6 text-sm lg:grid-cols-2 lg:items-start">
        <div>
            <h1 class="mb-2 text-2xl font-semibold">Storage</h1>
            <p class="mb-6 max-w-prose text-sm text-muted">
                Where the music files are stored. This page is read-only; the setup is changed with
                <code>php artisan magnetite:storage</code>.
            </p>

        <dl class="grid grid-cols-[8rem_1fr] gap-x-4 gap-y-2">
            <dt class="text-muted">Source</dt>
            <dd>{{ $remote ? 'Ferrite' : 'Local folder' }}</dd>

            @if ($remote)
                <dt class="text-muted">Address</dt>
                <dd class="break-all">{{ $address }}</dd>
            @else
                <dt class="text-muted">Folder</dt>
                <dd class="break-all">{{ $folder }}</dd>
            @endif

            <dt class="text-muted">Tracks</dt>
            <dd>{{ $tracks }}</dd>

            <dt class="text-muted">Library size</dt>
            <dd>{{ number_format($libraryBytes / 1024 ** 2, 1, '.', ' ') }} MB{{ auth()->user()->quota_mb !== null && ! $remote ? ' of '.number_format(auth()->user()->quota_mb / 1024, 1, '.', ' ').' GB allowed' : '' }}</dd>

            @if ($allUsers)
                <dt class="text-muted">All users</dt>
                <dd>{{ number_format($allUsers['bytes'] / 1024 ** 2, 1, '.', ' ') }} MB, {{ $allUsers['tracks'] }} {{ $allUsers['tracks'] === 1 ? 'track' : 'tracks' }}, {{ $allUsers['users'] }} {{ $allUsers['users'] === 1 ? 'user' : 'users' }}</dd>
            @endif

            <dt class="text-muted">Last scan</dt>
            <dd>
                @if ($scan['state'] === 'done')
                    {{ \Illuminate\Support\Carbon::createFromTimestamp($scan['finished_at'])->diffForHumans() }}:
                    {{ $scan['counts']['added'] ?? 0 }} new, {{ $scan['counts']['updated'] ?? 0 }} updated, {{ $scan['counts']['removed'] ?? 0 }} removed
                @elseif ($scan['state'] === 'failed')
                    <span class="text-accent2">failed: {{ $scan['error'] }}</span>
                @elseif (in_array($scan['state'], ['queued', 'running'], true))
                    {{ $scan['state'] }}
                @else
                    not yet
                @endif
            </dd>
        </dl>
        </div>

        <div class="space-y-6 lg:pt-1">
        <div class="space-y-2 border-t border-white/10 pt-4 lg:border-t-0 lg:pt-0">
            <button type="button" class="rounded-theme border border-white/10 px-3 py-1 text-xs hover:text-accent" :disabled="busy" @click="test()">
                Test connection
            </button>

            <div aria-live="polite" class="space-y-1">
                <p x-show="busy" class="text-muted">Checking…</p>
                <p x-show="result && ! result.ok" class="text-accent2" x-text="result?.error" role="alert"></p>

                <dl x-show="result?.ok && result?.folder" class="grid grid-cols-[8rem_1fr] gap-x-4 gap-y-1">
                    <dt class="text-muted">Ferrite folder</dt><dd x-text="result?.folder"></dd>
                    <dt class="text-muted">Access</dt>
                    <dd x-text="{ 'read+write+trash': 'Read, add files and move files to Ferrite\'s trash', 'read+write': 'Read and add files (deleting is off)' }[result?.access] ?? 'Read only (uploads and deleting are off)'"></dd>
                    <dt class="text-muted">Token expires</dt>
                    <dd>
                        <span x-text="result?.expires_at ?? 'never'"></span>
                        <span x-show="result?.expires_soon" class="text-accent2"> (soon: make a new token in Ferrite and run <code>magnetite:storage</code> again)</span>
                    </dd>
                    <dt class="text-muted">Ferrite space</dt>
                    <dd x-text="result?.quota ? (result.quota.limit ? bytes(result.quota.used) + ' of ' + bytes(result.quota.limit) + ' used' : bytes(result.quota.used) + ' used, no limit') : '?'"></dd>
                </dl>
                <p x-show="result?.ok && ! result?.folder" class="text-muted">The local folder is readable.</p>
            </div>
        </div>

        @if ($remote)
            <div class="space-y-2 border-t border-white/10 pt-4">
                <p>
                    Cache: <span x-text="cache.files"></span> files, <span x-text="bytes(cache.bytes)"></span>
                    of {{ $size($cacheMaxBytes) }}.
                </p>
                <p class="text-xs text-muted">
                    Files read while scanning or converting are kept here so they do not have to be fetched again; when it is full, the oldest files are deleted first.
                    The cache is shared by everyone on this server.
                </p>
                <button type="button" class="rounded-theme border border-white/10 px-3 py-1 text-xs hover:text-accent" :disabled="busy || ! cache.files" @click="clear()">
                    Clear cache
                </button>
            </div>
        @endif
        </div>
    </div>
</x-layouts.app>
