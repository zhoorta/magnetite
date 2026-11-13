import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { FONTS, FONT_GROUPS, exportTheme, parseTheme, slug, validateTheme } from '../../resources/js/magnetite/themes.js';

const builtIn = JSON.parse(readFileSync(new URL('../../resources/js/magnetite/themes.json', import.meta.url)));
const good = { name: 'My theme', colors: { bg: '#000', surface: '#111111', text: '#EEE', muted: '#888888', accent: '#ff0000', accent2: '#00ff00' }, radius: '0.5rem', font: 'Georgia, serif' };

test('every built-in theme passes the check unchanged', () => {
    for (const t of builtIn) {
        const { theme, error } = validateTheme(t);
        assert.equal(error, undefined, t.id);
        assert.deepEqual(theme.colors, t.colors, t.id);
        assert.equal(theme.id, t.id);
    }
});

test('colours are expanded and lower-cased, the id comes from the name', () => {
    const { theme } = validateTheme(good);
    assert.equal(theme.colors.bg, '#000000');
    assert.equal(theme.colors.text, '#eeeeee');
    assert.equal(theme.id, 'my-theme');
});

test('an id already taken gets a number', () => {
    assert.equal(validateTheme(good, ['my-theme']).theme.id, 'my-theme-2');
    assert.equal(validateTheme(good, ['my-theme', 'my-theme-2']).theme.id, 'my-theme-3');
});

test('what is wrong is said, and nothing unsafe gets through', () => {
    assert.match(validateTheme(null).error, /JSON object/);
    assert.match(validateTheme({ ...good, name: ' ' }).error, /name/);
    assert.match(validateTheme({ ...good, colors: { ...good.colors, accent: 'red' } }).error, /accent/);
    assert.match(validateTheme({ ...good, colors: { ...good.colors, bg: 'url(x)' } }).error, /bg/);
    assert.match(validateTheme({ ...good, radius: '1em; x' }).error, /radius/);
    assert.match(validateTheme({ ...good, font: 'a}; body{display:none' }).error, /font/);
    assert.match(parseTheme('{nope').error, /JSON/);
});

test('unknown keys are dropped, and meter looks (old saved ones) are ignored', () => {
    const { theme } = validateTheme({ ...good, evil: 1, visualizers: [{ id: 'vu-wide', params: { min: -40 } }], visualizer: { id: 'vu-needle', params: {} } });
    assert.equal('evil' in theme, false);
    assert.equal('visualizers' in theme, false);
    assert.equal('visualizer' in theme, false);
});

test('export and import round-trip, without local flags', () => {
    const t = { ...validateTheme(good).theme, custom: true };
    const text = exportTheme(t);
    assert.equal(JSON.parse(text).custom, undefined);
    assert.deepEqual(parseTheme(text).theme, validateTheme(good).theme);
});

test('slug', () => {
    assert.equal(slug('  Late-night FM! '), 'late-night-fm');
});

test('every font the studio offers passes the check, and every bundled one is declared in fonts.css with its file', () => {
    const css = readFileSync(new URL('../../resources/css/fonts.css', import.meta.url), 'utf8');
    assert.ok(FONTS.length >= 20);
    assert.equal(new Set(FONTS.map((f) => f[1])).size, FONTS.length, 'no stack twice');

    for (const [label, stack, group] of FONTS) {
        assert.equal(validateTheme({ ...good, font: stack }).error, undefined, label);
        if (group === 'System') continue; // what the device has

        const first = stack.match(/^'([^']+)'/)?.[1];
        assert.equal(first, label, `${label}: the stack starts with the bundled family`);
        assert.ok(css.includes(`font-family: '${first}'`), `${label} is not declared in fonts.css`);
    }

    // every file fonts.css points to is there (a font package that moved a file would otherwise fail only in the build)
    const files = [...css.matchAll(/url\('([^']+)'\)/g)].map((m) => m[1]);
    assert.ok(files.length >= 15);
    for (const f of files) assert.ok(existsSync(new URL(f, new URL('../../resources/css/', import.meta.url))), f);
});

test('the pickers get the fonts in groups, each font once', () => {
    assert.deepEqual(FONT_GROUPS.map((g) => g[0]), ['Sans', 'Rounded and display', 'Serif', 'Mono', 'Pixel and terminal', 'System']);
    assert.equal(FONT_GROUPS.reduce((n, g) => n + g[1].length, 0), FONTS.length);
});

test('the fonts the built-in themes use are offered, so the studio shows their name', () => {
    const stacks = FONTS.map((f) => f[1]);
    for (const t of builtIn) assert.ok(stacks.includes(t.font), `${t.id}: ${t.font}`);
});

test('a theme may name a font for the meters; left out or empty, it is not stored', () => {
    const mono = "'JetBrains Mono', ui-monospace, monospace";
    assert.equal(validateTheme({ ...good, meterFont: mono }).theme.meterFont, mono);
    assert.equal('meterFont' in validateTheme(good).theme, false);
    assert.equal('meterFont' in validateTheme({ ...good, meterFont: '' }).theme, false);
    assert.equal('meterFont' in validateTheme({ ...good, meterFont: '   ' }).theme, false);
});

test('the meter font gets the same check as the font, and exports and imports back', () => {
    assert.match(validateTheme({ ...good, meterFont: 'x; background: url(//evil)' }).error, /meterFont/);
    assert.equal('meterFont' in validateTheme({ ...good, meterFont: 5 }).theme, false); // a non-string is ignored, not an error

    const mono = "'IBM Plex Mono', monospace";
    const { theme } = validateTheme({ ...good, meterFont: mono });
    assert.equal(JSON.parse(exportTheme(theme)).meterFont, mono);
    assert.equal(parseTheme(exportTheme(theme)).theme.meterFont, mono);
    assert.equal('meterFont' in JSON.parse(exportTheme(validateTheme(good).theme)), false);
});
