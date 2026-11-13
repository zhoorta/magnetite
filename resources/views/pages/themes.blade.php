<x-layouts.app title="Themes · Magnetite">
    <div x-data="themeStudio" class="space-y-5">
        {{-- The themes as a strip, so the preview and the settings below get the whole width. Click one to see it; "Use" applies it. --}}
        <section aria-label="Themes">
            <h1 class="mb-1 text-2xl font-semibold">Themes</h1>
            <p class="mb-4 max-w-3xl text-sm text-muted">
                A theme is one JSON object with the palette, corner radius, font and, optionally, settings for some of the meters.
                The meters that show are chosen per account and are not part of a theme. Themes you edit or import are kept in this browser.
            </p>
            <div class="flex gap-3 overflow-x-auto pb-2" role="list">
                <template x-for="t in $store.player.themes" :key="t.id">
                    <button type="button" class="w-44 shrink-0 rounded-theme border p-3 text-left" :class="[selected.id === t.id && ! draft ? 'border-accent' : 'border-white/10', draft && 'opacity-50']"
                            :style="{ background: t.colors.surface, color: t.colors.text, fontFamily: t.font, borderRadius: t.radius }"
                            :aria-pressed="selected.id === t.id" @click="select(t.id)" @dblclick="select(t.id); use()">
                        <div class="mb-2 flex items-center gap-2 font-semibold">
                            <span class="min-w-0 flex-1 truncate" x-text="t.name"></span>
                            <span x-show="t.id === $store.player.themeId" class="text-xs font-normal" :style="{ color: t.colors.accent }">in use</span>
                            <span x-show="t.custom" x-cloak class="text-xs font-normal" :style="{ color: t.colors.muted }">yours</span>
                        </div>
                        <div class="flex gap-1">
                            <template x-for="c in ['bg', 'accent', 'accent2', 'muted']" :key="c">
                                <span class="size-4 rounded-full border border-black/20" :style="{ background: t.colors[c] }"></span>
                            </template>
                        </div>
                    </button>
                </template>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
            {{-- Left: how it looks, and what you can do with the selected theme. --}}
            <section aria-label="Preview" class="min-w-0 space-y-4 lg:sticky lg:top-4 lg:self-start">
                <div>
                    <div class="overflow-hidden border p-4" :style="{ background: shown.colors.bg, color: shown.colors.text, fontFamily: shown.font, borderRadius: shown.radius, borderColor: shown.colors.muted + '55' }" aria-label="Preview of the theme">
                        <div class="flex gap-3 p-3" :style="{ background: shown.colors.surface, borderRadius: shown.radius }">
                            <div class="size-16 shrink-0" :style="{ background: `linear-gradient(135deg, ${shown.colors.accent}, ${shown.colors.accent2})`, borderRadius: shown.radius }"></div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-semibold" x-text="shown.name"></div>
                                <div class="truncate text-sm" :style="{ color: shown.colors.muted }">Artist · Album · 1983</div>
                                <div class="mt-3 h-1.5" :style="{ background: shown.colors.muted + '55', borderRadius: shown.radius }"><div class="h-full w-2/5" :style="{ background: shown.colors.accent, borderRadius: shown.radius }"></div></div>
                            </div>
                            <div class="flex shrink-0 items-end gap-0.5" aria-hidden="true">
                                <template x-for="(n, i) in [40, 70, 55, 90, 65, 35, 80, 50]" :key="i">
                                    <span class="w-1.5" :style="{ height: n * 0.5 + 'px', background: n > 75 ? shown.colors.accent2 : shown.colors.accent }"></span>
                                </template>
                            </div>
                        </div>
                        <ul class="mt-3 text-sm" aria-hidden="true">
                            <li class="flex gap-2 px-2 py-0.5" :style="{ background: shown.colors.accent, color: shown.colors.bg, borderRadius: shown.radius }"><span class="flex-1">A selected row</span><span>DR12</span></li>
                            <li class="flex gap-2 px-2 py-0.5"><span class="flex-1">Another row</span><span :style="{ color: shown.colors.muted }">DR9</span></li>
                            <li class="flex gap-2 px-2 py-0.5"><span class="flex-1">A third row</span><span :style="{ color: shown.colors.accent2 }">DR7</span></li>
                        </ul>
                        <div class="mt-3 flex gap-2 text-sm" aria-hidden="true">
                            <span class="px-3 py-1 font-medium" :style="{ background: shown.colors.accent, color: shown.colors.bg, borderRadius: shown.radius }">Play</span>
                            <span class="border px-3 py-1" :style="{ borderColor: shown.colors.muted + '88', borderRadius: shown.radius }">Shuffle</span>
                        </div>
                    </div>
                    <p class="mt-2 flex flex-wrap gap-x-4 text-xs" aria-label="Contrast">
                        <template x-for="c in contrasts" :key="c.label">
                            <span :class="c.ok ? 'text-muted' : 'text-amber-500'" x-text="c.label + ' ' + c.ratio + ':1' + (c.ok ? '' : ' (low)')"></span>
                        </template>
                    </p>
                </div>
                <div x-show="! draft" class="flex flex-wrap items-center gap-2 text-sm">
                    <button type="button" class="rounded-theme bg-accent px-3 py-1 font-medium text-bg disabled:opacity-50" :disabled="inUse" @click="use()" x-text="inUse ? 'In use' : 'Use this theme'"></button>
                    <button type="button" class="rounded-theme border border-white/15 px-3 py-1 hover:text-accent" @click="startEdit()" x-text="selected.custom ? 'Edit' : 'Edit a copy'"></button>
                    <button type="button" class="rounded-theme border border-white/15 px-3 py-1 hover:text-accent" @click="download()">Download JSON</button>
                    <button type="button" class="rounded-theme border border-white/15 px-3 py-1 hover:text-accent" @click="copy()">Copy JSON</button>
                    <button type="button" x-show="selected.custom" x-cloak class="rounded-theme border border-white/15 px-3 py-1 text-muted hover:text-red-500" @click="$store.dialog.confirm({ title: 'Delete this theme?', message: selected.name, confirmLabel: 'Delete', danger: true }).then((ok) => ok && remove())">Delete</button>
                </div>
                {{-- The meters: the same drawing code as the player, with this theme's colours and settings; play something to see it move. --}}
                <div class="mt-4">
                    <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                        <label for="meter-pick" class="text-muted">Meter</label>
                        <select id="meter-pick" x-model="meterId" class="rounded-theme border border-white/15 bg-surface px-2 py-1">
                            <template x-for="m in meterChoices" :key="m[0]"><option :value="m[0]" x-text="m[1]"></option></template>
                        </select>
                        <span class="text-xs text-muted" x-text="live ? 'live' : 'at rest: play something to see it move'"></span>
                    </div>
                    <canvas x-ref="meterCanvas" class="h-44 w-full border border-white/10" :style="{ background: shown.colors.bg, borderRadius: shown.radius }" aria-hidden="true"></canvas>
                    <p x-show="previewError" x-cloak class="mt-1 text-xs text-red-500" role="alert" x-text="previewError"></p>
                </div>
                <p x-show="draft" x-cloak class="text-xs text-muted">Editing: what you change on the right shows here at once. Save keeps it as a theme of this browser.</p>
            </section>

            {{-- Right: the settings, in groups. Only a copy (or a theme of yours) can be edited. --}}
            <section aria-label="Settings" class="min-w-0 space-y-3">
                <p x-show="! draft" class="text-sm text-muted">Pick a theme above, then <em>Edit</em> (a built-in is copied first) to change it here: colours, corners, fonts and the settings of each meter.</p>

                <form x-show="draft" x-cloak class="space-y-3 text-sm" @submit.prevent="saveEdit()">
                    <label class="flex items-center gap-2"><span class="w-16 text-muted">Name</span>
                        <input type="text" x-model="draft.name" maxlength="40" required class="min-w-0 flex-1 rounded-theme border border-white/15 bg-transparent px-2 py-1"></label>
                    <div class="space-y-2">
                    <details name="theme-group" open class="rounded-theme border border-white/10 [&[open]>summary]:border-b [&[open]>summary]:border-white/10">
                        <summary class="cursor-pointer select-none px-3 py-2 text-sm font-semibold hover:text-accent">Colours <span class="ml-1 text-xs font-normal text-muted">palette</span></summary>
                        <div class="space-y-3 p-3">
                            <div class="grid gap-2 sm:grid-cols-2">
                                <template x-for="key in colorKeys" :key="key">
                                    <label class="flex items-center gap-2"><span class="w-16 text-muted" x-text="key"></span>
                                        <input type="color" :value="draft.colors[key]" @input="setColor(key, $event.target.value)" class="h-7 w-9 shrink-0 cursor-pointer rounded-theme border border-white/15 bg-transparent p-0.5" :aria-label="key + ' colour'">
                                        <input type="text" :value="draft.colors[key]" @input="setColor(key, $event.target.value)" maxlength="7" spellcheck="false" class="w-20 rounded-theme border border-white/15 bg-transparent px-2 py-1 font-mono text-xs"></label>
                                </template>
                            </div>
                        </div>
                    </details>
                    <details name="theme-group" class="rounded-theme border border-white/10 [&[open]>summary]:border-b [&[open]>summary]:border-white/10">
                        <summary class="cursor-pointer select-none px-3 py-2 text-sm font-semibold hover:text-accent">Type and shape <span class="ml-1 text-xs font-normal text-muted">corners, fonts</span></summary>
                        <div class="space-y-3 p-3">
                            <label class="flex items-center gap-2"><span class="w-16 text-muted">Corners</span>
                                <select x-model="draft.radius" class="rounded-theme border border-white/15 bg-surface px-2 py-1">
                                    <template x-for="r in radii" :key="r"><option :value="r" x-text="r"></option></template>
                                    <option x-show="! radii.includes(draft.radius)" :value="draft.radius" x-text="draft.radius"></option>
                                </select></label>
                            <label class="flex items-center gap-2"><span class="w-16 text-muted">Font</span>
                                <select x-model="draft.font" class="min-w-0 rounded-theme border border-white/15 bg-surface px-2 py-1">
                                    <template x-for="g in fontGroups" :key="g[0]"><optgroup :label="g[0]"><template x-for="f in g[1]" :key="f[1]"><option :value="f[1]" x-text="f[0]"></option></template></optgroup></template>
                                    <option x-show="! fonts.some((f) => f[1] === draft.font)" :value="draft.font" x-text="draft.font"></option>
                                </select></label>
                            <label class="flex items-center gap-2"><span class="w-16 text-muted">Meters</span>
                                <select x-model="draft.meterFont" class="min-w-0 rounded-theme border border-white/15 bg-surface px-2 py-1" aria-label="Font of the text on the meters">
                                    <option value="">Same as the font</option>
                                    <template x-for="g in fontGroups" :key="g[0]"><optgroup :label="g[0]"><template x-for="f in g[1]" :key="f[1]"><option :value="f[1]" x-text="f[0]"></option></template></optgroup></template>
                                    <option x-show="draft.meterFont && ! fonts.some((f) => f[1] === draft.meterFont)" :value="draft.meterFont" x-text="draft.meterFont"></option>
                                </select></label>
                        </div>
                    </details>
                        <details name="theme-group" class="rounded-theme border border-white/10 [&[open]>summary]:border-b [&[open]>summary]:border-white/10">
                            <summary class="cursor-pointer select-none px-3 py-2 text-sm font-semibold hover:text-accent">Meter settings <span class="ml-1 text-xs font-normal text-muted" x-text="meterLabel + ' (pick the meter on the left)'"></span></summary>
                            <div class="p-3">
                                <fieldset class="space-y-2">
                                    <legend class="sr-only">Settings of the meter</legend>
                                    <template x-for="item in schema" :key="meterId + item.key">
                                        <div class="flex items-center gap-2">
                                            <span class="w-36 shrink-0 text-muted" x-text="item.label"></span>
                                            <template x-if="item.type === 'number'">
                                                <input type="range" :min="item.min" :max="item.max" :step="item.step" :value="paramValue(item)" @input="setParam(item.key, $event.target.value)" class="min-w-0 flex-1 accent-accent" :aria-label="item.label">
                                            </template>
                                            <template x-if="item.type === 'color'">
                                                <input type="color" :value="colorOf(item)" @input="setParam(item.key, $event.target.value)" class="h-7 w-9 shrink-0 cursor-pointer rounded-theme border border-white/15 bg-transparent p-0.5" :aria-label="item.label + ' colour'">
                                            </template>
                                            <span class="w-24 shrink-0 font-mono text-xs" :class="isSet(item) ? '' : 'text-muted'" x-text="shownOf(item)"></span>
                                            <button type="button" x-show="isSet(item)" x-cloak class="text-muted hover:text-accent" @click="resetParam(item.key)" :aria-label="'Reset ' + item.label" title="Back to the default">↺</button>
                                        </div>
                                    </template>
                                </fieldset>
                            </div>
                        </details>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="rounded-theme bg-accent px-3 py-1 font-medium text-bg" x-text="draft.custom ? 'Save' : 'Save as a new theme'"></button>
                        <button type="button" class="rounded-theme border border-white/15 px-3 py-1 hover:text-accent" @click="cancelEdit()">Cancel</button>
                    </div>
                </form>

                <p x-show="note" x-cloak class="mt-3 text-sm text-accent2" role="status" x-text="note"></p>

                {{-- Import: a theme file or pasted JSON. --}}
                <div class="mt-6 border-t border-white/10 pt-4 text-sm">
                    <button type="button" class="text-muted hover:text-accent" :aria-expanded="importOpen" @click="importOpen = ! importOpen"><span x-text="importOpen ? '▾' : '▸'"></span> Import a theme</button>
                    <div x-show="importOpen" x-cloak class="mt-3 space-y-2">
                        <textarea x-model="importText" rows="8" spellcheck="false" placeholder='{ "name": "My theme", "colors": { "bg": "#000000", "surface": "#111111", "text": "#eeeeee", "muted": "#888888", "accent": "#ff5a5f", "accent2": "#ffb86b" }, "radius": "0.5rem", "font": "Georgia, serif" }'
                                  class="w-full rounded-theme border border-white/15 bg-transparent px-2 py-1 font-mono text-xs"></textarea>
                        <p x-show="importError" x-cloak class="text-red-500" role="alert" x-text="importError"></p>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" class="rounded-theme bg-accent px-3 py-1 font-medium text-bg disabled:opacity-50" :disabled="! importText.trim()" @click="doImport()">Import</button>
                            <label class="cursor-pointer rounded-theme border border-white/15 px-3 py-1 hover:text-accent">Load a file<input type="file" accept=".json,application/json" class="sr-only" @change="loadFile($event)"></label>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</x-layouts.app>
