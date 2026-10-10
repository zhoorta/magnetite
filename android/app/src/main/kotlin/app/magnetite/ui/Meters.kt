package app.magnetite.ui

import android.graphics.Bitmap
import android.graphics.Paint
import android.graphics.Typeface
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.withFrameNanos
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.clipRect
import androidx.compose.ui.graphics.drawscope.drawIntoCanvas
import androidx.compose.ui.graphics.nativeCanvas
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.IntSize
import androidx.compose.ui.unit.dp
import app.magnetite.player.Loudness
import app.magnetite.player.MeterTap
import app.magnetite.player.Spectrum
import kotlin.math.PI
import kotlin.math.abs
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlin.math.cos
import kotlin.math.log10
import kotlin.math.max
import kotlin.math.min
import kotlin.math.sin
import kotlin.math.sqrt

/** A meter the player can show. The ids are the web's, so the list saved in `users.meters` means the same on both. */
class MeterInfo(val id: String, val label: String, val heightDp: Int, val compactDp: Int)

val MeterCatalog = listOf(
    MeterInfo("vu-wide", "Wide meters", 150, 64),
    MeterInfo("vu-needle", "VU needles", 170, 84),
    MeterInfo("vu-leds", "LED meters", 60, 40),
    MeterInfo("magic-eye", "Magic eyes", 150, 76),
    MeterInfo("scope", "Scope", 90, 56),
    MeterInfo("goniometer", "Goniometer", 240, 110),
    MeterInfo("loudness", "Loudness (LUFS)", 190, 100),
    MeterInfo("third-octave", "Third-octave", 150, 80),
    MeterInfo("spectrogram", "Spectrogram", 150, 80),
)

const val MAX_METERS = 4

/** One meter by id; ids this version does not know draw nothing (the web ignores unknown ids the same way). */
@Composable
fun MeterView(id: String, playing: Boolean, compact: Boolean = false, fill: Boolean = false) {
    val info = MeterCatalog.firstOrNull { it.id == id } ?: return
    val h = (if (compact) info.compactDp else info.heightDp).dp
    val size = if (fill) Modifier.fillMaxSize() else Modifier.fillMaxWidth().height(h)
    when (id) {
        "vu-wide" -> MeterCanvas(playing, size) { dt, lv -> wideMeters(dt, lv) }
        "vu-needle" -> MeterCanvas(playing, size) { dt, lv -> needleMeters(dt, lv) }
        "vu-leds" -> LevelBars(playing, size, barHeight = if (fill) 56.dp else 18.dp)
        "magic-eye" -> MeterCanvas(playing, size) { dt, lv -> magicEyes(dt, lv) }
        "scope" -> MeterCanvas(playing, size) { _, lv -> scope(lv) }
        "goniometer" -> MeterCanvas(playing, size) { _, lv -> goniometer(lv) }
        "loudness" -> MeterCanvas(playing, size) { _, _ -> loudness() }
        "third-octave" -> ThirdOctave(playing, size)
        "spectrogram" -> Spectrogram(playing, size)
    }
}

/** What the meters read: the latest block's peak and RMS per channel, or silence when nothing is being played. */
class Lv(val rmsL: Float, val rmsR: Float, val peakL: Float, val peakR: Float, val live: Boolean)

private fun levels(playing: Boolean): Lv {
    val l = MeterTap.snapshot()
    val fresh = playing && System.nanoTime() - l.atNanos < 250_000_000L
    return if (fresh) Lv(l.rmsL, l.rmsR, l.peakL, l.peakR, true) else Lv(0f, 0f, 0f, 0f, false)
}

/** Redraws every frame while playing and for two seconds after, so needles and bars settle; then stops drawing. */
@Composable
private fun MeterCanvas(playing: Boolean, modifier: Modifier, work: ((dt: Float, lv: Lv) -> Unit)? = null, draw: DrawScope.(dt: Float, lv: Lv) -> Unit) {
    var tick by remember { mutableIntStateOf(0) }
    var dt by remember { mutableFloatStateOf(0f) }
    LaunchedEffect(playing) {
        var last = 0L
        var idle = 0f
        while (true) {
            val now = withFrameNanos { it }
            dt = if (last == 0L) 0f else min(0.1f, (now - last) / 1e9f)
            last = now
            // The heavy part (FFTs) runs off the UI thread; the canvas only draws what it left behind.
            if (work != null) { val lv = levels(playing); withContext(Dispatchers.Default) { work(dt, lv) } }
            tick++
            if (!playing) { idle += dt; if (idle > 2f) break }
        }
    }
    Canvas(modifier) {
        tick
        draw(dt, levels(playing))
    }
}

