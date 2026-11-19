import { clamp01, toDb } from './meter.js';
import { stateOf, Bars, Reference, begin, drawName, follow, mixHex, palette, posterText } from './poster.js';

// A track-layout diagram of the railway lands behind King's Cross and St Pancras (a black line drawing). `junction-field.png` is that drawing made
// into a field, 690 x 1188: for every pixel of a track the distance along the connected tracks to each of the two stations was worked out (a flood
// fill through the lines) and stored with it: red is the distance from St Pancras and green from King's Cross, both in steps of 8 pixels (255 means
// the pixel is not connected), blue is how black the pixel was. Its source and licence are not known: check them before this is shipped.
const FIELD_W = 690;
const FIELD_H = 1188;
const SCALE = 800 / FIELD_H;
const LEFT = (600 - FIELD_W * SCALE) / 2;
const HISTORY = 256; // 256 steps of 0.04 s, one step being 8 pixels of track: a pulse runs out at 200 pixels a second
const STEP = 0.04;
const BANDS = 30;

const make = () => ({
    bars: new Bars(BANDS),
    ref: new Reference(),
    st: { l: 0, r: 0, acc: 0, key: '' },
    h: { lb: new Float32Array(HISTORY), lm: new Float32Array(HISTORY), lh: new Float32Array(HISTORY), rb: new Float32Array(HISTORY), rm: new Float32Array(HISTORY), rh: new Float32Array(HISTORY) },
    surface: null,
});

// The field is a shared asset: loaded once, when the module is first used, and turned into lists of the track pixels. Until it has arrived (or if it
// cannot be loaded) the poster draws only its text.
let picture = null;
let tracks = null;
function load() {
    if (picture !== null || typeof Image === 'undefined') return;
    try {
        picture = new Image();
        picture.decoding = 'async';
        picture.src = new URL('./junction-field.png', import.meta.url).href;
    } catch {
        picture = false;
    }
}
load();

function field() {
    load();
    if (tracks) return tracks;
    if (!picture || !picture.complete || !picture.naturalWidth || typeof document === 'undefined') return null;
    const c = document.createElement('canvas');
    c.width = FIELD_W;
    c.height = FIELD_H;
    const x = c.getContext('2d');
    x.drawImage(picture, 0, 0);
    const px = x.getImageData(0, 0, FIELD_W, FIELD_H).data;
    let n = 0;
    for (let i = 0; i < FIELD_W * FIELD_H; i++) if (px[i * 4 + 2] > 12) n++;
    tracks = { n, index: new Uint32Array(n), left: new Uint8Array(n), right: new Uint8Array(n), cover: new Uint8Array(n) };
    let k = 0;
    for (let i = 0; i < FIELD_W * FIELD_H; i++) {
        if (px[i * 4 + 2] <= 12) continue;
        tracks.index[k] = i;
        tracks.left[k] = px[i * 4];
        tracks.right[k] = px[i * 4 + 1];
        tracks.cover[k] = px[i * 4 + 2];
        k++;
    }

    return tracks;
}

const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

/** Mean level of band groups `from`..`to`, brought up a little so the top of the scale is reachable. */
function group(v, from, to) {
    let t = 0;
    for (let i = from; i < to; i++) t += v[i];

    return clamp01((t / (to - from)) * 1.5);
}

const rgb = (hex) => {
    const m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(hex).trim());
    if (!m) return [128, 128, 128];
    const h = m[1].length === 3 ? [...m[1]].map((ch) => ch + ch).join('') : m[1];

    return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16));
};

/** One more step of 0.04 s: the levels of the three ranges of each channel go to the front of its history. */
function push(h, v, l, r) {
    for (const key of Object.keys(h)) h[key].copyWithin(1, 0, HISTORY - 1);
    const wl = 0.6 + 0.7 * l;
    const wr = 0.6 + 0.7 * r;
    const bass = group(v, 0, 8) * 0.7;
    const mid = group(v, 8, 20) * 1.1;
    const high = group(v, 20, 30) * 1.5;
    h.lb[0] = clamp01(bass * wl);
    h.lm[0] = clamp01(mid * wl);
    h.lh[0] = clamp01(high * wl);
    h.rb[0] = clamp01(bass * wr);
    h.rm[0] = clamp01(mid * wr);
    h.rh[0] = clamp01(high * wr);
}

function put(g, text, x, y, { size, font, color, weight = 500, align = 'left', spacing = 0 }) {
    if (!text) return;
    g.font = `${weight} ${size}px ${font}`;
    if ('letterSpacing' in g) g.letterSpacing = `${spacing}px`;
    g.textAlign = align;
    g.textBaseline = 'alphabetic';
    g.fillStyle = color;
    g.fillText(text, x, y);
    if ('letterSpacing' in g) g.letterSpacing = '0px';
    g.textAlign = 'left';
}

/**
 * A railway track layout as a meter. The two termini are the two channels (St Pancras the left, King's Cross the right). The music leaves them and runs
 * out along the connected tracks: a track pixel shows the level it had as long ago as it takes to get there (a pulse runs out at 200 pixels a second),
 * the bass, the mids and the highs of each channel in three colours, the strongest of the three winning, the stronger of the two channels winning where
 * the tracks of both reach. The unlit drawing is dim ink.
 */
