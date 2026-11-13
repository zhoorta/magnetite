import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { METER_SCHEMAS } from '../../resources/js/magnetite/meter-schemas.js';
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

test('unknown keys are dropped; meter looks keep ids and the schema\'s parameters only', () => {
    const { theme } = validateTheme({ ...good, evil: 1, visualizers: [{ id: 'vu-wide', params: { face: '#FFF', min: -40, n: 2, f() {}, o: {} } }, { id: 5 }] });
    assert.equal('evil' in theme, false);
    assert.deepEqual(theme.visualizers, [{ id: 'vu-wide', params: { min: -40, face: '#ffffff' } }]);
});

test('meter numbers are clamped, colours checked, null follows the theme, a bad value is dropped', () => {
    const { theme } = validateTheme({
        ...good,
        visualizers: [{ id: 'vu-leds', params: { segments: 5000, min: -500, hold: 'x', attack: NaN, midFrom: -10.5 } }, { id: 'vu-needle', params: { face: null, ink: 'red', zone: 'javascript:1', response: 0.5 } }],
    });
    assert.deepEqual(theme.visualizers[0].params, { min: -72, segments: 80, midFrom: -10.5 });
    assert.deepEqual(theme.visualizers[1].params, { response: 0.5, face: null });
});

test('a meter without a schema (the picture scenes) keeps plain values', () => {
    const { theme } = validateTheme({ ...good, visualizers: [{ id: 'scene-1', params: { zoom: 0.3, f() {} } }] });
    assert.deepEqual(theme.visualizers, [{ id: 'scene-1', params: { zoom: 0.3 } }]);
});

test('every schema key is a param of its meter, and its default fits the range', () => {
    const files = { 'vu-wide': 'vu-wide', 'vu-needle': 'vu-needle', 'vu-leds': 'vu-leds', 'magic-eye': 'magic-eye', cassette: 'cassette', 'third-octave': 'third-octave', loudness: 'loudness', spectrogram: 'spectrogram', goniometer: 'goniometer', scope: 'scope' };
    assert.deepEqual(Object.keys(METER_SCHEMAS).sort(), Object.keys(files).sort());
    for (const [id, schema] of Object.entries(METER_SCHEMAS)) {
        const src = readFileSync(new URL(`../../resources/js/magnetite/visualizers/${files[id]}.js`, import.meta.url), 'utf8');
        const block = src.slice(src.indexOf('params:'), src.indexOf('draw('));
        for (const { key, type, min, max } of schema) {
            const m = block.match(new RegExp(`\\b${key}: ([^,\\n]+?),`));
            assert.ok(m, `${id}.${key} is not in the meter's params`);
            if (type === 'number') {
                const v = Number(m[1].replace('}', ''));
                assert.ok(v >= min && v <= max, `${id}.${key} default ${v} is outside ${min}..${max}`);
            }
        }
    }
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
