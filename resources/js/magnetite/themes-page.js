import { SILENCE } from './engine';
import { METER_SCHEMAS } from './meter-schemas';
import { visualizers } from './visualizers';
import { loadFont } from './font-load';
import { COLOR_KEYS, FONTS, FONT_GROUPS, RADII, contrast, exportTheme, parseTheme, slug, validateTheme } from './themes';

/**
 * The themes page: pick a theme to see it in the preview, use it, edit it (a copy, when it is a built-in one), download or copy
 * its JSON, delete one of yours, or import one. Custom themes live in this browser (`magnetite.themes`); nothing goes to the server.
 * The preview paints with inline colours, so nothing here touches the app's own theme until "Use".
 */
export default () => ({
    selectedId: null,
    draft: null, // the theme being edited: { id, name, colors, radius, font, meterFont ('' = same as font), custom }, or null
    note: '',
    importOpen: false,
    importText: '',
    importError: '',
    colorKeys: COLOR_KEYS,
    radii: RADII,
    fonts: FONTS,
    fontGroups: FONT_GROUPS,
    meterId: 'vu-wide', // the meter the preview draws and the editor lists the settings of
    raf: 0,
    previewError: null,

    init() {
        this.selectedId = this.player.themeId;
        this.meterId = this.player.meterIds.find((id) => METER_SCHEMAS[id]) ?? this.meterId;

        let last = performance.now();
        const frame = (now) => {
            this.raf = requestAnimationFrame(frame);
            const dt = Math.min(0.1, (now - last) / 1000);
            last = now;
            try {
                this.drawMeter(dt);
                if (this.previewError) this.previewError = null;
            } catch (e) {
                this.previewError = String(e?.message ?? e);
                console.error(e);
            }
        };
        this.raf = requestAnimationFrame(frame);
    },

    destroy() {
        cancelAnimationFrame(this.raf);
    },

    get meterChoices() {
        return Object.keys(METER_SCHEMAS).filter((id) => visualizers[id]).map((id) => [id, visualizers[id].label]);
    },

    get schema() {
        return METER_SCHEMAS[this.meterId] ?? [];
    },

    get meterLabel() {
        return visualizers[this.meterId]?.label ?? this.meterId;
    },

    /** The meter looks a theme carries, whichever of the two keys it uses. */
    looksOf(theme) {
        return theme.visualizers ?? (theme.visualizer ? [theme.visualizer] : []);
    },

    /** What the shown theme (the draft while editing) sets on a meter: the rest is the meter's own default. */
    paramsOf(id) {
        return this.looksOf(this.shown).find((v) => v.id === id)?.params ?? {};
    },

    isSet(item) {
        const v = this.paramsOf(this.meterId)[item.key];

        return item.type === 'color' ? typeof v === 'string' : v !== undefined;
    },

    paramValue(item) {
        return this.paramsOf(this.meterId)[item.key] ?? visualizers[this.meterId].params[item.key];
    },

    /** A colour field cannot show "the theme's colour", so an unset one shows grey and says so beside it. */
    colorOf(item) {
        const v = this.paramValue(item);

        return typeof v === 'string' ? v : '#808080';
    },

    shownOf(item) {
        const v = this.paramValue(item);

        return item.type === 'color' ? (typeof v === 'string' ? v : 'theme') : `${v}${item.unit ? ' ' + item.unit : ''}`;
    },

    /** The draft's entry for a meter, made when the first setting is changed. */
    entry(id, make = false) {
        let e = this.draft.visualizers.find((v) => v.id === id);
        if (!e && make) this.draft.visualizers.push((e = { id, params: {} }));

        return e;
    },

    setParam(key, raw) {
        const item = this.schema.find((i) => i.key === key);
        if (!item || !this.draft) return;
        const value = item.type === 'number' ? Number(raw) : String(raw).toLowerCase();
        if (item.type === 'number' ? !Number.isFinite(value) : !/^#[0-9a-f]{6}$/.test(value)) return;
        this.entry(this.meterId, true).params[key] = value;
    },

    resetParam(key) {
        const e = this.entry(this.meterId);
        if (!e) return;
        delete e.params[key];
        if (!Object.keys(e.params).length) this.draft.visualizers = this.draft.visualizers.filter((v) => v !== e);
    },

    /** The live preview: the same drawing code as the player, fed with the music that plays (silence when nothing does). */
    drawMeter(dt) {
        const canvas = this.$refs.meterCanvas;
        const viz = visualizers[this.meterId];
        if (!canvas || !viz || document.hidden) return;

        const real = this.player.playing ? this.player.engine?.sample(viz.needs) : null;
        this.live = !!real;

        const dpr = window.devicePixelRatio || 1;
        const w = Math.max(1, Math.floor(canvas.clientWidth * dpr));
        const h = Math.max(1, Math.floor(canvas.clientHeight * dpr));
        if (canvas.width !== w || canvas.height !== h) [canvas.width, canvas.height] = [w, h];

        const g = canvas.getContext('2d');
        g.clearRect(0, 0, w, h);
        const font = this.shown.meterFont || this.shown.font;
        loadFont(font);
        viz.draw(g, real ?? SILENCE, { w, h, colors: { ...this.shown.colors, font }, params: this.paramsOf(this.meterId), dt, progress: 0, track: null });
    },

    live: false,

    get player() {
        return this.$store.player;
    },

    get selected() {
        return this.player.themes.find((t) => t.id === this.selectedId) ?? this.player.themes[0];
    },

    /** What the preview shows: the draft while editing, else the selected theme. */
    get shown() {
        return this.draft ?? this.selected;
    },

    get inUse() {
        return this.selected?.id === this.player.themeId;
    },

    /** Text on the background, and the accent on the surface: the two pairs people read most. */
    get contrasts() {
        const c = this.shown.colors;

        return [['text on background', contrast(c.text, c.bg)], ['accent on surface', contrast(c.accent, c.surface)]]
            .map(([label, ratio]) => ({ label, ratio: ratio.toFixed(1), ok: ratio >= 4.5 }));
    },

    say(text) {
        this.note = text;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => (this.note = ''), 3500);
    },

    select(id) {
        if (this.draft) return;
        this.selectedId = id;
    },

    use() {
        this.player.setTheme(this.selected.id);
        this.say(`Using ${this.selected.name}`);
    },

    startEdit() {
        const t = this.selected;
        this.draft = { id: t.id, name: t.custom ? t.name : `${t.name} (copy)`, colors: { ...t.colors }, radius: t.radius, font: t.font, meterFont: t.meterFont ?? '', custom: !!t.custom, visualizers: JSON.parse(JSON.stringify(this.looksOf(t))) };
    },

    cancelEdit() {
        this.draft = null;
    },

    /** The hex text field of a colour: only a complete colour changes it. */
    setColor(key, value) {
        if (/^#[0-9a-f]{6}$/i.test(value)) this.draft.colors[key] = value.toLowerCase();
    },

    saveEdit() {
        const d = this.draft;
        const taken = this.player.themes.map((t) => t.id).filter((id) => !(d.custom && id === d.id));
        const { theme, error } = validateTheme({ ...d, id: d.custom ? d.id : slug(d.name), visualizers: d.visualizers.length ? d.visualizers : undefined }, taken);
        if (error) return this.say(error);
        if (d.custom) this.player.replaceTheme(theme);
        else this.player.addTheme(theme);
        this.selectedId = theme.id;
        this.draft = null;
        this.say(d.custom ? 'Saved' : `Saved as ${theme.name}`);
    },

    remove() {
        const t = this.selected;
        if (!t.custom) return;
        this.player.removeTheme(t.id);
        this.selectedId = this.player.themeId;
        this.say(`Deleted ${t.name}`);
    },

    json() {
        return exportTheme(this.selected);
    },

    download() {
        const url = URL.createObjectURL(new Blob([this.json() + '\n'], { type: 'application/json' }));
        const a = Object.assign(document.createElement('a'), { href: url, download: `${this.selected.id}.json` });
        a.click();
        URL.revokeObjectURL(url);
    },

    async copy() {
        try {
            await navigator.clipboard.writeText(this.json());
            this.say('JSON copied');
        } catch {
            this.say('Could not copy: use Download instead');
        }
    },

    async loadFile(event) {
        const file = event.target.files?.[0];
        if (file && file.size < 20000) this.importText = await file.text();
        else if (file) this.importError = 'That file is too big for a theme.';
        event.target.value = '';
    },

    doImport() {
        const { theme, error } = parseTheme(this.importText, this.player.themes.map((t) => t.id));
        if (error) return (this.importError = error);
        this.player.addTheme(theme);
        this.selectedId = theme.id;
        this.importText = '';
        this.importError = '';
        this.importOpen = false;
        this.say(`Imported ${theme.name}`);
    },
});
