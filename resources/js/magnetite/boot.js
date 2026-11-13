import { Livewire, Alpine } from '../../../vendor/livewire/livewire/dist/livewire.esm';
import { AudioEngine, SILENCE } from './engine';
import { visualizers, meterList } from './visualizers';
import { meterGroups } from './visualizers/menu';
import { layout, MAX_PANELS } from './layout';
import builtinThemes from './themes.json';
import { validateTheme } from './themes';
import scenesPage from './scenes-page';
import themeStudio from './themes-page';
import { uploadsStore } from './uploads';
import storage from './storage';
import { send } from './http';
import { scenesStore } from './scenes';
import { shouldPrefetch, prefetchUrl } from './prefetch';
import { loadFont } from './font-load';
import dense from './dense/component';
import { uiStore } from './dense/ui';
import { dockInfo, trackPosition } from './dense/info';
import { playlistCreate, playlistPicker, playlistStore, playlistView, sharedPlaylistView } from './playlists';

const STORAGE_KEY = 'magnetite.theme';
const DEFAULT_THEME = 'terminal'; // keep the :root fallbacks in app.css in step
const VARS_KEY = 'magnetite.vars';
const CUSTOM_KEY = 'magnetite.themes'; // the themes this browser added (imported or edited)
const MAX_SAVED_QUEUE = 500; // tracks kept for the restore after a reload
/** A 404 means the saved track is gone for good; other failures (offline, server busy) keep the saved state for next time. */
function forgetIf(status, forget) {
    if (status === 404) forget();
}

const DEFAULT_METERS = ['vu-wide']; // what a person sees until they choose their own

function safeStorage(fn) {
    try {
        return fn(window.localStorage);
    } catch {
        return null;
    }
}

/** The built-in themes, then the ones saved in this browser (checked again: the file can be edited by hand). */
function loadThemes() {
    const list = [...builtinThemes];
    const saved = safeStorage((s) => JSON.parse(s.getItem(CUSTOM_KEY) ?? '[]'));
    for (const raw of Array.isArray(saved) ? saved : []) {
        const { theme } = validateTheme(raw, list.map((t) => t.id));
        if (theme) list.push({ ...theme, custom: true });
    }

    return list;
}

const themes = loadThemes();

function applyTheme(theme) {
    const root = document.documentElement;
    for (const [k, v] of Object.entries(theme.colors)) {
        root.style.setProperty(`--c-${k}`, v);
    }
    root.style.setProperty('--radius', theme.radius);
    root.style.setProperty('--font-ui', theme.font);
    // The meters' own font, when the theme has one; otherwise the var is absent and they follow --font-ui.
    if (theme.meterFont) root.style.setProperty('--font-meter', theme.meterFont);
    else root.style.removeProperty('--font-meter');
    loadFont(theme.meterFont || theme.font);
    root.dataset.theme = theme.id;
}

function readColors() {
    const cs = getComputedStyle(document.documentElement);
    const get = (n) => cs.getPropertyValue(`--c-${n}`).trim();
    return {
        bg: get('bg'),
        surface: get('surface'),
        text: get('text'),
        muted: get('muted'),
        accent: get('accent'),
        accent2: get('accent2'),
        // What the meters write with: the theme's meter font, else its font.
        font: cs.getPropertyValue('--font-meter').trim() || cs.getPropertyValue('--font-ui').trim() || 'sans-serif',
    };
}

Alpine.data('dense', dense);
Alpine.data('themeStudio', themeStudio);
Alpine.data('scenesPage', scenesPage);
Alpine.data('storage', storage);
Alpine.store('playlists', playlistStore);
Alpine.data('playlistPicker', playlistPicker);
Alpine.data('playlistView', playlistView);
Alpine.data('sharedPlaylistView', sharedPlaylistView);
Alpine.data('playlistCreate', playlistCreate);

/**
 * The app's own confirm and notice boxes, in place of the browser's. `await $store.dialog.confirm({ title, message, confirmLabel, danger })`
 * answers true or false; `await $store.dialog.notice(message)` waits for OK. The markup is in the layout, next to the player.
 */
