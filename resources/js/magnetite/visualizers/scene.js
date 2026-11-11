import { Beat, norm } from './beat';
import { BAND_LABELS } from '../bands';
import { analyze, dominantHue, hueColor, measure, suggest } from './scene-auto';

const N = BAND_LABELS.length;
const SMALL = 128; // analysis grid, pixels wide
const LAYER = 640; // layer canvases, pixels on the long side

/**
 * Any picture as a meter. The bright blobs found in it carry the music: the biggest pulses with the kick, the others light
 * up one band each from the left (bass) to the right (treble), and the whole picture swells a little with the level. Each layer
 * is the picture's own pixels inside the blob, added on top of it, so the shapes light up exactly and the colours stay the picture's.
 */
const tints = new WeakMap(); // layer canvas -> { colour -> the same shape filled with that colour }

function tinted(layer, colour) {
    let byColour = tints.get(layer);
    if (!byColour) tints.set(layer, (byColour = new Map()));
    if (!byColour.has(colour)) {
        const c = new OffscreenCanvas(layer.width, layer.height);
        const g = c.getContext('2d');
        g.drawImage(layer, 0, 0);
        g.globalCompositeOperation = 'source-in';
        g.fillStyle = colour;
        g.fillRect(0, 0, c.width, c.height);
        byColour.set(colour, c);
    }

    return byColour.get(colour);
}

