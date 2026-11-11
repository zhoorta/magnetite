import test from 'node:test';
import assert from 'node:assert/strict';
import { LIBRARY_ONLY, SOURCES, moveItem, playQueue, stepSource } from '../../resources/js/magnetite/dense/sources.js';
import { actionFor } from '../../resources/js/magnetite/dense/keymap.js';
import { parse } from '../../resources/js/magnetite/dense/commands.js';

const key = (k) => ({ key: k, ctrlKey: false, metaKey: false, altKey: false });

test('w cycles the sources forwards and W backwards, wrapping round', () => {
    assert.deepEqual(SOURCES, ['library', 'shared', 'playlists']);
    assert.equal(stepSource('library', 1), 'shared');
    assert.equal(stepSource('playlists', 1), 'library');
    assert.equal(stepSource('library', -1), 'playlists');
    assert.equal(stepSource('nonsense', 1), 'shared');
});

test('a queue holds only what you may play, and starts at the wanted track', () => {
    const tracks = [{ id: 1, open: true }, { id: 2, open: false }, { id: 3, open: true }];
    assert.deepEqual(playQueue(tracks, 2), { queue: [tracks[0], tracks[2]], index: 1 });
    assert.deepEqual(playQueue(tracks, 0).index, 0);
    // asking for one that is not shared with you starts at the first that is
    assert.equal(playQueue(tracks, 1).index, 0);
    // tracks of your own library carry no `open` at all and are all playable
    assert.equal(playQueue([{ id: 5 }, { id: 6 }], 1).index, 1);
    assert.equal(playQueue([{ id: 2, open: false }], 0), null);
    assert.equal(playQueue([], 0), null);
});

test('moveItem moves one item and leaves the original alone', () => {
    const list = ['a', 'b', 'c', 'd'];
    assert.deepEqual(moveItem(list, 0, 2), ['b', 'c', 'a', 'd']);
    assert.deepEqual(moveItem(list, 3, 0), ['d', 'a', 'b', 'c']);
    assert.deepEqual(list, ['a', 'b', 'c', 'd']);
    assert.equal(moveItem(list, 0, -5), list); // already at the top: nothing changes
    assert.equal(moveItem(list, 3, 9), list);
    assert.equal(moveItem(list, 7, 0), list);
});

test('the keys for sources: w, W, y in the tree, the tracks and the tiles; Shift+J and K reorder tracks', () => {
    for (const pane of ['tree', 'tracks', 'grid']) {
        assert.equal(actionFor(pane, key('w')), 'source');
        assert.equal(actionFor(pane, key('W')), 'source-back');
        assert.equal(actionFor(pane, key('y')), 'copy');
    }
    assert.equal(actionFor('tracks', key('J')), 'shift-down');
    assert.equal(actionFor('tracks', key('K')), 'shift-up');
    assert.equal(actionFor('tree', key('J')), null);
});

test('the actions that change your library are the ones refused elsewhere', () => {
    for (const a of ['mark', 'move', 'edit', 'details', 'cover', 'analyse', 'undo']) assert.ok(LIBRARY_ONLY.has(a));
    assert.ok(!LIBRARY_ONLY.has('delete'));
});

test('the commands for the sources', () => {
    assert.equal(parse('shared').name, 'shared');
    assert.equal(parse('playlists').name, 'playlists');
    assert.equal(parse('lib').name, 'library');
    assert.equal(parse('copy').name, 'copy');
    assert.equal(parse('sharetracks').name, 'sharetracks');
    assert.equal(parse('share').name, 'share');
});
