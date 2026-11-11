import { hubSpeed, packRadii } from './tape';

const ASPECT = 1.6; // a cassette is 100 x 63 mm
const TEETH = 6;
let angleL = 0;
let angleR = 0;
let glow = 0;

// A little dirt, always the same: smudges and specks on the label, scuffs on the case.
let seed = 7;
const rnd = () => ((seed = (seed * 16807) % 2147483647) / 2147483647);
const DIRT = Array.from({ length: 40 }, () => ({ x: 7 + rnd() * 86, y: 4 + rnd() * 32, r: 0.15 + rnd() * rnd() * 1.5, a: 0.04 + rnd() * 0.15 }));
const SCUFFS = Array.from({ length: 9 }, () => {
    const l = 2 + rnd() * 7;

    return { x: 4 + rnd() * (92 - l), y: 48.5 + rnd() * 10, l, a: 0.05 + rnd() * 0.1 }; // always inside the case
});

/**
 * A compact cassette in the style of a black chrome-type tape, drawn in millimetres: a cream writing area with ruled lines
 * (the title and artist typed on the first), the window with translucent hubs and a counter scale, a black brand band with the
 * tape length, and the darker lower part with the guide posts and the head. The packs show how far through the track you are
 * (the left empties, the right fills), the small pack turns faster, counter-clockwise like a real one, and the head
 * glows with the level. It stops when paused. Case and accents take the theme's colours.
 */
