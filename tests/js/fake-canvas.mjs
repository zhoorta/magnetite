// A canvas 2D context that accepts every call, measures text as 6 px a letter and records the text that was drawn (`said`).
// Not a test file (no `.test.`): the poster tests that draw on it import it.

export function fakeContext() {
    const said = [];

    return new Proxy({}, {
        get(target, prop) {
            if (prop === 'said') return said;
            if (prop === 'measureText') return (t) => ({ width: String(t).length * 6 });
            if (prop in target) return target[prop];

            return (...args) => {
                if (prop === 'fillText') said.push(args[0]);
            };
        },
        set(target, prop, value) {
            target[prop] = value;

            return true;
        },
    });
}
