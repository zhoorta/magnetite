package app.magnetite.ui

import android.graphics.Paint
import android.graphics.Typeface
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.drawIntoCanvas
import androidx.compose.ui.graphics.nativeCanvas
import androidx.compose.ui.graphics.toArgb
import app.magnetite.player.Loudness
import kotlin.math.abs
import kotlin.math.max
import kotlin.math.min
import kotlin.math.round
import kotlin.math.sqrt

// The nixie loudness readout, the K-meter, crest factor and loudness range: ports of `visualizers/loudness.js`, `k-meter.js`,
// `crest.js` and `lra.js` of the web, same layout rules and numbers.

private val NIXIE = Color(0xFFFFA04A)
private val WARN = Color(0xFFFF4D3D)
private val OK = Color(0xFF7EC17E)
private val tubePaint = Paint(Paint.ANTI_ALIAS_FLAG).apply { typeface = Typeface.MONOSPACE }

private fun tenths(v: Double): Int = round(abs(v) * 10).toInt()

/** `−23.4` padded to five cells; a value that is not there yet shows dashes. No String.format: it allocates and runs every frame. */
internal fun nixieText(v: Double): String {
    if (!v.isFinite()) return " −−.−"
    val t = tenths(v)
    return "${if (v < 0) "−" else " "}${t / 10}.${t % 10}".padStart(5, ' ')
}

/** One digit with a glow: a wide faint stroke under the bright fill (no blur: it would run 20 times a frame). */
private fun DrawScope.tube(ch: Char, cx: Float, cy: Float, size: Float, color: Color) {
    tubePaint.textSize = size
    tubePaint.textAlign = Paint.Align.CENTER
    val yy = cy - (tubePaint.ascent() + tubePaint.descent()) / 2
    drawIntoCanvas {
        tubePaint.style = Paint.Style.STROKE
        tubePaint.strokeJoin = Paint.Join.ROUND
        tubePaint.strokeWidth = size * 0.22f
        tubePaint.color = color.a(0.14f).toArgb()
        it.nativeCanvas.drawText(ch.toString(), cx, yy, tubePaint)
        tubePaint.style = Paint.Style.FILL
        tubePaint.color = color.toArgb()
        it.nativeCanvas.drawText(ch.toString(), cx, yy, tubePaint)
    }
}

private val loudRows = listOf("M" to "LUFS", "S" to "LUFS", "I" to "LUFS", "TP" to "dBTP")

internal fun DrawScope.nixieLoudness() {
    val w = size.width; val h = size.height
    val margin = min(w, h) * 0.06f
    val rowH = (h - margin * 2) / 4
    val cellH = min(rowH * 0.86f, (w - margin * 2) / 9)
    val cellW = cellH * 0.78f
    val gap = cellW * 0.12f
    val tubes = 5 * (cellW + gap) - gap
    val label = max(9f, min(cellH * 0.3f, w * 0.03f))
    val x0 = (w - (tubes + label * 4.6f)) / 2 + label * 1.6f
    val tp = max(Loudness.truePeakL, Loudness.truePeakR)
    val values = doubleArrayOf(Loudness.momentary, Loudness.shortTerm, Loudness.integrated, if (tp > 0f) db(tp).toDouble() else Double.NEGATIVE_INFINITY)
    for (i in 0 until 4) {
        val y = margin + i * rowH + (rowH - cellH) / 2
        val hot = i == 3 && values[i] > -1.0
        val glow = if (hot) WARN else NIXIE
        text(loudRows[i].first, x0 - label * 0.6f, y + cellH / 2, label, Palette.Muted, Paint.Align.RIGHT)
        text(loudRows[i].second, x0 + tubes + label * 0.6f, y + cellH / 2, label, Palette.Muted, Paint.Align.LEFT)
        val str = nixieText(values[i])
        for (k in 0 until 5) {
            val x = x0 + k * (cellW + gap)
            drawRoundRect(Palette.Bg, Offset(x, y), Size(cellW, cellH), CornerRadius(cellW * 0.16f))
            drawRoundRect(Palette.Muted.a(0.25f), Offset(x, y), Size(cellW, cellH), CornerRadius(cellW * 0.16f), style = Stroke(1f))
            val ch = str[k]
            if (ch != ' ') tube(ch, x + cellW / 2, y + cellH * 0.53f, cellH * 0.74f, glow)
        }
    }
}