// ---- shared drawing helpers -------------------------------------------------------------------------------------

private val paint = Paint(Paint.ANTI_ALIAS_FLAG).apply { typeface = Typeface.MONOSPACE }

private fun DrawScope.text(s: String, x: Float, y: Float, size: Float, color: Color, align: Paint.Align = Paint.Align.CENTER) {
    paint.textSize = size
    paint.color = color.toArgb()
    paint.textAlign = align
    // y is the vertical centre of the text
    val yy = y - (paint.ascent() + paint.descent()) / 2
    drawIntoCanvas { it.nativeCanvas.drawText(s, x, yy, paint) }
}

private fun db(x: Float) = 20f * log10(max(x, 1e-6f))
private fun deg(rad: Double) = (rad * 180 / PI).toFloat()
private fun Color.a(alpha: Float) = copy(alpha = alpha)

/** Ballistics of one meter channel, a port of `visualizers/meter.js`. `value` and `peak` are positions on the scale (0..1). */
class Ballistic {
    var value = 0f
    var peak = 0f
    var holdLeft = 0f
    var clipLeft = 0f

    fun update(rms: Float, pk: Float, dt: Float, min: Float, max: Float, attack: Float, release: Float, hold: Float, fall: Float, clipDb: Float = -0.5f) {
        val at = { lin: Float -> ((db(lin) - min) / (max - min)).coerceIn(0f, 1f) }
        val target = at(rms)
        val tau = (if (target > value) attack else release) / 4.6f
        value += (target - value) * (1 - kotlin.math.exp(-dt / tau))
        val p = at(pk)
        if (p >= peak) { peak = p; holdLeft = hold } else if (holdLeft > 0) holdLeft -= dt else peak = max(p, peak - fall * dt)
        clipLeft = if (db(pk) >= clipDb) 0.6f else max(0f, clipLeft - dt)
    }
}

private val Panel = Terminal.Surface

// ---- needle dials (vu-needle) -----------------------------------------------------------------------------------

private val majorDb = listOf(-30, -20, -10, -6, -3, 0)
private val needleL = Ballistic()
private val needleR = Ballistic()

private fun DrawScope.needleMeters(dt: Float, lv: Lv) {
    needleL.update(lv.rmsL, lv.peakL, dt, -30f, 3f, 0.3f, 0.3f, 1f, 0.3f)
    needleR.update(lv.rmsR, lv.peakR, dt, -30f, 3f, 0.3f, 0.3f, 1f, 0.3f)
    val aspect = 1.55f
    val gap = min(size.width, size.height) * 0.04f
    val boxW = min((size.width - gap * 3) / 2, (size.height - gap * 2) * aspect)
    val boxH = boxW / aspect
    val x0 = (size.width - (boxW * 2 + gap)) / 2
    val y0 = (size.height - boxH) / 2
    dial(x0, y0, boxW, boxH, "L", needleL)
    dial(x0 + boxW + gap, y0, boxW, boxH, "R", needleR)
}

