package app.magnetite.player

import kotlin.math.PI
import kotlin.math.abs
import kotlin.math.exp
import kotlin.math.log10
import kotlin.math.max
import kotlin.math.min
import kotlin.math.pow
import kotlin.math.sin
import kotlin.math.sqrt
import kotlin.math.tan

/**
 * Loudness and stereo meters on the audio thread, a port of `worklet/meter-processor.js`: K-weighted LUFS
 * (momentary 400 ms, short-term 3 s, integrated with the absolute and relative gates), true peak with a 4x
 * windowed-sinc interpolator, phase correlation and the last [GONIO] samples for the goniometer.
 * Written by [MeterTap]; the UI reads the volatile fields and [gonio] without a lock (a torn read is only a glitch in a picture).
 */
object Loudness {
    const val GONIO = 1024
    private const val ABS_GATE = -70.0
    private const val REL_GATE = -10.0
    private const val OFFSET = -0.691
    private const val TAPS = 12 // per phase; shorter than the web's 48, flat enough for a phone meter
    private const val RING = 32
    private const val LRA_BINS = 800 // 0.1 LU bins from -70 LUFS up, so memory does not grow with the programme
    const val SHOWN_BINS = 60
    private const val SHOWN_MIN = -60

    @Volatile var momentary = Double.NEGATIVE_INFINITY; private set
    @Volatile var shortTerm = Double.NEGATIVE_INFINITY; private set
    @Volatile var integrated = Double.NEGATIVE_INFINITY; private set
    /** Live loudness range (EBU Tech 3342) in LU from a fixed histogram of the short-term values; 0 until two values exist. */
    @Volatile var lra = 0f; private set
    @Volatile var lraLo = Double.NEGATIVE_INFINITY; private set
    @Volatile var lraHi = Double.NEGATIVE_INFINITY; private set
    /** The short-term values by loudness, 1 LU bins from -60 to 0 LUFS (the meter draws these). */
    val lraShape = IntArray(SHOWN_BINS)
    @Volatile var truePeakL = 0f; private set
    @Volatile var truePeakR = 0f; private set
    @Volatile var correlation = 0f; private set
    @Volatile var rate = 0; private set
    val gonio = FloatArray(GONIO * 2)
    @Volatile var gonioPos = 0; private set

    private class Biquad(val b0: Double, val b1: Double, val b2: Double, val a1: Double, val a2: Double) {
        var z1 = 0.0; var z2 = 0.0
        fun run(x: Double): Double {
            val y = b0 * x + z1
            z1 = b1 * x - a1 * y + z2
            z2 = b2 * x - a2 * y
            return y
        }
    }

    private fun stages(fs: Int): List<Biquad> {
        val shelf = run {
            val k = tan(PI * 1681.974450955533 / fs); val q = 0.7071752369554196
            val vh = 10.0.pow(3.999843853973347 / 20); val vb = vh.pow(0.4996667741545416)
            val a0 = 1 + k / q + k * k
            Biquad((vh + vb * k / q + k * k) / a0, 2 * (k * k - vh) / a0, (vh - vb * k / q + k * k) / a0, 2 * (k * k - 1) / a0, (1 - k / q + k * k) / a0)
        }
        val high = run {
            val k = tan(PI * 38.13547087602444 / fs); val q = 0.5003270373238773
            val a0 = 1 + k / q + k * k
            Biquad(1.0, -2.0, 1.0, 2 * (k * k - 1) / a0, (1 - k / q + k * k) / a0)
        }
        return listOf(shelf, high)
    }

    /** Phase p (1..3) of a Kaiser-windowed sinc; phase 0 is the input itself. */
    private val taps: Array<DoubleArray> = run {
        fun i0(x: Double): Double { var s = 1.0; var t = 1.0; for (k in 1 until 30) { t *= (x / (2 * k)).pow(2); s += t }; return s }
        val n = 4 * TAPS
        val mid = (n - 1) / 2.0
        val beta = 7.0
        val out = Array(4) { DoubleArray(TAPS) }
        for (i in 0 until n) {
            val x = (i - mid) / 4
            val sinc = if (x == 0.0) 1.0 else sin(PI * x) / (PI * x)
            val r = 2 * i / (n - 1.0) - 1
            out[i % 4][i / 4] = sinc * i0(beta * sqrt(max(0.0, 1 - r * r))) / i0(beta)
        }
        for (ph in out) { val s = ph.sum(); for (k in ph.indices) ph[k] /= s }
        out
    }

    private var filtersL = stages(44100)
    private var filtersR = stages(44100)
    private var blockLen = 4410
    private var blockSum = 0.0
    private var blockCount = 0
    private val blocks = DoubleArray(30)
    private var blockN = 0
    private var blockPos = 0
    private val gated = ArrayList<Double>()
    private var sinceIntegrated = 0
    private val lraHist = IntArray(LRA_BINS)
    private var lraEnergy = 0.0
    private var lraN = 0
    private val ringL = DoubleArray(RING * 2)
    private val ringR = DoubleArray(RING * 2)
    private var ringPos = 0
    private var tpL = 0.0
    private var tpR = 0.0
    private var sLL = 0.0; private var sRR = 0.0; private var sLR = 0.0
    private var alpha = exp(-1.0 / (44100 * 0.3))

    private fun toLufs(e: Double) = if (e > 0) OFFSET + 10 * log10(e) else Double.NEGATIVE_INFINITY
    private fun toEnergy(l: Double) = 10.0.pow((l - OFFSET) / 10)

    @Synchronized fun configure(sampleRate: Int) {
        rate = sampleRate
        filtersL = stages(sampleRate); filtersR = stages(sampleRate)
        blockLen = sampleRate / 10
        alpha = exp(-1.0 / (sampleRate * 0.3))
        reset()
    }

