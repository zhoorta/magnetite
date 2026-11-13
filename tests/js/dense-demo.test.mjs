import assert from 'node:assert/strict';
import { test } from 'node:test';
import { COMMANDS } from '../../resources/js/magnetite/dense/commands.js';
import { DEMO_ACTIONS, DEMO_COMMANDS, demoAllows, demoAllowsCommand, demoHints } from '../../resources/js/magnetite/dense/demo.js';
import { GRID, SEARCH, TRACKS, TREE, TRUTH } from '../../resources/js/magnetite/dense/keymap.js';

const actions = new Set([TREE, TRACKS, GRID, SEARCH, TRUTH].flatMap((t) => Object.values(t)));

test('the demo library allows moving, playing and the views', () => {
    for (const a of ['down', 'up', 'enter', 'play', 'tracks', 'detail', 'problems', 'sort', 'flat', 'command']) assert.ok(demoAllows(a), a);
    for (const c of ['truth', 'theme', 'info', 'help', 'full']) assert.ok(demoAllowsCommand(c), c);
});

test('the demo library refuses everything that changes something or needs an account', () => {
    for (const a of ['mark', 'move', 'new-folder', 'rename', 'delete', 'analyse', 'edit', 'details', 'cover', 'share', 'copy', 'undo', 'mark-track', 'shift-down', 'shift-up', 'upload', 'source', 'source-back', 'search']) {
        assert.ok(actions.has(a), `${a} is a key action`);
        assert.equal(demoAllows(a), false, a);
    }
    for (const c of ['analyze', 'details', 'edit', 'cover', 'merge', 'group', 'suggest', 'move', 'new', 'rename', 'delete', 'upload', 'share', 'unshare', 'library', 'shared', 'playlists', 'copy', 'sharetracks', 'search']) {
        assert.equal(demoAllowsCommand(c), false, c);
    }
});

test('what the demo library allows exists: every action is a key action, every command a command', () => {
    for (const a of DEMO_ACTIONS) assert.ok(actions.has(a), a);
    const names = COMMANDS.map(([n]) => n);
    for (const c of DEMO_COMMANDS) assert.ok(names.includes(c), c);
});

test('the demo hints only name keys that work', () => {
    const keys = (pane) => demoHints(pane, [['?', 'keys']]).map(([k]) => k);
    assert.deepEqual(keys('tracks'), ['j k', 'Enter', 'l', 'o', '⌫', '?']);
    assert.deepEqual(keys('tree'), ['j k', 'Enter', 'Tab', 's', 'o', '?']);
});
