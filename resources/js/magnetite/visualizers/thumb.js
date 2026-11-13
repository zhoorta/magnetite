import { demoSample, stepDemo } from './demo-sample.js';

const FRAMES = 90; // long enough for the smoothing to settle and for the scrolling meters to fill
const FPS = 30;

/**
 * A still picture (a data URL) of a poster meter: its shapes only, drawn on a demo signal with the given colours and settings. It draws on a
 * canvas of its own, and the poster meters keep their state per canvas, so the live meter is not disturbed.
 */
export function renderThumb(visualizer, colors, params = {}, width = 96, height = 128) {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const g = canvas.getContext('2d');
    const sample = demoSample();
    for (let f = 0; f < FRAMES; f++) {
        stepDemo(sample, f / FPS);
        g.clearRect(0, 0, width, height);
        visualizer.draw(g, sample, { w: width, h: height, colors, params: { ...params, text: 0 }, dt: 1 / FPS, track: null });
    }

    return canvas.toDataURL('image/png');
}
