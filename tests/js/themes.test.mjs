import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { contrast, exportTheme, parseTheme, slug, validateTheme } from '../../resources/js/magnetite/themes.js';

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

test('unknown keys are dropped; meter looks keep ids and plain parameters only', () => {
    const { theme } = validateTheme({ ...good, evil: 1, visualizers: [{ id: 'vu-wide', params: { face: '#fff', n: 2, f() {}, o: {} } }, { id: 5 }] });
    assert.equal('evil' in theme, false);
    assert.deepEqual(theme.visualizers, [{ id: 'vu-wide', params: { face: '#fff', n: 2 } }]);
});

test('export and import round-trip, without local flags', () => {
    const t = { ...validateTheme(good).theme, custom: true };
    const text = exportTheme(t);
    assert.equal(JSON.parse(text).custom, undefined);
    assert.deepEqual(parseTheme(text).theme, validateTheme(good).theme);
});

test('slug and contrast', () => {
    assert.equal(slug('  Late-night FM! '), 'late-night-fm');
    assert.ok(Math.abs(contrast('#000000', '#ffffff') - 21) < 1e-9);
    assert.ok(contrast('#777', '#888') < 1.5);
});
