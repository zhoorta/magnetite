import { clamp01 } from './meter.js';
import { stateOf, Bars, begin, drawName, isDark, palette, posterText } from './poster.js';

// The Berlin U-Bahn map (Wikimedia Commons, File:U-Bahn_Berlin_-_Netzplan.svg by Arbalete, CC BY-SA 4.0), turned a quarter so its long side runs down
// the poster. `berlin-ink.png` is the neutral part of that drawing (station rings and outlines, 1660 x 1004, a mask: alpha is how black a pixel was);
// the station names, the line badges and the routes themselves are not in it. The routes are drawn here from their path data, dim, with the real
// colours of their lines coming up along them. The path data is a modified copy of the drawing's own, so the poster prints the credit.
const CREDIT = 'Map: Arbalete, CC BY-SA 4.0, Wikimedia Commons (modified)';
const MAP_W = 1660.094;
const MAP_H = 1003.688;
const LAYER = [-4368.7187, -728.25]; // the drawing's own offset of its paths
const SCALE = 0.42;
const CENTRE = [300, 412];

// The 16 routes of the nine lines (path data in the drawing's coordinates; `length` is measured).
const ROUTES = [
    { line: 'U5', width: 8, dashed: false, length: 970.5, d: 'm 5108.9751,1174.2292 c 0,0 591.1222,2e-4 600.2262,2e-4 9.104,0 14.1007,5.8084 14.1007,5.8084 l 56.621,56.6212 c 0,0 4.7336,5.8088 14.1028,5.8089 9.3692,0 14.2304,-5.9221 14.2304,-5.9221 l 171.8271,-171.8269' },
    { line: 'U8', width: 8, dashed: false, length: 666.4, d: 'M 5386.7812,1537.8275 V 1153.795 c 0,0 0.574,-7.6404 -5.8635,-14.0779 -3.2842,-3.2841 -88.7947,-88.795 -188.5758,-188.57603' },
    { line: 'U6', width: 8, dashed: false, length: 834.2, d: 'm 5269.0224,1666.8405 v -498.6816 c 0,0 0.574,-7.6403 -5.8635,-14.0778 -6.4375,-6.4375 -226.1742,-226.17432 -226.1742,-226.17432' },
    { line: 'U6', width: 4, dashed: true, length: 153, d: 'm 5038.7739,929.92796 c -6.4375,-6.4375 -108.1742,-108.17432 -108.1742,-108.17432' },
    { line: 'U7', width: 8, dashed: false, length: 812.7, d: 'm 5053.8075,1447.536 53.5478,-2e-4 c 0,0 7.6468,0.6134 14.1269,-5.8668 26.3834,-26.3835 76.4211,-76.4207 76.4211,-76.4207 0,0 5.0603,-5.8483 14.1643,-5.8483 0,0 7.8339,-0.4179 14.1464,5.8945 6.3127,6.3125 57.4028,57.7563 57.4028,57.7563 0,0 4.8894,5.8269 14.1392,5.8266 9.2501,3e-4 128.3917,10e-5 128.3917,10e-5 0,0 7.7924,-0.5044 14.136,5.8395 6.3439,6.3436 256.4139,256.4139 256.4139,256.4139' },
    { line: 'U7', width: 8, dashed: false, length: 912.9, d: 'm 5080.2396,1447.5231 h -232.68 c 0,0 -7.7436,0.5695 -14.1738,-5.8606 -6.4304,-6.4305 -24.5872,-24.5873 -24.5872,-24.5873 0,0 -5.8935,-4.9886 -5.8935,-14.1762 v -243.682 c 0,0 0.464,-7.7892 -5.8116,-14.0647 -6.2755,-6.2756 -12.551,-5.9221 -14.2304,-5.922 -1.6795,-10e-5 -338.7044,-10e-5 -338.7044,-10e-5' },
    { line: 'U9', width: 8, dashed: false, length: 318.8, d: 'm 4901.2653,1623.5379 c 0,0 34.4558,-34.4558 40.831,-40.8308 6.375,-6.375 5.8321,-14.1121 5.8321,-14.1121 v -245.4154' },
    { line: 'U9', width: 8, dashed: false, length: 485.3, d: 'm 4947.9284,1334.5938 v -133.1046 c 0,0 -0.663,-7.635 5.8336,-14.1315 l 237.8972,-237.89721' },
    { line: 'U8', width: 8, dashed: false, length: 236, d: 'm 5084.0858,756.08708 v 78.56239 c 0,0 -0.574,7.64044 5.8635,14.07794 3.2842,3.28422 0.4636,0.46339 100.2446,100.24447' },
    { line: 'U3', width: 8, dashed: false, length: 1007.3, d: 'm 4664.7384,1671.2355 317.7876,-317.7877 c 0,0 5.2643,-5.8695 14.1706,-5.8695 8.9063,0 542.1549,0 542.1549,0' },
    { line: 'U1', width: 8, dashed: false, length: 659.3, d: 'm 4880.2939,1335.6442 659.2502,2e-4' },
    { line: 'U2', width: 8, dashed: false, length: 419.9, d: 'm 4803.3694,1260.4551 c 70.6487,-10e-5 113.8576,-10e-5 117.5589,-10e-5 9.1251,-10e-5 14.1385,5.8283 14.1385,5.8283 l 52.1155,52.1155 c 0,0 5.2646,5.8696 14.1707,5.8694 2.3364,10e-5 40.1973,2e-4 68.1435,2e-4 0,0 7.1953,-0.1555 12.7962,5.4429 l 38.1975,38.1975 c 0,0 5.1767,5.864 14.114,5.8641 8.9374,0 13.6874,-5.375 14.1876,-5.875 0.5001,-0.5001 5.8126,-5.3125 5.8122,-14.1249 0,-3.7054 0.088,-13.6545 0.088,-13.6135' },
    { line: 'U2', width: 8, dashed: false, length: 243.3, d: 'm 4801.6015,1260.4548 c -101.9792,0 -126.3024,0 -126.3024,0 0,0 -7.4734,0.861 -14.1468,-5.8124 -6.6733,-6.6733 -71.6117,-71.6119 -71.6117,-71.6119' },
    { line: 'U2', width: 8, dashed: false, length: 184.8, d: 'm 5154.605,1336.1594 c 0,-20.2688 0,-59.938 0,-59.938 0,0 -0.2552,-8.049 5.8383,-14.1429 6.0938,-6.0937 13.1251,-5.8749 14.1876,-5.8749 0.549,0 57.2178,0 93.4176,0' },
    { line: 'U2', width: 8, dashed: false, length: 377.8, d: 'm 5252.7607,1256.2036 c 31.6906,10e-5 43.7308,0 43.7308,0 0,0 7.9799,0.326 14.1048,-5.7992 6.1249,-6.1251 122.4735,-122.4736 122.4735,-122.4736 0,0 5.8331,-5.1409 5.8332,-14.1123 0,-8.9715 -1e-4,-129.53908 -1e-4,-129.53908' },
    { line: 'U4', width: 8, dashed: false, length: 181.9, d: 'm 5053.8422,1348.8155 3e-4,181.9355' },
];

