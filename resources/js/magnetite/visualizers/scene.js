import { Beat, norm } from './beat';
import { BAND_LABELS } from '../bands';
import { analyze, cleanHotspots, dominantHue, hueColor, measure, suggest } from './scene-auto';

const N = BAND_LABELS.length;
const SMALL = 128; // analysis grid, pixels wide
const LAYER = 640; // layer canvases, pixels on the long side

/**
 * Any picture as a meter. The bright blobs found in it carry the music: the biggest pulses with the kick, the others light
 * up one band each from the left (bass) to the right (treble), and the whole picture swells a little with the level. Each layer
 * is the picture's own pixels inside the blob, added on top of it, so the shapes light up exactly and the colours stay the picture's.
 */
const MAX_PIXELS = 700_000; // a scene is painted into a buffer of at most this many pixels and scaled up: the blend modes cost per pixel
const FPS = 30; // and repainted at most this often (the music state still moves every frame)

/** A scratch canvas kept with the picture, so nothing is allocated while drawing. */
function scratch(layers, name, w, h) {
    layers.scratch ??= {};
    const c = layers.scratch[name];
    if (c && c.width === w && c.height === h) return c;

    return (layers.scratch[name] = new OffscreenCanvas(w, h));
}

const STEPS = 12; // hue copies of the picture, 30 degrees apart; the player cross-fades between two
const BASE = 640; // long side of those copies

/** The picture with every hue turned by `deg` (the same matrix as CSS hue-rotate), done in pixels: Safari has no canvas filter. */
function rotateHue(src, deg) {
    const c = new OffscreenCanvas(src.width, src.height);
    const g = c.getContext('2d', { willReadFrequently: true });
    g.drawImage(src, 0, 0);
    const img = g.getImageData(0, 0, c.width, c.height);
    const d = img.data;
    const a = (deg * Math.PI) / 180;
    const cs = Math.cos(a);
    const sn = Math.sin(a);
    const m = [
        0.213 + cs * 0.787 - sn * 0.213, 0.715 - cs * 0.715 - sn * 0.715, 0.072 - cs * 0.072 + sn * 0.928,
        0.213 - cs * 0.213 + sn * 0.143, 0.715 + cs * 0.285 + sn * 0.14, 0.072 - cs * 0.072 - sn * 0.283,
        0.213 - cs * 0.213 - sn * 0.787, 0.715 - cs * 0.715 + sn * 0.715, 0.072 + cs * 0.928 + sn * 0.072,
    ];
    for (let i = 0; i < d.length; i += 4) {
        const r = d[i];
        const gg = d[i + 1];
        const b = d[i + 2];
        d[i] = m[0] * r + m[1] * gg + m[2] * b;
        d[i + 1] = m[3] * r + m[4] * gg + m[5] * b;
        d[i + 2] = m[6] * r + m[7] * gg + m[8] * b;
    }
    g.putImageData(img, 0, 0);
    const out = new OffscreenCanvas(c.width, c.height); // a plain canvas to draw from: the reading one stays on the CPU
    out.getContext('2d').drawImage(c, 0, 0);

    return out;
}

/** One layer per hotspot: the picture's pixels inside a feathered ellipse, so the shape a person drew lights up like an automatic one. */
function spotLayer(image, s) {
    const lw = Math.round(image.width * (LAYER / Math.max(image.width, image.height)));
    const lh = Math.round(image.height * (LAYER / Math.max(image.width, image.height)));
    const c = new OffscreenCanvas(lw, lh);
    const g = c.getContext('2d');
    g.drawImage(image, 0, 0, lw, lh);
    g.globalCompositeOperation = 'destination-in';
    g.save();
    g.translate(s.x * lw, s.y * lh);
    g.scale((s.w * lw) / 2, (s.h * lh) / 2); // a unit circle becomes the ellipse
    const f = g.createRadialGradient(0, 0, 0, 0, 0, 1);
    f.addColorStop(0, 'rgba(0,0,0,1)');
    f.addColorStop(0.7, 'rgba(0,0,0,1)');
    f.addColorStop(1, 'rgba(0,0,0,0)');
    g.fillStyle = f;
    g.fillRect(-1, -1, 2, 2);
    g.restore();

    return c;
}

