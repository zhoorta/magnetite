import { COLOR_KEYS, FONTS, RADII, contrast, exportTheme, parseTheme, slug, validateTheme } from './themes';

/**
 * The themes page: pick a theme to see it in the preview, use it, edit it (a copy, when it is a built-in one), download or copy
 * its JSON, delete one of yours, or import one. Custom themes live in this browser (`magnetite.themes`); nothing goes to the server.
 * The preview paints with inline colours, so nothing here touches the app's own theme until "Use".
 */
export default () => ({
    selectedId: null,
    draft: null, // the theme being edited: { id, name, colors, radius, font, custom }, or null
    note: '',
    importOpen: false,
    importText: '',
    importError: '',
    colorKeys: COLOR_KEYS,
    radii: RADII,
    fonts: FONTS,

    init() {
        this.selectedId = this.player.themeId;
    },

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
        this.draft = { id: t.id, name: t.custom ? t.name : `${t.name} (copy)`, colors: { ...t.colors }, radius: t.radius, font: t.font, custom: !!t.custom, visualizer: t.visualizer, visualizers: t.visualizers };
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
