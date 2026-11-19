import { clamp01, toDb } from './meter.js';
import { stateOf, Bars, Reference, begin, follow, palette, posterText } from './poster.js';

const GROUPS = 20;
const MONO = "ui-monospace, 'SF Mono', Menlo, Consolas, monospace";
// The teletext screen keeps its own colours whatever the theme.
const TT = { black: '#000000', green: '#2bff4a', yellow: '#ffee2e', red: '#ff3b2e', cyan: '#2ee6ff', white: '#f4f4f4', blue: '#1a2cff' };

const quad = (a, c, b, n = 18) => Array.from({ length: n + 1 }, (_, i) => {
    const t = i / n;
    const u = 1 - t;

    return [u * u * a[0] + 2 * u * t * c[0] + t * t * b[0], u * u * a[1] + 2 * u * t * c[1] + t * t * b[1]];
});

// The routes, in the poster's 600 x 800 space (the old "how it works" diagram this follows).
const PATH_A = [[232, 452], [322, 452], [322, 604]]; // the computer to the phone (solid: a page)
const PATH_B = [[284, 604], [284, 490], [234, 490]]; // the phone to the computer (dashed: a request)
const PATH_C = [[346, 640], [372, 652], [396, 650]]; // the phone to the TV
const PATH_D = [[430, 724], [430, 746], [250, 746], [250, 654], [264, 654]]; // the TV to the phone
const PATH_E = [[140, 616], [140, 566]]; // the organisations to the computer
const PATH_F = quad([214, 206], [310, 206], [424, 240]); // the broadcast
const KEY = { P0: [514, 746], P1: [548, 741], P3: [530, 785] };

const make = () => ({
    bars: new Bars(GROUPS),
    ref: new Reference(),
    pk: new Float32Array(GROUPS),
    st: { l: 0, r: 0, lv: 0, avg: 0, cool: 0, on: 0, dial: 0, key: 0, keyT: 0, phase: 0 },
});

const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

const plen = (p) => p.slice(1).reduce((d, pt, i) => d + Math.hypot(pt[0] - p[i][0], pt[1] - p[i][1]), 0);

/** The point `d` along a polyline. */
function along(p, d) {
    for (let i = 1; i < p.length; i++) {
        const l = Math.hypot(p[i][0] - p[i - 1][0], p[i][1] - p[i - 1][1]);
        if (d <= l) {
            const k = d / l;

            return [p[i - 1][0] + (p[i][0] - p[i - 1][0]) * k, p[i - 1][1] + (p[i][1] - p[i - 1][1]) * k];
        }
        d -= l;
    }

    return p[p.length - 1];
}

function trace(g, pts) {
    g.beginPath();
    pts.forEach(([x, y], i) => (i ? g.lineTo(x, y) : g.moveTo(x, y)));
}

/** An arrowhead at the end of a polyline, filled with `fill`. */
function arrow(g, p, fill, size = 9) {
    const [x, y] = p[p.length - 1];
    const [px, py] = p[p.length - 2];
    const a = Math.atan2(y - py, x - px);
    g.beginPath();
    g.moveTo(x + 2 * Math.cos(a), y + 2 * Math.sin(a));
    g.lineTo(x - size * Math.cos(a - 0.42), y - size * Math.sin(a - 0.42));
    g.lineTo(x - size * Math.cos(a + 0.42), y - size * Math.sin(a + 0.42));
    g.closePath();
    g.save();
    g.fillStyle = fill;
    g.fill();
    g.restore();
    g.stroke();
}

/** An oblique box: the front, the top and the right side. */
function box3(g, x, y, w, h, dx, dy, ground) {
    g.save();
    g.fillStyle = ground;
    g.beginPath();
    g.moveTo(x, y);
    g.lineTo(x + dx, y + dy);
    g.lineTo(x + w + dx, y + dy);
    g.lineTo(x + w, y);
    g.closePath();
    g.fill();
    g.stroke();
    g.beginPath();
    g.moveTo(x + w, y);
    g.lineTo(x + w + dx, y + dy);
    g.lineTo(x + w + dx, y + h + dy);
    g.lineTo(x + w, y + h);
    g.closePath();
    g.fill();
    g.stroke();
    g.fillRect(x, y, w, h);
    g.strokeRect(x, y, w, h);
    g.restore();
}