// The eight band groups, the bass first; U5 is the time played.
const ORDER = ['U9', 'U8', 'U7', 'U6', 'U3', 'U1', 'U2', 'U4'];
const COLOR = { U1: '#7dad4c', U2: '#da421e', U3: '#007a5b', U4: '#f0d722', U5: '#7e5330', U6: '#8c6dab', U7: '#528dba', U8: '#224f86', U9: '#f3791d' };
// On a dark ground the darkest lines would sink, so they are lifted.
const COLOR_DARK = { ...COLOR, U3: '#13a37c', U5: '#b98457', U8: '#4f86c8' };

const make = () => ({ bars: new Bars(8), pk: new Float32Array(8), tints: new Map(), st: { shapes: null } });

// The picture is a shared asset (not drawing state): loaded once, when the module is first used. If it cannot be loaded the routes and the text are
// still drawn.
let picture = null;
function load() {
    if (picture !== null || typeof Image === 'undefined') return;
    try {
        picture = new Image();
        picture.decoding = 'async';
        picture.src = new URL('./berlin-ink.png', import.meta.url).href;
    } catch {
        picture = false;
    }
}
load();

function pictureReady() {
    load();

    return picture && picture.complete && picture.naturalWidth > 0 ? picture : null;
}

/** The map's ink in one colour (made once per colour and canvas). */
function tinted(cache, color) {
    if (!cache.has(color)) {
        const c = document.createElement('canvas');
        c.width = picture.naturalWidth;
        c.height = picture.naturalHeight;
        const t = c.getContext('2d');
        t.fillStyle = color;
        t.fillRect(0, 0, c.width, c.height);
        t.globalCompositeOperation = 'destination-in';
        t.drawImage(picture, 0, 0);
        cache.set(color, c);
    }

    return cache.get(color);
}

// A point along a route needs the browser's own path measuring; without a document (Node) there are no points and the markers are left out.
let probe = null;
function pointAt(route, fraction) {
    if (typeof document === 'undefined' || !document.createElementNS) return null;
    if (!route.el) {
        probe ??= document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        route.el = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        route.el.setAttribute('d', route.d);
        probe.append(route.el);
    }

    return route.el.getPointAtLength(route.length * clamp01(fraction));
}

const longest = {};
for (const route of ROUTES) if (!longest[route.line] || route.length > longest[route.line].length) longest[route.line] = route;

const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