private fun DrawScope.dial(x: Float, y: Float, w: Float, h: Float, name: String, m: Ballistic) {
    val min = -30f; val max = 3f; val redFrom = -6f
    val sweep = 96 * PI / 180
    val r = min(w * 0.6f, h * 0.85f)
    val cx = x + w / 2
    val cy = y + h * 0.93f
    val angleOf = { d: Float -> -sweep / 2 + sweep * ((d - min) / (max - min)) }
    val pt = { d: Float, len: Float -> Offset(cx + (sin(angleOf(d)) * len).toFloat(), cy - (cos(angleOf(d)) * len).toFloat()) }
    val ink = Terminal.Accent
    clipRect(x, y, x + w, y + h) {
        drawRoundRect(Panel, Offset(x, y), Size(w, h), CornerRadius(min(w, h) * 0.06f))
        val rr = r * 0.93f
        drawArc(Terminal.Red, deg(angleOf(redFrom) - PI / 2), deg(angleOf(max) - angleOf(redFrom)), false, Offset(cx - rr, cy - rr), Size(rr * 2, rr * 2), style = Stroke(r * 0.05f))
        var d = min
        while (d <= max) {
            val major = d.toInt() in majorDb
            drawLine(ink, pt(d, r * (if (major) 0.8f else 0.85f)), pt(d, r * 0.9f), r * (if (major) 0.012f else 0.006f) + 1f)
            if (major) { val p = pt(d, r * 0.7f); text(d.toInt().toString(), p.x, p.y, max(10f, r * 0.085f), ink) }
            d += 2f
        }
        text(name, x + w * 0.1f, y + h * 0.16f, max(12f, r * 0.14f), ink)
        text("dB", cx, cy - r * 0.36f, max(10f, r * 0.08f), ink)
        drawCircle(Terminal.Red.a(if (m.clipLeft > 0) 1f else 0.18f), r * 0.045f, Offset(x + w * 0.9f, y + h * 0.15f))
        val a = -sweep / 2 + sweep * m.value
        drawLine(ink, Offset(cx - (sin(a) * r * 0.08).toFloat(), cy + (cos(a) * r * 0.08).toFloat()), Offset(cx + (sin(a) * r * 0.92).toFloat(), cy - (cos(a) * r * 0.92).toFloat()), r * 0.018f + 1f)
        drawCircle(ink, r * 0.045f, Offset(cx, cy))
    }
}

// ---- wide meters (vu-wide) --------------------------------------------------------------------------------------

private val wideL = Ballistic()
private val wideR = Ballistic()

private fun DrawScope.wideMeters(dt: Float, lv: Lv) {
    wideL.update(lv.rmsL, lv.peakL, dt, -30f, 3f, 0.3f, 0.3f, 1f, 0.3f)
    wideR.update(lv.rmsR, lv.peakR, dt, -30f, 3f, 0.3f, 0.3f, 1f, 0.3f)
    val aspect = 4.2f
    val gap = min(size.width, size.height) * 0.06f
    val side = min((size.width - gap * 3) / 2, (size.height - gap * 2) * aspect)
    val stacked = min(size.width - gap * 2, ((size.height - gap * 3) / 2) * aspect)
    val useStack = stacked > side
    val bw = if (useStack) stacked else side
    val bh = bw / aspect
    if (useStack) {
        val y0 = (size.height - (bh * 2 + gap)) / 2
        val x0 = (size.width - bw) / 2
        wide(x0, y0, bw, bh, "L", wideL)
        wide(x0, y0 + bh + gap, bw, bh, "R", wideR)
    } else {
        val x0 = (size.width - (bw * 2 + gap)) / 2
        val y0 = (size.height - bh) / 2
        wide(x0, y0, bw, bh, "L", wideL)
        wide(x0 + bw + gap, y0, bw, bh, "R", wideR)
    }
}

private fun DrawScope.wide(x: Float, y: Float, w: Float, h: Float, name: String, m: Ballistic) {
    val theta = 14 * PI / 180
    val min = -30f; val max = 3f
    val r = (w * 0.43 / sin(theta)).toFloat()
    val cx = x + w / 2
    val py = y + h * 0.42f + r
    val angleOf = { d: Float -> -theta + 2 * theta * ((d - min) / (max - min)) }
    val pt = { d: Float, len: Float -> Offset(cx + (sin(angleOf(d)) * len).toFloat(), py - (cos(angleOf(d)) * len).toFloat()) }
    val ink = Terminal.Accent
    clipRect(x, y, x + w, y + h) {
        drawRoundRect(Panel, Offset(x, y), Size(w, h), CornerRadius(h * 0.1f))
        drawArc(Terminal.Red, deg(angleOf(-6f) - PI / 2), deg(angleOf(max) - angleOf(-6f)), false, Offset(cx - r, py - r), Size(r * 2, r * 2), style = Stroke(h * 0.07f))
        var d = min
        while (d <= max) {
            val major = d.toInt() in majorDb
            drawLine(ink, pt(d, r - h * 0.05f), pt(d, r - h * (if (major) 0.16f else 0.11f)), h * (if (major) 0.02f else 0.011f) + 1f)
            if (major) { val p = pt(d, r - h * 0.27f); text(d.toInt().toString(), p.x, p.y, max(9f, h * 0.17f), ink) }
            d += 2f
        }
        text(name, x + w * 0.035f, y + h * 0.22f, max(10f, h * 0.24f), ink, Paint.Align.LEFT)
        drawCircle(Terminal.Red.a(if (m.clipLeft > 0) 1f else 0.18f), h * 0.05f, Offset(x + w * 0.965f, y + h * 0.2f))
        val a = -theta + 2 * theta * m.value
        drawLine(ink, Offset(cx + (sin(a) * (r - h * 1.3f)).toFloat(), py - (cos(a) * (r - h * 1.3f)).toFloat()), Offset(cx + (sin(a) * (r + h * 0.06f)).toFloat(), py - (cos(a) * (r + h * 0.06f)).toFloat()), max(1.5f, h * 0.022f))
    }
}