export function createScene(id = 'scene', label = 'Scene', defaults = {}, settings = {}) {
    const beat = new Beat();
    const level = new Float32Array(N);
    let src = null;
    let img = null;
    let layers = null;
    let kick = 0;
    let loud = 0;
    // The loudness effects (colour wash, brightness, hue follow, dim) use the loudness relative to the track's own recent range, not
    // the absolute level: steady loud music would otherwise sit at the top and nothing would move after the first second.
    let loudFast = 0;
    let hi = 0;
    let lo = 0;
    let swell = 0;
    // `punch`: how much of the spectrum just rose, against its own recent average. It is what keeps the effects alive when the bass
    // is steady (no kick to detect) or the music is loud all the time: any transient in any band pulses it.
    const prevRaw = new Float32Array(N);
    let avgFlux = 0;
    let punch = 0;
    let t = 0;
    const bufs = new Map(); // painted buffers by size
    let lastTick = 0; // when the state last moved on
    let spin = 0; // degrees the colours have turned by themselves
    let usedSpots = null; // the hotspot list the layers were last built from

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
            pictureHue: dom ? dom.hue : null,
            base: (() => {
                const k2 = BASE / Math.max(image.width, image.height);
                const c = new OffscreenCanvas(Math.round(image.width * k2), Math.round(image.height * k2));
                c.getContext('2d').drawImage(image, 0, 0, c.width, c.height);

                return c;
            })(),
            hues: [],
            suggested,
            tiny,
            found,
            kick: found.kick ? layer(found.kick) : null,
            bands: found.bands.map(layer),
            sw,
            sh,
        };
    }

    /** One painting of the scene into `g` (a buffer, not the player's canvas), `w` by `h`. */
    function paintScene(g, w, h, p, colors) {
        g.globalCompositeOperation = 'source-over';
        g.globalAlpha = 1;
        g.fillStyle = '#000';
        g.fillRect(0, 0, w, h);
        const z = (1 + p.zoom * kick) * (1 + p.drift * (0.5 + 0.5 * Math.sin(t * 0.07)));
        const contain = p.fit === 'contain';
        const s = (contain ? Math.min(w / img.width, h / img.height) : Math.max(w / img.width, h / img.height)) * z;
        const iw = img.width * s;
        const ih = img.height * s;
        const ox = (w - iw) / 2 + Math.sin(t * 0.045) * p.drift * w * 0.6;
        const oyy = ih > h ? -(ih - h) * p.focus : (h - ih) / 2;

        if (contain && p.backdrop > 0 && (iw < w - 1 || ih < h - 1)) {
            // the picture keeps its proportions; what is left of the cell shows a dim, blurred copy of it
            const k = Math.max(w / layers.tiny.width, h / layers.tiny.height);
            g.globalAlpha = 0.35 * p.backdrop;
            g.imageSmoothingQuality = 'high';
            g.drawImage(layers.tiny, (w - layers.tiny.width * k) / 2, (h - layers.tiny.height * k) / 2, layers.tiny.width * k, layers.tiny.height * k);
            g.globalAlpha = 1;
        }
        // Colour effects, all done with blend modes and pixel copies so they work in Safari too (it has no canvas `filter`).
        const drive = Math.min(1, 0.65 * swell + 0.7 * punch); // loudness against its own range, plus the transients
        const hit = Math.max(kick, 0.7 * punch); // the kick, or any sharp rise in the music
        const hue = p.hueShift + spin + p.hueKick * hit + p.hueFollow * drive;
        const turned = ((hue % 360) + 360) % 360;
        const rotating = turned >= 1 && turned <= 359;
        const pos = turned / (360 / STEPS);
        const i0 = Math.floor(pos) % STEPS;
        const frac = pos - Math.floor(pos);
        // One new hue copy per frame at most (each is a pass over the picture's pixels); until it exists, the nearest one stands in.
        const copy = (i) => {
            if (i === 0) return layers.base;
            if (!layers.hues[i] && layers.budget > 0) {
                layers.budget--;
                layers.hues[i] = rotateHue(layers.base, (i * 360) / STEPS);
            }
            for (let d = 0; d < STEPS / 2; d++) {
                const near = layers.hues[(i + d) % STEPS] ? (i + d) % STEPS : layers.hues[(i - d + STEPS) % STEPS] ? (i - d + STEPS) % STEPS : -1;
                if (near === 0) return layers.base;
                if (near > 0) return layers.hues[near];
            }

            return layers.base;
        };
        const rx = Math.max(0, ox);
        const ry = Math.max(0, oyy);
        const rw = Math.min(w, iw);
        const rh = Math.min(h, ih);
        // the picture, at some strength: the base (source-over, opaque) or an addition (lighter)
        const paint = (alpha, additive) => {
            if (!rotating) {
                g.globalAlpha = alpha;
                g.drawImage(img, ox, oyy, iw, ih);
            } else if (frac < 0.06 || frac > 0.94) {
                g.globalAlpha = alpha; // right on a copy: no cross-fade needed
                g.drawImage(copy(frac < 0.5 ? i0 : (i0 + 1) % STEPS), ox, oyy, iw, ih);
            } else {
                g.globalAlpha = additive ? alpha * (1 - frac) : alpha;
                g.drawImage(copy(i0), ox, oyy, iw, ih);
                g.globalAlpha = alpha * frac;
                g.drawImage(copy((i0 + 1) % STEPS), ox, oyy, iw, ih);
            }
            g.globalAlpha = 1;
        };
        paint(1, false);

        g.save();
        g.beginPath();
        g.rect(rx, ry, rw, rh);
        g.clip();
        if (p.saturation * 0.45 * (1 - drive) > 0.02) {
            g.globalCompositeOperation = 'saturation'; // grey in this mode drains the colour
            g.globalAlpha = p.saturation * 0.45 * (1 - drive);
            g.fillStyle = '#808080';
            g.fillRect(rx, ry, rw, rh);
        }
        if (p.brightPulse > 0) {
            g.globalCompositeOperation = 'lighter'; // adding the picture to itself is brightness
            let amount = p.brightPulse * (0.6 * drive + 0.5 * hit);
            while (amount > 0.01) {
                paint(Math.min(1, amount), true);
                amount -= 1;
            }
        }
        const flash = p.invertKick * Math.min(1, Math.max(beat.pulse, punch * 0.9) ** 2 * 1.5);
        if (flash > 0.02) {
            g.globalCompositeOperation = 'difference'; // against white: the negative
            g.globalAlpha = Math.min(1, flash); // the raw hit, not the soft swell: a clear flash that is gone in about a tenth of a second
            g.fillStyle = '#fff';
            g.fillRect(rx, ry, rw, rh);
        }
        g.restore();
        g.globalAlpha = 1;
        g.globalCompositeOperation = 'source-over';

        if (p.dim * (1 - Math.min(1, drive + hit * 0.5)) > 0.02) {
            g.globalAlpha = p.dim * (1 - Math.min(1, drive + hit * 0.5));
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
        const swellAlpha = Math.min(0.25, p.bloom * (0.1 + loud * 1.2) * 1.5);
        if (swellAlpha > 0.01) {
            // the whole picture swells: its blurred copy (the tiny one, scaled up) is added on top
            g.globalAlpha = swellAlpha;
            g.imageSmoothingQuality = 'low';
            g.drawImage(layers.tiny, ox, oyy, iw, ih);
        }
        // Glow colour; when the hues are turned, the picture's own colour turns with them (a chosen colour stays).
        const turnedHue = Math.round(turned / 15) * 15;
        const pictureGlow = layers.pictureHue === null ? null : hueColor((layers.pictureHue + turnedHue / 360) % 1);
        const glow = p.glowColor === 'accent' ? colors?.accent : p.glowColor === 'picture' ? (rotating ? pictureGlow : layers.pictureColor) : p.glowColor;
        // turned hues: the lit shapes can only be shown in a colour (their own pixels are not turned), so they always get one
        const shapeColour = glow ?? (rotating ? (pictureGlow ?? colors?.accent) : null);
        if (p.wash * (0.15 + 0.85 * drive) > 0.02) {
            g.globalCompositeOperation = 'color'; // keeps the picture's light and dark, replaces its colours
            g.globalAlpha = Math.min(1, p.wash * (0.15 + 0.85 * drive));
            g.fillStyle = shapeColour ?? colors?.accent ?? '#ff4d4d';
            g.fillRect(rx, ry, rw, rh);
            g.globalCompositeOperation = 'lighter';
        }
        // The lit shapes: every shape that matters is added into one scratch picture (and one tinted with the glow colour), which is
        // then drawn once, instead of two draws per shape. Shapes that are barely lit are skipped.
        const shapes = [];
        if (layers.kick) shapes.push([layers.kick, Math.min(0.5, (0.06 + 0.3 * kick + 0.15 * loud) * p.kickGain)]);
        layers.bands.forEach((c, i) => {
            const b = 2 + Math.round(i * ((N - 4) / Math.max(1, layers.bands.length - 1)));
            shapes.push([c, Math.min(1, (0.03 + 0.95 * level[b] ** 1.25) * p.bandGain)]);
        });
        const on = shapes.filter(([, a]) => a >= 0.02);
        if (on.length) {
            const lw = on[0][0].width;
            const lh = on[0][0].height;
            const accumulate = (name, gain, colour) => {
                const c = scratch(layers, name, lw, lh);
                const cg = c.getContext('2d');
                cg.globalCompositeOperation = 'source-over';
                cg.globalAlpha = 1;
                cg.clearRect(0, 0, lw, lh);
                cg.globalCompositeOperation = 'lighter';
                for (const [layer, a] of on) {
                    cg.globalAlpha = Math.min(1, a * gain);
                    cg.drawImage(layer, 0, 0);
                }
                if (colour) {
                    cg.globalCompositeOperation = 'source-in'; // the shapes' outline filled with one colour
                    cg.globalAlpha = 1;
                    cg.fillStyle = colour;
                    cg.fillRect(0, 0, lw, lh);
                }
                g.globalAlpha = 1;
                g.drawImage(c, ox, oyy, iw, ih);
            };
            if (!rotating) accumulate('lit', 1, null);
            if (shapeColour) accumulate('glow', rotating ? Math.max(1, p.glowGain) : p.glowGain, shapeColour);
        }
        g.restore();
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
            backdrop: 1, // 1: what is left of the cell beside a picture that keeps its proportions shows a dim blurred copy of it; 0: plain black
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
            hueShift: 0, // degrees: turns every colour of the picture round the wheel, 180 is the opposite colours
            hueSpin: 0, // degrees per second: the colours cycle all the time
            hueFollow: 0, // degrees the colours slide with the loudness: quiet = the picture's own, loud = turned this far
            hueKick: 0, // degrees the colours jump on a kick, then drift back
            brightPulse: 0, // 0 to 2: the whole picture gets brighter with the loudness and the kick
            invertKick: 0, // 0 to 1: the picture flips to its negative on a kick
            wash: 0, // 0 to 1: the glow colour is poured over the whole picture with the loudness
            kickThreshold: 4, // dB the bass must rise above its average to count as a hit; lower = more sensitive
            kickCooldown: 0.2, // seconds before the next hit can count
            vignette: 0, // darkness of the edges, which open a little on the kick (off; a per-picture choice)
            tint: null, // colour of the glow when the picture is bright and its dark shapes carry the music; defaults to the theme accent
        },
        get state() {
            return { kick, swell, punch, loud, loudFast, hi, lo, pulse: beat.pulse, avg: beat.avg, energy: beat.energy, spin, lastTick };
        },
        hotspots: [], // ellipses the person drew; when there are any they replace the automatic shapes
        settings, // only what the person changed for this picture (the scenes page edits it live)
        get layers() {
            return layers;
        },

        draw(g, { bands }, { w, h, params, dt, colors }) {
            const p = { ...this.params, ...(layers?.suggested ?? {}), ...this.settings, ...params }; // defaults, what the picture suggests, what the person set for this picture, then the theme's look
            // The scenes page draws the same scene a second time in a frame (its live preview): the state moves on once per
            // frame, so both pictures are always in step.
            const now = performance.now();
            if (now - lastTick > dt * 500) {
                lastTick = now;
                t += dt;
                spin = (spin + p.hueSpin * dt) % 360;
                beat.update(bands, dt, { threshold: p.kickThreshold, cooldown: p.kickCooldown });
                kick += (beat.pulse - kick) * (1 - Math.exp(-dt / 0.12)); // a soft swell, not a flash
                let sum = 0;
                let flux = 0;
                for (let i = 0; i < N; i++) {
                    const v = norm(bands[i], p.min, p.max);
                    flux += Math.max(0, v - prevRaw[i]);
                    prevRaw[i] = v;
                    const tau = v > level[i] ? p.attack : p.release;
                    level[i] = tau > 0 ? level[i] + (v - level[i]) * (1 - Math.exp(-dt / tau)) : v;
                    sum += level[i];
                }
                loud += (sum / N - loud) * (1 - Math.exp(-dt / 0.5));
                flux /= N;
                avgFlux += (flux - avgFlux) * (1 - Math.exp(-dt / 1));
                punch = Math.max(punch * Math.exp(-dt / 0.18), flux > 0.008 ? Math.min(1, flux / Math.max(0.012, avgFlux * 2.2)) : 0);
                loudFast += (sum / N - loudFast) * (1 - Math.exp(-dt / 0.12));
                const follow = 1 - Math.exp(-dt / 2); // the range follows the music, so a change of section re-scales it within a couple of seconds
                hi = loudFast > hi ? loudFast : hi + (loudFast - hi) * follow;
                lo = loudFast < lo ? loudFast : lo + (loudFast - lo) * follow;
                swell = Math.min(1, Math.max(0, (loudFast - lo) / Math.max(0.08, hi - lo))) * Math.min(1, hi / 0.1); // and nothing moves in near silence
            }

            if (p.image && p.image !== src) {
                src = p.image;
                img = null;
                layers = null;
                const im = new Image();
                im.onload = () => {
                    if (src === im.dataset.src) {
                        img = im;
                        layers = build(im, p.tint ?? colors?.accent ?? '#ff4d4d');
                        layers.auto = { kick: layers.kick, bands: layers.bands };
                        usedSpots = null; // build from the hotspots on the next frame
                    }
                };
                im.dataset.src = src;
                im.src = src;
            }
            g.fillStyle = '#000';
            g.fillRect(0, 0, w, h);
            if (!img || !layers) return;

            if (this.hotspots !== usedSpots) {
                usedSpots = this.hotspots;
                const spots = cleanHotspots(usedSpots);
                layers.kick = spots.kick || spots.bands.length ? (spots.kick ? spotLayer(img, spots.kick) : null) : layers.auto.kick;
                layers.bands = spots.kick || spots.bands.length ? spots.bands.map((b) => spotLayer(img, b)) : layers.auto.bands;
            }

            // Painted into a buffer of limited size, at most FPS times a second, then scaled onto the cell every frame.
            const scale = Math.min(1, Math.sqrt(MAX_PIXELS / (w * h)));
            const bw = Math.max(1, Math.round(w * scale));
            const bh = Math.max(1, Math.round(h * scale));
            const key = `${bw}x${bh}`;
            let buf = bufs.get(key);
            if (!buf) {
                buf = { canvas: new OffscreenCanvas(bw, bh), at: 0 };
                buf.g = buf.canvas.getContext('2d');
                bufs.set(key, buf);
                if (bufs.size > 4) bufs.delete(bufs.keys().next().value); // the preview and the player cells; old sizes go
            }
            if (!buf.at || now - buf.at >= 1000 / FPS - 3) {
                buf.at = now;
                layers.budget = 1;
                paintScene(buf.g, bw, bh, p, colors);
            }
            g.imageSmoothingQuality = 'low';
            g.drawImage(buf.canvas, 0, 0, w, h);
        },
    };
}