export default {
    id: 'poster-junction',
    needs: ['bands', 'levels'],
    label: 'Poster: Junction',
    params: {
        range: 40,
        attack: 0.05,
        release: 0.3,
        text: 1,
        theme: 1,
        paper: '#0b0c0f',
        ink: '#f2f0ea',
        a: '#ff5a36',
        b: '#ffd23f',
        c: '#4fd1ff',
    },

    draw(g, { bands, levels }, { w, h, colors, params, dt, track, progress = 0 }) {
        const p = { ...this.params, ...params };
        const state = stateOf(g, make);
        const { bars, ref, st, h: hist } = state;
        const look = palette(p, colors, ['paper', 'ink', 'a', 'b', 'c']);
        const view = begin(g, w, h, look.paper, { p });

        const v = bars.update(bands, dt, p);
        const l = toDb(levels.l.rms);
        const r = toDb(levels.r.rms);
        ref.update(Math.max(l, r), dt);
        const nl = ref.norm(l, 30);
        const nr = ref.norm(r, 30);
        st.l = follow(st.l, nl, dt, nl > st.l ? p.attack : p.release);
        st.r = follow(st.r, nr, dt, nr > st.r ? p.attack : p.release);
        st.acc += dt;
        while (st.acc >= STEP) {
            st.acc -= STEP;
            push(hist, v, st.l, st.r);
        }

        const set = field();
        if (set && typeof document !== 'undefined') {
            const surface = (state.surface ??= (() => {
                const c = document.createElement('canvas');
                c.width = FIELD_W;
                c.height = FIELD_H;
                const ctx = c.getContext('2d');

                return { canvas: c, ctx, img: ctx.createImageData(FIELD_W, FIELD_H), key: '' };
            })());
            const [br, bg, bb] = rgb(look.paper);
            if (surface.key !== look.paper) {
                for (let i = 0; i < FIELD_W * FIELD_H; i++) {
                    surface.img.data[i * 4] = br;
                    surface.img.data[i * 4 + 1] = bg;
                    surface.img.data[i * 4 + 2] = bb;
                    surface.img.data[i * 4 + 3] = 255;
                }
                surface.key = look.paper;
            }
            const [lr, lg, lb] = rgb(mixHex(look.paper, look.ink, 0.45));
            const [r1, g1, b1] = rgb(look.a);
            const [r2, g2, b2] = rgb(look.b);
            const [r3, g3, b3] = rgb(look.c);
            const data = surface.img.data;
            const stride = view.scale < 0.25 ? 3 : 1; // a thumbnail needs a third of the pixels
            for (let k = 0; k < set.n; k += stride) {
                const il = set.left[k];
                const ir = set.right[k];
                const cover = set.cover[k] / 255;
                let bass = 0;
                let mid = 0;
                let high = 0;
                if (il < 255) {
                    bass = hist.lb[il];
                    mid = hist.lm[il];
                    high = hist.lh[il];
                }
                if (ir < 255) {
                    bass = Math.max(bass, hist.rb[ir]);
                    mid = Math.max(mid, hist.rm[ir]);
                    high = Math.max(high, hist.rh[ir]);
                }
                const strength = Math.min(1, Math.max(0, Math.max(bass, mid, high) - 0.3) * 1.9);
                const wb = bass ** 6;
                const wm = mid ** 6;
                const wh = high ** 6;
                const total = wb + wm + wh + 1e-6;
                const o4 = set.index[k] * 4;
                data[o4] = br * (1 - cover) + (lr * (1 - strength) + ((r1 * wb + r2 * wm + r3 * wh) / total) * strength) * cover;
                data[o4 + 1] = bg * (1 - cover) + (lg * (1 - strength) + ((g1 * wb + g2 * wm + g3 * wh) / total) * strength) * cover;
                data[o4 + 2] = bb * (1 - cover) + (lb * (1 - strength) + ((b1 * wb + b2 * wm + b3 * wh) / total) * strength) * cover;
            }
            surface.ctx.putImageData(surface.img, 0, 0);
            g.imageSmoothingQuality = 'high';
            g.drawImage(surface.canvas, LEFT, 0, FIELD_W * SCALE, FIELD_H * SCALE);
        }

        if (view.text) {
            const say = posterText(track);
            const font = colors.font;
            const muted = mixHex(look.paper, look.ink, 0.55);
            const length = track?.duration ?? 0;
            drawName(g, say.title || say.name, 22, 686, { font, maxW: 200, size: 34, color: look.ink, weight: 700, spacing: -0.6 });
            put(g, say.title ? say.name : '', 22, 712, { font, color: muted, size: 12 });
            put(g, [say.album, say.year].filter(Boolean).join(' · '), 22, 727, { font, color: muted, size: 11 });
            put(g, [say.fmt, say.dr].filter(Boolean).join(' · '), 22, 742, { font, color: muted, size: 11 });
            put(g, length ? `${mmss(progress * length)} / ${mmss(length)}` : '', 22, 757, { font, color: muted, size: 11 });
        }
        g.restore();
    },
};
