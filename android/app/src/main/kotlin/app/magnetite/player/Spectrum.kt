package app.magnetite.player

import kotlin.math.PI
import kotlin.math.cos
import kotlin.math.log10
import kotlin.math.sin
import kotlin.math.sqrt

/** Log-spaced spectrum bands (in dBFS) of the latest audio from [MeterTap]. One instance per screen; not thread-safe. */
class Spectrum(private val bands: Int = 48, private val n: Int = 2048) {
    private val window = FloatArray(n) { (0.5 - 0.5 * cos(2 * PI * it / (n - 1))).toFloat() }
    private val re = FloatArray(n)
    private val im = FloatArray(n)
    private val samples = FloatArray(n)

    /** Fills [out] (size = bands) with dBFS per band, floor -120. */
    fun read(out: FloatArray) {
        MeterTap.recent(samples)
        for (i in 0 until n) { re[i] = samples[i] * window[i]; im[i] = 0f }
        fft()
        val rate = MeterTap.sampleRate
        val lo = 40.0
        val hi = minOf(16000.0, rate / 2.0)
        val binHz = rate.toDouble() / n
        for (b in 0 until bands) {
            val f0 = lo * Math.pow(hi / lo, b.toDouble() / bands)
            val f1 = lo * Math.pow(hi / lo, (b + 1.0) / bands)
            val i0 = (f0 / binHz).toInt().coerceIn(1, n / 2 - 1)
            val i1 = maxOf(i0, (f1 / binHz).toInt()).coerceAtMost(n / 2 - 1)
            var peak = 0f
            for (i in i0..i1) peak = maxOf(peak, sqrt(re[i] * re[i] + im[i] * im[i]))
            // Hann window: coherent gain 0.5, so a full-scale sine reads 0 dB.
            val amp = peak * 4f / n
            out[b] = if (amp <= 1e-6f) -120f else 20f * log10(amp)
        }
    }

    /** In-place radix-2 FFT of re/im. */
    private fun fft() {
        var j = 0
        for (i in 1 until n) {
            var bit = n shr 1
            while (j and bit != 0) { j = j xor bit; bit = bit shr 1 }
            j = j xor bit
            if (i < j) { val t = re[i]; re[i] = re[j]; re[j] = t; val u = im[i]; im[i] = im[j]; im[j] = u }
        }
        var len = 2
        while (len <= n) {
            val ang = -2 * PI / len
            val wr = cos(ang).toFloat()
            val wi = sin(ang).toFloat()
            var i = 0
            while (i < n) {
                var cr = 1f
                var ci = 0f
                for (k in 0 until len / 2) {
                    val a = i + k
                    val b = a + len / 2
                    val tr = re[b] * cr - im[b] * ci
                    val ti = re[b] * ci + im[b] * cr
                    re[b] = re[a] - tr; im[b] = im[a] - ti
                    re[a] += tr; im[a] += ti
                    val nr = cr * wr - ci * wi
                    ci = cr * wi + ci * wr
                    cr = nr
                }
                i += len
            }
            len = len shl 1
        }
    }
}
