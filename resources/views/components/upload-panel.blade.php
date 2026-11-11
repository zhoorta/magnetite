{{-- The upload panel: choose or drop zips, send them, see what was added and the scan that follows. It is bound to the `uploads` store, so it
     works as a page and as a dialog in the library, and an upload goes on when you leave. Keys (focus inside it): c choose, Enter upload, x clear. --}}
<div x-data="{ keys(e) {
         if (e.ctrlKey || e.metaKey || e.altKey || ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;
         if (e.key === 'c') { e.preventDefault(); this.$refs.file.click(); }
         else if (e.key === 'x') { e.preventDefault(); $store.uploads.clear(); }
         else if (e.key === 'Enter' && e.target.tagName !== 'BUTTON' && $store.uploads.files.length) { e.preventDefault(); $store.uploads.send(); }
     } }"
     {{ $attributes->merge(['class' => 'relative rounded-theme border border-white/15 px-4 pb-4 pt-5 text-sm outline-none focus-visible:border-accent', 'tabindex' => '-1']) }} @keydown="keys($event)">
    <span class="absolute -top-2.5 left-3 bg-bg px-1.5 text-xs text-muted">Upload</span>

    <label class="flex cursor-pointer items-center gap-3 rounded-theme border border-dashed border-white/20 px-3 py-4 hover:border-accent"
           @dragover.prevent @drop.prevent="$store.uploads.pick($event.dataTransfer.files)">
        <x-icon name="upload" class="size-5 text-muted" />
        <span class="min-w-0 flex-1">Choose zips or drop them here</span>
        <span class="shrink-0 text-xs text-muted" x-text="'.zip · up to ' + $store.uploads.maxMb + ' MB'"></span>
        <input x-ref="file" type="file" accept=".zip,application/zip" multiple class="sr-only" @change="$store.uploads.pick($event.target.files); $event.target.value = ''">
    </label>

    <ul x-show="$store.uploads.files.length" x-cloak class="mt-3">
        <template x-for="(f, i) in $store.uploads.files" :key="f.name + f.size">
            <li class="flex items-baseline gap-2 px-1 hover:bg-white/5">
                <span class="min-w-0 flex-1 truncate" x-text="f.name"></span>
                <span class="shrink-0 text-xs text-muted" x-text="$store.uploads.size(f.size)"></span>
                <button type="button" x-show="! $store.uploads.busy" class="shrink-0 text-muted hover:text-accent" :aria-label="'Remove ' + f.name" title="Remove" @click="$store.uploads.remove(i)">&times;</button>
            </li>
        </template>
    </ul>

    <div x-show="$store.uploads.busy" x-cloak class="mt-3 space-y-1">
        <div class="h-1 w-full bg-white/10"><div class="h-1 bg-accent" :style="{ width: $store.uploads.progress + '%' }"></div></div>
        <p class="text-xs text-muted" x-text="$store.uploads.note"></p>
    </div>

    <p x-show="$store.uploads.error" x-cloak x-text="$store.uploads.error" class="mt-3 text-accent2" role="alert"></p>

    <div x-show="$store.uploads.files.length" x-cloak class="mt-3 flex items-center gap-2">
        <button type="button" :disabled="$store.uploads.busy" class="rounded-theme bg-accent px-4 py-1 font-medium text-bg disabled:opacity-50" @click="$store.uploads.send()">Upload</button>
        <button type="button" x-show="! $store.uploads.busy" class="rounded-theme border border-white/15 px-3 py-1 hover:text-accent" @click="$store.uploads.clear()">Clear</button>
    </div>

    {{-- What each zip gave: added, already there, a different file with that name (not added), left out (not audio or cover). --}}
    <table x-show="$store.uploads.result" x-cloak class="mt-4 w-full text-xs">
        <thead class="text-muted"><tr class="text-right"><th class="w-2/5 py-1 text-left font-normal">Zip</th><th class="font-normal">added</th><th class="font-normal">there</th><th class="font-normal" title="A different file has that name: not added">conflicts</th><th class="font-normal" title="Not audio or a cover image">left out</th><th class="font-normal">size</th></tr></thead>
        <tbody>
            <template x-for="a in $store.uploads.result?.archives ?? []" :key="a.name">
                <tr class="text-right tabular-nums">
                    <td class="max-w-0 truncate py-0.5 text-left" x-text="a.name"></td>
                    <template x-if="a.ok"><td class="text-accent2" x-text="a.added"></td></template>
                    <template x-if="a.ok"><td class="text-muted" x-text="a.existing || ''"></td></template>
                    <template x-if="a.ok"><td :class="a.conflicts ? 'text-amber-500' : 'text-muted'" x-text="a.conflicts || ''"></td></template>
                    <template x-if="a.ok"><td class="text-muted" x-text="a.skipped || ''"></td></template>
                    <template x-if="a.ok"><td class="text-muted" x-text="$store.uploads.size(a.bytes)"></td></template>
                    <template x-if="! a.ok"><td colspan="5" class="text-left text-accent2" x-text="a.error"></td></template>
                </tr>
            </template>
        </tbody>
    </table>

    {{-- The scan that follows an upload (or "Scan now" for files copied in by hand). --}}
    <div class="mt-4 flex flex-wrap items-baseline gap-x-3 gap-y-1 border-t border-white/10 pt-3 text-xs text-muted" aria-live="polite">
        <span x-show="$store.uploads.scan?.state === 'queued'">Scan queued.<span x-show="$store.uploads.waited >= 16"> Nothing has picked it up yet: a queue worker has to be running (<code>php artisan queue:work</code>).</span></span>
        <span x-show="$store.uploads.scan?.state === 'running'" x-text="'Scanning the library… ' + $store.uploads.scan?.processed + ' files read'"></span>
        <span x-show="$store.uploads.scan?.state === 'done'">
            Scanned: <span x-text="$store.uploads.scan?.counts?.added"></span> new, <span x-text="$store.uploads.scan?.counts?.updated"></span> updated, <span x-text="$store.uploads.scan?.counts?.removed"></span> removed<span x-show="$store.uploads.scan?.counts?.failed">, <span x-text="$store.uploads.scan?.counts?.failed"></span> could not be read</span>.
            {{ $slot }}
        </span>
        <span x-show="$store.uploads.scan?.state === 'failed'" class="text-accent2" x-text="'The scan failed: ' + $store.uploads.scan?.error"></span>
        <button type="button" class="rounded-theme border border-white/10 px-2 py-0.5 hover:text-accent disabled:opacity-50" :disabled="['queued', 'running'].includes($store.uploads.scan?.state)" title="For files you copied into the folder by hand" @click="$store.uploads.scanNow()">Scan now</button>
        <span class="ml-auto"><kbd class="text-accent2">c</kbd> choose · <kbd class="text-accent2">Enter</kbd> upload · <kbd class="text-accent2">x</kbd> clear</span>
    </div>
</div>
