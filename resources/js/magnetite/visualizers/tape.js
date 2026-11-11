// The geometry of a tape transport, shared by the cassette and the reel-to-reel. No canvas: Node tests it.

const clamp01 = (x) => (x < 0 ? 0 : x > 1 ? 1 : x);

/**
 * Radii of the two tape packs at `progress` (0..1) through the tape. The tape's area is conserved: what leaves the supply pack
 * (left) goes onto the take-up pack (right), so r² moves linearly. At 0 the left is full (`rMax`) and the right is empty (`rMin`).
 */
export function packRadii(progress, rMin, rMax) {
    const p = clamp01(progress);
    const span = rMax * rMax - rMin * rMin;

    return [Math.sqrt(rMin * rMin + span * (1 - p)), Math.sqrt(rMin * rMin + span * p)];
}

/** Angular speed of a pack, rad/s: the tape moves at one speed, so a small pack turns faster than a full one (`speed` is at `rMax`). */
export const hubSpeed = (radius, rMax, speed) => (speed * rMax) / Math.max(radius, 1e-6);
