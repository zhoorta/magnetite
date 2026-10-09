{{-- The tracks of one shared album: play and queue only, no findings and no sharing controls. Expects `tracks` in x-data. --}}
<div class="divide-y divide-white/10 rounded-theme border border-white/10 bg-surface">
    <template x-for="(t, i) in tracks" :key="t.id">
        <button type="button" class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:text-accent"
                @click="$store.player.setQueue(tracks, i)">
            <span class="w-5 shrink-0 text-xs text-muted" x-text="i + 1"></span>
            <span class="flex-1 truncate" x-text="t.title"></span>
            <span class="shrink-0 text-xs text-accent2" x-show="$store.player.current?.id === t.id"
                  x-text="$store.player.playing ? 'playing' : 'paused'"></span>
            <span class="shrink-0 text-xs text-muted" x-show="! t.playable" title="This browser cannot decode ALAC and ffmpeg is not available to convert it">ALAC</span>
            <span class="w-10 shrink-0 text-right text-xs tabular-nums text-muted" x-text="$store.player.fmt(t.duration)"></span>
        </button>
    </template>
</div>
