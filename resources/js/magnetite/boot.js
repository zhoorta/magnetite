import { Livewire, Alpine } from '../../../vendor/livewire/livewire/dist/livewire.esm';
import { AudioEngine, SILENCE } from './engine';
import { visualizers } from './visualizers';
import { layout, MAX_PANELS } from './layout';
import themes from './themes.json';
import uploader from './upload';
import { playlistCreate, playlistPicker, playlistView, sharedPlaylistView } from './playlists';

const STORAGE_KEY = 'magnetite.theme';
const VARS_KEY = 'magnetite.vars';
const METERS_KEY = 'magnetite.meters'; // 'off' when the person removed every meter; a theme change must not bring them back

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
    panels: [], // the visible meters: [{ id, params }], at most MAX_PANELS; set by the theme, editable in the player

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

        audio.addEventListener('timeupdate', () => (this.time = audio.currentTime));
        audio.addEventListener('durationchange', () => (this.duration = audio.duration || 0));
        audio.addEventListener('play', () => (this.playing = true));
        audio.addEventListener('pause', () => (this.playing = false));
        audio.addEventListener('ended', () => this.next());
        // Chrome/Firefox cannot decode everything (e.g. ALAC in .m4a). Say so and move on.
        audio.addEventListener('error', () => this.fail());

        const saved = safeStorage((s) => s.getItem(STORAGE_KEY));
        this.setTheme(themes.find((t) => t.id === saved)?.id ?? this.themeId);

        document.addEventListener('keydown', (e) => this.onKey(e));

        new ResizeObserver(() => this.resize()).observe(canvas);
        this.loop();
    },

    setTheme(id) {
        const theme = themes.find((t) => t.id === id);
        if (!theme) return;

        this.themeId = id;
        this.setPanels(this.metersOff() ? [] : this.themePanels(theme));
        applyTheme(theme);
        this.colors = readColors();
        safeStorage((s) => {
            s.setItem(STORAGE_KEY, id);
            // Read by the inline script in the layout's <head> to avoid a flash on full reloads.
            s.setItem(VARS_KEY, JSON.stringify({ colors: theme.colors, radius: theme.radius, font: theme.font }));
        });
    },

    themePanels(theme) {
        return (theme.visualizers ?? [theme.visualizer]).map(({ id, params }) => ({ id, params }));
    },

    metersOff() {
        return safeStorage((s) => s.getItem(METERS_KEY)) === 'off';
    },

    setPanels(panels) {
        this.panels = panels;
        this.needs = [...new Set(panels.flatMap((p) => visualizers[p.id].needs))];
    },

    /** Swap the meter in one panel; the new one starts with its own default parameters. */
    setPanel(i, id) {
        this.setPanels(this.panels.map((p, j) => (j === i ? { id, params: {} } : p)));
    },

    addPanel() {
        // With no meters, bring back the theme's own.
        if (this.panels.length === 0) {
            safeStorage((s) => s.removeItem(METERS_KEY));
            this.setPanels(this.themePanels(themes.find((t) => t.id === this.themeId)));

            return;
        }

        const free = this.visualizerList.find((v) => !this.panels.some((p) => p.id === v.id));
        if (free && this.panels.length < MAX_PANELS) this.setPanels([...this.panels, { id: free.id, params: {} }]);
    },

    removePanel(i) {
        this.setPanels(this.panels.filter((_, j) => j !== i));

        // Removing the last meter is a choice worth remembering; the player then shows the cover and title instead.
        if (this.panels.length === 0) safeStorage((s) => s.setItem(METERS_KEY, 'off'));
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

    seekBy(seconds) {
        const el = this.engine.el;
        if (this.duration) el.currentTime = Math.max(0, Math.min(this.duration, el.currentTime + seconds));
    },

    /** All meters off, or the theme's meters back; the same switch as the button in the player. */
    toggleMeters() {
        if (this.panels.length === 0) return this.addPanel();

        for (let i = this.panels.length - 1; i >= 0; i--) this.removePanel(i);
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