/** A disk (a cylinder) filled as far as `amount` from the bottom. */
function disk(g, cx, cy, rx, ry, h, amount, look) {
    const bottom = cy + h;
    g.beginPath();
    g.moveTo(cx - rx, cy);
    g.lineTo(cx - rx, bottom);
    g.ellipse(cx, bottom, rx, ry, 0, Math.PI, 0, true);
    g.lineTo(cx + rx, cy);
    g.ellipse(cx, cy, rx, ry, 0, 0, Math.PI, true);
    g.closePath();
    g.save();
    g.fillStyle = look.paper;
    g.fill();
    g.clip();
    g.globalAlpha = 0.85;
    g.fillStyle = look.a;
    g.fillRect(cx - rx - 2, bottom + ry - amount * (h + ry), rx * 2 + 4, h + ry * 2);
    g.restore();
    g.stroke();
    g.beginPath();
    g.ellipse(cx, cy, rx, ry, 0, 0, 7);
    g.save();
    g.fillStyle = look.paper;
    g.fill();
    g.restore();
    g.stroke();
}

function put(g, text, x, y, { size, font, color, weight = 400, align = 'left', spacing = 0 }) {
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

/** Mean level of band groups `from`..`to`, brought up a little so the top of the scale is reachable. */
function group(v, from, to) {
    let t = 0;
    for (let i = from; i < to; i++) t += v[i];

    return clamp01((t / (to - from)) * 1.6);
}

/** The value of the 20 groups at a fractional position. */
function at(v, x) {
    const i = Math.max(0, Math.min(GROUPS - 1, Math.floor(x)));
    const f = x - i;

    return v[i] * (1 - f) + v[Math.min(GROUPS - 1, i + 1)] * f;
}

/**
 * The old "how it works" diagram of a television that gets pages over a telephone line and programs over the air, as a meter. The radio arcs on the
 * mast pulse with four band groups, the aerial's teeth light with level, the dashed request lines and the solid page lines flow (faster when louder)
 * with packets riding them, and the arrowheads flash on bass onsets. The cabinet's lamps are seven band groups and its disk fills with the loudness;
 * the second disk fills with the time played; the network box holds the left and right level; the dial spins and a key of the keypad is pressed on
 * each onset. The TV shows a teletext page: the track on its first rows and the spectrum as mosaic bars with held peaks.
 */
export default {
    id: 'poster-viewdata',
    needs: ['bands', 'levels'],
    label: 'Poster: Viewdata',
    params: {
        range: 40,
        attack: 0.08,
        release: 0.4,
        text: 1,
        theme: 1,
        paper: '#e6dfc9',
        ink: '#1c1b17',
        a: '#c2331f',
    },

    draw(g, { bands, levels }, { w, h, colors, params, dt, track, progress = 0 }) {
        const p = { ...this.params, ...params };
        const { bars, ref, pk, st } = stateOf(g, make);
        const look = palette(p, colors, ['paper', 'ink', 'a']);
        const view = begin(g, w, h, look.paper, { p });
        const { ink, a: acc, paper } = look;
        const font = colors.font;

        // The measurements.
        const v = bars.update(bands, dt, p);
        let mean = 0;
        for (let i = 0; i < GROUPS; i++) {
            pk[i] = Math.max(v[i], pk[i] - dt * 0.25);
            mean += v[i] / GROUPS;
        }
        const l = toDb(levels.l.rms);
        const r = toDb(levels.r.rms);
        ref.update(Math.max(l, r), dt);
        const nl = ref.norm(l, 30);
        const nr = ref.norm(r, 30);
        st.l = follow(st.l, nl, dt, nl > st.l ? p.attack : p.release);
        st.r = follow(st.r, nr, dt, nr > st.r ? p.attack : p.release);
        st.lv = follow(st.lv, mean, dt, 1.6);
        const bass = (bars.raw[0] + bars.raw[1] + bars.raw[2]) / 3;
        st.avg = follow(st.avg, bass, dt, 2.7);
        st.cool -= dt;
        st.on = Math.max(0, st.on - dt * 3.2);
        if (st.cool <= 0 && bass > st.avg * 1.3 + 0.04) {
            st.on = 1;
            st.cool = 0.16;
            st.dial += 0.9;
            st.key = (st.key + 5) % 12; // not random: the same music presses the same keys
            st.keyT = 1;
        }
        st.keyT = Math.max(0, st.keyT - dt * 2.5);
        st.phase += dt * (34 + mean * 200);
        const flash = st.on;
        const say = posterText(track);
        const length = track?.duration ?? 0;
        const label = { font, color: ink, size: 9.5 };

        g.lineWidth = 3;
        g.lineJoin = 'round';
        g.lineCap = 'round';
        g.strokeStyle = ink;
        g.fillStyle = ink;

        // The heading: album and year, the title in at most two lines, the artist.
        if (view.text) {
            put(g, [say.album, say.year].filter(Boolean).join('  ·  ').toUpperCase(), 40, 50, { size: 12, font, color: ink, spacing: 2.6 });
            const words = (say.title || say.name).toUpperCase().split(/\s+/);
            let fit = null;
            for (let size = 40; size >= 20 && !fit; size -= 2) {
                g.font = `700 ${size}px ${font}`;
                if ('letterSpacing' in g) g.letterSpacing = '3px';
                const lines = [];
                let cur = '';
                for (const word of words) {
                    const next = cur ? `${cur} ${word}` : word;
                    if (!cur || g.measureText(next).width <= 520) cur = next;
                    else {
                        lines.push(cur);
                        cur = word;
                    }
                }
                lines.push(cur);
                if (lines.length <= 2 && lines.every((ln) => g.measureText(ln).width <= 520)) fit = { size, lines };
                if ('letterSpacing' in g) g.letterSpacing = '0px';
            }
            fit = fit || { size: 20, lines: [(say.title || say.name).toUpperCase()] };
            fit.lines.forEach((ln, i) => put(g, ln, 40, 92 + i * (fit.size + 6), { size: fit.size, font, color: ink, weight: 700, spacing: 3 }));
            if (say.title) put(g, say.name.toUpperCase(), 40, 92 + fit.lines.length * (fit.size + 6) + 4, { size: 14, font, color: acc, weight: 700, spacing: 3.4 });
        }

        // The radio mast and its arcs: four band groups.
        const mx = 96;
        const top = 190;
        const base = 300;
        const half = (y) => 1.5 + (25 * (y - top)) / (base - top);
        trace(g, [[mx - half(base), base], [mx - 1.5, top]]);
        g.stroke();
        trace(g, [[mx + half(base), base], [mx + 1.5, top]]);
        g.stroke();
        g.lineWidth = 2.2;
        for (let i = 0; i < 6; i++) {
            const y0 = top + 12 + i * 18;
            const y1 = y0 + 18;
            trace(g, i % 2 ? [[mx - half(y0), y0], [mx + half(y1), y1]] : [[mx + half(y0), y0], [mx - half(y1), y1]]);
            g.stroke();
            trace(g, [[mx - half(y1), y1], [mx + half(y1), y1]]);
            g.stroke();
        }
        [[0, 5], [5, 10], [10, 15], [15, 20]].forEach(([from, to], i) => {
            const level = group(v, from, to);
            g.strokeStyle = level > 0.25 ? acc : ink;
            g.globalAlpha = 0.3 + 0.7 * level;
            g.lineWidth = 2.6 + level * 2;
            g.beginPath();
            g.arc(mx + 4, top + 4, 34 + i * 20, -0.62, 0.62);
            g.stroke();
        });
        g.globalAlpha = 1;
        g.strokeStyle = ink;

        // The broadcast, the aerial and its mast down to the TV.
        g.lineWidth = 1.6;
        g.setLineDash([9, 7]);
        g.lineDashOffset = -st.phase;
        trace(g, PATH_F);
        g.stroke();
        g.setLineDash([]);
        arrow(g, PATH_F, flash > 0.3 ? acc : ink, 10);
        if (view.text) ['REGULAR PROGRAM AND TELETEXT', 'ONE-WAY BROADCAST SERVICES;', 'SAME TRANSMISSION AS TV'].forEach((t, i) => put(g, t, 200, 272 + i * 13, label));
        g.lineWidth = 3;
        trace(g, [[404, 244], [572, 244]]);
        g.stroke();
        const teeth = group(v, 8, 20);
        for (let i = 0; i < 24; i++) {
            const x = 408 + i * 7;
            g.strokeStyle = i / 24 < teeth ? acc : ink;
            g.lineWidth = 2;
            trace(g, [[x - 5, 236], [x + 3, 252]]);
            g.stroke();
        }
        g.strokeStyle = ink;
        g.lineWidth = 3;
        for (const pts of [[[572, 222], [572, 266]], [[572, 232], [584, 220]], [[572, 252], [584, 240]]]) {
            trace(g, pts);
            g.stroke();
        }
        g.lineWidth = 1.8;
        trace(g, [[520, 252], [520, 556]]);
        g.stroke();
        g.lineWidth = 3;

        // The computer cabinet: seven lamps (band groups) and a disk that fills with the loudness.
        box3(g, 70, 400, 120, 160, 36, -30, paper);
        for (let i = 0; i < 7; i++) {
            const level = group(v, Math.floor((i * GROUPS) / 7), Math.ceil(((i + 1) * GROUPS) / 7));
            g.lineWidth = 1.8;
            if (level > 0.05) {
                g.fillStyle = acc;
                g.globalAlpha = 0.2 + level * 0.8;
                g.fillRect(82 + i * 14, 414, 9, 9);
                g.globalAlpha = 1;
            }
            g.strokeRect(82 + i * 14, 414, 9, 9);
        }
        g.lineWidth = 3;
        disk(g, 130, 494, 38, 11, 48, clamp01(st.lv * 1.5), look);
        if (view.text) {
            put(g, 'COMPUTER', 70, 326, { ...label, size: 11 });
            put(g, '(PROVIDING THE PAGES OF THIS', 70, 341, label);
            put(g, 'ALBUM AND ALL THE OTHERS)', 70, 354, label);
        }

        // The organisations (the file) and the disk that fills with the time played.
        box3(g, 80, 640, 120, 50, 40, -26, paper);
        disk(g, 50, 604, 22, 8, 40, clamp01(progress), look);
        if (view.text) {
            put(g, 'ORGANIZATIONS', 40, 716, { ...label, size: 10 });
            put(g, `CONTRIBUTING ${say.fmt.toUpperCase()}`.slice(0, 30), 40, 730, { ...label, size: 10 });
        }

        // The telephone network: the left and right level.
        g.lineWidth = 1.8;
        g.setLineDash([8, 6]);
        g.strokeRect(270, 430, 80, 110);
        g.setLineDash([]);
        g.fillStyle = acc;
        g.globalAlpha = 0.85;
        g.fillRect(292, 534 - st.l * 36, 8, st.l * 36);
        g.fillRect(306, 534 - st.r * 36, 8, st.r * 36);
        g.globalAlpha = 1;
        if (view.text) {
            put(g, 'TELEPHONE', 360, 456, { ...label, size: 10.5 });
            put(g, 'NETWORK', 360, 471, { ...label, size: 10.5 });
        }

        // The flowing lines: solid for a page, dashed for a request, with packets riding them.
        const flow = (pts, dashed) => {
            g.lineWidth = 1.8;
            if (dashed) {
                g.setLineDash([9, 7]);
                g.lineDashOffset = -st.phase;
            }
            trace(g, pts);
            g.stroke();
            g.setLineDash([]);
            const len = plen(pts);
            for (let j = 0; j < 3; j++) {
                const [x, y] = along(pts, (st.phase * 1.1 + (j * len) / 3) % len);
                g.beginPath();
                g.arc(x, y, 3.6, 0, 7);
                if (dashed) {
                    g.save();
                    g.fillStyle = paper;
                    g.fill();
                    g.strokeStyle = acc;
                    g.stroke();
                    g.restore();
                } else {
                    g.save();
                    g.fillStyle = acc;
                    g.fill();
                    g.restore();
                }
            }
            arrow(g, pts, flash > 0.3 ? acc : ink, 9);
            g.lineWidth = 3;
        };
        flow(PATH_A, false);
        flow(PATH_B, true);
        flow(PATH_C, false);
        flow(PATH_D, true);
        flow(PATH_E, false);

        // The telephone: its dial spins on each onset.
        g.fillStyle = paper;
        g.beginPath();
        g.moveTo(264, 662);
        g.lineTo(264, 650);
        g.lineTo(276, 630);
        g.lineTo(332, 630);
        g.lineTo(346, 650);
        g.lineTo(346, 662);
        g.closePath();
        g.fill();
        g.stroke();
        g.beginPath();
        g.moveTo(274, 634);
        g.quadraticCurveTo(268, 610, 296, 612);
        g.lineTo(318, 618);
        g.stroke();
        g.beginPath();
        g.arc(324, 642, 11, 0, 7);
        g.fill();
        g.lineWidth = 2;
        g.stroke();
        g.fillStyle = ink;
        for (let i = 0; i < 8; i++) {
            const angle = st.dial + (i * Math.PI * 2) / 10;
            g.beginPath();
            g.arc(324 + 7 * Math.cos(angle), 642 + 7 * Math.sin(angle), 1.2, 0, 7);
            g.fill();
        }
        if (view.text) {
            put(g, 'VIEWDATA CALL', 270, 692, label);
            put(g, 'VIA TELEPHONE', 270, 705, label);
        }

        // The TV and its teletext page: 20 x 14 cells, each 2 x 3 of mosaic.
        g.fillStyle = paper;
        g.lineWidth = 3;
        g.beginPath();
        g.moveTo(392, 570);
        g.lineTo(416, 553);
        g.lineTo(560, 553);
        g.lineTo(576, 570);
        g.closePath();
        g.fill();
        g.stroke();
        g.beginPath();
        g.roundRect(392, 568, 184, 156, 8);
        g.fill();
        g.stroke();
        g.lineWidth = 1.6;
        for (let i = 0; i < 12; i++) {
            trace(g, [[540, 586 + i * 9], [566, 586 + i * 9]]);
            g.stroke();
        }
        g.fillStyle = TT.black;
        g.beginPath();
        g.roundRect(402, 577, 130, 130, 14);
        g.fill();
        g.lineWidth = 2.4;
        g.stroke();
        const ax = 408;
        const ay = 583;
        const cw = 5.9;
        const rh = 8.43;
        const cols = 20;
        const cells = (text, row, color) => {
            g.font = `700 9px ${MONO}`;
            g.textAlign = 'center';
            g.textBaseline = 'alphabetic';
            g.fillStyle = color;
            for (let i = 0; i < text.length; i++) if (text[i] !== ' ') g.fillText(text[i], ax + (i + 0.5) * cw, ay + (row + 0.78) * rh);
            g.textAlign = 'left';
        };
        const foot = `${say.fmt.toUpperCase()} ${say.dr}`.slice(0, 13).padEnd(13) + (say.no ? say.no.padStart(cols - 13) : '');
        const SUBR = 33;
        const sw = cw / 2;
        const sh = rh / 3;
        for (let j = 0; j < cols * 2; j++) {
            const x = ((j + 0.5) / 2 / (cols - 1)) * (GROUPS - 1);
            const n = Math.round(clamp01(at(v, x) * 1.25) * SUBR);
            const peak = Math.round(clamp01(at(pk, x) * 1.25) * SUBR);
            for (let k = 0; k < n; k++) {
                const f = k / SUBR;
                g.fillStyle = f < 0.55 ? TT.green : f < 0.82 ? TT.yellow : TT.red;
                g.fillRect(ax + j * sw + 0.25, ay + 2 * rh + (SUBR - 1 - k) * sh + 0.25, sw - 0.5, sh - 0.5);
            }
            if (peak > n && peak > 0) {
                g.fillStyle = TT.cyan;
                g.fillRect(ax + j * sw + 0.25, ay + 2 * rh + (SUBR - peak) * sh + 0.25, sw - 0.5, sh - 0.5);
            }
        }
        if (view.text) {
            g.fillStyle = TT.blue;
            g.fillRect(ax, ay, cols * cw, rh);
            cells(`P${100 + Number(say.no || 0)} MAGNETITE ${length ? mmss(progress * length).padStart(5, ' ') : ''}`.slice(0, cols), 0, TT.white);
            const t = (say.title || say.name).toUpperCase();
            let line;
            if (t.length <= cols) {
                line = t.padStart(Math.floor((cols + t.length) / 2)).padEnd(cols);
            } else {
                const scroll = `${t}   ${t}`;
                const off = Math.floor(st.phase / 9) % (t.length + 3);
                line = scroll.slice(off, off + cols);
            }
            cells(line, 1, TT.yellow);
            g.fillStyle = TT.black;
            g.fillRect(ax, ay + 13 * rh, cols * cw, rh);
            cells(foot, 13, TT.cyan);
            put(g, 'VIEWDATA', 510, 518, { ...label, size: 10.5, align: 'right' });
            put(g, 'AND TV', 510, 533, { ...label, size: 10.5, align: 'right' });
        }

        // The keypad: a key is pressed on each onset.
        const kp = (u, vv) => [KEY.P0[0] + u * (KEY.P1[0] - KEY.P0[0]) + vv * (KEY.P3[0] - KEY.P0[0]), KEY.P0[1] + u * (KEY.P1[1] - KEY.P0[1]) + vv * (KEY.P3[1] - KEY.P0[1])];
        g.fillStyle = paper;
        g.lineWidth = 3;
        trace(g, [kp(0, 0), kp(1, 0), kp(1, 1), kp(0, 1), kp(0, 0)]);
        g.fill();
        g.stroke();
        for (let i = 0; i < 12; i++) {
            const u = 0.2 + (i % 3) * 0.26;
            const vv = 0.12 + Math.floor(i / 3) * 0.22;
            const q = [kp(u, vv), kp(u + 0.16, vv), kp(u + 0.16, vv + 0.12), kp(u, vv + 0.12)];
            g.lineWidth = 1.2;
            trace(g, [...q, q[0]]);
            if (i === st.key && st.keyT > 0) {
                g.save();
                g.fillStyle = acc;
                g.globalAlpha = st.keyT;
                g.fill();
                g.restore();
            }
            g.stroke();
        }
        g.lineWidth = 1.4;
        trace(g, [[540, 726], [540, 740]]);
        g.stroke();
        if (view.text) put(g, 'KEYPAD', 500, 790, { ...label, size: 10.5, align: 'right' });

        // The legend.
        g.lineWidth = 1.8;
        g.setLineDash([9, 7]);
        trace(g, [[40, 770], [92, 770]]);
        g.stroke();
        g.setLineDash([]);
        trace(g, [[40, 788], [92, 788]]);
        g.stroke();
        if (view.text) {
            put(g, 'USER REQUESTS INFORMATION', 106, 774, { ...label, size: 10.5 });
            put(g, 'COMPUTER RETURNS A VIEWDATA PAGE', 106, 792, { ...label, size: 10.5 });
        }
        g.restore();
    },
};