export default {
    id: 'cassette',
    needs: ['levels'],
    label: 'Cassette',
    params: {
        shell: null, // the case; defaults to the theme surface, darkened
        paper: null, // the writing area; defaults to the theme text
        accent: null, // the logo and the head's glow; defaults to the theme accent
        pack: '#55524d', // the wound tape
        length: 90, // minutes, on the band
        speed: 2.4, // rad/s of a full pack
    },

    draw(g, { levels, playing }, { w, h, colors, params, dt, progress = 0, track = null }) {
        const p = { ...this.params, ...params };
        const shell = p.shell ?? colors.surface;
        const paper = p.paper ?? colors.text;
        const accent = p.accent ?? colors.accent;

        const W = Math.min(w * 0.96, h * 0.96 * ASPECT);
        const H = W / ASPECT;
        g.save();
        g.translate((w - W) / 2, (h - H) / 2);
        const u = W / 100;
        g.scale(u, u); // from here on: millimetres
        const HM = H / u;

        const level = Math.max(levels?.l?.rms ?? 0, levels?.r?.rms ?? 0);
        glow += (Math.min(1, level * 3) - glow) * (1 - Math.exp(-dt * 12));

        const rMin = 6.1; // the hub's ring; a full pack reaches past the window's edge, which clips it
        const rMax = 10.6;
        const [rl, rr] = packRadii(progress, rMin, rMax);
        if (playing) {
            angleL -= hubSpeed(rl, rMax, p.speed) * dt; // both hubs turn counter-clockwise
            angleR -= hubSpeed(rr, rMax, p.speed) * dt;
        }
        const cy = 28.8;
        const cxL = 29;
        const cxR = 71;

        const rect = (x, y, rw, rh, r) => {
            g.beginPath();
            g.roundRect(x, y, rw, rh, r);
        };
        const disc = (x, y, r) => {
            g.beginPath();
            g.arc(x, y, r, 0, Math.PI * 2);
        };

        // the case: matte, darker than the theme's surface, with a soft shine from the top
        g.fillStyle = shell;
        rect(0, 0, 100, HM, 3);
        g.fill();
        g.fillStyle = 'rgba(0,0,0,0.4)';
        rect(0, 0, 100, HM, 3);
        g.fill();
        const shine = g.createLinearGradient(0, 0, 0, HM);
        shine.addColorStop(0, 'rgba(255,255,255,0.09)');
        shine.addColorStop(0.5, 'rgba(255,255,255,0)');
        shine.addColorStop(1, 'rgba(0,0,0,0.2)');
        g.fillStyle = shine;
        rect(0, 0, 100, HM, 3);
        g.fill();
        g.strokeStyle = 'rgba(0,0,0,0.55)';
        g.lineWidth = 0.5;
        g.stroke();
        for (const [x, y] of [[4, 4], [96, 4], [4, 58.5], [96, 58.5]]) {
            g.fillStyle = 'rgba(160,160,160,0.55)';
            disc(x, y, 1.7);
            g.fill();
            g.strokeStyle = 'rgba(0,0,0,0.55)';
            g.lineWidth = 0.4;
            g.beginPath();
            g.moveTo(x - 1.1, y - 0.5);
            g.lineTo(x + 1.1, y + 0.5);
            g.stroke();
        }

        // the writing area: ruled lines, with the title and artist typed on a strip at the top
        g.fillStyle = paper;
        g.globalAlpha = 0.94;
        rect(7, 4, 86, 31.8, 1.2);
        g.fill();
        g.globalAlpha = 1;
        for (const d of DIRT) {
            g.fillStyle = `rgba(70,50,30,${d.a})`;
            disc(d.x, d.y, d.r);
            g.fill();
        }
        g.strokeStyle = 'rgba(60,45,30,0.45)';
        g.lineWidth = 0.28;
        for (const y of [12.6, 17.2]) {
            g.beginPath();
            g.moveTo(7, y);
            g.lineTo(93, y);
            g.stroke();
        }
        g.fillStyle = 'rgba(255,255,255,0.45)';
        rect(10, 6, 80, 6, 0.6);
        g.fill();
        typed(g, [track?.title ?? 'Magnetite', track?.artist].filter(Boolean).join(' - ').toUpperCase(), 11.5, 10.2, 77, '#2a2520');

        // the window with the two packs and their translucent hubs
        g.fillStyle = '#0b0b0b';
        rect(12.5, 21.8, 75, 14, 7);
        g.fill();
        g.save();
        rect(12.5, 21.8, 75, 14, 7);
        g.clip();
        for (const [cx, r, angle] of [[cxL, rl, angleL], [cxR, rr, angleR]]) {
            g.fillStyle = p.pack;
            disc(cx, cy, r);
            g.fill();
            g.strokeStyle = 'rgba(255,255,255,0.08)';
            g.lineWidth = 0.3;
            for (let k = r - 1.1; k > rMin; k -= 1.1) {
                disc(cx, cy, k);
                g.stroke();
            }
            g.fillStyle = 'rgba(215,215,215,0.82)'; // the hub: a wide grey ring round a dark opening, with teeth pointing in, that turns
            disc(cx, cy, 6);
            g.fill();
            g.fillStyle = '#0b0b0b';
            disc(cx, cy, 4.6);
            g.fill();
            g.fillStyle = 'rgba(215,215,215,0.92)';
            for (let i = 0; i < TEETH; i++) {
                const a = angle + (i * 2 * Math.PI) / TEETH;
                g.save();
                g.translate(cx, cy);
                g.rotate(a);
                g.fillRect(3.1, -0.8, 1.6, 1.6);
                g.restore();
            }
        }
        // the counter scale between the hubs
        g.strokeStyle = 'rgba(255,255,255,0.5)';
        g.lineWidth = 0.25;
        for (let i = 0; i < 11; i++) {
            const x = 44.5 + i * 1.1;
            g.beginPath();
            g.moveTo(x, 34.8);
            g.lineTo(x, i % 5 === 0 ? 33 : 33.9);
            g.stroke();
        }
        const glass = g.createLinearGradient(12.5, 21.8, 50, 35.8);
        glass.addColorStop(0, 'rgba(255,255,255,0.12)');
        glass.addColorStop(0.45, 'rgba(255,255,255,0)');
        g.fillStyle = glass;
        g.fillRect(12.5, 21.8, 75, 14);
        g.restore();

        // the brand band: black, the logo, the name, the type and the tape length
        g.fillStyle = '#0d0d0d';
        g.fillRect(7, 36, 86, 9.2);
        g.fillStyle = accent;
        rect(9, 38, 5.6, 5.6, 0.6);
        g.fill();
        g.strokeStyle = '#0d0d0d';
        g.lineWidth = 0.35;
        for (const r of [0.8, 1.7]) {
            disc(11.8, 40.8, r);
            g.stroke();
        }
        g.fillStyle = '#f2f2f2';
        g.textBaseline = 'alphabetic';
        g.font = '800 4.6px system-ui, sans-serif';
        g.fillText('MAGNETITE', 16.8, 42.4);
        g.font = '2.4px system-ui, sans-serif';
        g.globalAlpha = 0.8;
        g.fillText('ferrite super', 49, 42.4);
        g.globalAlpha = 1;
        g.font = '800 7px system-ui, sans-serif';
        g.textAlign = 'right';
        g.fillText(String(p.length), 91.5, 43.2);
        g.textAlign = 'left';
        g.fillStyle = paper;
        g.globalAlpha = 0.9;
        g.fillRect(64.5, 38.2, 13, 5);
        g.globalAlpha = 1;
        g.fillStyle = '#2a2520';
        g.font = '1.9px system-ui, sans-serif';
        g.fillText('BIAS HIGH', 65.6, 40.2);
        g.fillText('EQ 70 µs', 65.6, 42.5);

        // the lower part: darker, with holes, the opening, the guide posts and the head
        g.fillStyle = 'rgba(0,0,0,0.38)';
        g.beginPath();
        g.moveTo(12, 47.4);
        g.lineTo(88, 47.4);
        g.lineTo(83, 60.6);
        g.lineTo(17, 60.6);
        g.closePath();
        g.fill();
        g.strokeStyle = '#fff';
        g.lineWidth = 0.25;
        for (const c of SCUFFS) {
            g.globalAlpha = c.a;
            g.beginPath();
            g.moveTo(c.x, c.y);
            g.lineTo(c.x + c.l, c.y + c.l * 0.15);
            g.stroke();
        }
        g.globalAlpha = 1;
        const postL = [25, 54.6];
        const postR = [75, 54.6];

        for (const [x, y] of [postL, postR]) {
            g.fillStyle = '#0b0b0b';
            disc(x, y, 2.3);
            g.fill();
            g.strokeStyle = paper;
            g.globalAlpha = 0.6;
            g.lineWidth = 0.4;
            g.stroke();
            g.globalAlpha = 1;
        }

        // the head and its pad, glowing with the level
        g.fillStyle = '#0b0b0b';
        rect(44, 50.4, 12, 6.2, 1);
        g.fill();
        g.fillStyle = accent;
        g.globalAlpha = 0.35 + 0.65 * glow;
        g.fillRect(47, 53, 6, 1.7);
        g.globalAlpha = 1;
        g.fillStyle = 'rgba(255,255,255,0.3)';
        for (const x of [31, 69]) {
            disc(x, 57.6, 0.9);
            g.fill();
        }

        g.restore();
    },
};

/** Typewriter text, a little uneven like a worn ribbon: monospace, each letter a touch off the line and of its own strength. Shortened with … to fit `max` mm. */
function typed(g, text, x, y, max, ink) {
    g.font = '3.4px "Courier New", Courier, monospace';
    const step = g.measureText('M').width;
    const room = Math.max(1, Math.floor(max / step));
    const t = text.length > room ? text.slice(0, room - 1) + '…' : text;
    g.fillStyle = ink;
    [...t].forEach((ch, i) => {
        const k = (i * 37 + 11) % 10;
        g.globalAlpha = 0.62 + k * 0.035;
        g.fillText(ch, x + i * step, y + (k % 3 - 1) * 0.12);
    });
    g.globalAlpha = 1;
}
