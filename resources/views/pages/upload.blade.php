<x-layouts.app title="Upload · Magnetite">
    <h1 class="mb-6 text-2xl font-semibold">Upload</h1>
    <div class="grid gap-8 lg:grid-cols-[minmax(0,42rem)_minmax(0,1fr)]">
        <x-upload-panel x-init="$store.uploads.watch()">
            <a href="{{ route('library') }}" wire:navigate class="text-accent underline">Open the library</a>
        </x-upload-panel>

        <aside class="max-w-prose space-y-3 text-sm text-muted" aria-label="How zips are read">
            <h2 class="font-semibold text-text">How zips are read</h2>
            <ul class="list-disc space-y-1.5 pl-5">
                <li>Only audio and cover images are kept; hidden files are left out.</li>
                <li>Existing files are never overwritten. A different file with the same name is skipped and counted as a conflict.</li>
                <li>Keep a folder per album inside the zip. Loose files go into a folder named after the zip.</li>
                <li>When the files are in, the library is scanned in the background and the new albums appear.</li>
            </ul>
            <p>Zips can also be dropped on the library page (<kbd class="text-accent2">U</kbd> opens this panel there). "Scan now" picks up files copied into the folder by hand.</p>
        </aside>
    </div>
</x-layouts.app>
