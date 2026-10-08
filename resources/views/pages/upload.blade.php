<x-layouts.app title="Upload · Magnetite">
    <h1 class="mb-2 text-2xl font-semibold">Upload</h1>
    <p class="mb-6 max-w-prose text-sm text-muted">
        Send one or more zips. They are unpacked into the library (audio and cover images only, hidden files left out,
        nothing that is already there is overwritten) and the library is scanned. Keep the folder per album inside the
        zip; loose files go into a folder named after the zip. Up to {{ $maxMb }} MB per zip.
    </p>

    <div x-data="uploader(@js(route('upload.store')), @js(csrf_token()), @js($maxMb), @js(route('scan.show')))" class="space-y-4">
        <label class="flex cursor-pointer flex-col items-center gap-2 rounded-theme border border-dashed border-white/20 bg-surface px-4 py-10 text-center text-sm hover:border-accent"
               @dragover.prevent @drop.prevent="pick($event.dataTransfer.files)">
            <span class="font-medium">Choose zips or drop them here</span>
            <span class="text-xs text-muted">.zip only</span>
            <input type="file" accept=".zip,application/zip" multiple class="sr-only" @change="pick($event.target.files); $event.target.value = ''">
        </label>

        <ul x-show="files.length" class="space-y-1 text-sm">
            <template x-for="(f, i) in files" :key="i">
                <li class="flex items-center justify-between gap-3">
                    <span class="truncate" x-text="f.name"></span>
                    <span class="flex shrink-0 items-center gap-3 text-xs text-muted">
                        <span x-text="size(f.size)"></span>
                        <button type="button" x-show="! busy" class="hover:text-accent" @click="files.splice(i, 1)">&times;</button>
                    </span>
                </li>
            </template>
        </ul>

        <div x-show="busy" class="space-y-1">
            <div class="h-1 w-full bg-white/10"><div class="h-1 bg-accent" :style="{ width: progress + '%' }"></div></div>
            <p class="text-xs text-muted" x-text="progress < 100 ? 'Uploading ' + progress + '%' : 'Unpacking…'"></p>
        </div>

        <p x-show="error" x-text="error" class="text-sm text-accent2" role="alert"></p>

        <button type="button" x-show="files.length" :disabled="busy"
                class="rounded-theme bg-accent px-5 py-2 text-sm font-medium text-bg disabled:opacity-50" @click="send()">
            Upload
        </button>

        <div x-show="result" class="space-y-2 text-sm">
            <template x-for="a in result?.archives ?? []" :key="a.name">
                <p>
                    <span class="font-medium" x-text="a.name"></span>
                    <span x-show="a.ok" class="text-muted"
                          x-text="': ' + a.added + ' files added' + (a.existing ? ', ' + a.existing + ' already there' : '') + (a.skipped ? ', ' + a.skipped + ' left out' : '') + ' (' + size(a.bytes) + ')'"></span>
                    <span x-show="! a.ok" class="text-accent2" x-text="': ' + a.error"></span>
                </p>
            </template>
        </div>

        <div class="space-y-2 border-t border-white/10 pt-4 text-sm" aria-live="polite">
            <p x-show="scan?.state === 'queued'" class="text-muted">
                Scan queued.
                <span x-show="waited >= 16">Nothing has picked it up yet: a queue worker has to be running (<code>php artisan queue:work</code>).</span>
            </p>
            <p x-show="scan?.state === 'running'" class="text-muted">
                Scanning the library… <span x-text="scan?.processed"></span> files read.
            </p>
            <p x-show="scan?.state === 'done'" class="text-muted">
                Library scanned:
                <span x-text="scan?.counts?.added"></span> new,
                <span x-text="scan?.counts?.updated"></span> updated,
                <span x-text="scan?.counts?.removed"></span> removed<span x-show="scan?.counts?.failed">,
                <span x-text="scan?.counts?.failed"></span> could not be read</span>.
                <a href="{{ route('library') }}" wire:navigate class="text-accent underline">Open the library</a>
            </p>
            <p x-show="scan?.state === 'failed'" class="text-accent2" x-text="'The scan failed: ' + scan?.error"></p>
            <button type="button" class="rounded-theme border border-white/10 px-3 py-1 text-xs hover:text-accent"
                    :disabled="['queued', 'running'].includes(scan?.state)" @click="scanNow()">
                Scan the library now
            </button>
            <span class="text-xs text-muted">for files you copied into the folder by hand</span>
        </div>
    </div>
</x-layouts.app>