// ---- magic eyes -------------------------------------------------------------------------------------------------

private val eyeL = Ballistic()
private val eyeR = Ballistic()

private fun DrawScope.magicEyes(dt: Float, lv: Lv) {
    eyeL.update(lv.rmsL, lv.peakL, dt, -36f, 0f, 0.12f, 0.35f, 0.6f, 0.3f)
    eyeR.update(lv.rmsR, lv.peakR, dt, -36f, 0f, 0.12f, 0.35f, 0.6f, 0.3f)
    val gap = min(size.width, size.height) * 0.12f
    val d = max(20f, min(size.height * 0.74f, (size.width - gap * 3) / 2))
    val x0 = (size.width - (d * 2 + gap)) / 2
    val cy = size.height * 0.46f
    listOf("L" to eyeL, "R" to eyeR).forEachIndexed { i, (name, m) ->
        val cx = x0 + d / 2 + i * (d + gap)
        eye(cx, cy, d / 2, m.value)
        text(name, cx, cy + d / 2 + d * 0.16f, max(10f, d * 0.1f), Terminal.Muted)
    }
}

private fun DrawScope.eye(cx: Float, cy: Float, r: Float, v: Float) {
    val glass = Color(0xFF050806)
    val phosphor = Terminal.Accent2
    val half = 78f * (1 - v)
    val target = r * 0.84f
    drawCircle(glass, r, Offset(cx, cy))
    drawCircle(Terminal.Muted, r, Offset(cx, cy), style = Stroke(r * 0.05f))
    // Glow: a few concentric discs fading outwards (shaders per frame were the cost of this meter).
    val glowR = target * (1.1f + 0.3f * v)
    for (i in 0 until 3) drawCircle(phosphor.a((0.5f + 0.5f * v) * 0.24f), glowR * (1f - i * 0.08f), Offset(cx, cy))
    drawCircle(phosphor.a(0.45f + 0.55f * v), target, Offset(cx, cy))
    for (i in 0 until 2) drawCircle(Color.White.a((0.3f + 0.7f * v) * 0.2f), target * (1f - i * 0.35f), Offset(cx, cy))
    if (half > 0.1f) drawArc(glass, 90f - half, half * 2, true, Offset(cx - r, cy - r), Size(r * 2, r * 2))
    drawCircle(glass, r * 0.12f, Offset(cx, cy))
    drawCircle(phosphor.a(0.5f + 0.5f * v), r * 0.12f, Offset(cx, cy), style = Stroke(r * 0.02f))
}

// ---- scope ------------------------------------------------------------------------------------------------------

private val scopeBuf = FloatArray(1024)
private val scopePath = Path()

private fun DrawScope.scope(lv: Lv) {
    if (lv.live) MeterTap.recent(scopeBuf) else scopeBuf.fill(0f)
    val mid = size.height / 2
    drawLine(Color(0xFF1E2622), Offset(0f, mid), Offset(size.width, mid), 1f)
    // A path of 1024 anti-aliased segments is slow to stroke every frame; one point per ~3 px (the loudest sample of its bucket) looks the same.
    val points = (size.width / 3f).toInt().coerceIn(32, scopeBuf.size)
    val path = scopePath
    path.rewind()
    for (p in 0 until points) {
        val from = p * scopeBuf.size / points
        val to = maxOf(from + 1, (p + 1) * scopeBuf.size / points)
        var v = scopeBuf[from]
        for (i in from + 1 until to) if (abs(scopeBuf[i]) > abs(v)) v = scopeBuf[i]
        val x = p * size.width / (points - 1)
        val y = mid - v.coerceIn(-1f, 1f) * mid
        if (p == 0) path.moveTo(x, y) else path.lineTo(x, y)
    }
    drawPath(path, Terminal.Accent, style = Stroke(2.5f))
}

// ---- goniometer -------------------------------------------------------------------------------------------------

