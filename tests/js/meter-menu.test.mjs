import { test } from 'node:test';
import assert from 'node:assert/strict';
import { meterEntries, meterGroups } from '../../resources/js/magnetite/visualizers/menu.js';

const visualizers = {
    'vu-wide': { id: 'vu-wide', label: 'Wide meters' },
    'poster-lens': { id: 'poster-lens', label: 'Poster: Lens' },
    'poster-fan': { id: 'poster-fan', label: 'Poster: Fan' },
    'scene-7': { id: 'scene-7', label: 'Scene: Harbour' },
};

test('posters and scenes are grouped, other meters stay in the main list', () => {
    const entries = meterEntries(visualizers);
    assert.deepEqual(entries.map((e) => [e.id, e.group, e.short]), [
        ['vu-wide', null, 'Wide meters'],
        ['poster-lens', 'Posters', 'Lens'],
        ['poster-fan', 'Posters', 'Fan'],
        ['scene-7', 'Scenes', 'Harbour'],
    ]);
    assert.equal(entries[1].label, 'Poster: Lens', 'the full label is kept for the bar');
});

test('only groups with something in them are listed, posters first', () => {
    assert.deepEqual(meterGroups(meterEntries(visualizers)).map((g) => [g.name, g.items.length]), [['Posters', 2], ['Scenes', 1]]);
    const { 'scene-7': _gone, ...noScenes } = visualizers;
    assert.deepEqual(meterGroups(meterEntries(noScenes)).map((g) => g.name), ['Posters']);
    assert.deepEqual(meterGroups(meterEntries({ 'vu-wide': visualizers['vu-wide'] })), []);
});
