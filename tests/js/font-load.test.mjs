import test from 'node:test';
import assert from 'node:assert/strict';
import { firstFamily, loadFont } from '../../resources/js/magnetite/font-load.js';

test('the first family of a stack, unquoted', () => {
    assert.equal(firstFamily("'Inter', ui-sans-serif, system-ui, sans-serif"), 'Inter');
    assert.equal(firstFamily('"IBM Plex Mono", monospace'), 'IBM Plex Mono');
    assert.equal(firstFamily('Georgia, serif'), 'Georgia');
    assert.equal(firstFamily('monospace'), 'monospace');
    assert.equal(firstFamily(''), null);
    assert.equal(firstFamily(undefined), null);
});

test('asking for a font where there is no document does nothing', () => {
    assert.doesNotThrow(() => loadFont("'Inter', sans-serif"));
});