private const val GONIO = 1024
private val gonioL = FloatArray(GONIO)
private val gonioR = FloatArray(GONIO)
private val gonioPts = FloatArray(GONIO * 2)
private val goniometerPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply { strokeCap = Paint.Cap.ROUND }

private fun DrawScope.goniometer(lv: Lv) {
    val margin = min(size.width, size.height) * 0.06f
    val font = max(10f, min(size.height * 0.045f, size.width * 0.03f))
    val barH = max(6f, font * 0.7f)
    val strip = barH + font * 2.4f
    val sz = min(size.width - margin * 2, size.height - strip - margin * 2)
    if (sz < 20f) return
    val cx = size.width / 2
    val top = (size.height - sz - strip) / 2
    val cy = top + sz / 2
    val r = sz / 2
    val grid = Terminal.Muted.a(0.4f)
    drawCircle(grid, r, Offset(cx, cy), style = Stroke(1f))
    drawLine(grid, Offset(cx, cy - r), Offset(cx, cy + r), 1f)
    drawLine(grid, Offset(cx - r, cy), Offset(cx + r, cy), 1f)
    val q = r * 0.7071f
    drawLine(grid, Offset(cx - q, cy - q), Offset(cx + q, cy + q), 1f)
    drawLine(grid, Offset(cx + q, cy - q), Offset(cx - q, cy + q), 1f)
    text("M", cx, cy - r - font * 0.7f, font, Terminal.Muted)
    text("L", cx - q - font * 0.9f, cy - q - font * 0.3f, font, Terminal.Muted)
    text("R", cx + q + font * 0.9f, cy - q - font * 0.3f, font, Terminal.Muted)

    // Trace: the last 1024 samples at the playhead. Mid is up, side across. Drawn as points: a stroked path of 1024 segments costs several frames.
    val k = r * 0.7f
    if (lv.live) {
        MeterTap.recentStereo(gonioL, gonioR)
        for (n in 0 until GONIO) {
            val l = gonioL[n]; val rr = gonioR[n]
            gonioPts[n * 2] = cx + (rr - l) / sqrt(2f) * k
            gonioPts[n * 2 + 1] = cy - (l + rr) / sqrt(2f) * k
        }
        goniometerPaint.strokeWidth = max(1.5f, sz / 200f)
        goniometerPaint.color = Terminal.Accent.a(0.6f).toArgb()
        drawIntoCanvas { it.nativeCanvas.drawPoints(gonioPts, goniometerPaint) }
    }

    val bw = min(size.width - margin * 2, sz * 1.5f)
    val x0 = cx - bw / 2
    val y0 = cy + r + font * 0.9f
    val at = { v: Float -> x0 + (v + 1) / 2 * bw }
    val c = (if (lv.live) Loudness.correlation else 0f).coerceIn(-1f, 1f)
    drawRect(Terminal.Muted.a(0.25f), Offset(x0, y0), Size(bw, barH))
    drawRect(if (c < 0) Terminal.Red else Terminal.Accent, Offset(min(at(0f), at(c)), y0), Size(kotlin.math.abs(at(c) - at(0f)), barH))
    text("-1", at(-1f), y0 + barH + font * 0.9f, font * 0.9f, Terminal.Muted, Paint.Align.LEFT)
    text("0", at(0f), y0 + barH + font * 0.9f, font * 0.9f, Terminal.Muted)
    text("+1", at(1f), y0 + barH + font * 0.9f, font * 0.9f, Terminal.Muted, Paint.Align.RIGHT)
    text("correlation ${if (c >= 0) "+" else "−"}${"%.2f".format(kotlin.math.abs(c))}", cx, y0 + barH + font * 2.1f, font, Terminal.Text)
}

// ---- loudness ---------------------------------------------------------------------------------------------------

private fun fmt(v: Double): String {
    if (!v.isFinite()) return "−−.-"
    val tenths = kotlin.math.round(kotlin.math.abs(v) * 10).toInt() // no String.format: it allocates and runs several times per frame
    return "${if (v < 0) "−" else "+"}${tenths / 10}.${tenths % 10}"
}

