/**
 * The pixels of a small copy of the cover, for the meter colours (`palette.js`). The cover itself is always shown as it is.
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
