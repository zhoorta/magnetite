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
    A: 'analyse',
    e: 'edit',
    S: 'share',
    i: 'details',
    C: 'cover',
    ':': 'command',
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
};

/** The action for a key event in a pane ('tree' | 'tracks'), or null when the key is not ours (or has a modifier of the browser's). */
export function actionFor(pane, e) {
    if (e.ctrlKey || e.metaKey || e.altKey) return null;
    if (e.key === 'Tab' && e.shiftKey) return null; // Shift+Tab leaves the library, to the toolbar above it
    const table = pane === 'tracks' ? TRACKS : TREE;

    return Object.hasOwn(table, e.key) ? table[e.key] : null;
}

/** Keys typed into a field belong to the field. */
export function isTextTarget(el) {
    return !!el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));
}
