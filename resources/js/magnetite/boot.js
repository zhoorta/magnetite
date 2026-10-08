import { Livewire, Alpine } from '../../../vendor/livewire/livewire/dist/livewire.esm';
import { AudioEngine, SILENCE } from './engine';
import { visualizers } from './visualizers';
import themes from './themes.json';

const STORAGE_KEY = 'magnetite.theme';
const VARS_KEY = 'magnetite.vars';

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

Alpine.store('player', {
    // state
    queue: [],
    index: -1,
    playing: false,
    time: 0,
    duration: 0,
    expanded: false,
    error: null,
    failedIndex: null,
    themes,
    themeId: themes[0].id,
    vizId: themes[0].visualizer.id,
    vizParams: themes[0].visualizer.params,

    // internals
    engine: null,
    canvas: null,
    colors: null,
    raf: null,

    get current() {
        return this.queue[this.index] ?? null;
    },

    mount(audio, canvas) {
        if (this.engine) return;

        this.engine = new AudioEngine(audio);
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

        new ResizeObserver(() => this.resize()).observe(canvas);
        this.loop();
    },

    setTheme(id) {
        const theme = themes.find((t) => t.id === id);
        if (!theme) return;

        this.themeId = id;
        this.vizId = theme.visualizer.id;
        this.vizParams = theme.visualizer.params;
        applyTheme(theme);
        this.colors = readColors();
        safeStorage((s) => {
            s.setItem(STORAGE_KEY, id);
            // Read by the inline script in the layout's <head> to avoid a flash on full reloads.
            s.setItem(VARS_KEY, JSON.stringify({ colors: theme.colors, radius: theme.radius, font: theme.font }));
        });
    },

    setViz(id) {
        this.vizId = id;
        this.vizParams = {}; // fall back to the visualizer's own defaults
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

            // No point drawing in a hidden tab.
            if (document.hidden) {
                last = now;

                return;
            }

            // Seconds since the previous frame, capped so a stalled tab does not make meters jump.
            const dt = Math.min(0.1, (now - last) / 1000);
            last = now;

            const { width: w, height: h } = this.canvas;
            g.clearRect(0, 0, w, h);

            // Before the first gesture there is no audio graph yet: draw the idle state.
            const sample = this.engine.sample() ?? SILENCE;

            visualizers[this.vizId].draw(g, sample, { w, h, colors: this.colors, params: this.vizParams, dt });
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