    /** New track: the integrated value starts again, like the web's reset. */
    @Synchronized fun reset() {
        blockSum = 0.0; blockCount = 0; blockN = 0; blockPos = 0; gated.clear(); sinceIntegrated = 0
        lraHist.fill(0); lraEnergy = 0.0; lraN = 0; lraShape.fill(0); lra = 0f; lraLo = Double.NEGATIVE_INFINITY; lraHi = Double.NEGATIVE_INFINITY
        tpL = 0.0; tpR = 0.0; sLL = 0.0; sRR = 0.0; sLR = 0.0
        ringL.fill(0.0); ringR.fill(0.0); gonio.fill(0f)
        for (f in filtersL + filtersR) { f.z1 = 0.0; f.z2 = 0.0 }
        momentary = Double.NEGATIVE_INFINITY; shortTerm = Double.NEGATIVE_INFINITY; integrated = Double.NEGATIVE_INFINITY
        truePeakL = 0f; truePeakR = 0f; correlation = 0f
    }

    @Synchronized fun process(l: Float, r: Float) {
        val kl = filtersL[1].run(filtersL[0].run(l.toDouble()))
        val kr = filtersR[1].run(filtersR[0].run(r.toDouble()))
        blockSum += kl * kl + kr * kr
        if (++blockCount == blockLen) endBlock()

        val p = ringPos
        ringL[p] = l.toDouble(); ringL[p + RING] = l.toDouble()
        ringR[p] = r.toDouble(); ringR[p + RING] = r.toDouble()
        ringPos = (p + 1) and (RING - 1)
        tpL = max(tpL, truePeak(ringL, p)); tpR = max(tpR, truePeak(ringR, p))

        sLL = sLL * alpha + l * l * (1 - alpha)
        sRR = sRR * alpha + r * r * (1 - alpha)
        sLR = sLR * alpha + l * r * (1 - alpha)
        val g = gonioPos
        gonio[g * 2] = l; gonio[g * 2 + 1] = r
        gonioPos = (g + 1) % GONIO
        if (g % 256 == 0) {
            truePeakL = tpL.toFloat(); truePeakR = tpR.toFloat()
            val d = sqrt(sLL * sRR)
            correlation = if (d > 1e-9) (sLR / d).toFloat() else 0f
        }
    }

    /** Largest of the interpolated values around the newest sample. Phase 0 is the input delayed by half the filter. */
    private fun truePeak(ring: DoubleArray, pos: Int): Double {
        val base = pos + RING
        var peak = abs(ring[base - TAPS / 2])
        for (ph in 1..3) {
            var y = 0.0
            val t = taps[ph]
            for (k in 0 until TAPS) y += t[k] * ring[base - k]
            peak = max(peak, abs(y))
        }
        return peak
    }

    private fun mean(from: Int, count: Int): Double {
        var s = 0.0
        for (i in 0 until count) s += blocks[(blockPos - 1 - i + 60) % 30]
        return s / count
    }

    private fun endBlock() {
        val e = blockSum / blockLen
        blockSum = 0.0; blockCount = 0
        blocks[blockPos] = e
        blockPos = (blockPos + 1) % 30
        if (blockN < 30) blockN++
        if (blockN >= 4) {
            val e400 = mean(0, 4)
            momentary = toLufs(e400)
            if (toLufs(e400) > ABS_GATE) gated.add(e400)
            if (++sinceIntegrated >= 10) { sinceIntegrated = 0; integrated = integratedLufs() }
        }
        if (blockN >= 30) {
            val e = mean(0, 30)
            shortTerm = toLufs(e)
            addShortTerm(e)
        }
    }

    private fun addShortTerm(e: Double) {
        val l = toLufs(e)
        if (!(l > ABS_GATE)) return
        lraHist[min(LRA_BINS - 1, ((l - ABS_GATE) * 10).toInt())]++
        lraEnergy += e; lraN++
        for (k in 0 until SHOWN_BINS) lraShape[k] = 0
        for (i in 0 until LRA_BINS) {
            val k = (i / 10) + ABS_GATE.toInt() - SHOWN_MIN
            if (k in 0 until SHOWN_BINS) lraShape[k] += lraHist[i]
        }
        liveRange()
    }

    /** The loudness range: values more than 20 LU under the mean are dropped, then the 10th to 95th percentile (the web's `liveRange()`). */
    private fun liveRange() {
        if (lraN < 2) return
        val threshold = toLufs(lraEnergy / lraN) - 20
        var from = max(0, kotlin.math.ceil((threshold - ABS_GATE) * 10).toInt())
        if (from / 10.0 + ABS_GATE <= threshold) from++
        var count = 0
        for (i in from until LRA_BINS) count += lraHist[i]
        if (count < 2) return
        fun nth(k: Int): Double {
            var seen = 0
            for (i in from until LRA_BINS) { seen += lraHist[i]; if (seen > k) return ABS_GATE + (i + 0.5) / 10 }
            return ABS_GATE + LRA_BINS / 10.0
        }
        val lo = nth(Math.round((count - 1) * 0.1).toInt())
        val hi = nth(Math.round((count - 1) * 0.95).toInt())
        lraLo = lo; lraHi = hi; lra = (hi - lo).toFloat()
    }

    private fun integratedLufs(): Double {
        if (gated.isEmpty()) return Double.NEGATIVE_INFINITY
        val threshold = toEnergy(toLufs(gated.average()) + REL_GATE)
        var sum = 0.0; var n = 0
        for (e in gated) if (e > threshold) { sum += e; n++ }
        return if (n > 0) toLufs(sum / n) else Double.NEGATIVE_INFINITY
    }
}