private fun DrawScope.loudness() {
    val margin = min(size.width, size.height) * 0.07f
    val rowH = min((size.height - margin * 2) / 4.4f, size.width * 0.12f)
    val top = (size.height - (rowH * 4 + rowH * 0.4f)) / 2
    val label = max(9f, min(rowH * 0.22f, size.width * 0.02f))
    val big = max(14f, min(rowH * 0.5f, size.width * 0.07f))
    val numW = big * 3.4f
    val barW = size.width - margin * 2 - numW - label * 4.5f
    val at = { v: Double -> ((v - -41) / 36).toFloat().coerceIn(0f, 1f) }
    listOf(Triple("MOMENTARY", Loudness.momentary, Terminal.Accent), Triple("SHORT-TERM", Loudness.shortTerm, Terminal.Accent), Triple("INTEGRATED", Loudness.integrated, Terminal.Accent2)).forEachIndexed { i, (name, v, color) ->
        val y = top + i * rowH
        val barH = max(4f, rowH * 0.16f)
        val barY = y + rowH * 0.62f
        text(name, margin, y + label, label, Terminal.Muted, Paint.Align.LEFT)
        drawRect(Terminal.Muted.a(0.22f), Offset(margin, barY), Size(barW, barH))
        drawRect(color, Offset(margin, barY), Size(barW * at(v), barH))
        drawRect(Terminal.Text, Offset(margin + barW * at(-23.0) - 0.5f, barY - barH * 0.35f), Size(1.5f, barH * 1.7f))
        text(fmt(v), size.width - margin - label * 3.2f, y + rowH * 0.45f, big, Terminal.Text, Paint.Align.RIGHT)
        text("LUFS", size.width - margin, y + rowH * 0.45f, label, Terminal.Muted, Paint.Align.RIGHT)
    }
    val y = top + rowH * 3.4f
    text("TRUE PEAK", margin, y + label, label, Terminal.Muted, Paint.Align.LEFT)
    listOf("L" to Loudness.truePeakL, "R" to Loudness.truePeakR).forEachIndexed { i, (name, lin) ->
        val d = if (lin > 0) db(lin).toDouble() else Double.NEGATIVE_INFINITY
        val x = margin + barW * 0.45f + i * barW * 0.28f
        text(name, x, y + rowH * 0.4f, label, Terminal.Muted, Paint.Align.LEFT)
        text(fmt(d), x + label * 1.6f, y + rowH * 0.4f, big * 0.8f, if (d > -1.0) Terminal.Red else Terminal.Text, Paint.Align.LEFT)
    }
    text("dBTP", size.width - margin, y + rowH * 0.4f, label, Terminal.Muted, Paint.Align.RIGHT)
}

// ---- third-octave -----------------------------------------------------------------------------------------------

private const val THIRDS = 31

@Composable
private fun ThirdOctave(playing: Boolean, modifier: Modifier) {
    val spectrum = remember { Spectrum(THIRDS, 8192, 25.0, 20000.0) }
    val raw = remember { FloatArray(THIRDS) }
    val value = remember { FloatArray(THIRDS) { -120f } }
    val peak = remember { FloatArray(THIRDS) { -120f } }
    val hold = remember { FloatArray(THIRDS) }
    MeterCanvas(playing, modifier, work = { _, lv -> if (lv.live) spectrum.read(raw) else raw.fill(-120f) }) { dt, _ ->
        for (i in 0 until THIRDS) {
            val d = raw[i]
            value[i] = if (d > value[i]) d else d + (value[i] - d) * kotlin.math.exp(-dt / (0.35f / 4.6f))
            if (d >= peak[i]) { peak[i] = d; hold[i] = 1.5f } else if (hold[i] > 0) hold[i] -= dt else peak[i] = max(d, peak[i] - 18f * dt)
        }
        val min = -72f; val max = -12f; val segs = 20
        val font = max(9f, min(size.height * 0.07f, size.width * 0.03f))
        val left = font * 2.6f
        val bottom = size.height - font * 1.6f
        val plotH = bottom - font * 0.5f
        val slot = (size.width - left - font * 0.5f) / THIRDS
        val bw = slot * 0.72f
        val segH = plotH / segs
        val lit = { d: Float -> ((d - min) / 3f).toInt().coerceIn(0, segs) }
        for (i in 0 until THIRDS) {
            val x = left + i * slot + (slot - bw) / 2
            val n = lit(value[i])
            val pk = min(segs - 1, lit(peak[i]))
            for (s in 0 until segs) {
                val on = s < n
                val isPeak = s == pk && peak[i] > min
                val c = if ((isPeak && s >= n) || s > segs * 0.85f) Terminal.Accent2 else Terminal.Accent
                drawRect(c.a(if (on || isPeak) 1f else 0.1f), Offset(x, bottom - (s + 1) * segH + segH * 0.12f), Size(bw, segH * 0.76f))
            }
        }
        for (d in listOf(-60, -48, -36, -24, -12)) text(d.toString(), left - font * 0.3f, bottom - (d - min) / (max - min) * plotH, font, Terminal.Muted, Paint.Align.RIGHT)
        // 31.5 Hz is band 1 of the web's 20 Hz start; here band 0 is 25 Hz, so the labels are placed by frequency.
        for ((f, label) in listOf(31.5 to "31", 125.0 to "125", 500.0 to "500", 1000.0 to "1k", 4000.0 to "4k", 16000.0 to "16k")) {
            val i = (kotlin.math.ln(f / 25.0) / kotlin.math.ln(20000.0 / 25.0) * THIRDS).toInt().coerceIn(0, THIRDS - 1)
            text(label, left + i * slot + slot / 2, size.height - font * 0.5f, font, Terminal.Muted)
        }
    }
}

