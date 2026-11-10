/**
 * The library's command line (`:`): the commands, and how a typed line is read and completed. No DOM; Node tests it.
 * The component maps a command name to what it does.
 */

export const COMMANDS = [
    ['analyze', 'measure the selected albums (A)'],
    ['details', 'look the album up online: date, label, titles (i)'],
    ['edit', 'edit the album as a table (e)'],
    ['cover', 'find a cover (C)'],
    ['merge', 'merge the marked albums into the selected one'],
    ['group', 'name an album for tracks without an album tag'],
    ['suggest', 'suggest albums for tracks without an album tag'],
    ['move', 'move to a folder: move NAME (v)'],
    ['new', 'new folder: new NAME (N)'],
    ['rename', 'rename the folder: rename NAME (r)'],
    ['delete', 'delete the selection (d)'],
    ['share', 'share the selection (S)'],
    ['unshare', 'stop sharing the selection'],
    ['sort', 'sort library, title, year or dr (s)'],
    ['problems', 'the Findings view: only the files with a finding (o)'],
    ['search', 'search: search TEXT, with year: dr: codec: artist: album: (/)'],
    ['truth', 'the Truth view: every file, the worst first; truth again goes back'],
    ['tree', 'back to the folders and albums'],
    ['info', 'info 0, 1 or 2 (I)'],
    ['tint', 'meter colours from the cover (c)'],
    ['theme', 'theme NAME (t)'],
    ['full', 'full screen (f)'],
    ['help', 'the keys (?)'],
];

const NAMES = COMMANDS.map(([n]) => n);
const ALIASES = { analyse: 'analyze', mv: 'move', rm: 'delete', mkdir: 'new', e: 'edit', ls: 'help' };

/**
 * Read a typed line: { name, arg } where `name` is the command (an exact name, an alias, or a prefix that only one command has),
 * or null when it is unknown or ambiguous (`ambiguous` lists the candidates then).
 */
export function parse(text) {
    const m = /^\s*(\S+)\s*(.*?)\s*$/s.exec(text ?? '');
    if (!m) return { name: null, arg: '', typed: '', ambiguous: [] };
    const typed = m[1].toLowerCase();
    const exact = ALIASES[typed] ?? (NAMES.includes(typed) ? typed : null);
    if (exact) return { name: exact, arg: m[2], typed, ambiguous: [] };
    const starts = NAMES.filter((n) => n.startsWith(typed));

    return { name: starts.length === 1 ? starts[0] : null, arg: m[2], typed, ambiguous: starts.length > 1 ? starts : [] };
}

/**
 * Completions of a line, each a whole line. Before the first space it is the command names; after it, the arguments the command
 * takes: folder names, theme names, sort orders, info levels.
 */
export function complete(text, { folders = [], themes = [], sorts = ['library', 'title', 'year', 'dr'] } = {}) {
    const m = /^(\s*)(\S*)(\s+)?(.*)$/s.exec(text ?? '');
    const [, , head, space, rest] = m;
    if (!space) return NAMES.filter((n) => n.startsWith(head.toLowerCase())).map((n) => n);

    const cmd = parse(head).name;
    const pool = { move: folders, rename: folders, theme: themes, sort: sorts, info: ['0', '1', '2'] }[cmd] ?? [];
    const want = rest.toLowerCase();

    return pool.filter((p) => String(p).toLowerCase().startsWith(want)).map((p) => `${cmd} ${p}`);
}

/** Find a name in a list by exact (any case) or unique prefix match: for `move jazz`, `theme paper`. Null when none or several. */
export function pick(arg, names) {
    const want = String(arg ?? '').trim().toLowerCase();
    if (!want) return null;
    const exact = names.filter((n) => String(n).toLowerCase() === want);
    if (exact.length === 1) return exact[0];
    const starts = names.filter((n) => String(n).toLowerCase().startsWith(want));

    return starts.length === 1 ? starts[0] : null;
}