function put(g, text, x, y, { size, font, color, weight = 700, align = 'left', spacing = 0 }) {
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
 * The Berlin U-Bahn map as a meter. Eight lines are the eight band groups (U9 the bass, U4 the highs): a line is coloured from the start of its route
 * as far as its band's level, and a white dot with a coloured ring rides at the held peak. U5 is the time played: a train runs along it and the line is
 * coloured behind it. The station rings sit on top, in the poster's ink colour.
 */
export default {
    id: 'poster-network',
    needs: ['bands'],
    label: 'Poster: Network',
    params: {
        range: 40,
        attack: 0.15,
        release: 0.5,
        text: 1,
        theme: 1,
        paper: '#ffffff',
        ink: '#15171b',
    },

    draw(g, { bands }, { w, h, colors, params, dt, track, progress = 0 }) {
        const p = { ...this.params, ...params };
        const { bars, pk, tints, st } = stateOf(g, make);
        const look = palette(p, colors, ['paper', 'ink']);
        const view = begin(g, w, h, look.paper, { p });
        const lines = isDark(look.paper) ? COLOR_DARK : COLOR;
        const v = bars.update(bands, dt, p);
        for (let i = 0; i < 8; i++) pk[i] = Math.max(v[i], pk[i] - dt * 0.18);
        const level = Object.fromEntries(ORDER.map((line, i) => [line, clamp01(v[i] * 1.5)]));
        const held = Object.fromEntries(ORDER.map((line, i) => [line, clamp01(pk[i] * 1.5)]));

        // The routes, dim, then lit from the start as far as the level; the map's station rings over them. Everything is in the map's own coordinates,
        // turned a quarter and scaled onto the poster.
        g.save();
        g.translate(CENTRE[0], CENTRE[1]);
        g.rotate(-Math.PI / 2);
        g.scale(SCALE, SCALE);
        g.translate(-MAP_W / 2, -MAP_H / 2);
        g.save();
        g.translate(LAYER[0], LAYER[1]);
        g.lineCap = 'round';
        g.lineJoin = 'round';
        st.shapes ??= ROUTES.map((route) => (typeof Path2D === 'undefined' ? null : new Path2D(route.d)));
        ROUTES.forEach((route, i) => {
            const shape = st.shapes[i];
            if (!shape) return;
            g.lineWidth = route.width;
            g.setLineDash(route.dashed ? [4, 4] : []);
            g.strokeStyle = look.ink;
            g.globalAlpha = 0.16;
            g.stroke(shape);
            g.globalAlpha = 1;
            const shown = route.line === 'U5' ? 0.02 + 0.96 * clamp01(progress) : level[route.line];
            g.strokeStyle = lines[route.line];
            if (route.dashed) {
                g.globalAlpha = 0.25 + 0.75 * shown;
                g.stroke(shape);
                g.globalAlpha = 1;
            } else {
                g.setLineDash([route.length * shown, route.length * 2]);
                g.stroke(shape);
            }
        });
        g.setLineDash([]);
        // A dot at each line's held peak, the train on U5.
        for (const line of Object.keys(longest)) {
            const at = pointAt(longest[line], line === 'U5' ? 0.02 + 0.96 * clamp01(progress) : held[line]);
            if (!at) continue;
            g.beginPath();
            g.arc(at.x, at.y, line === 'U5' ? 11 : 6, 0, 7);
            g.fillStyle = look.paper;
            g.fill();
            g.lineWidth = line === 'U5' ? 5 : 3;
            g.strokeStyle = lines[line];
            g.stroke();
            if (line === 'U5') {
                g.beginPath();
                g.arc(at.x, at.y, 3.6, 0, 7);
                g.fillStyle = lines.U5;
                g.fill();
            }
        }
        g.restore();
        if (pictureReady()) g.drawImage(tinted(tints, look.ink), 0, 0, MAP_W, MAP_H);
        g.restore();

        if (view.text) {
            const say = posterText(track);
            const font = colors.font;
            const muted = { font, color: look.ink, weight: 500 };
            const length = track?.duration ?? 0;
            drawName(g, say.title || say.name, 30, 56, { font, maxW: 520, size: 34, color: look.ink, weight: 700, spacing: -0.6 });
            g.globalAlpha = 0.6;
            put(g, [say.title ? say.name : '', say.album, say.year].filter(Boolean).join(' · '), 30, 74, { ...muted, size: 12, spacing: 0.2 });
            put(g, [say.fmt, say.dr].filter(Boolean).join(' · '), 570, 36, { ...muted, size: 12, align: 'right', spacing: 0.2 });
            // The key: the nine lines as badges, the bass first, U5 for the time.
            [...ORDER, 'U5'].forEach((line, i) => {
                const x = 30 + i * 60;
                g.globalAlpha = 1;
                g.fillStyle = lines[line];
                g.beginPath();
                g.roundRect(x, 768, 26, 14, 3);
                g.fill();
                put(g, line, x + 13, 779, { font, color: '#ffffff', size: 10, align: 'center' });
            });
            g.globalAlpha = 0.6;
            put(g, 'BASS', 30, 794, { ...muted, size: 8, weight: 600, spacing: 1.5 });
            put(g, 'HIGHS', 30 + 7 * 60 + 26, 794, { ...muted, size: 8, weight: 600, align: 'right', spacing: 1.5 });
            put(g, length ? `TIME  ${mmss(progress * length)} / ${mmss(length)}` : '', 570, 794, { ...muted, size: 8, weight: 600, align: 'right', spacing: 1.5 });
            put(g, CREDIT, 570, 52, { ...muted, size: 6.5, align: 'right' });
            g.globalAlpha = 1;
        }
        g.restore();
    },
};