// ---- spectrogram ------------------------------------------------------------------------------------------------

private const val COLS = 400
private const val ROWS = 64

@Composable
private fun Spectrogram(playing: Boolean, modifier: Modifier) {
    val spectrum = remember { Spectrum(ROWS, 4096, 25.0, 20000.0) }
    val raw = remember { FloatArray(ROWS) }
    val pixels = remember { IntArray(COLS * ROWS) { Terminal.Bg.toArgb() } }
    val bitmap = remember { Bitmap.createBitmap(COLS, ROWS, Bitmap.Config.ARGB_8888) }
    val state = remember { floatArrayOf(0f) }
    val lut = remember {
        val stops = listOf(0f to Terminal.Bg, 0.4f to Terminal.Accent, 0.75f to Terminal.Accent2, 1f to Terminal.Text)
        IntArray(256) { i ->
            val t = i / 255f
            var k = 1
            while (k < stops.size - 1 && t > stops[k].first) k++
            val (t0, c0) = stops[k - 1]; val (t1, c1) = stops[k]
            androidx.compose.ui.graphics.lerp(c0, c1, ((t - t0) / (t1 - t0)).coerceIn(0f, 1f)).toArgb()
        }
    }
    val fresh = remember { booleanArrayOf(false) }
    MeterCanvas(playing, modifier, work = { _, lv -> fresh[0] = lv.live; if (lv.live) spectrum.read(raw) }) { dt, lv ->
        if (fresh[0]) {
            state[0] += dt * COLS / 13f // about 13 seconds from the right edge to the left; the fraction slides the picture between whole columns
            val n = min(COLS, state[0].toInt())
            if (n >= 1) {
                state[0] -= n
                for (row in 0 until ROWS) {
                    System.arraycopy(pixels, row * COLS + n, pixels, row * COLS, COLS - n)
                    // the treble gets a little lift so music (about -3 dB per octave) does not fade to black
                    val f = 25.0 * Math.pow(800.0, 1 - (row + 0.5) / ROWS)
                    val v = ((raw[ROWS - 1 - row] - -95f + 2f * (kotlin.math.ln(f / 1000.0) / kotlin.math.ln(2.0)).toFloat()) / 85f).coerceIn(0f, 1f)
                    val c = lut[(v * 255).toInt()]
                    for (k in 0 until n) pixels[row * COLS + COLS - n + k] = c
                }
                bitmap.setPixels(pixels, 0, COLS, 0, 0, COLS, ROWS)
            }
        }
        val font = max(9f, size.height * 0.055f)
        val left = font * 3f
        val colW = (size.width - left) / COLS
        clipRect(left, 0f, size.width, size.height) {
            drawImage(bitmap.asImageBitmap(), dstOffset = IntOffset((left - state[0] * colW).toInt(), 0), dstSize = IntSize((size.width - left).toInt(), size.height.toInt()), filterQuality = androidx.compose.ui.graphics.FilterQuality.None)
        }
        for ((f, label) in listOf(100.0 to "100", 1000.0 to "1k", 10000.0 to "10k")) {
            val y = size.height * (1 - (kotlin.math.ln(f / 25.0) / kotlin.math.ln(800.0)).toFloat())
            text(label, left - 4f, y, font, Terminal.Muted, Paint.Align.RIGHT)
        }
    }
}
