// Third-octave real-time analyser: 31 bands from 20 Hz to 20 kHz, dB scale with a full-scale sine at 0,
// segmented like the LED meters, with a peak marker per band that holds and then falls.

import { BAND_LABELS, BAND_FLOOR } from '../bands';

const N = BAND_LABELS.length;
const value = new Float32Array(N).fill(BAND_FLOOR); // smoothed level, dB
const peak = new Float32Array(N).fill(BAND_FLOOR);
const hold = new Float32Array(N);
const NAMED = new Map([[31.5, '31'], [63, '63'], [125, '125'], [250, '250'], [500, '500'], [1000, '1k'], [2000, '2k'], [4000, '4k'], [8000, '8k'], [16000, '16k']]);
const SCALE = [-60, -48, -36, -24, -12];

export default {
    id: 'third-octave',
    needs: ['bands'],
    label: 'Third-octave',
    params: {
        min: -72,
        max: -12,
        segment: 3, // dB per lit segment
        release: 0.35, // seconds for a band to fall; the rise is instant
        hold: 1.5, // seconds the peak marker stays
        fall: 18, // dB per second once the hold is over
    },

    draw(g, { bands }, { w, h, colors, params, dt }) {
        const p = { ...this.params, ...params };

        for (let i = 0; i < N; i++) {
            const db = bands[i];
            value[i] = db > value[i] ? db : db + (value[i] - db) * Math.exp(-dt / (p.release / 4.6));
            if (db >= peak[i]) {
                peak[i] = db;
                hold[i] = p.hold;
            } else if (hold[i] > 0) {
                hold[i] -= dt;
            } else {
                peak[i] = Math.max(db, peak[i] - p.fall * dt);
            }
        }

        const font = Math.max(9, Math.min(h * 0.032, w * 0.016));
        const left = font * 2.6;
        const bottom = h - font * 2;
        const top = font;
        const plotW = w - left - font * 0.5;
        const plotH = bottom - top;
        const slot = plotW / N;
        const bw = slot * 0.72;
        const segs = Math.round((p.max - p.min) / p.segment);
        const segH = plotH / segs;
        const lit = (db) => Math.max(0, Math.min(segs, Math.floor((db - p.min) / p.segment)));

        for (let i = 0; i < N; i++) {
            const x = left + i * slot + (slot - bw) / 2;
            const n = lit(value[i]);
            const pk = Math.min(segs - 1, lit(peak[i]));

            for (let s = 0; s < segs; s++) {
                const on = s < n;
                const isPeak = s === pk && peak[i] > p.min;
                g.globalAlpha = on || isPeak ? 1 : 0.1;
                g.fillStyle = isPeak && s >= n ? colors.accent2 : s > segs * 0.85 ? colors.accent2 : colors.accent;
                g.fillRect(x, bottom - (s + 1) * segH + segH * 0.12, bw, segH * 0.76);
            }
        }
        g.globalAlpha = 1;

        g.fillStyle = colors.muted;
        g.font = `${Math.round(font)}px ${colors.font}`;
        g.textBaseline = 'middle';
        g.textAlign = 'right';
        for (const db of SCALE) {
            if (db < p.min || db > p.max) continue;
            g.fillText(String(db), left - font * 0.4, bottom - ((db - p.min) / (p.max - p.min)) * plotH);
        }
        g.textAlign = 'center';
        g.textBaseline = 'top';
        BAND_LABELS.forEach((f, i) => {
            const label = NAMED.get(f);
            if (label) g.fillText(label, left + i * slot + slot / 2, bottom + font * 0.5);
        });
    },
};
