import test from 'node:test';
import assert from 'node:assert/strict';
import { TREE, TRACKS, actionFor, isTextTarget } from '../../resources/js/magnetite/dense/keymap.js';

const key = (k, mods = {}) => ({ key: k, ctrlKey: false, metaKey: false, altKey: false, ...mods });

test('the movement keys do the same thing in both panes', () => {
    for (const pane of ['tree', 'tracks']) {
        assert.equal(actionFor(pane, key('j')), 'down');
        assert.equal(actionFor(pane, key('ArrowUp')), 'up');
        assert.equal(actionFor(pane, key('G')), 'bottom');
    }
});

test('Tab moves between the panes; h and Esc step back (closing the detail first)', () => {
    assert.equal(actionFor('tree', key('Tab')), 'tracks');
    assert.equal(actionFor('tracks', key('Tab')), 'tree');
    assert.equal(actionFor('tracks', key('h')), 'back');
    assert.equal(actionFor('tracks', key('Escape')), 'back');
});

test('i looks an album up, C finds a cover, : opens the command line, x marks loose tracks', () => {
    for (const pane of ['tree', 'tracks']) {
        assert.equal(actionFor(pane, key('i')), 'details');
        assert.equal(actionFor(pane, key('C')), 'cover');
        assert.equal(actionFor(pane, key(':')), 'command');
    }
    assert.equal(actionFor('tracks', key('x')), 'mark-track');
    assert.equal(actionFor('tree', key('x')), 'mark');
});

test('l shows the measurements of a track, o filters to the files with findings', () => {
    assert.equal(actionFor('tracks', key('l')), 'detail');
    assert.equal(actionFor('tree', key('o')), 'problems');
    assert.equal(actionFor('tracks', key('o')), 'problems');
});

test('Shift+Tab is left to the browser so that focus can leave the library', () => {
    assert.equal(actionFor('tree', key('Tab', { shiftKey: true })), null);
    assert.equal(actionFor('tracks', key('Tab', { shiftKey: true })), null);
});

test('Enter plays from a track, and plays or opens in the tree', () => {
    assert.equal(actionFor('tracks', key('Enter')), 'play');
    assert.equal(actionFor('tree', key('Enter')), 'enter');
});

test('keys with the browser\'s modifiers are never ours', () => {
    for (const mod of ['ctrlKey', 'metaKey', 'altKey']) assert.equal(actionFor('tree', key('j', { [mod]: true })), null);
});

test('keys that belong to the player fall through', () => {
    for (const k of [' ', 'n', 'p', 'P', 'I', 'f', 'm', '?', '0', '5', ',', '.']) {
        assert.equal(actionFor('tree', key(k)), null, `${k} in the tree`);
        assert.equal(actionFor('tracks', key(k)), null, `${k} in the tracks`);
    }
});

test('the capital letters are the library\'s own: new folder, analyse, share (so N and P are no longer next and previous there)', () => {
    assert.equal(actionFor('tree', key('N')), 'new-folder');
    assert.equal(actionFor('tree', key('A')), 'analyse');
    assert.equal(actionFor('tree', key('S')), 'share');
    });

test('what is bound exists in a table, and the move key is v (m stays the meters key)', () => {
    assert.equal(TREE.v, 'move');
    assert.equal(Object.hasOwn(TREE, 'm'), false);
    assert.ok(Object.keys(TREE).length > 15 && Object.keys(TRACKS).length > 10);
});

test('typing in a field is not a command', () => {
    assert.equal(isTextTarget({ tagName: 'INPUT' }), true);
    assert.equal(isTextTarget({ tagName: 'SELECT' }), true);
    assert.equal(isTextTarget({ tagName: 'DIV', isContentEditable: true }), true);
    assert.equal(isTextTarget({ tagName: 'BUTTON' }), false);
    assert.equal(isTextTarget(null), false);
});

test('/ opens the search from the tree, the tracks and the truth view; in the results it edits the box', () => {
    for (const pane of ['tree', 'tracks', 'truth']) assert.equal(actionFor(pane, key('/')), 'search');
    assert.equal(actionFor('search', key('/')), 'query');
    assert.equal(actionFor('search', key('Enter')), 'enter');
    assert.equal(actionFor('search', key('Escape')), 'close');
    assert.equal(actionFor('search', key('j')), 'down');
});

test('the truth view plays, sorts and filters; Esc leaves it', () => {
    assert.equal(actionFor('truth', key('Enter')), 'play');
    assert.equal(actionFor('truth', key('s')), 'sort');
    assert.equal(actionFor('truth', key('o')), 'problems');
    assert.equal(actionFor('truth', key('Escape')), 'close');
    assert.equal(actionFor('truth', key('d')), null, 'nothing destructive in a flat list');
    assert.equal(actionFor('truth', key('x')), null);
});
