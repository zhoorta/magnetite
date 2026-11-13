@props(['keys'])
{{-- Sliders for some of the scene settings (keys of `effects` and `sliders` in scenes-page.js); "auto" = nothing saved for it. --}}
<div class="grid gap-x-5 gap-y-3 sm:grid-cols-2">
    <template x-for="[key, label, min, max, step, hint] in rowsOf(@js($keys))" :key="key">
        <div>
            <div class="flex items-baseline gap-2 text-sm">
                <label :for="'sl-' + key" class="flex-1" x-text="label"></label>
                <span class="tabular-nums text-muted" x-text="Number(value(selected, key)).toFixed(2)"></span>
                <button type="button" class="text-xs" :class="isSet(selected, key) ? 'text-accent hover:underline' : 'invisible'" @click="reset(selected, key)">auto</button>
            </div>
            <input :id="'sl-' + key" type="range" class="w-full accent-[var(--color-accent)]" :min="min" :max="max" :step="step" :value="value(selected, key)"
                   :title="hint" @input="set(selected, key, $event.target.value)">
        </div>
    </template>
</div>
