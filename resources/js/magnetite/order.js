/**
 * The play order: which queue index follows which. Pure; Node tests it (tests/js/order.test.mjs).
 * The queue itself is never reordered, so turning shuffle off puts everything back. `order` is a list of queue indices,
 * the identity when shuffle is off. Shuffle modes: 'off', 'tracks' (every track at random), 'albums' (the albums at random,
 * each one in its own order).
 */

export const SHUFFLES = ['off', 'tracks', 'albums'];
export const REPEATS = ['off', 'all', 'one'];

/** The next value in a cycle; an unknown value starts it again. */
export const cycle = (list, value) => list[(list.indexOf(value) + 1) % list.length];

function shuffled(items, rand) {
    const a = [...items];
    for (let i = a.length - 1; i > 0; i--) {
        const j = Math.floor(rand() * (i + 1));
        [a[i], a[j]] = [a[j], a[i]];
    }

    return a;
}

/**
 * The order for `queue`. `first` (a queue index) plays first, so turning shuffle on never jumps away from the playing track;
 * null picks the start at random (a new round). In 'albums' mode `first` carries on through the rest of its album, then the other
 * albums follow at random, and what came before `first` in its album is last.
 */
export function makeOrder(queue, mode, first = null, rand = Math.random) {
    const all = queue.map((_, i) => i);
    if (mode === 'off' || all.length < 2) return all;

    if (mode === 'tracks') {
        const rest = shuffled(all.filter((i) => i !== first), rand);

        return first === null ? rest : [first, ...rest];
    }

    // Albums: tracks of one album stay together, in queue order. A track without an album is an album of one.
    const groups = new Map();
    all.forEach((i) => {
        const t = queue[i];
        const key = t?.album_id != null ? `a${t.album_id}` : `t${i}`;
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(i);
    });

    const lists = [...groups.values()];
    const own = first === null ? null : lists.find((g) => g.includes(first));
    const others = shuffled(lists.filter((g) => g !== own), rand).flat();
    if (!own) return others;

    const at = own.indexOf(first);

    return [...own.slice(at), ...others, ...own.slice(0, at)];
}

/**
 * Where to go after `index`: { index } or null at the end. With repeat 'all' the end wraps: `order` is the list to use from
 * then on (a fresh shuffle that does not start with the track that just ended, when there is a choice).
 */
export function advance({ queue, order, index, shuffle, repeat, rand = Math.random }) {
    const at = order.indexOf(index);
    if (at >= 0 && at + 1 < order.length) return { index: order[at + 1], order };
    if (repeat !== 'all' || queue.length === 0) return null;

    let next = makeOrder(queue, shuffle, null, rand);
    if (next.length > 1 && next[0] === index && shuffle !== 'off') next = [...next.slice(1), next[0]];
    if (shuffle === 'albums' && next.length > 1 && queue[next[0]]?.album_id != null && queue[next[0]].album_id === queue[index]?.album_id) {
        // The same album twice in a row is a repeat, not a shuffle: only a one-album queue has no choice.
        const other = next.findIndex((i) => queue[i]?.album_id !== queue[index].album_id);
        if (other > 0) {
            const ownTracks = next.slice(0, other);
            next = [...next.slice(other), ...ownTracks];
        }
    }

    return { index: next[0], order: next };
}

/** The queue index before `index` in the order, or null at the start. */
export function previous(order, index) {
    const at = order.indexOf(index);

    return at > 0 ? order[at - 1] : null;
}

/** The queue index that plays after `index` without wrapping (what to prefetch, where to skip to), or null. */
export function upcoming(order, index) {
    const at = order.indexOf(index);

    return at >= 0 && at + 1 < order.length ? order[at + 1] : null;
}
