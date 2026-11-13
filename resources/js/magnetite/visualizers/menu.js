// What the meter menu lists: every meter with its label, and, for the poster meters, the group they sit in. No DOM: Node tests it.

const GROUPS = [['poster-', 'Posters', 'Poster: ']];

/** `[{ id, label, group, short }]`: `group` is null for a meter that sits in the main list; `short` is the label without the group's prefix. */
export function meterEntries(visualizers) {
    return Object.values(visualizers).map(({ id, label }) => {
        const [, group, prefix] = GROUPS.find(([start]) => id.startsWith(start)) ?? [];

        return { id, label, group: group ?? null, short: prefix && label.startsWith(prefix) ? label.slice(prefix.length) : label };
    });
}

/** The groups that have something in them, in menu order: `[{ name, items }]`. */
export function meterGroups(entries) {
    return GROUPS.map(([, name]) => ({ name, items: entries.filter((e) => e.group === name) })).filter((g) => g.items.length > 0);
}
