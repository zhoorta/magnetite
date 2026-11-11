/**
 * The dense library's keys, in one table so that no key is bound twice and Node can check it. A key means one thing per pane;
 * keys not listed here fall through to the player (space, n, p, f, m, ?...). Names, not functions: the component maps them.
 */

export const TREE = {
    j: 'down', ArrowDown: 'down',
    k: 'up', ArrowUp: 'up',
    l: 'open', ArrowRight: 'open',
    h: 'close', ArrowLeft: 'close',
    Enter: 'enter',
    g: 'top', Home: 'top',
    G: 'bottom', End: 'bottom',
    PageDown: 'pagedown', PageUp: 'pageup',
    x: 'mark',
    v: 'move',
    N: 'new-folder',
    r: 'rename',
    d: 'delete',
    s: 'sort',
    o: 'problems',
    Tab: 'tracks',
    Backspace: 'folder',
    A: 'analyse',
    e: 'edit',
    S: 'share',
    i: 'details',
    C: 'cover',
    u: 'undo',
    ':': 'command',
    '/': 'search',
    Escape: 'clear',
};

export const TRACKS = {
    j: 'down', ArrowDown: 'down',
    k: 'up', ArrowUp: 'up',
    g: 'top', Home: 'top',
    G: 'bottom', End: 'bottom',
    PageDown: 'pagedown', PageUp: 'pageup',
    Enter: 'play',
    l: 'detail', ArrowRight: 'detail',
    h: 'back', ArrowLeft: 'back', Escape: 'back',
    Backspace: 'folder',
    Tab: 'tree',
    o: 'problems',
    d: 'delete',
    e: 'edit',
    A: 'analyse',
    S: 'share',
    i: 'details',
    C: 'cover',
    x: 'mark-track',
    ':': 'command',
    '/': 'search',
};

/** The tiles of a selected folder (Tab from the tree): move, open an album or a subfolder, mark, move the marked, back. */
export const GRID = {
    h: 'left', ArrowLeft: 'left',
    l: 'right', ArrowRight: 'right',
    j: 'down', ArrowDown: 'down',
    k: 'up', ArrowUp: 'up',
    g: 'top', Home: 'top',
    G: 'bottom', End: 'bottom',
    Enter: 'open',
    x: 'mark',
    v: 'move',
    Tab: 'tree', Escape: 'tree',
    Backspace: 'folder',
    ':': 'command',
    '/': 'search',
};

/** The search results (after Enter in the box): move, play or open, back to the box, close. */
export const SEARCH = {
    j: 'down', ArrowDown: 'down',
    k: 'up', ArrowUp: 'up',
    g: 'top', Home: 'top',
    G: 'bottom', End: 'bottom',
    PageDown: 'pagedown', PageUp: 'pageup',
    Enter: 'enter',
    '/': 'query',
    ':': 'command',
    Escape: 'close',
};

/** The truth view: every file, worst first. */
export const TRUTH = {
    j: 'down', ArrowDown: 'down',
    k: 'up', ArrowUp: 'up',
    g: 'top', Home: 'top',
    G: 'bottom', End: 'bottom',
    PageDown: 'pagedown', PageUp: 'pageup',
    Enter: 'play',
    s: 'sort',
    o: 'problems',
    A: 'analyse',
    '/': 'search',
    ':': 'command',
    Escape: 'close',
};

/** The action for a key event in a pane ('tree' | 'tracks' | 'grid' | 'search' | 'truth'), or null when the key is not ours (or has a modifier of the browser's). */
export function actionFor(pane, e) {
    if (e.ctrlKey || e.metaKey || e.altKey) return null;
    if (e.key === 'Tab' && e.shiftKey) return null; // Shift+Tab leaves the library, to the toolbar above it
    const table = { tree: TREE, tracks: TRACKS, grid: GRID, search: SEARCH, truth: TRUTH }[pane] ?? TREE;

    return Object.hasOwn(table, e.key) ? table[e.key] : null;
}

/** Keys typed into a field belong to the field. */
export function isTextTarget(el) {
    return !!el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));
}