export function createScene(id = 'scene', label = 'Scene', defaults = {}, settings = {}) {
    const beat = new Beat();
    const level = new Float32Array(N);
    let src = null;
    let img = null;
    let layers = null;
    let kick = 0;
    let loud = 0;
    let t = 0;

    function build(image, tint) {
        const k = SMALL / image.width;
        const sw = SMALL;
        const sh = Math.max(1, Math.round(image.height * k));
        const small = new OffscreenCanvas(sw, sh);
        const sg = small.getContext('2d', { willReadFrequently: true });
        sg.drawImage(image, 0, 0, sw, sh);
        const px = sg.getImageData(0, 0, sw, sh).data;
        const luma = new Float32Array(sw * sh);
        const hue = new Float32Array(sw * sh);
        const sat = new Float32Array(sw * sh);
        for (let i = 0; i < luma.length; i++) {
            const r = px[i * 4] / 255;
            const gr = px[i * 4 + 1] / 255;
            const b = px[i * 4 + 2] / 255;
            luma[i] = 0.2126 * r + 0.7152 * gr + 0.0722 * b;
            const mx = Math.max(r, gr, b);
            const d = mx - Math.min(r, gr, b);
            sat[i] = mx ? d / mx : 0;
            const hh = !d ? 0 : mx === r ? ((gr - b) / d + 6) % 6 : mx === gr ? (b - r) / d + 2 : (r - gr) / d + 4;
            hue[i] = hh / 6;
        }
        const found = analyze(luma, sw, sh, { hue, sat });
        const dom = dominantHue(hue, sat, luma);
        const pictureColor = dom ? hueColor(dom.hue) : null;
        const suggested = suggest(measure(luma, sat), found, { color: pictureColor });

        const ls = LAYER / Math.max(image.width, image.height);
        const lw = Math.round(image.width * ls);
        const lh = Math.round(image.height * ls);
        const layer = (blob) => {
            const mask = new OffscreenCanvas(sw, sh);
            const mg = mask.getContext('2d');
            const data = mg.createImageData(sw, sh);
            for (let i = 0; i < found.labels.length; i++) {
                const on = (blob.labels ?? found.labels)[i] === blob.id ? 255 : 0;
                data.data[i * 4 + 3] = on;
                data.data[i * 4] = data.data[i * 4 + 1] = data.data[i * 4 + 2] = 255;
            }
            mg.putImageData(data, 0, 0);

            const c = new OffscreenCanvas(lw, lh);
            const g = c.getContext('2d');
            g.drawImage(image, 0, 0, lw, lh);
            g.globalCompositeOperation = 'destination-in';
            g.imageSmoothingQuality = 'high';
            if ('filter' in g) g.filter = `blur(${Math.max(1, lw / sw)}px)`; // a soft edge, not blocks
            g.drawImage(mask, 0, 0, lw, lh);
            if (found.inverted) {
                // dark shapes on a light picture: they glow in a colour instead
                g.filter = 'none';
                g.globalCompositeOperation = 'source-in';
                g.fillStyle = tint;
                g.fillRect(0, 0, lw, lh);
            }

            return c;
        };

        const tiny = new OffscreenCanvas(24, Math.max(1, Math.round((24 * image.height) / image.width)));
        tiny.getContext('2d').drawImage(image, 0, 0, tiny.width, tiny.height); // scaled up it is the blurred backdrop

        return {
            pictureColor,
            suggested,
            tiny,
            found,
            kick: found.kick ? layer(found.kick) : null,
            bands: found.bands.map(layer),
            sw,
            sh,
        };
    }

    return {
        id,
        needs: ['bands'],
        label,
        params: {
            image: null, // url of the picture
            ...defaults,
            min: -66,
            max: -22,
            focus: 0.5, // which part of a tall picture stays in a wide cell, 0 top to 1 bottom
            bloom: 0.1, // how much the whole picture swells with the level
            fit: 'contain', // 'contain' keeps the whole picture and its proportions, 'cover' fills the cell and crops
            zoom: 0, // how far it breathes in on the kick (0 = still)
            drift: 0, // a very slow pan and scale over minutes, so a still picture is never frozen (off; a per-picture choice)
            attack: 0, // seconds for a shape to light up (0 = at once)
            release: 0.2, // seconds for it to fade
            kickGain: 1, // strength of the kick glow, 0 turns it off
            bandGain: 1, // strength of the band shapes
            glowColor: null, // a colour added to the lit shapes: 'picture' (its own strongest colour, the default suggestion), 'accent' (the theme's), a hex value, or null for none
            glowGain: 1.4, // how strong that colour is
            dim: 0, // 0 to 1: darkens the picture at rest, so the music lights it up
            saturation: 0, // 0 to 1: the colour swells with the loudness (and is a little muted at rest)
            kickThreshold: 4, // dB the bass must rise above its average to count as a hit; lower = more sensitive
            kickCooldown: 0.2, // seconds before the next hit can count
            vignette: 0, // darkness of the edges, which open a little on the kick (off; a per-picture choice)
            tint: null, // colour of the glow when the picture is bright and its dark shapes carry the music; defaults to the theme accent
        },
        settings, // only what the person changed for this picture (the scenes page edits it live)
        get layers() {
            return layers;
        },

        draw(g, { bands }, { w, h, params, dt, colors }) {
            const p = { ...this.params, ...(layers?.suggested ?? {}), ...this.settings, ...params }; // defaults, what the picture suggests, what the person set for this picture, then the theme's look
            t += dt;
            beat.update(bands, dt, { threshold: p.kickThreshold, cooldown: p.kickCooldown });
            kick += (beat.pulse - kick) * (1 - Math.exp(-dt / 0.12)); // a soft swell, not a flash
            let sum = 0;
            for (let i = 0; i < N; i++) {
                const v = norm(bands[i], p.min, p.max);
                const tau = v > level[i] ? p.attack : p.release;
                level[i] = tau > 0 ? level[i] + (v - level[i]) * (1 - Math.exp(-dt / tau)) : v;
                sum += level[i];
            }
            loud += (sum / N - loud) * (1 - Math.exp(-dt / 0.5));

            if (p.image && p.image !== src) {
                src = p.image;
                img = null;
                layers = null;
                const im = new Image();
                im.onload = () => {
                    if (src === im.dataset.src) (img = im), (layers = build(im, p.tint ?? colors?.accent ?? '#ff4d4d'));
                };
                im.dataset.src = src;
                im.src = src;
            }
            g.fillStyle = '#000';
            g.fillRect(0, 0, w, h);
            if (!img || !layers) return;

            const z = (1 + p.zoom * kick) * (1 + p.drift * (0.5 + 0.5 * Math.sin(t * 0.07)));
            const contain = p.fit === 'contain';
            const s = (contain ? Math.min(w / img.width, h / img.height) : Math.max(w / img.width, h / img.height)) * z;
            const iw = img.width * s;
            const ih = img.height * s;
            const ox = (w - iw) / 2 + Math.sin(t * 0.045) * p.drift * w * 0.6;
            const oyy = ih > h ? -(ih - h) * p.focus : (h - ih) / 2;

            if (contain && (iw < w - 1 || ih < h - 1)) {
                // the picture keeps its proportions; what is left of the cell shows a dim, blurred copy of it
                const k = Math.max(w / layers.tiny.width, h / layers.tiny.height);
                g.globalAlpha = 0.35;
                g.imageSmoothingQuality = 'high';
                g.drawImage(layers.tiny, (w - layers.tiny.width * k) / 2, (h - layers.tiny.height * k) / 2, layers.tiny.width * k, layers.tiny.height * k);
                g.globalAlpha = 1;
            }
            const swell = Math.min(1, loud * 2.5);
            if (p.saturation > 0 && 'filter' in g) g.filter = `saturate(${1 - p.saturation * 0.4 + p.saturation * 0.9 * swell})`;
            g.drawImage(img, ox, oyy, iw, ih);
            g.filter = 'none';

            if (p.dim > 0) {
                g.globalAlpha = p.dim * (1 - Math.min(1, swell + kick * 0.5));
                g.fillStyle = '#000';
                g.fillRect(Math.max(0, ox), Math.max(0, oyy), Math.min(w, iw), Math.min(h, ih));
                g.globalAlpha = 1;
            }

            if (p.vignette > 0) {
                g.save();
                g.beginPath();
                g.rect(Math.max(0, ox), Math.max(0, oyy), Math.min(w, iw), Math.min(h, ih));
                g.clip();
                const cx = ox + iw / 2;
                const cy = oyy + ih / 2;
                const r = Math.hypot(iw, ih) / 2;
                const vg = g.createRadialGradient(cx, cy, r * 0.35, cx, cy, r);
                vg.addColorStop(0, 'rgba(0,0,0,0)');
                vg.addColorStop(1, '#000');
                g.globalAlpha = p.vignette * (1 - 0.5 * kick);
                g.fillStyle = vg;
                g.fillRect(0, 0, w, h);
                g.restore();
            }

            g.save();
            g.globalCompositeOperation = 'lighter';
            if ('filter' in g) {
                g.filter = 'brightness(0.8) contrast(1.8)';
                g.globalAlpha = Math.min(0.2, p.bloom * (0.1 + loud * 1.2));
                g.drawImage(img, ox, oyy, iw, ih);
                g.filter = 'none';
            }
            const glow = p.glowColor === 'accent' ? colors?.accent : p.glowColor === 'picture' ? layers.pictureColor : p.glowColor;
            if (layers.kick) {
                const a = Math.min(0.5, (0.06 + 0.3 * kick + 0.15 * loud) * p.kickGain);
                g.globalAlpha = a;
                g.drawImage(layers.kick, ox, oyy, iw, ih);
                if (glow) {
                    g.globalAlpha = a * p.glowGain;
                    g.drawImage(tinted(layers.kick, glow), ox, oyy, iw, ih);
                }
            }
            layers.bands.forEach((c, i) => {
                const b = 2 + Math.round(i * ((N - 4) / Math.max(1, layers.bands.length - 1)));
                const a = Math.min(1, (0.03 + 0.95 * level[b] ** 1.25) * p.bandGain);
                g.globalAlpha = a;
                g.drawImage(c, ox, oyy, iw, ih);
                if (glow) {
                    g.globalAlpha = a * p.glowGain;
                    g.drawImage(tinted(c, glow), ox, oyy, iw, ih);
                }
            });
            g.restore();
        },
    };
}
