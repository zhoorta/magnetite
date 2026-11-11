<x-layouts.app title="Scenes · Magnetite">
    <div x-data="scenesPage" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]" @dragover.prevent @drop.prevent="drop($event)">
        <section aria-label="Your scenes">
            <h1 class="mb-1 text-2xl font-semibold">Scenes</h1>
            <p class="mb-4 text-sm text-muted">
                Any picture can be a meter. The bright shapes in it light up with the music: the biggest with the kick, the others one band each, bass on the left.
                Pictures are private to your account and never shared.
            </p>

            <label class="mb-4 flex cursor-pointer items-center justify-center rounded-theme border border-dashed border-white/20 p-6 text-sm text-muted hover:border-accent hover:text-accent">
                <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple class="sr-only" @change="pick($event)">
                <span x-text="store.busy ? 'Uploading…' : 'Choose pictures, or drop them here'"></span>
            </label>
            <p x-show="store.error" x-cloak class="mb-3 text-sm text-red-400" x-text="store.error" role="alert"></p>

            <div class="grid gap-3 sm:grid-cols-2">
                <template x-for="s in store.list" :key="s.id">
                    <button type="button" class="overflow-hidden rounded-theme border text-left" :class="selectedId === s.id ? 'border-accent' : 'border-white/10'"
                            :aria-pressed="selectedId === s.id" @click="selectedId = s.id">
                        <img :src="s.url" :alt="s.name" class="aspect-video w-full bg-black object-cover" loading="lazy">
                        <div class="truncate px-3 py-2 text-sm" x-text="s.name"></div>
                    </button>
                </template>
            </div>
            <p x-show="store.list.length === 0" x-cloak class="text-sm text-muted">No scenes yet.</p>
        </section>

        <section aria-label="Settings" x-show="selected" x-cloak class="space-y-4">
            <template x-if="selected">
                <div class="space-y-4">
                    <div class="flex items-center gap-2">
                        <input type="text" maxlength="80" class="min-w-0 flex-1 rounded-theme border border-white/10 bg-transparent px-3 py-1.5 text-lg font-semibold"
                               :value="selected.name" aria-label="Name" @change="store.change(selected, { name: $event.target.value.trim() || selected.name })">
                        <button type="button" class="rounded-theme border border-white/10 px-3 py-1.5 text-sm hover:border-accent hover:text-accent" @click="show(selected)">Show in player</button>
                    </div>
                    <p class="text-xs text-muted">Start a track, then move the sliders: the meter in the player changes as you go. Only what you change is saved; the rest stays automatic.</p>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">Picture
                            <select class="mt-1 block w-full rounded-theme border border-white/10 bg-bg px-2 py-1.5" :value="selected.settings.fit ?? 'auto'" @change="setFit(selected, $event.target.value)">
                                <option value="auto">Whole picture (auto)</option>
                                <option value="contain">Whole picture</option>
                                <option value="cover">Fill and crop</option>
                            </select>
                        </label>
                        <label class="text-sm">Glow colour
                            <select class="mt-1 block w-full rounded-theme border border-white/10 bg-bg px-2 py-1.5" :value="glowMode(selected).startsWith('#') ? 'auto' : glowMode(selected)" @change="setGlow(selected, $event.target.value)">
                                <option value="auto">Automatic</option>
                                <option value="picture">From the picture</option>
                                <option value="accent">Theme accent</option>
                                <option value="none">None</option>
                            </select>
                        </label>
                    </div>

                    <div class="space-y-3">
                        <template x-for="[key, label, min, max, step, hint] in sliders" :key="key">
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

                    <div class="flex gap-2">
                        <button type="button" class="rounded-theme border border-white/10 px-3 py-1.5 text-sm hover:border-accent hover:text-accent" @click="resetAll(selected)">Reset everything to auto</button>
                        <button type="button" class="rounded-theme border border-white/10 px-3 py-1.5 text-sm text-red-400 hover:border-red-400"
                                @click="removeSelected()">Delete</button>
                    </div>
                </div>
            </template>
        </section>
    </div>
</x-layouts.app>
