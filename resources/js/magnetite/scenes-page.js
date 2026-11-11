import { SILENCE } from './engine';
import { visualizers } from './visualizers';

/** Sliders for the scene settings: [key, label, min, max, step, hint]. What a scene has no value for is "auto". */
const EFFECTS = [
    ['hueShift', 'Hue shift (°)', 0, 360, 5, 'Turns every colour round the wheel; 180 gives the opposite colours'],
    ['hueFollow', 'Hue follows loudness (°)', 0, 360, 5, 'Quiet shows the picture as it is; the louder it gets, the further the colours turn'],
    ['hueSpin', 'Hue cycle (°/s)', 0, 120, 1, 'The colours keep cycling by themselves'],
    ['hueKick', 'Hue jump on kick (°)', 0, 180, 5, 'The colours jump on a kick and drift back'],
    ['wash', 'Colour wash', 0, 1, 0.05, 'The glow colour is poured over the picture with the loudness'],
    ['brightPulse', 'Brightness pulse', 0, 2, 0.05, 'The whole picture gets brighter with the music'],
    ['invertKick', 'Negative on kick', 0, 1, 0.05, 'The picture flips towards its negative on a kick'],
];

const SLIDERS = [
    ['kickGain', 'Kick glow', 0, 2, 0.05, 'How strongly the main shape answers the bass'],
    ['bandGain', 'Band shapes', 0, 2, 0.05, 'How strongly the other shapes answer their bands'],
    ['bloom', 'Bloom', 0, 0.4, 0.01, 'The whole picture swells with the level'],
    ['glowGain', 'Colour glow', 0, 3, 0.05, 'How strong the added colour is'],
    ['dim', 'Dim at rest', 0, 0.9, 0.05, 'Darkens the picture so the music lights it'],
    ['saturation', 'Colour swell', 0, 1, 0.05, 'Colour grows with the loudness'],
    ['attack', 'Attack (s)', 0, 0.5, 0.01, 'Time a shape takes to light up'],
    ['release', 'Release (s)', 0, 1, 0.01, 'Time a shape takes to fade'],
    ['kickThreshold', 'Kick sensitivity (dB)', 1, 12, 0.5, 'Lower reacts to softer bass hits'],
    ['kickCooldown', 'Kick cooldown (s)', 0.05, 1, 0.05, 'Pause before the next hit can count'],
    ['drift', 'Drift', 0, 1, 0.05, 'A very slow pan so it is never frozen'],
    ['vignette', 'Vignette', 0, 1, 0.05, 'Darker edges that open on the kick'],
    ['zoom', 'Kick zoom', 0, 0.1, 0.005, 'Breathes in on the kick'],
];

/** One-click starting points for the colour effects; each only sets the effect values, the rest is left alone. */
const PRESETS = {
    Calm: { hueFollow: 40, brightPulse: 0.3 },
    Reactive: { hueFollow: 120, hueKick: 40, brightPulse: 0.6, wash: 0.25 },
    Wild: { hueFollow: 220, hueSpin: 25, hueKick: 90, brightPulse: 1, wash: 0.5, invertKick: 0.4 },
};
const EFFECT_KEYS = ['hueShift', 'hueFollow', 'hueSpin', 'hueKick', 'wash', 'brightPulse', 'invertKick'];

