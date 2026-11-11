/** Sliders for the scene settings: [key, label, min, max, step, hint]. What a scene has no value for is "auto". */
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

export default () => ({
    sliders: SLIDERS,
    selectedId: null,

    get store() {
        return Alpine.store('scenes');
    },

    get selected() {
        return this.store.list.find((s) => s.id === this.selectedId) ?? null;
    },

    init() {
        this.selectedId = this.store.list[0]?.id ?? null;
    },

    pick(event) {
        [...event.target.files].forEach((f) => this.store.upload(f));
        event.target.value = '';
    },

    drop(event) {
        [...event.dataTransfer.files].filter((f) => f.type.startsWith('image/')).forEach((f) => this.store.upload(f));
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

    setGlow(scene, value) {
        this.store.change(scene, { settings: { glowColor: value === 'auto' ? undefined : value === 'none' ? null : value } });
    },

    glowMode(scene) {
        const g = scene.settings.glowColor;
        return g === undefined ? 'auto' : g === null ? 'none' : g;
    },

    setFit(scene, value) {
        this.store.change(scene, { settings: { fit: value === 'auto' ? undefined : value } });
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