// ---- K-meter ----------------------------------------------------------------------------------------------------

private val kL = Ballistic()
private val kR = Ballistic()
private const val K_SCALE = 20 // K-20; the web's `scale` setting (20, 14 or 12) has no switch here yet
private const val K_FLOOR = 40

private fun signed(v: Double): String {
    if (v <= -K_FLOOR + 0.05) return "−−"
    val t = tenths(v)
    return "${if (v > 0) "+" else if (v < 0) "−" else ""}${t / 10}.${t % 10}"
}

internal fun DrawScope.kMeter(dt: Float, lv: Lv) {
    val min0 = (-K_FLOOR - K_SCALE).toFloat()
    val root2 = 1.4142135f // +3.01 dB: the AES17 reading of an RMS value
    kL.update(lv.rmsL * root2, lv.peakL, dt, min0, 0f, 0.3f, 0.3f, 1.5f, 0.25f, -0.1f)
    kR.update(lv.rmsR * root2, lv.peakR, dt, min0, 0f, 0.3f, 0.3f, 1.5f, 0.25f, -0.1f)
    val kOf = { m: Ballistic -> min0 + m.value * -min0 + K_SCALE }
    val peakOf = { m: Ballistic -> min0 + m.peak * -min0 + K_SCALE }
    val w = size.width; val h = size.height
    val margin = min(w, h) * 0.06f
    val label = max(9f, min(h * 0.045f, w * 0.045f))
    val top = margin + label * 1.6f
    val bottom = h - margin - label * 1.8f
    val bw = min(w * 0.14f, (bottom - top) * 0.18f)
    val gapB = bw * 0.6f
    val scaleW = label * 2.6f
    val readout = w >= h * 1.15f
    val textW = if (readout) label * 8 else 0f
    val total = scaleW + bw * 2 + gapB + (if (readout) bw + textW else 0f)
    val x0 = (w - total) / 2 + scaleW
    val totalK = (K_FLOOR + K_SCALE).toFloat()
    val y = { k: Float -> bottom - (bottom - top) * (k.coerceIn(-K_FLOOR.toFloat(), K_SCALE.toFloat()) + K_FLOOR) / totalK }

    text("K-$K_SCALE", margin, margin + label * 0.7f, label * 1.1f, Palette.Muted, Paint.Align.LEFT)
    var k = -K_FLOOR
    while (k <= K_SCALE) {
        val major = k % 10 == 0 || k == 4
        val col = if (k == 0) Palette.Text else Palette.Muted
        drawLine(col.a(if (major) 0.9f else 0.4f), Offset(x0 - label * 0.5f, y(k.toFloat())), Offset(x0 - label * (if (major) 0.05f else 0.25f), y(k.toFloat())), if (k == 0) 2f else 1f)
        if (major) text(if (k > 0) "+$k" else "$k", x0 - label * 0.7f, y(k.toFloat()), label, col, Paint.Align.RIGHT)
        k += 2
    }
    listOf("L" to kL, "R" to kR).forEachIndexed { i, (name, m) ->
        val x = x0 + i * (bw + gapB)
        drawRect(Palette.Muted.a(0.2f), Offset(x, top), Size(bw, bottom - top))
        val kv = kOf(m)
        for ((a, b, c) in listOf(Triple(-K_FLOOR.toFloat(), 0f, OK), Triple(0f, 4f, Palette.Accent2), Triple(4f, 99f, WARN))) {
            if (kv <= a) continue
            val top2 = y(min(b, kv))
            drawRect(c, Offset(x, top2), Size(bw, y(a) - top2))
        }
        drawRect(Palette.Text, Offset(x - bw * 0.12f, y(peakOf(m)) - 1.5f), Size(bw * 1.24f, 3f))
        text(name, x + bw / 2, bottom + label * 1.1f, label, Palette.Muted)
    }
    if (readout) {
        val x = x0 + bw * 2 + gapB + bw
        val big = max(14f, label * 2.2f)
        val mid = (top + bottom) / 2
        val rms = max(kOf(kL), kOf(kR)).toDouble()
        val pk = max(peakOf(kL), peakOf(kR)).toDouble()
        text("RMS", x, mid - big * 0.9f - big * 0.8f, label, Palette.Muted, Paint.Align.LEFT)
        text(signed(rms), x, mid - big * 0.9f, big, if (rms > 4) WARN else Palette.Text, Paint.Align.LEFT)
        text("PEAK", x, mid + big * 1.1f - big * 0.8f, label, Palette.Muted, Paint.Align.LEFT)
        text(signed(pk), x, mid + big * 1.1f, big, Palette.Text, Paint.Align.LEFT)
    }
}