export default () => ({
    presets: Object.keys(PRESETS),
    sliders: SLIDERS,
    effects: EFFECTS,
    selectedId: null,
    spot: null, // index of the selected hotspot
    drawing: null, // { x0, y0, x1, y1 } while dragging out a new one, as fractions of the picture

    get store() {
        return Alpine.store('scenes');
    },

    get selected() {
        return this.store.list.find((s) => s.id === this.selectedId) ?? null;
    },

    raf: 0,
    previewError: null,

    init() {
        this.selectedId = this.store.list[0]?.id ?? null;

        let last = performance.now();
        let t = 0;
        const frame = (now) => {
            this.raf = requestAnimationFrame(frame);
            const dt = Math.min(0.1, (now - last) / 1000);
            last = now;
            t += dt;
            try {
                this.draw(dt, t);
                if (this.previewError) this.previewError = null;
            } catch (e) {
                this.previewError = String(e?.message ?? e); // shown under the picture, so a failure is not silent
                console.error(e);
            }
        };
        this.raf = requestAnimationFrame(frame);
    },

    destroy() {
        cancelAnimationFrame(this.raf);
    },

    /** The live preview: the same drawing code the player uses, fed with the music that plays (silence when nothing does). */
    draw(dt, t) {
        const canvas = this.$refs.live;
        const scene = this.selected;
        if (!canvas || !scene || document.hidden) return;

        const player = Alpine.store('player');
        const real = player.playing ? player.engine?.sample(['bands']) : null;
        this.live = !!real;

        // The very same scene object the player draws (same settings, shapes and state, so the two always agree). With no music
        // it gets silence, like the player: nothing moves except what moves by itself (hue cycle, drift).
        const viz = visualizers[`scene-${scene.id}`];
        if (!viz) return;

        const dpr = window.devicePixelRatio || 1;
        const w = Math.max(1, Math.floor(canvas.clientWidth * dpr));
        const h = Math.max(1, Math.floor(canvas.clientHeight * dpr));
        if (canvas.width !== w || canvas.height !== h) [canvas.width, canvas.height] = [w, h];

        const sample = real ?? SILENCE;

        const g = canvas.getContext('2d');
        viz.draw(g, sample, { w, h, colors: player.colors, params: {}, dt });
    },

    live: false,


    choose(id) {
        this.selectedId = id;
        this.spot = null;
    },

    /** Upload in order; the first new picture is selected so its settings are at hand. */
    async add(files) {
        for (const f of files) {
            const scene = await this.store.upload(f);
            if (scene && this.selectedId === null) this.selectedId = scene.id;
        }
    },

    pick(event) {
        this.add([...event.target.files]);
        event.target.value = '';
    },

    drop(event) {
        this.add([...event.dataTransfer.files].filter((f) => f.type.startsWith('image/')));
    },

    /** The suggestion for this picture (what "auto" means), once the player has analysed it; else the default. */
    auto(scene, key) {
        const v = Alpine.store('player').visualizerObj?.(this.store.idOf(scene));
        return v?.layers?.suggested?.[key] ?? v?.params?.[key];
    },

    value(scene, key) {
        return scene.settings[key] ?? this.auto(scene, key) ?? 0;
    },

    isSet(scene, key) {
        return scene.settings[key] !== undefined;
    },

    set(scene, key, value) {
        this.store.change(scene, { settings: { [key]: Number(value) } });
    },

    reset(scene, key) {
        this.store.change(scene, { settings: { [key]: undefined } });
    },

    resetAll(scene) {
        Object.keys(scene.settings).forEach((k) => this.reset(scene, k));
    },

    preset(scene, name) {
        const patch = Object.fromEntries(EFFECT_KEYS.map((k) => [k, PRESETS[name]?.[k]])); // not in the preset: back to off
        this.store.change(scene, { settings: patch });
    },

    setGlow(scene, value) {
        this.store.change(scene, { settings: { glowColor: value === 'auto' ? undefined : value === 'none' ? null : value } });
    },

    glowMode(scene) {
        const g = scene.settings.glowColor;
        return g === undefined ? 'auto' : g === null ? 'none' : g;
    },

    /** The blurred copy of the picture that fills the sides of a wide cell: on unless switched off. */
    hasBackdrop(scene) {
        return (scene.settings.backdrop ?? 1) > 0;
    },

    setBackdrop(scene, on) {
        this.store.change(scene, { settings: { backdrop: on ? undefined : 0 } });
    },

    setFit(scene, value) {
        this.store.change(scene, { settings: { fit: value === 'auto' ? undefined : value } });
    },

    // Hotspots: ellipses drawn on the picture. With any, they replace the automatic shapes; none means automatic.
    point(event) {
        const r = this.$refs.stage.getBoundingClientRect();
        const c = (v) => Math.min(1, Math.max(0, v));

        return { x: c((event.clientX - r.left) / r.width), y: c((event.clientY - r.top) / r.height) };
    },

    startDraw(event) {
        if (event.button !== 0) return;
        event.currentTarget.setPointerCapture?.(event.pointerId);
        const { x, y } = this.point(event);
        this.drawing = { x0: x, y0: y, x1: x, y1: y };
    },

    moveDraw(event) {
        if (!this.drawing) return;
        const { x, y } = this.point(event);
        Object.assign(this.drawing, { x1: x, y1: y });
    },

    endDraw() {
        const d = this.drawing;
        this.drawing = null;
        if (!d || !this.selected) return;
        const w = Math.abs(d.x1 - d.x0);
        const h = Math.abs(d.y1 - d.y0);
        if (w < 0.03 || h < 0.03) {
            this.spot = null; // a click on empty picture only deselects
            return;
        }
        const list = [...(this.selected.hotspots ?? [])];
        if (list.length >= 12) return;
        list.push({ x: (d.x0 + d.x1) / 2, y: (d.y0 + d.y1) / 2, w, h, role: list.some((s) => s.role === 'kick') ? 'band' : 'kick' });
        this.store.change(this.selected, { hotspots: list });
        this.spot = list.length - 1;
    },

    /** The ellipse being dragged, drawn as it is dragged. */
    box(s) {
        return { left: `${(s.x - s.w / 2) * 100}%`, top: `${(s.y - s.h / 2) * 100}%`, width: `${s.w * 100}%`, height: `${s.h * 100}%` };
    },

    get draft() {
        const d = this.drawing;
        return d ? this.box({ x: (d.x0 + d.x1) / 2, y: (d.y0 + d.y1) / 2, w: Math.abs(d.x1 - d.x0), h: Math.abs(d.y1 - d.y0) }) : null;
    },

    /** "bass" for the kick, else the place among the bands from the left (1 = lowest). */
    spotLabel(i) {
        const list = this.selected?.hotspots ?? [];
        if (list[i].role === 'kick') return 'kick';
        const bands = list.map((s, k) => [s, k]).filter(([s]) => s.role !== 'kick').sort((a, b) => a[0].x - b[0].x);

        return String(bands.findIndex(([, k]) => k === i) + 1);
    },

    setRole(i, role) {
        const list = this.selected.hotspots.map((s, k) => (k === i ? { ...s, role } : role === 'kick' && s.role === 'kick' ? { ...s, role: 'band' } : s));
        this.store.change(this.selected, { hotspots: list });
    },

    removeSpot(i) {
        this.store.change(this.selected, { hotspots: this.selected.hotspots.filter((_, k) => k !== i) });
        this.spot = null;
    },

    clearSpots() {
        this.store.change(this.selected, { hotspots: [] });
        this.spot = null;
    },

    async removeSelected() {
        if (!this.selected || !confirm('Delete this scene and its picture?')) return;
        await this.store.remove(this.selected);
        this.selectedId = this.store.list[0]?.id ?? null;
    },

    show(scene) {
        const p = Alpine.store('player');
        const id = this.store.idOf(scene);
        if (!p.meterIds.includes(id)) p.setMeters([id, ...p.meterIds.filter((m) => m !== id)]);
    },
});
