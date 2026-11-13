<x-layouts.app title="Scenes · Magnetite">
    <div x-data="scenesPage" class="space-y-5" @dragover.prevent @drop.prevent="drop($event)">
        <section aria-label="Your scenes">
            <h1 class="mb-1 text-2xl font-semibold">Scenes</h1>
            <p class="mb-4 max-w-3xl text-sm text-muted">
                A picture can be used as a meter. Its bright shapes light up with the music: the biggest with the kick, the others one band each, bass on the left.
                Pictures are private to your account and never shared.
            </p>
            <p x-show="store.error" x-cloak class="mb-3 text-sm text-red-400" x-text="store.error" role="alert"></p>

            {{-- The scenes as a strip, so the picture and the settings below get the whole width. --}}
            <div class="flex gap-3 overflow-x-auto pb-2" role="list">
                <label class="flex aspect-video w-36 shrink-0 cursor-pointer items-center justify-center rounded-theme border border-dashed border-white/20 p-2 text-center text-xs text-muted hover:border-accent hover:text-accent">
                    <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple class="sr-only" @change="pick($event)">
                    <span x-text="store.busy ? 'Uploading…' : 'Add pictures, or drop them here'"></span>
                </label>
                <template x-for="s in store.list" :key="s.id">
                    <button type="button" role="listitem" class="w-36 shrink-0 overflow-hidden rounded-theme border text-left" :class="selectedId === s.id ? 'border-accent' : 'border-white/10'"
                            :aria-pressed="selectedId === s.id" @click="choose(s.id)">
                        <img :src="s.url" :alt="s.name" class="aspect-video w-full bg-black object-cover" loading="lazy">
                        <div class="truncate px-2 py-1 text-xs" x-text="s.name"></div>
                    </button>
                </template>
            </div>
            <p x-show="store.list.length === 0" x-cloak class="text-sm text-muted">No scenes yet.</p>
        </section>

        <section aria-label="Settings" x-show="selected" x-cloak>
            <template x-if="selected">
                <div class="space-y-4">
                    <div class="flex items-center gap-2">
                        <input type="text" maxlength="80" class="min-w-0 flex-1 rounded-theme border border-white/10 bg-transparent px-3 py-1.5 text-lg font-semibold"
                               :value="selected.name" aria-label="Name" @change="store.change(selected, { name: $event.target.value.trim() || selected.name })">
                        <button type="button" class="rounded-theme border border-white/10 px-3 py-1.5 text-sm hover:border-accent hover:text-accent" @click="show(selected)">Show in player</button>
                        <button type="button" class="rounded-theme border border-white/10 px-3 py-1.5 text-sm text-red-400 hover:border-red-400" @click="removeSelected()">Delete</button>
                    </div>
                    <p class="text-xs text-muted">The picture reacts as you change the settings, to the music that plays. Only what you change is saved; the rest stays automatic.</p>

                    <div class="grid gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
                        <div class="space-y-2 lg:sticky lg:top-4 lg:self-start">
                            <h2 class="text-sm font-semibold">Shapes</h2>
                            <p class="text-xs text-muted">
                                Drag on the picture to mark what should light up. The first becomes the kick; the others take one band each, bass on the left.
                                Marked shapes replace the automatic ones; with none marked, the picture decides.
                            </p>
                            <div x-ref="stage" class="relative mx-auto w-fit max-w-full touch-none select-none overflow-hidden rounded-theme border border-white/10 bg-black"
                                 @pointerdown="startDraw($event)" @pointermove="moveDraw($event)" @pointerup="endDraw()" @pointercancel="drawing = null">
                                <img :src="selected.url" :alt="selected.name" class="pointer-events-none block max-h-[50vh] w-auto max-w-full" draggable="false">
                                <canvas x-ref="live" class="pointer-events-none absolute inset-0 size-full" aria-hidden="true"></canvas>
                                <template x-for="(s, i) in (selected.hotspots ?? [])" :key="i">
                                    <button type="button" class="absolute grid place-items-center rounded-full border-2 text-xs font-semibold text-white [text-shadow:0_0_3px_#000]"
                                            :class="[spot === i ? 'border-white bg-white/25' : 'border-accent bg-accent/20', s.role === 'kick' && 'border-dashed']"
                                            :style="box(s)" :aria-label="'Shape ' + spotLabel(i)" @pointerdown.stop @click.stop="spot = i" x-text="spotLabel(i)"></button>
                                </template>
                                <div x-show="drawing" x-cloak class="pointer-events-none absolute rounded-full border-2 border-dashed border-white bg-white/10" :style="draft"></div>
                            </div>
                            <p x-show="previewError" x-cloak class="text-xs text-red-400" x-text="'Preview error: ' + previewError" role="alert"></p>
                            <p class="text-xs text-muted" x-text="live ? 'Live: the picture reacts to the music playing.' : 'No music playing: play a track to see it react.'"></p>
                            <div class="flex flex-wrap items-center gap-2 text-sm" x-show="spot !== null && selected.hotspots?.[spot]" x-cloak>
                                <span class="text-muted">Selected:</span>
                                <button type="button" class="rounded-theme border border-white/10 px-2 py-1 hover:border-accent hover:text-accent" @click="setRole(spot, selected.hotspots[spot].role === 'kick' ? 'band' : 'kick')"
                                        x-text="selected.hotspots[spot]?.role === 'kick' ? 'Make it a band' : 'Make it the kick'"></button>
                                <button type="button" class="rounded-theme border border-white/10 px-2 py-1 text-red-400 hover:border-red-400" @click="removeSpot(spot)">Remove</button>
                            </div>
                            <button type="button" class="text-xs text-muted hover:text-accent hover:underline" x-show="selected.hotspots?.length" x-cloak @click="clearSpots()">Clear all shapes (back to automatic)</button>
                        </div>


                        <div class="space-y-2">
                            <details name="scene-group" open class="rounded-theme border border-white/10 [&[open]>summary]:border-b [&[open]>summary]:border-white/10">
                                <summary class="cursor-pointer select-none px-3 py-2 text-sm font-semibold hover:text-accent">Picture <span class="ml-1 text-xs font-normal text-muted">fit, drift, zoom, vignette</span></summary>
                                <div class="space-y-3 p-3">
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" class="size-4 accent-[var(--color-accent)]" :checked="hasBackdrop(selected)" @change="setBackdrop(selected, $event.target.checked)">
                                        Blurred picture on the sides (off: the picture alone on a plain background)
                                    </label>
                                    <label class="block text-sm sm:max-w-xs">Picture
                                        <select class="mt-1 block w-full rounded-theme border border-white/10 bg-bg px-2 py-1.5" :value="selected.settings.fit ?? 'auto'" @change="setFit(selected, $event.target.value)">
                                            <option value="auto">Whole picture (auto)</option>
                                            <option value="contain">Whole picture</option>
                                            <option value="cover">Fill and crop</option>
                                        </select>
                                    </label>
                                    <x-scene-sliders :keys="['drift', 'zoom', 'vignette']" />
                                </div>
                            </details>
                            <details name="scene-group"  class="rounded-theme border border-white/10 [&[open]>summary]:border-b [&[open]>summary]:border-white/10">
                                <summary class="cursor-pointer select-none px-3 py-2 text-sm font-semibold hover:text-accent">Colour effects <span class="ml-1 text-xs font-normal text-muted">colours turn, flash or wash with the music; all off until you move them</span></summary>
                                <div class="space-y-3 p-3">
                                    <div class="flex flex-wrap items-center gap-2 text-sm">
                                        <span class="text-muted">Start from:</span>
                                        <template x-for="name in presets" :key="name">
                                            <button type="button" class="rounded-theme border border-white/10 px-2 py-1 hover:border-accent hover:text-accent" @click="preset(selected, name)" x-text="name"></button>
                                        </template>
                                    </div>
                                    <x-scene-sliders :keys="['hueShift', 'hueFollow', 'hueSpin', 'hueKick', 'wash', 'brightPulse', 'invertKick', 'saturation']" />
                                </div>
                            </details>
                            <details name="scene-group"  class="rounded-theme border border-white/10 [&[open]>summary]:border-b [&[open]>summary]:border-white/10">
                                <summary class="cursor-pointer select-none px-3 py-2 text-sm font-semibold hover:text-accent">Light <span class="ml-1 text-xs font-normal text-muted">glow and how strongly the shapes answer</span></summary>
                                <div class="space-y-3 p-3">
                                    <label class="block text-sm sm:max-w-xs">Glow colour
                                        <select class="mt-1 block w-full rounded-theme border border-white/10 bg-bg px-2 py-1.5" :value="glowMode(selected).startsWith('#') ? 'auto' : glowMode(selected)" @change="setGlow(selected, $event.target.value)">
                                            <option value="auto">Automatic</option>
                                            <option value="picture">From the picture</option>
                                            <option value="accent">Theme accent</option>
                                            <option value="none">None</option>
                                        </select>
                                    </label>
                                    <x-scene-sliders :keys="['kickGain', 'bandGain', 'bloom', 'glowGain', 'dim']" />
                                </div>
                            </details>
                            <details name="scene-group"  class="rounded-theme border border-white/10 [&[open]>summary]:border-b [&[open]>summary]:border-white/10">
                                <summary class="cursor-pointer select-none px-3 py-2 text-sm font-semibold hover:text-accent">Response <span class="ml-1 text-xs font-normal text-muted">how fast the shapes react</span></summary>
                                <div class="space-y-3 p-3">
                                    <x-scene-sliders :keys="['attack', 'release', 'kickThreshold', 'kickCooldown']" />
                                </div>
                            </details>

                            <button type="button" class="rounded-theme border border-white/10 px-3 py-1.5 text-sm hover:border-accent hover:text-accent" @click="resetAll(selected)">Reset everything to auto</button>
                        </div>
                    </div>
                </div>
            </template>
        </section>
    </div>
</x-layouts.app>
