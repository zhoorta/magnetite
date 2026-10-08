// Radix-2 FFT for the offline analysis: in place, on separate real and imaginary arrays.

export class FFT {
    constructor(size) {
        if (size & (size - 1)) throw new Error('FFT size must be a power of two');
        this.size = size;
        this.cos = new Float64Array(size / 2);
        this.sin = new Float64Array(size / 2);
        for (let i = 0; i < size / 2; i++) {
            this.cos[i] = Math.cos((2 * Math.PI * i) / size);
            this.sin[i] = -Math.sin((2 * Math.PI * i) / size);
        }
        this.rev = new Uint32Array(size);
        const bits = Math.log2(size);
        for (let i = 0; i < size; i++) {
            let r = 0;
            for (let b = 0; b < bits; b++) r |= ((i >> b) & 1) << (bits - 1 - b);
            this.rev[i] = r;
        }
    }

    transform(re, im) {
        const n = this.size;
        for (let i = 0; i < n; i++) {
            const j = this.rev[i];
            if (j > i) {
                [re[i], re[j]] = [re[j], re[i]];
                [im[i], im[j]] = [im[j], im[i]];
            }
        }

        for (let len = 2; len <= n; len <<= 1) {
            const half = len >> 1;
            const step = n / len;
            for (let start = 0; start < n; start += len) {
                for (let k = 0, t = 0; k < half; k++, t += step) {
                    const a = start + k;
                    const b = a + half;
                    const xr = re[b] * this.cos[t] - im[b] * this.sin[t];
                    const xi = re[b] * this.sin[t] + im[b] * this.cos[t];
                    re[b] = re[a] - xr;
                    im[b] = im[a] - xi;
                    re[a] += xr;
                    im[a] += xi;
                }
            }
        }
    }
}