Alpine.store('dialog', {
    open: false,
    title: '',
    message: '',
    confirmLabel: 'OK',
    danger: false,
    asking: false, // false: a notice with one button
    resolver: null,

    show(options) {
        this.resolver?.(false); // a box still open is answered "no" by the next one
        Object.assign(this, { title: '', message: '', confirmLabel: 'OK', danger: false, asking: false }, options, { open: true });

        // Focus the safe button: Cancel for something destructive, else the main one.
        setTimeout(() => document.querySelector(this.danger && this.asking ? '[data-dialog-cancel]' : '[data-dialog-ok]')?.focus(), 30);

        return new Promise((resolve) => (this.resolver = resolve));
    },

    confirm(options) {
        return this.show({ ...options, asking: true });
    },

    notice(message, title = '') {
        return this.show({ title, message });
    },

    answer(value) {
        this.open = false;
        const resolve = this.resolver;
        this.resolver = null;
        resolve?.(value);
    },
});

/** The user's interface choices (info level, meter tint). */
Alpine.store('scenes', scenesStore(window.magnetite?.scenes, () => Alpine.store('player')));
Alpine.store('uploads', uploadsStore(window.magnetite?.uploads ?? null));
Alpine.store('ui', uiStore(window.magnetite?.ui, !!window.magnetite?.signedIn));

