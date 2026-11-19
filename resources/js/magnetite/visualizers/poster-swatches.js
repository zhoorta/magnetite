import { stateOf, Bars, begin, drawName, fitSize, luminance, mixHex, palette, posterText, readableOn } from './poster.js';

const BANDS = 30;
const SWATCHES = [{ label: 'highs', from: 20, to: 30 }, { label: 'mids', from: 8, to: 20 }, { label: 'bass', from: 0, to: 8 }];

const make = () => ({ bars: new Bars(BANDS) });

const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

/** Mean level of band groups `from`..`to`, brought up a little so the top of the scale is reachable. */
function group(v, from, to) {
    let t = 0;
    for (let i = from; i < to; i++) t += v[i];

    return Math.min(1, (t / (to - from)) * 1.5);
}

/** A colour for text on the card: the poster's own colour when it reads on the card's colour, else whichever of the card's two usual inks does. */
const onCard = (color, card) => (Math.abs(luminance(color) - luminance(card)) > 0.3 ? color : readableOn(card, [color, '#161616', '#f5f5f5']));

/** The title as three words, filled up from the year or the track number when it has fewer. */
function chunks(say) {
    const words = (say.title || say.name).split(/\s+/).filter(Boolean);
    if (words.length >= 3) return [words[0], words[1], words.slice(2).join(' ')];

    return [...words, say.year || say.no, say.dur].filter(Boolean).slice(0, 3);
}

function put(g, text, x, y, { size, font, color, weight = 800, align = 'left', spacing = 0 }) {
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
 * A palette-reference card: a dark rounded card with three big rounded swatches, each with a huge word of the title on it and its hex code. The three
 * swatches are three band groups (the highs on top, the bass at the bottom): the colour moves from its base towards a lighter one with the level and
 * the hex code written on it is the colour right now, rewritten every frame; a thin bar along the foot is the level. The big number is the track
 * number and the slate time at the bottom is the time played.
 */
export default {
    id: 'poster-swatches',
    needs: ['bands'],
    label: 'Poster: Swatches',
    params: {
        range: 40,
        attack: 0.12,
        release: 0.4,
        text: 1,
        theme: 1,
        paper: '#c9c7c2',
        dark: '#1b1b1b',
        cream: '#e8d8c9',
        mid: '#4b607f',
        warm: '#f3701e',
    },

    draw(g, { bands }, { w, h, colors, params, dt, track, progress = 0 }) {
        const p = { ...this.params, ...params };
        const { bars } = stateOf(g, make);
        const look = palette(p, colors, ['paper', 'dark']);
        const colour = Number(p.theme) >= 2 ? { cream: colors.text, mid: colors.accent2, warm: colors.accent } : { cream: p.cream, mid: p.mid, warm: p.warm };
        const view = begin(g, w, h, look.paper, { p });
        const own = { cream: onCard(colour.cream, look.dark), mid: onCard(colour.mid, look.dark), warm: onCard(colour.warm, look.dark) };
        const v = bars.update(bands, dt, p);
        const font = colors.font;

        // The card.
        g.fillStyle = look.dark;
        g.beginPath();
        g.roundRect(14, 14, 572, 772, 46);
        g.fill();

        // The three swatches.
        const base = [colour.cream, colour.mid, colour.warm];
        const say = posterText(track);
        const words = chunks(say);
        const codes = [];
        SWATCHES.forEach((sw, i) => {
            const level = group(v, sw.from, sw.to);
            const color = mixHex(base[i], '#ffffff', level * 0.42);
            const y = 190 + i * 160;
            codes.push(color);
            g.fillStyle = color;
            g.beginPath();
            g.roundRect(40, y, 520, 146, 26);
            g.fill();
            // The level along the foot of the swatch.
            const ink = readableOn(color, [look.dark, '#111111', '#f5f5f5']);
            g.save();
            g.beginPath();
            g.roundRect(40, y, 520, 146, 26);
            g.clip();
            g.fillStyle = ink;
            g.globalAlpha = 0.85;
            g.fillRect(40, y + 138, 520 * level, 8);
            g.restore();
            if (view.text) {
                const word = (words[i] ?? '').toUpperCase();
                const size = fitSize(g, word, 900, font, 330, 124, 30);
                put(g, word, 58, y + 112 - (124 - size) * 0.22, { size, font, color: ink, weight: 900, spacing: -2 });
                put(g, sw.label, 544, y + 56, { size: 46, font, color: ink, weight: 800, align: 'right', spacing: -1 });
                put(g, color.toLowerCase(), 544, y + 122, { size: 38, font, color: ink, weight: 700, align: 'right', spacing: -0.5 });
            }
        });

        if (view.text) {
            // The header: the track number, the album, the artist, "now playing".
            put(g, say.no ? String(Number(say.no)) : '', 40, 168, { size: 158, font, color: own.cream, weight: 900, spacing: -6 });
            put(g, (say.album || say.fmt).toUpperCase(), 176, 80, { size: 20, font, color: own.warm, weight: 700, spacing: 1 });
            g.fillStyle = own.cream;
            g.beginPath();
            g.arc(310, 74, 8, 0, 7);
            g.fill();
            drawName(g, say.title ? say.name : '', 176, 120, { font, maxW: 196, size: 52, color: own.mid, weight: 800, spacing: -1 });
            put(g, 'NOW', 560, 96, { size: 42, font, color: own.cream, align: 'right', spacing: -1 });
            put(g, 'PLAYING', 560, 140, { size: 42, font, color: own.cream, align: 'right', spacing: -1 });
            // The foot: the time played and the format.
            const length = track?.duration ?? 0;
            put(g, length ? mmss(progress * length) : '', 40, 750, { size: 86, font, color: own.mid, weight: 900, spacing: -4 });
            put(g, length ? `/ ${mmss(length)}` : '', 244, 748, { size: 22, font, color: own.mid, spacing: 1 });
            put(g, say.fmt, 560, 724, { size: 20, font, color: own.mid, weight: 700, align: 'right', spacing: 0.5 });
            put(g, [say.dr, say.no].filter(Boolean).join('  ·  '), 560, 750, { size: 20, font, color: own.mid, weight: 700, align: 'right', spacing: 0.5 });
        }
        g.restore();
    },
};
