import { Livewire, Alpine } from '../../../vendor/livewire/livewire/dist/livewire.esm';
import { AudioEngine, SILENCE } from './engine';
import { visualizers } from './visualizers';
import { layout, MAX_PANELS } from './layout';
import themes from './themes.json';
import uploader from './upload';
import { send } from './http';
import { playlistCreate, playlistPicker, playlistView, sharedPlaylistView } from './playlists';

const STORAGE_KEY = 'magnetite.theme';
const VARS_KEY = 'magnetite.vars';
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

function applyTheme(theme) {
    const root = document.documentElement;
    for (const [k, v] of Object.entries(theme.colors)) {
        root.style.setProperty(`--c-${k}`, v);
    }
    root.style.setProperty('--radius', theme.radius);
    root.style.setProperty('--font-ui', theme.font);
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
        font: cs.getPropertyValue('--font-ui').trim() || 'sans-serif',
    };
}

Alpine.data('uploader', uploader);
Alpine.data('playlistPicker', playlistPicker);
Alpine.data('playlistView', playlistView);
Alpine.data('sharedPlaylistView', sharedPlaylistView);
Alpine.data('playlistCreate', playlistCreate);

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
    themeId: themes[0].id,
    panels: [], // the visible meters: [{ id, params }], at most MAX_PANELS; the person's own list, styled by the theme
    meterIds: DEFAULT_METERS, // the same list as ids only; this is what is saved for a signed-in user
    signingOut: false,
    savedAt: 0, // performance.now() of the last save of the playback state
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

        audio.addEventListener('timeupdate', () => {
            this.time = audio.currentTime;

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
            s.setItem(VARS_KEY, JSON.stringify({ colors: theme.colors, radius: theme.radius, font: theme.font }));
        });
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

    /** Swap the meter in one panel; the new one starts with its own default parameters. */
    setPanel(i, id) {
        this.setMeters(this.meterIds.map((m, j) => (j === i ? id : m)));
    },

    addPanel() {
        // With no meters, bring back the last ones, or the default.
        if (this.panels.length === 0) {
            this.setMeters(this.lastMeters ?? DEFAULT_METERS);

            return;
        }

        const free = this.visualizerList.find((v) => !this.meterIds.includes(v.id));
        if (free && this.panels.length < MAX_PANELS) this.setMeters([...this.meterIds, free.id]);
    },

    removePanel(i) {
        if (this.meterIds.length === 1) this.lastMeters = this.meterIds;

        this.setMeters(this.meterIds.filter((_, j) => j !== i));
    },

    /** Visualizers a panel can switch to: its own plus any not already shown in another panel. */
    optionsFor(i) {
        return this.visualizerList.filter((v) => v.id === this.panels[i].id || !this.panels.some((p) => p.id === v.id));
    },

    get canAddPanel() {
        return this.panels.length === 0 || (this.panels.length < MAX_PANELS && this.panels.length < this.visualizerList.length);
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
        if (!key || !this.current || this.signingOut) return;

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
        if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey) return;

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
        else if (/^[0-9]$/.test(key) && this.duration) act(() => this.seek(Number(key) / 10));
        else if (key === 'n' || key === 'N') act(() => this.next());
        else if (key === 'p' || key === 'P') act(() => this.prev());
        else if (key === 'f' || key === 'F') act(() => (this.expanded = !this.expanded));
        else if (key === 'm' || key === 'M') act(() => this.toggleMeters());
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
            const cells = layout(panels.length, w, h);
            for (let i = 0; i < panels.length; i++) {
                const { x, y, w: cw, h: ch } = cells[i];
                g.save();
                g.beginPath();
                g.rect(x, y, cw, ch);
                g.clip();
                g.translate(x, y);
                visualizers[panels[i].id].draw(g, sample, { w: cw, h: ch, colors: this.colors, params: panels[i].params, dt });
                g.restore();
            }

            // Dev probe for the frame budget: set `window.__magnetitePerf = []` in the console to collect timings (ms).
            window.__magnetitePerf?.push({ viz: panels.map((p) => p.id).join('+'), sample: t1 - t0, draw: performance.now() - t1, dt });
        };

        frame();
    },

    fmt(s) {
        s = Math.floor(s || 0);
        return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
    },

    visualizerList: Object.values(visualizers).map(({ id, label }) => ({ id, label })),
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