Alpine.store('player', {
    // state
    queue: [],
    index: -1,
    playing: false,
    time: 0,
    duration: 0,
    expanded: false,
    helpOpen: false, // the keyboard shortcut list
    error: null,
    audioState: 'idle', // AudioContext state once the graph exists: running, suspended, interrupted
    failedIndex: null,
    themes,
    themeId: DEFAULT_THEME, // what a first-time visitor sees; a saved choice wins
    panels: [], // the visible meters: [{ id, params }], at most MAX_PANELS; the person's own list, styled by the theme
    meterIds: DEFAULT_METERS, // the same list as ids only; this is what is saved for a signed-in user
    signingOut: false,
    prefetched: null, // id of the track the server was last asked to fetch ahead (Ferrite libraries), so it is asked once
    savedAt: 0, // performance.now() of the last save of the playback state
    reveal: null, // { album_id, id }: what the library should show when it opens (set by showInLibrary)
    /** Clicking the playing track or album in the player: the library opens on its album, with the tracks pane on that track. */
    showInLibrary() {
        const c = this.current;
        if (!c?.album_id) return;
        this.reveal = { album_id: c.album_id, id: c.id };
        if (window.location.pathname === '/library') window.dispatchEvent(new CustomEvent('magnetite:reveal'));
        else Livewire.navigate('/library');
    },
    libraryKeys: false, // the library page is open (the ? list then shows its keys)
    activePanel: 0, // the meter the m keys change
    note: '', // a few words about what a key just did, shown in the bar
    lastMeters: null, // what was showing before the meters were switched off, for the M key and the Meters button

    // internals
    engine: null,
    canvas: null,
    colors: null,
    needs: [], // union of the inputs the visible panels read, so the engine computes nothing else
    raf: null,

    get current() {
        return this.queue[this.index] ?? null;
    },

    mount(audio, canvas) {
        if (this.engine) return;

        this.engine = new AudioEngine(audio);
        this.engine.onState = (state) => (this.audioState = state);

        // Browsers keep audio suspended until the user interacts; any click or key press is enough to unlock it.
        const unlock = () => this.engine.unlock();
        for (const type of ['pointerdown', 'keydown']) {
            document.addEventListener(type, unlock, { passive: true });
        }
        this.canvas = canvas;

        // The height of the docked player, for the pages that fit themselves above it (--dock-h); in full screen it is the whole window, so not then.
        const dock = canvas.closest('.fixed');
        if (dock) {
            new ResizeObserver(() => {
                if (!this.expanded) document.documentElement.style.setProperty('--dock-h', `${dock.offsetHeight}px`);
            }).observe(dock);
        }

        audio.addEventListener('timeupdate', () => {
            this.time = audio.currentTime;
            this.prefetchNext(audio);

            // The position changes all the time; a few seconds off after a reload does not matter.
            if (performance.now() - this.savedAt > 5000) this.savePlayback();
        });
        audio.addEventListener('durationchange', () => (this.duration = audio.duration || 0));
        audio.addEventListener('play', () => (this.playing = true));
        audio.addEventListener('pause', () => {
            this.playing = false;
            this.savePlayback();
        });
        audio.addEventListener('ended', () => this.next());
        // Chrome/Firefox cannot decode everything (e.g. ALAC in .m4a). Say so and move on.
        audio.addEventListener('error', () => this.fail());

        // A signed-in user's saved list comes with the page (see the head partial); null means they never chose.
        const mine = window.magnetite?.meters;
        this.meterIds = Array.isArray(mine) ? mine : DEFAULT_METERS;

        const saved = safeStorage((s) => s.getItem(STORAGE_KEY));
        this.setTheme(themes.find((t) => t.id === saved)?.id ?? this.themeId);

        document.addEventListener('keydown', (e) => this.onKey(e));

        // Leaving or reloading the page: remember where we were. Signing out forgets it.
        window.addEventListener('pagehide', () => this.savePlayback());
        document.addEventListener('visibilitychange', () => document.hidden && this.savePlayback());
        document.addEventListener('submit', (e) => e.target.action?.endsWith('/logout') && this.forgetPlayback(true));
        this.restorePlayback();
        // A page asked for a track to be ready before the player existed (see preload): load it now, paused.
        if (this.current && !this.engine.el.src) this.engine.load(this.current.url, this.time);

        new ResizeObserver(() => this.resize()).observe(canvas);
        this.loop();
    },

    setTheme(id) {
        const theme = themes.find((t) => t.id === id);
        if (!theme) return;

        this.themeId = id;
        this.setMeters(this.meterIds, false); // same meters, styled by the new theme
        applyTheme(theme);
        this.colors = readColors();
        safeStorage((s) => {
            s.setItem(STORAGE_KEY, id);
            // Read by the inline script in the layout's <head> to avoid a flash on full reloads.
            s.setItem(VARS_KEY, JSON.stringify({ colors: theme.colors, radius: theme.radius, font: theme.font, meterFont: theme.meterFont ?? null }));
        });
    },

    /** Add a checked theme of this browser (see themes.js), and keep it. */
    addTheme(theme) {
        this.themes.push({ ...theme, custom: true });
        this.saveCustom();
    },

    /** Replace a custom theme by its id; when it is the one in use, show the change. */
    replaceTheme(theme) {
        const i = this.themes.findIndex((t) => t.id === theme.id && t.custom);
        if (i < 0) return;
        this.themes.splice(i, 1, { ...theme, custom: true });
        this.saveCustom();
        if (this.themeId === theme.id) this.setTheme(theme.id);
    },

    /** Delete a custom theme; the one in use falls back to the default. */
    removeTheme(id) {
        const i = this.themes.findIndex((t) => t.id === id && t.custom);
        if (i < 0) return;
        this.themes.splice(i, 1);
        this.saveCustom();
        if (this.themeId === id) this.setTheme(DEFAULT_THEME);
    },

    saveCustom() {
        safeStorage((s) => s.setItem(CUSTOM_KEY, JSON.stringify(this.themes.filter((t) => t.custom).map(({ custom, ...t }) => t))));
    },

    /** The meters this theme gives a look to (palette tweaks for one meter); it does not choose which meters show. */
    styles(theme) {
        return theme.visualizers ?? (theme.visualizer ? [theme.visualizer] : []);
    },

    /** Show these meters (known ones only, no repeats, at most MAX_PANELS), and remember them for a signed-in user. */
    setMeters(ids, save = true) {
        const theme = themes.find((t) => t.id === this.themeId);
        const wanted = [...new Set(ids)].filter((id) => visualizers[id]).slice(0, MAX_PANELS);

        this.meterIds = wanted;
        this.panels = wanted.map((id) => ({ id, params: this.styles(theme).find((v) => v.id === id)?.params ?? {} }));
        this.needs = [...new Set(this.panels.flatMap((p) => visualizers[p.id].needs))];

        if (save) this.saveMeters();
    },

    /** Saved on the server for the signed-in user, so the same meters come back after a reload or a new login. */
    saveMeters() {
        if (!window.magnetite?.signedIn) return;

        clearTimeout(this.saving);
        this.saving = setTimeout(() => send('PUT', '/settings/meters', { ids: this.meterIds }), 400);
    },

    addPanel() {
        // With no meters, bring back the last ones, or the default.
        if (this.panels.length === 0) {
            this.setMeters(this.lastMeters ?? DEFAULT_METERS);
            this.activePanel = 0;

            return;
        }

        const free = this.visualizerList.find((v) => !this.meterIds.includes(v.id));
        if (free && this.panels.length < MAX_PANELS) {
            this.setMeters([...this.meterIds, free.id]);
            this.activePanel = this.panels.length - 1;
            this.say(`meter ${this.panels.length}: ${free.label}`);
        } else if (this.panels.length >= MAX_PANELS) this.say(`up to ${MAX_PANELS} meters`);
    },

    /** A few words in the bar, gone after a moment. */
    say(text) {
        this.note = text;
        clearTimeout(this.noteTimer);
        this.noteTimer = setTimeout(() => (this.note = ''), 2500);
    },

    label(id) {
        return this.visualizerList.find((v) => v.id === id)?.label ?? id;
    },

    /** m / M: the active panel shows the next (or previous) meter that is not on another panel; with no meters, m brings one back. */
    cycleMeter(d) {
        if (this.panels.length === 0) return this.addPanel();

        const others = this.meterIds.filter((_, i) => i !== this.activePanel);
        const free = this.visualizerList.map((v) => v.id).filter((id) => !others.includes(id));
        const at = free.indexOf(this.meterIds[this.activePanel]);
        const next = free[(at + d + free.length) % free.length];
        const list = [...this.meterIds];
        list[this.activePanel] = next;
        this.setMeters(list);
        this.say(`meter ${this.activePanel + 1}: ${this.label(next)}`);
    },

    /** - removes the active meter; the last one goes and the cover takes the room. */
    removePanel() {
        if (this.panels.length === 0) return this.say('no meters to remove');

        const gone = this.meterIds[this.activePanel];
        if (this.meterIds.length === 1) this.lastMeters = this.meterIds;
        this.setMeters(this.meterIds.filter((_, i) => i !== this.activePanel));
        this.activePanel = Math.max(0, Math.min(this.activePanel, this.panels.length - 1));
        this.say(this.panels.length ? `removed ${this.label(gone)}` : 'no meters: m brings one back');
    },

    /** [ and ] (Tab in full screen): choose which meter the m keys change. */
    pickPanel(i) {
        if (this.panels.length < 2) return;
        this.activePanel = (i + this.panels.length) % this.panels.length;
        this.say(`meter ${this.activePanel + 1}: ${this.label(this.meterIds[this.activePanel])}`);
    },

    /** t / T: the next or previous theme. */
    cycleTheme(d) {
        const i = themes.findIndex((t) => t.id === this.themeId);
        const next = themes[(i + d + themes.length) % themes.length];
        this.setTheme(next.id);
        this.say(`theme: ${next.name}`);
    },

    hasMeter(id) {
        return this.meterIds.includes(id);
    },

    /** The Meters menu: switch one meter on or off. A new one is added last; at MAX_PANELS only the shown ones can be switched off. */
    toggleMeter(id) {
        if (this.hasMeter(id)) {
            if (this.meterIds.length === 1) this.lastMeters = this.meterIds;
            this.setMeters(this.meterIds.filter((m) => m !== id));
        } else if (this.meterIds.length < MAX_PANELS) {
            this.setMeters([...this.meterIds, id]);
        }
    },

    get meterLimitReached() {
        return this.meterIds.length >= MAX_PANELS;
    },

    get maxPanels() {
        return MAX_PANELS;
    },

    setQueue(tracks, index = 0) {
        this.queue = tracks;
        return this.playAt(index);
    },

    async playAt(i) {
        if (!this.queue[i]) return;

        this.index = i;
        this.error = null;
        this.failedIndex = null;
        this.time = 0;
        this.savePlayback(0); // the element still holds the old track's position

        try {
            await this.engine.play(this.queue[i].url);
        } catch (e) {
            // play() rejects when the source is unsupported; an interrupted load (user picked another track) is not an error.
            if (e.name === 'NotSupportedError') this.fail();
        }
    },

    /** Ask the server to fetch the next track into its cache while this one plays. Fire and forget: nothing depends on it. */
    prefetchNext(audio) {
        const next = this.queue[this.index + 1];

        if (!shouldPrefetch({ playing: this.playing, time: audio.currentTime, duration: audio.duration, next, already: this.prefetched })) return;

        this.prefetched = next.id;
        send('POST', prefetchUrl(next)).catch(() => {});
    },

    fail() {
        // Both the audio 'error' event and the play() rejection can report the same failure.
        if (this.failedIndex === this.index) return;
        this.failedIndex = this.index;

        const title = this.current?.title ?? 'this track';
        this.error = `Can't play "${title}": the browser can't decode this format.`;
        this.playing = false;

        // Skip ahead so a run of unplayable files doesn't stall the queue, but stop at the end.
        if (this.index + 1 < this.queue.length) setTimeout(() => this.next(), 1500);
    },

    toggle() {
        if (!this.current) return;

        this.playing ? this.engine.pause() : this.engine.play();
    },

    next() {
        if (this.index + 1 < this.queue.length) this.playAt(this.index + 1);
    },

    prev() {
        if (this.engine.el.currentTime > 3 || this.index === 0) {
            this.engine.el.currentTime = 0;
        } else {
            this.playAt(this.index - 1);
        }
    },

    /** Where the playback state is kept: in this browser, for this user only. Null for guests. */
    get playbackKey() {
        const id = window.magnetite?.userId;

        return id ? `magnetite.playback.${id}` : null;
    },

    /** The queue, the current track and the position, so a reload (or closing the tab) can carry on from here. */
    savePlayback(time = this.engine?.el.currentTime ?? this.time) {
        const key = this.playbackKey;
        this.savedAt = performance.now();
        if (!key || !this.current || this.current.demo || this.signingOut) return; // the demo music is not a queue to come back to

        // A very long queue keeps the part around the current track.
        const from = Math.max(0, Math.min(this.index - MAX_SAVED_QUEUE / 2, this.queue.length - MAX_SAVED_QUEUE));
        const queue = this.queue.slice(from, from + MAX_SAVED_QUEUE);

        safeStorage((s) => s.setItem(key, JSON.stringify({ queue, index: this.index - from, time })));
    },

    /** `signingOut`: the page is about to unload and would save again on the way out; do not. */
    forgetPlayback(signingOut = false) {
        this.signingOut = signingOut;

        const key = this.playbackKey;
        key && safeStorage((s) => s.removeItem(key));
    },

    /**
     * Have `tracks[0]` ready to play, paused (the start page does this with its demo music). Nothing happens when something
     * is already loaded or this person has a saved queue to come back to, so it never replaces what they were doing, and it
     * never starts sound: the first Play does.
     */
    preload(tracks) {
        const key = this.playbackKey;
        if (this.current || !tracks?.length || (key && safeStorage((s) => s.getItem(key)))) return;

        this.queue = [...tracks];
        this.index = 0;
        this.time = 0;
        this.duration = tracks[0].duration ?? 0;
        if (this.engine) this.engine.load(tracks[0].url);
    },

    /** Load the saved track, paused, at the saved position. Browsers do not allow sound to start by itself, so it waits for Play. */
    async restorePlayback() {
        const key = this.playbackKey;
        const raw = key && safeStorage((s) => s.getItem(key));
        if (!raw || this.current) return;

        try {
            const saved = JSON.parse(raw);
            // Addresses are made relative to this site, in case it is now served from another host or port.
            const local = (u) => (typeof u === 'string' ? ((x) => x.pathname + x.search)(new URL(u, location.href)) : null);
            const queue = saved.queue
                .filter((t) => t && typeof t.title === 'string' && typeof t.url === 'string')
                .map((t) => ({ ...t, url: local(t.url), cover: t.cover ? local(t.cover) : null }));
            const track = queue[saved.index];
            if (!track) return;

            // The track may have been removed or unshared since: then there is nothing to carry on with.
            const check = await fetch(track.url, { method: 'HEAD', credentials: 'same-origin' });
            if (!check.ok || this.current) return forgetIf(check.status, () => this.forgetPlayback());

            this.queue = queue;
            this.index = saved.index;
            this.time = Number(saved.time) || 0;
            this.duration = track.duration ?? 0;
            this.engine.load(track.url, this.time);
        } catch {
            this.forgetPlayback();
        }
    },

    seekBy(seconds) {
        const el = this.engine.el;
        if (this.duration) el.currentTime = Math.max(0, Math.min(this.duration, el.currentTime + seconds));
    },

    /** All meters off, or the last ones back; the same switch as the buttons in the player. */
    toggleMeters() {
        if (this.panels.length === 0) return this.addPanel();

        this.lastMeters = this.meterIds;
        this.setMeters([]);
    },

    /**
     * Keyboard shortcuts. They stay out of the way of typing, of the browser's own shortcuts, and of buttons and links
     * (space on a focused button must press it, not toggle playback).
     */
    onKey(e) {
        // An open dialog has the keyboard (it handles Esc and Tab itself).
        if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey || Alpine.store('dialog').open) return;

        const t = e.target;
        if (t instanceof HTMLElement && (t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName))) return;

        const onControl = t instanceof HTMLElement && ['BUTTON', 'A', 'SUMMARY'].includes(t.tagName);
        const key = e.key;
        const act = (fn) => {
            e.preventDefault();
            fn();
        };

        if (key === 'Escape') {
            if (this.helpOpen) act(() => (this.helpOpen = false));
            else if (this.expanded) act(() => (this.expanded = false));
        } else if (key === ' ' && !onControl) act(() => this.toggle());
        else if (key === 'ArrowRight') act(() => this.seekBy(e.shiftKey ? 30 : 5));
        else if (key === 'ArrowLeft') act(() => this.seekBy(e.shiftKey ? -30 : -5));
        else if (key === ',') act(() => this.seekBy(-10)); // as in the terminal client
        else if (key === '.') act(() => this.seekBy(10));
        else if (/^[0-9]$/.test(key) && this.duration) act(() => this.seek(Number(key) / 10));
        else if (key === 'n') act(() => this.next());
        else if (key === 'p') act(() => this.prev());
        else if (key === 'I') act(() => Alpine.store('ui').cycleInfo()); // how much the lists and the player say about the files
        else if (key === 'f' || key === 'F') act(() => (this.expanded = !this.expanded));
        else if (key === 'm') act(() => this.cycleMeter(1)); // the terminal client's keys: m / M change the meter, + and - the number of meters
        else if (key === 'M') act(() => this.cycleMeter(-1));
        else if (key === '+' || key === '=') act(() => this.addPanel());
        else if (key === '-') act(() => this.removePanel());
        else if (key === '[') act(() => this.pickPanel(this.activePanel - 1));
        else if (key === ']') act(() => this.pickPanel(this.activePanel + 1));
        else if (key === 'Tab' && this.expanded && this.panels.length > 1) act(() => this.pickPanel(this.activePanel + 1));
        else if (key === 'c') act(() => Alpine.store('ui').toggleTint());
        else if (key === 't') act(() => this.cycleTheme(1));
        else if (key === 'T') act(() => this.cycleTheme(-1));
        else if (key === '?') act(() => (this.helpOpen = !this.helpOpen));
    },

    seek(fraction) {
        if (this.duration) this.engine.el.currentTime = fraction * this.duration;
    },

    resize() {
        const c = this.canvas;
        const dpr = window.devicePixelRatio || 1;
        c.width = Math.max(1, Math.floor(c.clientWidth * dpr));
        c.height = Math.max(1, Math.floor(c.clientHeight * dpr));
    },

    loop() {
        const g = this.canvas.getContext('2d');

        let last = performance.now();

        const frame = (now = performance.now()) => {
            this.raf = requestAnimationFrame(frame);

            // No point drawing in a hidden tab, or when there are no meters (the canvas is hidden too).
            if (document.hidden || this.panels.length === 0) {
                last = now;

                return;
            }

            // Seconds since the previous frame, capped so a stalled tab does not make meters jump.
            const dt = Math.min(0.1, (now - last) / 1000);
            last = now;

            const { width: w, height: h } = this.canvas;
            g.clearRect(0, 0, w, h);

            // Before the first gesture there is no audio graph yet: draw the idle state.
            const t0 = performance.now();
            const sample = this.engine.sample(this.needs) ?? SILENCE;
            const t1 = performance.now();

            // Each panel draws into its own clipped cell, so a visualizer only ever sees its own w and h.
            const panels = this.panels;
            const colors = Alpine.store('ui').shade(this.colors, this.current?.cover ?? null, dt); // the theme's, or the cover's with the tint on
            const cells = layout(panels.length, w, h);
            for (let i = 0; i < panels.length; i++) {
                const { x, y, w: cw, h: ch } = cells[i];
                g.save();
                g.beginPath();
                g.rect(x, y, cw, ch);
                g.clip();
                g.translate(x, y);
                visualizers[panels[i].id].draw(g, sample, { w: cw, h: ch, colors, params: panels[i].params, dt, progress: this.duration ? this.time / this.duration : 0, track: this.current });
                g.restore();
            }

            // Dev probe for the frame budget: set `window.__magnetitePerf = []` in the console to collect timings (ms).
            window.__magnetitePerf?.push({ viz: panels.map((p) => p.id).join('+'), sample: t1 - t0, draw: performance.now() - t1, dt });
        };

        frame();
    },

    /** What the bar says under the title about the playing file, at the user's info level (see dense/info.js). */
    get dock() {
        return dockInfo(this.current, Alpine.store('ui').info);
    },

    get position() {
        return trackPosition(this.current, this.queue, this.index);
    },

    fmt(s) {
        s = Math.floor(s || 0);
        return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
    },

    // "12 tracks · 48 min", or "1 h 12 min" from an hour up.
    summary(tracks) {
        const min = Math.round(tracks.reduce((sum, t) => sum + (t.duration || 0), 0) / 60);
        const time = min >= 60 ? `${Math.floor(min / 60)} h ${String(min % 60).padStart(2, '0')} min` : `${min} min`;
        return `${tracks.length} ${tracks.length === 1 ? 'track' : 'tracks'} · ${time}`;
    },

    visualizerObj: (id) => visualizers[id],
    visualizerList: meterList(),

    /** The poster meters and the scenes, as groups for submenus in the meter menu. */
    get visualizerGroups() {
        return meterGroups(this.visualizerList);
    },
});

// wire:navigate swaps the document's <html> attributes, which wipes the inline CSS variables and
// data-theme set by applyTheme(). The persisted player keeps its state, so re-apply the theme.
document.addEventListener('livewire:navigated', () => {
    const store = Alpine.store('player');
    if (!store.engine) return;

    applyTheme(themes.find((t) => t.id === store.themeId));
    store.colors = readColors();
});

Livewire.start();
