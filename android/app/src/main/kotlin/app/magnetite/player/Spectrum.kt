package app.magnetite.player

import kotlin.math.PI
import kotlin.math.cos
import kotlin.math.log10
import kotlin.math.sin
import kotlin.math.sqrt

/** Log-spaced spectrum bands (in dBFS) of the latest audio from [MeterTap]. One instance per screen; not thread-safe. */
class Spectrum(private val bands: Int = 48, private val n: Int = 2048, private val lo: Double = 40.0, private val topHz: Double = 16000.0) {
    private val window = FloatArray(n) { (0.5 - 0.5 * cos(2 * PI * it / (n - 1))).toFloat() }
    private val re = FloatArray(n)
    private val im = FloatArray(n)
    private val samples = FloatArray(n)

    private val cosT = FloatArray(n / 2) { cos(-2 * PI * it / n).toFloat() }
    private val sinT = FloatArray(n / 2) { sin(-2 * PI * it / n).toFloat() }
    private val rev = IntArray(n).also { r ->
        val bits = Integer.numberOfTrailingZeros(n)
        for (i in 0 until n) r[i] = Integer.reverse(i) ushr (32 - bits)
    }

    // First and last bin of each band; depends on the sample rate, so it is rebuilt only when that changes.
    private var edgesRate = 0
    private val first = IntArray(bands)
    private val last = IntArray(bands)

    private fun edges(rate: Int) {
        if (rate == edgesRate) return
        edgesRate = rate
        val hi = minOf(topHz, rate / 2.0)
        val binHz = rate.toDouble() / n
        for (b in 0 until bands) {
            val f0 = lo * Math.pow(hi / lo, b.toDouble() / bands)
            val f1 = lo * Math.pow(hi / lo, (b + 1.0) / bands)
            first[b] = (f0 / binHz).toInt().coerceIn(1, n / 2 - 1)
            last[b] = maxOf(first[b], (f1 / binHz).toInt()).coerceAtMost(n / 2 - 1)
        }
    }

    /** Fills [out] (size = bands) with dBFS per band, floor -120. */
    fun read(out: FloatArray) {
        MeterTap.recent(samples)
        for (i in 0 until n) { val j = rev[i]; re[j] = samples[i] * window[i]; im[j] = 0f }
        fft()
        edges(MeterTap.sampleRate)
        for (b in 0 until bands) {
            var peak = 0f
            for (i in first[b]..last[b]) peak = maxOf(peak, re[i] * re[i] + im[i] * im[i])
            // Hann window: coherent gain 0.5, so a full-scale sine reads 0 dB.
            val amp = sqrt(peak) * 4f / n
            out[b] = if (amp <= 1e-6f) -120f else 20f * log10(amp)
        }
    }

    /** In-place radix-2 FFT of re/im, input already in bit-reversed order. */
    private fun fft() {
        var len = 2
        while (len <= n) {
            val half = len / 2
            val stride = n / len
            var i = 0
            while (i < n) {
                var t = 0
                for (k in 0 until half) {
                    val a = i + k
                    val b = a + half
                    val cr = cosT[t]
                    val ci = sinT[t]
                    t += stride
                    val tr = re[b] * cr - im[b] * ci
                    val ti = re[b] * ci + im[b] * cr
                    re[b] = re[a] - tr; im[b] = im[a] - ti
                    re[a] += tr; im[a] += ti
                }
                i += len
            }
            len = len shl 1
        }
    }
}
