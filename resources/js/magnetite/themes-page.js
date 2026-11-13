import { SILENCE } from './engine';
import { visualizers } from './visualizers';
import { loadFont } from './font-load';
import { COLOR_KEYS, FONTS, FONT_GROUPS, RADII, exportTheme, parseTheme, slug, validateTheme } from './themes';

/**
 * The themes page: a click on a theme applies it to the whole page; "Edit a copy" (a built-in is copied first) opens the editor with
 * a preview of the draft; download or copy a theme's JSON, delete one of yours, or import one. Custom themes live in this browser
 * (`magnetite.themes`); nothing goes to the server. The preview paints with inline colours, so the draft never touches the app's
 * own theme until it is saved.
 */
/** The meter the preview draws: the wide one, the default of every account. */
const METER = 'vu-wide';

export default () => ({
    draft: null, // the theme being edited: { id, name, colors, radius, font, meterFont ('' = same as font), custom }, or null
    note: '',
    importOpen: false,
    importText: '',
    importError: '',
    colorKeys: COLOR_KEYS,
    radii: RADII,
    fonts: FONTS,
    fontGroups: FONT_GROUPS,
    raf: 0,
    previewError: null,

    init() {
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

    /** The live preview: the same drawing code as the player, fed with the music that plays (silence when nothing does). */
    drawMeter(dt) {
        const canvas = this.$refs.meterCanvas;
        const viz = visualizers[METER];
        if (!canvas || !viz || !this.draft || document.hidden) return;

        const real = this.player.playing ? this.player.engine?.sample(viz.needs) : null;

        const dpr = window.devicePixelRatio || 1;
        const w = Math.max(1, Math.floor(canvas.clientWidth * dpr));
        const h = Math.max(1, Math.floor(canvas.clientHeight * dpr));
        if (canvas.width !== w || canvas.height !== h) [canvas.width, canvas.height] = [w, h];

        const g = canvas.getContext('2d');
        g.clearRect(0, 0, w, h);
        const font = this.shown.meterFont || this.shown.font;
        loadFont(font);
        viz.draw(g, real ?? SILENCE, { w, h, colors: { ...this.shown.colors, font }, params: {}, dt, progress: 0, track: null });
    },

    get player() {
        return this.$store.player;
    },

    /** The theme in use: the whole page wears it. */
    get selected() {
        return this.player.themes.find((t) => t.id === this.player.themeId) ?? this.player.themes[0];
    },

    /** What the preview shows: the draft while editing, else the selected theme. */
    get shown() {
        return this.draft ?? this.selected;
    },

    say(text) {
        this.note = text;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => (this.note = ''), 3500);
    },

    /** A click on a theme applies it to the whole page. */
    select(id) {
        if (!this.draft) this.player.setTheme(id);
    },

    startEdit() {
        const t = this.selected;
        this.draft = { id: t.id, name: t.custom ? t.name : `${t.name} (copy)`, colors: { ...t.colors }, radius: t.radius, font: t.font, meterFont: t.meterFont ?? '', custom: !!t.custom };
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
        const { theme, error } = validateTheme({ ...d, id: d.custom ? d.id : slug(d.name) }, taken);
        if (error) return this.say(error);
        if (d.custom) this.player.replaceTheme(theme);
        else this.player.addTheme(theme);
        this.player.setTheme(theme.id);
        this.draft = null;
        this.say(d.custom ? 'Saved' : `Saved as ${theme.name}`);
    },

    remove() {
        const t = this.selected;
        if (!t.custom) return;
        this.player.removeTheme(t.id);
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
        this.player.setTheme(theme.id);
        this.importText = '';
        this.importError = '';
        this.importOpen = false;
        this.say(`Imported ${theme.name}`);
    },
});
