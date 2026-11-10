/**
 * The cover as the terminal shows it, only bigger: a small mosaic (averaged by the browser when it shrinks the picture) drawn
 * with hard edges. The real image stays the source; this only changes how it is shown.
 */
const MOSAIC = 32;
const loaded = new Map(); // url -> Promise<HTMLImageElement | null>

function load(url) {
    if (!loaded.has(url)) {
        loaded.set(url, new Promise((resolve) => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = () => resolve(null);
            img.src = url;
        }));
    }

    return loaded.get(url);
}

/** The pixels (RGBA, 32 x 32) of a small copy of the cover at `url`, or null when it cannot be read. */
export async function coverPixels(url) {
    const img = url ? await load(url) : null;
    if (!img) return null;
    const c = document.createElement('canvas');
    c.width = c.height = MOSAIC;
    const g = c.getContext('2d', { willReadFrequently: true });
    g.imageSmoothingQuality = 'high';
    const side = Math.min(img.naturalWidth, img.naturalHeight);
    g.drawImage(img, (img.naturalWidth - side) / 2, (img.naturalHeight - side) / 2, side, side, 0, 0, MOSAIC, MOSAIC);

    return g.getImageData(0, 0, MOSAIC, MOSAIC).data;
}

/** Draw the cover at `url` into `canvas` as a MOSAIC x MOSAIC picture (the canvas is scaled up by CSS with `image-rendering: pixelated`). */
export async function paintMosaic(canvas, url) {
    canvas.width = canvas.height = MOSAIC;
    const g = canvas.getContext('2d');
    g.clearRect(0, 0, MOSAIC, MOSAIC);
    if (!url) return;
    const img = await load(url);
    if (!img || canvas.dataset.url === 'stale') return;
    const side = Math.min(img.naturalWidth, img.naturalHeight);
    g.imageSmoothingEnabled = true;
    g.imageSmoothingQuality = 'high';
    g.drawImage(img, (img.naturalWidth - side) / 2, (img.naturalHeight - side) / 2, side, side, 0, 0, MOSAIC, MOSAIC);
}
