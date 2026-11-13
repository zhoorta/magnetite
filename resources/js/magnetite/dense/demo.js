/**
 * The demo library: what a visitor who is not signed in may do. It is the library page on the demo tracks, read only: moving,
 * playing and the views work; anything that changes something or needs an account is refused with one line. No DOM; Node tests it.
 * (The server refuses those requests anyway; this only keeps the page from asking.)
 */

export const DEMO_NOTE = 'Demo library: sign in to do that';

/** The key actions (names from keymap.js) that work in the demo library. */
export const DEMO_ACTIONS = new Set([
    'down', 'up', 'left', 'right', 'pagedown', 'pageup', 'top', 'bottom',
    'open', 'close', 'enter', 'play', 'tracks', 'tree', 'back', 'folder', 'clear',
    'flat', 'sort', 'detail', 'problems', 'command',
]);

/** The command line's commands that work in the demo library. */
export const DEMO_COMMANDS = new Set(['sort', 'problems', 'truth', 'tree', 'info', 'flat', 'tint', 'hints', 'theme', 'shuffle', 'repeat', 'full', 'help']);

export const demoAllows = (action) => DEMO_ACTIONS.has(action);
export const demoAllowsCommand = (name) => DEMO_COMMANDS.has(name);

/** The key hints of the status line, per pane. */
export function demoHints(pane, common) {
    if (pane === 'tracks') return [['j k', 'move'], ['Enter', 'play'], ['l', 'details'], ['o', 'findings'], ['⌫', 'back'], ...common];

    return [['j k', 'move'], ['Enter', 'play'], ['Tab', 'tracks'], ['s', 'order'], ['o', 'findings'], ...common];
}