// ---- crest factor -----------------------------------------------------------------------------------------------

private const val CREST_WINDOW = 3f // seconds of peak and energy the reading looks at
private const val CREST_POINT = 0.25f // seconds between the points of the line
private const val CREST_SPAN = 30f
private const val CREST_MAX = 24f
private val cFrameDt = FloatArray(1024)
private val cFramePeak = FloatArray(1024)
private val cFrameE = FloatArray(1024)
private var cFrom = 0
private var cCount = 0
private var cSince = 0f
private var cValue = Float.NaN
private val cLine = FloatArray((CREST_SPAN / CREST_POINT).toInt())
private var cLineN = 0

internal fun DrawScope.crestFactor(dt: Float, lv: Lv) {
    val rms = max(lv.rmsL, lv.rmsR)
    val peak = max(lv.peakL, lv.peakR)
    if (lv.live && rms > 1e-4f && dt > 0f) {
        val at = (cFrom + cCount) % 1024
        cFrameDt[at] = dt; cFramePeak[at] = peak; cFrameE[at] = rms * rms
        if (cCount < 1024) cCount++ else cFrom = (cFrom + 1) % 1024
        var total = 0f
        for (i in 0 until cCount) total += cFrameDt[(cFrom + i) % 1024]
        while (total > CREST_WINDOW && cCount > 1) { total -= cFrameDt[cFrom]; cFrom = (cFrom + 1) % 1024; cCount-- }
        var pk = 0f; var e = 0f
        for (i in 0 until cCount) { val j = (cFrom + i) % 1024; pk = max(pk, cFramePeak[j]); e += cFrameE[j] * cFrameDt[j] }
        cValue = db(pk) - db(sqrt(e / total))
        cSince += dt
        if (cSince >= CREST_POINT) {
            cSince = 0f
            if (cLineN == cLine.size) { System.arraycopy(cLine, 1, cLine, 0, cLine.size - 1); cLineN-- }
            cLine[cLineN++] = cValue
        }
    }
    val w = size.width; val h = size.height
    val margin = min(w, h) * 0.07f
    val label = max(9f, min(h * 0.06f, w * 0.04f))
    val big = max(18f, min(h * 0.3f, w * 0.2f))
    text("CREST FACTOR", margin, margin + label * 0.6f, label, Palette.Muted, Paint.Align.LEFT)
    val shown = !cValue.isNaN()
    val num = if (shown) { val t = round(cValue * 10).toInt(); "${t / 10}.${t % 10}" } else "−−.−"
    val numY = margin + label * 1.6f + big * 0.4f
    text(num, margin, numY, big, Palette.Accent2, Paint.Align.LEFT)
    tubePaint.textSize = big
    text("dB", margin + tubePaint.measureText(num) + label * 0.5f, numY + big * 0.2f, label * 1.3f, Palette.Muted, Paint.Align.LEFT)
    val lx0 = margin; val lx1 = w - margin; val ly1 = h - margin
    val ly0 = max(margin + label * 1.6f + big + label, h * 0.5f)
    if (ly1 - ly0 < label * 2) return
    val yOf = { v: Float -> ly1 - (ly1 - ly0) * v.coerceIn(0f, CREST_MAX) / CREST_MAX }
    for (v in intArrayOf(6, 12, 18)) {
        drawLine(Palette.Muted.a(0.3f), Offset(lx0, yOf(v.toFloat())), Offset(lx1, yOf(v.toFloat())), 1f)
        text("$v", lx1, yOf(v.toFloat()) - label * 0.6f, label * 0.9f, Palette.Muted, Paint.Align.RIGHT)
    }
    if (cLineN > 1) {
        val n = cLine.size
        val path = androidx.compose.ui.graphics.Path()
        for (i in 0 until cLineN) {
            val x = lx0 + (lx1 - lx0) * (i + (n - cLineN)) / (n - 1)
            if (i == 0) path.moveTo(x, yOf(cLine[i])) else path.lineTo(x, yOf(cLine[i]))
        }
        drawPath(path, Palette.Accent, style = Stroke(max(1.5f, h * 0.01f)))
    }
}

// ---- loudness range ---------------------------------------------------------------------------------------------

internal fun DrawScope.loudnessRange() {
    val w = size.width; val h = size.height
    val shape = Loudness.lraShape
    val bins = shape.size
    val margin = min(w, h) * 0.07f
    val label = max(9f, min(h * 0.06f, w * 0.04f))
    val big = max(16f, min(h * 0.2f, w * 0.14f))
    val lo = Loudness.lraLo; val hi = Loudness.lraHi; val lra = Loudness.lra
    val ready = lo.isFinite() && lra > 0f
    text("LOUDNESS RANGE", margin, margin + label * 0.6f, label, Palette.Muted, Paint.Align.LEFT)
    val num = if (ready) { val t = round(lra * 10).toInt(); "${t / 10}.${t % 10}" } else "−−.−"
    text(num, w - margin - label * 2.2f, margin + label * 0.6f + big * 0.35f, big, Palette.Accent2, Paint.Align.RIGHT)
    text("LU", w - margin - label * 2.0f, margin + label * 0.6f + big * 0.35f, label, Palette.Muted, Paint.Align.LEFT)
    val x0 = margin; val x1 = w - margin
    val y0 = margin + label * 1.6f + big
    val y1 = h - margin - label * 3
    if (y1 - y0 < label * 2) return
    val bw = (x1 - x0) / bins
    var mx = 1
    for (n in shape) if (n > mx) mx = n
    val xOf = { l: Double -> x0 + (x1 - x0) * ((l + 60) / bins).toFloat() }
    for (i in 0 until bins) {
        val n = shape[i]
        if (n == 0) continue
        val mid = -60 + i + 0.5
        val inside = ready && mid >= lo - 0.5 && mid <= hi + 0.5
        val bh = max(1f, (y1 - y0) * n / mx)
        drawRect(if (inside) Palette.Accent else Palette.Muted.a(0.4f), Offset(x0 + i * bw + 0.5f, y1 - bh), Size(max(1f, bw - 1f), bh))
    }
    if (ready) {
        val a = xOf(lo); val b = xOf(hi); val yy = y1 + label * 0.8f
        val s = max(1.5f, h * 0.008f)
        drawLine(Palette.Accent2, Offset(a, yy - label * 0.4f), Offset(a, yy), s)
        drawLine(Palette.Accent2, Offset(a, yy), Offset(b, yy), s)
        drawLine(Palette.Accent2, Offset(b, yy), Offset(b, yy - label * 0.4f), s)
    }
    for (l in intArrayOf(-50, -40, -30, -20, -10)) text("$l", xOf(l.toDouble()), y1 + label * 2.4f, label * 0.9f, Palette.Muted)
}
