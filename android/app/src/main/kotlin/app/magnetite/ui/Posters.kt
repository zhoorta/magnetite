package app.magnetite.ui

import android.graphics.Paint
import android.graphics.Path
import android.graphics.PorterDuff
import android.graphics.PorterDuffXfermode
import android.graphics.RectF
import android.graphics.Typeface
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.drawscope.drawIntoCanvas
import androidx.compose.ui.graphics.nativeCanvas
import androidx.compose.ui.graphics.toArgb
import app.magnetite.data.Track
import app.magnetite.player.Loudness
import app.magnetite.player.Spectrum
import kotlin.math.PI
import kotlin.math.abs
import kotlin.math.cos
import kotlin.math.exp
import kotlin.math.floor
import kotlin.math.log10
import kotlin.math.max
import kotlin.math.min
import kotlin.math.pow
import kotlin.math.roundToInt
import kotlin.math.sin

// Poster meters (docs/posters.md): the web's band and level posters (the nine newest are in `PostersMore.kt`), ported from `visualizers/poster-*.js`. Each one draws in its own
// 600 x 800 space, scaled to fit the cell, so the ports keep the web's numbers; `Cv` is a small stand-in for the web's 2D canvas so a poster reads
// like its original. Theme level 1 only (paper, ink and dark panel follow the theme, the accents stay the poster's own); the web's other
// settings keep their defaults.

internal const val W = 600f
internal const val H = 800f
internal const val PI_F = PI.toFloat()
internal const val BANDS = 31

// ---- the canvas stand-in -----------------------------------------------------------------------------------------

class Cv {
    lateinit var c: android.graphics.Canvas
    var fill = 0
    var stroke = 0
    var lineWidth = 1f
    var alpha = 1f
    var mode: PorterDuff.Mode? = null
    var dash: FloatArray? = null
    var dashPhase = 0f
    var round = false
    var viewScale = 1f
    private val path = Path()
    private var has = false
    private val oval = RectF()
    private val rect = RectF()
    private val p = Paint(Paint.ANTI_ALIAS_FLAG)
    private val tp = Paint(Paint.ANTI_ALIAS_FLAG)
    private val regular = Typeface.create("sans-serif", Typeface.NORMAL)
    private val bold = Typeface.create("sans-serif", Typeface.BOLD)
    private val italicFace = Typeface.create("sans-serif", Typeface.ITALIC)
    private val boldItalic = Typeface.create("sans-serif", Typeface.BOLD_ITALIC)
    private val faces = HashMap<Int, Typeface>()
    private val imagePaint = Paint(Paint.ANTI_ALIAS_FLAG or Paint.FILTER_BITMAP_FLAG)

    fun save() { c.save() }
    fun restore() { c.restore() }
    fun translate(x: Float, y: Float) = c.translate(x, y)
    fun scale(s: Float) = c.scale(s, s)
    fun rotate(rad: Float) = c.rotate(rad * 180f / PI_F)

    fun beginPath() { path.rewind(); has = false }
    fun moveTo(x: Float, y: Float) { path.moveTo(x, y); has = true }
    fun lineTo(x: Float, y: Float) { if (has) path.lineTo(x, y) else moveTo(x, y) }
    fun bezierTo(x1: Float, y1: Float, x2: Float, y2: Float, x: Float, y: Float) { path.cubicTo(x1, y1, x2, y2, x, y) }
    fun quadTo(x1: Float, y1: Float, x: Float, y: Float) { path.quadTo(x1, y1, x, y) }
    fun closePath() = path.close()
    fun rect(x: Float, y: Float, w: Float, h: Float) { path.addRect(x, y, x + w, y + h, Path.Direction.CW); has = true }
    fun roundRect(x: Float, y: Float, w: Float, h: Float, r: Float) { rect.set(x, y, x + w, y + h); path.addRoundRect(rect, r, r, Path.Direction.CW); has = true }
    fun circle(x: Float, y: Float, r: Float) { path.addCircle(x, y, r, Path.Direction.CW); has = true }

    /** The canvas `arc`: clockwise from a0 to a1 (radians), joined to the current point by a line. */
    fun arc(cx: Float, cy: Float, r: Float, a0: Float, a1: Float) = ellipse(cx, cy, r, r, a0, a1)

    fun ellipse(cx: Float, cy: Float, rx: Float, ry: Float, a0: Float, a1: Float, ccw: Boolean = false) {
        oval.set(cx - rx, cy - ry, cx + rx, cy + ry)
        var sweep = (a1 - a0) * 180f / PI_F
        if (ccw) {
            // anticlockwise: the sweep is negative
            while (sweep > 0f) sweep -= 360f
            if (sweep <= -360f) { path.addOval(oval, Path.Direction.CW); has = true; return }
        } else {
            // The canvas goes clockwise: arc(PI, 0) is the half over the top (+180), not the half underneath (-180).
            while (sweep < 0f) sweep += 360f
            if (sweep >= 360f) { path.addOval(oval, Path.Direction.CW); has = true; return }
        }
        path.arcTo(oval, a0 * 180f / PI_F, sweep, !has)
        has = true
    }

    fun clip() { c.clipPath(path) }
    fun clipRect(x: Float, y: Float, w: Float, h: Float) { c.clipRect(x, y, x + w, y + h) }

    private fun paint(color: Int, style: Paint.Style) {
        p.style = style
        p.color = color
        p.alpha = (Color.alpha(color) * alpha).roundToInt()
        p.strokeWidth = lineWidth
        p.xfermode = mode?.let { PorterDuffXfermode(it) }
        p.strokeJoin = if (round) Paint.Join.ROUND else Paint.Join.MITER
        p.strokeCap = if (round) Paint.Cap.ROUND else Paint.Cap.BUTT
        p.pathEffect = dash?.let { android.graphics.DashPathEffect(it, dashPhase) }
    }

    /** The path of a stand-in `Path2D`: stroked or filled with the current colours, leaving the canvas path alone. */
    fun strokePath(shape: Path) { paint(stroke, Paint.Style.STROKE); c.drawPath(shape, p) }
    fun fillPath(shape: Path) { paint(fill, Paint.Style.FILL); c.drawPath(shape, p) }

    /** A picture drawn into the box; `tint` turns a mask picture (colour from alpha) into one colour. */
    fun image(bmp: android.graphics.Bitmap, x: Float, y: Float, w: Float, h: Float, tint: Int? = null) {
        imagePaint.colorFilter = tint?.let { android.graphics.PorterDuffColorFilter(it, PorterDuff.Mode.SRC_IN) }
        imagePaint.alpha = (alpha * 255).roundToInt()
        rect.set(x, y, x + w, y + h)
        c.drawBitmap(bmp, null, rect, imagePaint)
    }

    /** The font by a CSS-like weight (400, 500, 600 medium, 700 and 800 bold, 900 black), in the system sans. */
    fun fontW(sizePx: Float, weight: Int, spacing: Float = 0f) {
        val key = when { weight >= 900 -> 900; weight >= 700 -> 700; weight >= 500 -> 500; else -> 400 }
        tp.textSize = sizePx
        tp.typeface = faces.getOrPut(key) { Typeface.create(when (key) { 900 -> "sans-serif-black"; 700 -> "sans-serif"; 500 -> "sans-serif-medium"; else -> "sans-serif" }, if (key == 700) Typeface.BOLD else Typeface.NORMAL) }
        tp.letterSpacing = if (spacing == 0f) 0f else spacing / sizePx
    }

    /** A font of the poster's own (the pixel font, the monospace). */
    fun fontFace(sizePx: Float, face: Typeface, spacing: Float = 0f) {
        tp.textSize = sizePx
        tp.typeface = face
        tp.letterSpacing = if (spacing == 0f) 0f else spacing / sizePx
    }

    fun fill() { paint(fill, Paint.Style.FILL); c.drawPath(path, p) }
    fun stroke() { paint(stroke, Paint.Style.STROKE); c.drawPath(path, p) }
    fun strokeRect(x: Float, y: Float, w: Float, h: Float) { paint(stroke, Paint.Style.STROKE); c.drawRect(x, y, x + w, y + h, p) }
    fun fillRect(x: Float, y: Float, w: Float, h: Float) { paint(fill, Paint.Style.FILL); c.drawRect(x, y, x + w, y + h, p) }

    fun font(sizePx: Float, isBold: Boolean, spacing: Float = 0f, italic: Boolean = false) {
        tp.textSize = sizePx
        tp.typeface = if (italic) (if (isBold) boldItalic else italicFace) else if (isBold) bold else regular
        tp.letterSpacing = if (spacing == 0f) 0f else spacing / sizePx
    }

    fun measure(s: String) = tp.measureText(s)

    fun strokeText(s: String, x: Float, y: Float, color: Int) {
        tp.style = Paint.Style.STROKE; tp.strokeWidth = lineWidth; tp.strokeJoin = Paint.Join.ROUND
        tp.color = color; tp.alpha = Color.alpha(color); tp.textAlign = Paint.Align.LEFT
        c.drawText(s, x, y, tp)
        tp.style = Paint.Style.FILL
    }

    fun text(s: String, x: Float, y: Float, color: Int, align: Paint.Align = Paint.Align.LEFT) {
        tp.color = color
        tp.alpha = Color.alpha(color)
        tp.textAlign = align
        c.drawText(s, x, y, tp)
    }
}

/** The canvas `save()` and `restore()` together: the matrix and clip as well as the colours, line width, alpha, dash and blend mode. */
inline fun Cv.keep(block: Cv.() -> Unit) {
    val f = fill; val st = stroke; val lw = lineWidth; val a = alpha; val d = dash; val dp = dashPhase; val r = round; val m = mode
    save()
    block()
    restore()
    fill = f; stroke = st; lineWidth = lw; alpha = a; dash = d; dashPhase = dp; round = r; mode = m
}

internal object Color {
    fun alpha(c: Int) = (c ushr 24) and 0xFF
    fun rgb(c: Int) = intArrayOf((c shr 16) and 0xFF, (c shr 8) and 0xFF, c and 0xFF)
    fun of(hex: String): Int = android.graphics.Color.parseColor(hex)
}

// ---- shared pieces (visualizers/poster.js) -----------------------------------------------------------------------

internal fun toDb(x: Float) = 20f * log10(max(x, 1e-6f))
internal fun clamp01(x: Float) = x.coerceIn(0f, 1f)
internal fun follow(value: Float, target: Float, dt: Float, seconds: Float) = value + (target - value) * (1 - exp(-dt / (max(seconds, 0.001f) / 4.6f)))

/** What "full" means for a track: a level in dB that follows the track's own peaks (see the web's `Reference`). */
internal class Reference(var db: Float = -90f) {
    fun update(peakDb: Float, dt: Float, rise: Float = 40f, fall: Float = 2f): Float {
        val floor = max(db, peakDb - 30f)
        db = if (peakDb > floor) min(peakDb, floor + rise * dt) else max(peakDb, floor - fall * dt)
        return db
    }

    fun norm(d: Float, range: Float, gamma: Float = 1.5f) = clamp01((d - (db - range)) / range).pow(gamma)
}

/** `n` groups of the third-octave bands that rise and fall like a meter, scaled against the track's own peak. */
internal class Bars(val n: Int) {
    private val db = FloatArray(n)
    val raw = FloatArray(n)
    val v = FloatArray(n)
    private val ref = Reference()

    fun update(bands: FloatArray, dt: Float, range: Float, attack: Float, release: Float): FloatArray {
        for (i in 0 until n) {
            val from = floor(i * bands.size / n.toFloat()).toInt()
            val to = max(from + 1, floor((i + 1) * bands.size / n.toFloat()).toInt())
            var loudest = Float.NEGATIVE_INFINITY
            for (k in from until min(to, bands.size)) if (bands[k] > loudest) loudest = bands[k]
            db[i] = loudest
        }
        ref.update(bands.max(), dt)
        for (i in 0 until n) {
            raw[i] = ref.norm(db[i], range)
            v[i] = follow(v[i], raw[i], dt, if (raw[i] > v[i]) attack else release)
        }
        return v
    }
}

internal fun luminance(c: Int): Float {
    val l = Color.rgb(c).map { v -> val s = v / 255f; if (s <= 0.03928f) s / 12.92f else ((s + 0.055f) / 1.055f).pow(2.4f) }
    return 0.2126f * l[0] + 0.7152f * l[1] + 0.0722f * l[2]
}

internal fun isDark(c: Int) = luminance(c) < 0.2f

/** Of the colours, the one that reads best on [bg]. */
internal fun readableOn(bg: Int, vararg candidates: Int): Int {
    val lb = luminance(bg)
    val ratio = { c: Int -> (max(lb, luminance(c)) + 0.05f) / (min(lb, luminance(c)) + 0.05f) }
    return candidates.reduce { best, c -> if (ratio(c) > ratio(best)) c else best }
}

/** How shapes on this paper add up where they overlap: darker on light paper (like ink), lighter on dark paper. */
internal fun overlap(paper: Int) = if (isDark(paper)) PorterDuff.Mode.SCREEN else PorterDuff.Mode.MULTIPLY

/** The poster's colours at theme level 1: paper, ink and the dark panel from the theme, the rest its own. */
internal class PosterLook(own: Map<String, String>) {
    private val m = own.mapValues { Color.of(it.value) }.toMutableMap().apply {
        put("paper", Palette.Bg.toArgb()); put("ink", Palette.Text.toArgb()); put("dark", Palette.Surface.toArgb())
        // a colour the poster does not name follows the theme
        putIfAbsent("a", Palette.Accent.toArgb()); putIfAbsent("b", Palette.Accent2.toArgb()); putIfAbsent("c", Palette.Text.toArgb())
    }
    operator fun get(k: String) = m.getValue(k)
}

internal class Say(t: Track?) {
    val name = t?.artist?.takeIf { it.isNotEmpty() } ?: t?.title?.takeIf { it.isNotEmpty() } ?: "magnetite"
    val title = if (t?.artist.isNullOrEmpty()) "" else t?.title ?: ""
    val album = t?.album ?: ""
    val year = t?.year?.toString() ?: ""
    val no = t?.number?.let { it.toString().padStart(2, '0') } ?: ""
    val dur = t?.duration?.takeIf { it > 0 }?.let { "${(it / 60).toInt()}:${((it % 60).toInt()).toString().padStart(2, '0')}" } ?: ""
    val fmt = t?.let { Info.formatText(it) } ?: ""
    val dr = Info.dr(t?.truth?.dr) ?: ""
    fun meta() = listOf(listOf(year, listOf(no, dur).filter { it.isNotEmpty() }.joinToString(" · ")), listOf(album), listOf(fmt, dr)).map { c -> c.filter { it.isNotEmpty() } }
}

internal fun join(vararg s: String) = s.filter { it.isNotEmpty() }.joinToString(" · ")

/** The 600 x 800 space as large as fits, centred on the paper; returns whether the cell is big enough to print text. Close it with `g.restore()`. */
internal fun begin(g: Cv, w: Float, h: Float, paper: Int): Boolean {
    val scale = min(w / W, h / H)
    g.viewScale = scale
    g.fill = paper
    g.fillRect(0f, 0f, w, h)
    g.save()
    g.translate((w - W * scale) / 2, (h - H * scale) / 2)
    g.scale(scale)
    g.beginPath(); g.rect(0f, 0f, W, H); g.clip()
    return scale >= 0.3f
}

private fun fitSize(g: Cv, text: String, bold: Boolean, maxW: Float, start: Float, min: Float = 10f, spacing: Float = 0f): Float {
    var size = start
    g.font(size, bold, spacing)
    while (size > min && g.measure(text) > maxW) { size -= 2f; g.font(size, bold, spacing) }
    return size
}

private fun drawName(g: Cv, text: String, x: Float, y: Float, maxW: Float, size: Float, color: Int, bold: Boolean = true, caps: Boolean = false, spacing: Float = 0f, align: Paint.Align = Paint.Align.LEFT) {
    if (text.isEmpty()) return
    val t = if (caps) text.uppercase() else text
    fitSize(g, t, bold, maxW, size, spacing = spacing)
    g.text(t, x, y, color, align)
}

private fun drawColumns(g: Cv, cols: List<List<String>>, y: Float, color: Int, size: Float = 14f, xs: List<Float> = listOf(28f, W * 0.34f, W * 0.7f)) {
    g.font(size, false)
    cols.forEachIndexed { i, lines ->
        lines.forEachIndexed { k, line ->
            val maxW = (xs.getOrNull(i + 1) ?: (W - 20)) - xs[i] - 12
            var t = line
            while (t.length > 3 && g.measure(t) > maxW) t = t.dropLast(2)
            g.text(if (t == line) t else t.trimEnd() + "…", xs[i], y + k * (size + 4), color)
        }
    }
}

// ---- the posters -------------------------------------------------------------------------------------------------

class PosterCtx {
    lateinit var g: Cv
    var w = 0f
    var h = 0f
    var dt = 0f
    var bands = FloatArray(BANDS) { -120f }
    var lv = Lv(0f, 0f, 0f, 0f, false)
    var track: Track? = null
    var progress = 0f
    val gonioL = FloatArray(1024)
    val gonioR = FloatArray(1024)

    /** Thumbnails wait for the pictures a poster uses; the live meter draws without them until they have loaded. */
    var sync = false
}

abstract class Poster(val needsBands: Boolean = true, val needsGonio: Boolean = false) {
    abstract fun draw(x: PosterCtx)
}

private class Pillars : Poster() {
    private val bars = Bars(7)
    override fun draw(x: PosterCtx) = with(x.g) {
        val l = PosterLook(mapOf("a" to "#f26a14", "b" to "#f58a2a"))
        val txt = begin(this, x.w, x.h, l["paper"])
        val e = bars.update(x.bands, x.dt, 40f, 0.04f, 0.35f)
        val size = if (txt) W - 56 else min(W - 56, H - 56)
        val px = (W - size) / 2
        val py = if (txt) 28f else (H - size) / 2
        val gap = 5f
        val width = (size - gap * 6) / 7
        save(); beginPath(); roundRect(px, py, size, size, 36f); clip()
        for (i in 0 until 7) {
            val height = size * (0.35f + 0.65f * e[i])
            val xx = px + i * (width + gap)
            val top = py + size - height
            val r = width / 2
            fill = if (i % 2 == 1) l["b"] else l["a"]
            beginPath(); moveTo(xx, py + size + 2); lineTo(xx, top + r); arc(xx + r, top + r, r, PI_F, 0f); lineTo(xx + width, py + size + 2); closePath(); fill()
        }
        val cr = size * 0.12f * (1 + 0.3f * e[0])
        val cx = px + size - cr - 26
        val cy = py + cr + 26
        fill = l["paper"]; beginPath(); circle(cx, cy, cr + 7); fill()
        fill = l["a"]; beginPath(); circle(cx, cy, cr); fill()
        restore()
        if (txt) {
            val say = Say(x.track)
            drawName(this, say.name, 28f, py + size + 76, W - 56, 52f, l["ink"], caps = true, spacing = 2f)
            drawColumns(this, listOf(listOf(join(say.title, say.album), join(say.year, say.fmt, say.dur)).filter { it.isNotEmpty() }), py + size + 108, l["ink"], 15f, listOf(28f))
        }
        restore()
    }
}

private class Lens : Poster(needsBands = false) {
    private val ref = Reference()
    private var l = 0f
    private var r = 0f
    private var corr = 0f
    override fun draw(x: PosterCtx) = with(x.g) {
        val look = PosterLook(mapOf("a" to "#fb6a1c", "b" to "#ee2e2e"))
        val txt = begin(this, x.w, x.h, look["paper"])
        val lv = x.lv
        ref.update(toDb(max(lv.rmsL, lv.rmsR)), x.dt)
        l = follow(l, ref.norm(toDb(lv.rmsL), 30f), x.dt, 0.25f)
        r = follow(r, ref.norm(toDb(lv.rmsR), 30f), x.dt, 0.25f)
        corr = follow(corr, (if (lv.live) Loudness.correlation else 0f).coerceIn(-1f, 1f), x.dt, 0.25f)
        var top = 28f
        if (txt) {
            val say = Say(x.track)
            drawColumns(this, say.meta(), 40f, look["ink"])
            drawName(this, say.name, 28f, 176f, W - 56, 104f, look["ink"], bold = false)
            drawName(this, say.title, 28f, 222f, W - 56, 24f, look["ink"], bold = false)
            top = 250f
        }
        val ax = 28f
        val aw = W - 56
        val ah = H - top - 28
        save(); beginPath(); rect(ax, top, aw, ah); clip()
        val cy = top + ah / 2
        val base = aw * 0.26f
        val dx = base * (0.2f + ((1 - corr) / 2) * 1.5f)
        mode = overlap(look["paper"])
        fill = look["a"]; beginPath(); circle(W / 2 - dx, cy, base * (0.55f + l * 0.7f)); fill()
        fill = look["b"]; beginPath(); circle(W / 2 + dx, cy, base * (0.55f + r * 0.7f)); fill()
        mode = null
        restore()
        stroke = look["ink"]; lineWidth = 2f; beginPath(); rect(ax, top, aw, ah); stroke()
        font(14f, true)
        text("L", ax + 10, top + ah - 10, look["ink"])
        text("R", ax + aw - 22, top + ah - 10, look["ink"])
        text("${if (corr >= 0) "+" else "-"}${"%.2f".format(kotlin.math.abs(corr))}", W / 2 - 18, top + ah - 10, look["ink"])
        restore()
    }
}

private class Arches : Poster() {
    private val bars = Bars(6)
    override fun draw(x: PosterCtx) = with(x.g) {
        val l = PosterLook(mapOf("a" to "#f4601a"))
        val txt = begin(this, x.w, x.h, l["paper"])
        val e = bars.update(x.bands, x.dt, 40f, 0.05f, 0.4f)
        var top = 30f
        if (txt) {
            val say = Say(x.track)
            drawName(this, say.name, 28f, 82f, W - 56, 56f, l["ink"])
            drawName(this, say.title, 28f, 124f, W - 56, 28f, l["a"], bold = false)
            drawColumns(this, listOf(listOf(join(say.album, say.year))), 154f, l["ink"], 15f, listOf(28f))
            top = 190f
        }
        val aw = (W - 60) / 6
        val base = H - 30
        val tall = base - top
        val gap = aw * 0.03f
        save(); beginPath(); rect(0f, top - 2, W, tall + 4); clip()
        for (i in 0 until 6) {
            val height = tall * (0.22f + 0.78f * e[i])
            val xx = 30 + i * aw + gap / 2
            val width = aw - gap
            val step = width * 0.085f
            listOf(l["ink"], l["a"], l["ink"], l["a"], l["ink"], l["paper"]).forEachIndexed { k, c ->
                val xk = xx + k * step
                val wk = width - 2 * k * step
                val yk = base - height + k * step
                val r = wk / 2
                fill = c
                beginPath(); moveTo(xk, base + 4); lineTo(xk, yk + r); arc(xk + r, yk + r, r, PI_F, 0f); lineTo(xk + wk, base + 4); closePath(); fill()
            }
        }
        restore()
        restore()
    }
}

private class Fan : Poster() {
    private val bars = Bars(12)
    override fun draw(x: PosterCtx) = with(x.g) {
        val l = PosterLook(mapOf("a" to "#12a84a"))
        val txt = begin(this, x.w, x.h, l["paper"])
        val e = bars.update(x.bands, x.dt, 40f, 0.05f, 0.4f)
        val ox = W * 0.86f
        val oy = H * 0.075f
        val from = 185f * PI_F / 180
        val to = 72f * PI_F / 180
        val turn = (from - to) / 12
        val hub = 26f
        for (i in 0 until 12) {
            val a0 = from - i * turn
            val a1 = a0 - turn
            val length = H * (0.3f + 0.6f * e[i])
            fill = if (i % 2 == 0) l["ink"] else l["a"]
            beginPath()
            moveTo(ox + hub * cos(a0), oy + hub * sin(a0)); lineTo(ox + length * cos(a0), oy + length * sin(a0))
            lineTo(ox + length * cos(a1), oy + length * sin(a1)); lineTo(ox + hub * cos(a1), oy + hub * sin(a1))
            closePath(); fill()
        }
        fill = l["paper"]; beginPath(); circle(ox, oy, hub * 0.85f); fill()
        if (txt) {
            val say = Say(x.track)
            drawName(this, say.name, 28f, H - 96, W * 0.7f, 40f, l["ink"])
            drawName(this, say.title, 28f, H - 66, W * 0.7f, 20f, l["a"], bold = false)
            drawColumns(this, listOf(listOf(join(say.album, say.year), join(say.fmt, say.dur)).filter { it.isNotEmpty() }), H - 42, l["ink"], 13f, listOf(28f))
        }
        restore()
    }
}

private class Lenses : Poster() {
    private val bars = Bars(9)
    private var t = 0f
    override fun draw(x: PosterCtx) = with(x.g) {
        val l = PosterLook(mapOf("a" to "#e2412a", "c" to "#e8dfc6"))
        val txt = begin(this, x.w, x.h, l["paper"])
        t += x.dt
        val e = bars.update(x.bands, x.dt, 40f, 0.06f, 0.45f)
        val px = 28f; val py = 28f; val pw = W - 56
        val ph = H - 28 - (if (txt) 76 else 28)
        save(); beginPath(); rect(px, py, pw, ph); clip()
        fill = l["dark"]; fillRect(px, py, pw, ph)
        val radius = pw * 0.78f
        fill = l["a"]; beginPath(); circle(W / 2, py + ph * 0.42f + radius, radius); fill()
        val first = py + ph * 0.6f
        val last = py + ph - 18
        val gap = (last - first) / 9
        for (j in 0 until 9) {
            val y = first + (j + 0.5f) * gap
            stroke = l["c"]; lineWidth = 1.5f; alpha = 0.85f
            beginPath(); moveTo(px, y); lineTo(px + pw, y); stroke()
            alpha = 1f
            val lx = px + pw * (0.5f + 0.36f * sin(t * 0.13f * (1 + j * 0.17f) + j * 1.9f))
            val half = pw * (0.07f + 0.17f * e[j])
            val lift = gap * (0.15f + e[j] * 1.5f)
            for (grow in listOf(0f, 5f)) {
                beginPath()
                for (k in 0..40) {
                    val u = k / 20f - 1
                    val yy = y - (lift + grow) * (1 - u * u).pow(2)
                    if (k == 0) moveTo(lx + u * (half + grow), yy) else lineTo(lx + u * (half + grow), yy)
                }
                for (k in 40 downTo 0) {
                    val u = k / 20f - 1
                    lineTo(lx + u * (half + grow), y + (lift + grow) * (1 - u * u).pow(2))
                }
                closePath()
                if (grow > 0) { lineWidth = 1.2f; stroke() } else { fill = l["c"]; fill() }
            }
        }
        restore()
        if (txt) {
            val say = Say(x.track)
            drawName(this, say.name, px + 16, py + 54, pw * 0.6f, 34f, readableOn(l["dark"], l["c"], l["ink"]))
            drawColumns(this, listOf(listOf(join(say.title, say.album, say.year, say.fmt))), H - 34, l["ink"], 12f, listOf(28f))
        }
        restore()
    }
}

private class Overcome : Poster() {
    private val bars = Bars(31)
    private val ref = Reference()
    private var t = 0f
    private var pl = 0f
    private var pr = 0f
    override fun draw(x: PosterCtx) = with(x.g) {
        val l = PosterLook(mapOf("a" to "#1f63b0", "b" to "#ee2a24"))
        val txt = begin(this, x.w, x.h, l["paper"])
        t += x.dt
        val e = bars.update(x.bands, x.dt, 40f, 0.05f, 0.4f)
        val lv = x.lv
        ref.update(toDb(max(lv.peakL, lv.peakR)), x.dt)
        pl = max(ref.norm(toDb(lv.peakL), 40f), pl - 0.35f * x.dt)
        pr = max(ref.norm(toDb(lv.peakR), 40f), pr - 0.35f * x.dt)
        val x0 = W * 0.1f; val x1 = W * 0.89f; val y0 = H * 0.087f; val y1 = H * 0.915f
        val step = (x1 - x0) / 30
        fill = l["ink"]
        for (i in 0 until 31) {
            val top = y0 + (1 - (0.7f + 0.3f * e[i])) * (y1 - y0)
            fillRect(x0 + i * step - 2, top, 4f, y1 - top)
        }
        val r = W * 0.075f
        listOf(Triple(l["a"], 0.2f + 0.06f * sin(t * 0.2f), pl), Triple(l["b"], 0.78f + 0.06f * sin(t * 0.17f + 2), pr)).forEach { (c, at, level) ->
            val cx = x0 + (x1 - x0) * at
            val cy = y1 - r - 14 - level * (y1 - y0 - 2 * r - 28)
            fill = l["paper"]; beginPath(); circle(cx, cy, r + 9); fill()
            fill = c; beginPath(); circle(cx, cy, r); fill()
        }
        if (txt) {
            val say = Say(x.track)
            val size = fitSize(this, say.name, true, (x1 - x0) * 0.6f, 40f, 16f)
            val width = measure(say.name)
            val y = H * 0.72f
            fill = l["paper"]; beginPath(); roundRect(x0 + 10, y - size - 6, width + 28, size + 24, (size + 24) / 2); fill()
            text(say.name, x0 + 24, y, l["ink"])
            val mark = listOf(say.no, say.dur).filter { it.isNotEmpty() }.joinToString("/")
            if (mark.isNotEmpty()) { font(28f, false); text("/$mark", W - 54, H - 36, l["ink"], Paint.Align.RIGHT) }
        }
        restore()
    }
}

private class Facets : Poster() {
    private val bars = Bars(13)
    private val history = MutableList(16) { FloatArray(13) }
    private var acc = 0f
    override fun draw(x: PosterCtx) = with(x.g) {
        val l = PosterLook(mapOf("a" to "#ffd21a"))
        val ground = Color.of("#f2500f")
        val paperC = Color.of("#dfe0d6")
        val txt = begin(this, x.w, x.h, ground)
        val v = bars.update(x.bands, x.dt, 40f, 0.04f, 0.2f)
        if (x.lv.live) {
            acc += x.dt
            if (acc >= 0.14f) { acc = 0f; val first = history.removeAt(0); history.add(first); v.copyInto(first) }
        }
        val ink = readableOn(ground, l["ink"], Color.of("#1a1a1a"), Color.of("#f6efdc"))
        val a = l["a"]
        val cell = W / 16
        val art = if (txt) 13 * cell else H
        val rows = if (txt) 13 else floor(H / cell).toInt()
        val shift = if (txt) 0f else (H - rows * cell) / 2
        for (c in 0 until 16) for (r in 0 until rows) {
            val value = history[c][min(12, floor(r.toFloat() / rows * 13).toInt())]
            val xx = c * cell
            val yy = shift + (rows - 1 - r) * cell
            if (value > 0.82f) { fill = a; fillRect(xx, yy, cell, cell); continue }
            fill = if (value < 0.4f) paperC else a
            beginPath(); moveTo(xx, yy); lineTo(xx + cell, yy); lineTo(xx, yy + cell); closePath(); fill()
            if (value > 0.6f) { fill = a; beginPath(); moveTo(xx + cell, yy); lineTo(xx + cell, yy + cell); lineTo(xx, yy + cell); closePath(); fill() }
        }
        if (txt) {
            val say = Say(x.track)
            drawName(this, say.name, 28f, art + 84, W - 56, 70f, ink)
            drawName(this, say.title, 28f, art + 118, W - 56, 20f, ink, bold = false)
            drawColumns(this, say.meta(), H - 40, ink, 13f)
        }
        restore()
    }
}

private class Rings : Poster() {
    private val bars = Bars(9)
    override fun draw(x: PosterCtx) = with(x.g) {
        val l = PosterLook(mapOf("a" to "#f08a12", "c" to "#f3e8c0"))
        val txt = begin(this, x.w, x.h, l["paper"])
        val e = bars.update(x.bands, x.dt, 40f, 0.06f, 0.5f)
        val lvl = e.sum() / 9
        val m = 14f
        val pw = W - 2 * m; val ph = H - 2 * m
        save(); beginPath(); rect(m, m, pw, ph); clip()
        fill = l["dark"]; fillRect(m, m, pw, ph)
        val stripe = readableOn(l["dark"], l["c"], l["ink"])
        val cx = W * 0.1f; val cy = H * 0.72f
        val radius = W * 0.46f * (0.96f + 0.08f * lvl)
        fun ring(i: Int, front: Boolean) {
            val rx = radius * 1.3f + i * 27
            save(); translate(cx, cy); rotate(-0.4f)
            stroke = stripe; lineWidth = 2 + e[i] * 11
            beginPath(); ellipse(0f, 0f, rx, rx * 0.3f, if (front) 0f else PI_F, if (front) PI_F else 2 * PI_F); stroke()
            restore()
        }
        for (i in 0 until 9) ring(i, false)
        fill = l["a"]; beginPath(); circle(cx, cy, radius); fill()
        for (i in 0 until 9) ring(i, true)
        restore()
        if (txt) {
            val say = Say(x.track)
            val on = readableOn(l["dark"], l["c"], l["ink"], l["paper"])
            drawName(this, say.name, m + 26, m + 66, pw - 60, 40f, on)
            drawColumns(this, listOf(listOf(join(say.title, say.album, say.year))), m + 90, on, 13f, listOf(m + 26))
        }
        restore()
    }
}

private class Slides : Poster() {
    private val bars = Bars(4)
    override fun draw(x: PosterCtx) = with(x.g) {
        val l = PosterLook(mapOf("a" to "#e8581f", "b" to "#e3262e", "c" to "#f5a800"))
        val c3 = Color.of("#f5a800")
        val txt = begin(this, x.w, x.h, l["paper"])
        val e = bars.update(x.bands, x.dt, 40f, 0.08f, 0.6f)
        val r = W * 0.25f
        val y0 = if (txt) 56f else (H - 4 * r) / 2
        val span = W / 2 - r
        for (k in 0 until 4) {
            val y = y0 + k * r
            val ground = if (k % 2 == 1) l["paper"] else c3
            fill = ground; fillRect(0f, y, W, r)
            val slide = e[k] * span
            val dome = if (k % 2 == 0) r + slide else W - r - slide
            val bowl = if (k % 2 == 0) W - r - slide else r + slide
            save(); beginPath(); rect(0f, y, W, r); clip()
            mode = overlap(ground)
            fill = l["a"]; beginPath(); arc(dome, y + r, r, PI_F, 0f); fill()
            fill = l["b"]; beginPath(); arc(bowl, y, r, 0f, PI_F); fill()
            mode = null
            restore()
        }
        if (txt) {
            val say = Say(x.track)
            val below = y0 + 4 * r
            drawName(this, say.year, W - 28, 36f, 120f, 16f, l["ink"], align = Paint.Align.RIGHT)
            drawName(this, say.name, 28f, below + 64, W - 56, 46f, l["ink"], caps = true, spacing = 3f)
            drawColumns(this, listOf(listOf(join(say.title, say.album))), below + 94, l["ink"], 14f, listOf(28f))
        }
        restore()
    }
}

private class Heads : Poster() {
    private val bars = Bars(16)
    private var hot = 0

    private fun Cv.head(x: Float, y: Float, s: Float) {
        save(); translate(x, y); scale(s)
        beginPath()
        moveTo(10f, 110f); lineTo(12f, 78f)
        bezierTo(-4f, 62f, 0f, 18f, 40f, 6f); bezierTo(72f, -4f, 98f, 26f, 92f, 52f)
        lineTo(100f, 66f); lineTo(92f, 72f); lineTo(95f, 82f); lineTo(86f, 90f); lineTo(88f, 100f); lineTo(66f, 104f); lineTo(66f, 110f)
        closePath(); fill(); restore()
    }

    override fun draw(x: PosterCtx) = with(x.g) {
        val l = PosterLook(mapOf("a" to "#f04a24", "b" to "#1d6f8a"))
        val txt = begin(this, x.w, x.h, l["paper"])
        val e = bars.update(x.bands, x.dt, 40f, 0.08f, 0.5f)
        var loudest = 0
        for (i in 1 until 16) if (e[i] > e[loudest]) loudest = i
        if (e[loudest] > e[hot] + 0.08f) hot = loudest
        var top = 24f
        if (txt) {
            val say = Say(x.track)
            drawName(this, say.name, 28f, 70f, W - 56, 56f, l["ink"])
            drawName(this, say.title, 28f, 112f, W - 56, 30f, l["a"])
            drawColumns(this, listOf(listOf(join(say.album, say.year))), 150f, l["ink"], 16f, listOf(28f))
            top = 200f
        }
        val cw = W / 4
        val ch = (H - top - 16) / 4
        val scale = min(cw * 0.78f / 100, ch * 0.95f / 110)
        for (i in 0 until 16) {
            val grow = scale * (0.88f + 0.12f * min(1f, e[i] * 1.6f))
            val cx = (i % 4) * cw + cw / 2
            val cy = top + (i / 4) * ch + ch / 2
            fill = if (i == hot) l["a"] else l["b"]
            head(cx - 50 * grow, cy - 55 * grow, grow)
        }
        restore()
    }
}

private class Halftone : Poster() {
    private val bars = Bars(13)
    private val ref = Reference()
    private val history = MutableList(16) { FloatArray(13) }
    private var acc = 0f
    private var l = 0f
    private var r = 0f
    override fun draw(x: PosterCtx) = with(x.g) {
        val k = PosterLook(mapOf("a" to "#f2512b", "b" to "#18b84a"))
        val txt = begin(this, x.w, x.h, k["paper"])
        val v = bars.update(x.bands, x.dt, 40f, 0.04f, 0.2f)
        if (x.lv.live) {
            acc += x.dt
            if (acc >= 0.14f) { acc = 0f; val first = history.removeAt(0); history.add(first); v.copyInto(first) }
        }
        ref.update(toDb(max(x.lv.rmsL, x.lv.rmsR)), x.dt)
        l = follow(l, ref.norm(toDb(x.lv.rmsL), 30f), x.dt, 0.25f)
        r = follow(r, ref.norm(toDb(x.lv.rmsR), 30f), x.dt, 0.25f)
        var top = 0f
        if (txt) {
            val say = Say(x.track)
            drawName(this, say.name, 28f, 70f, W - 56, 52f, k["ink"])
            drawName(this, say.title, 28f, 106f, W - 56, 22f, k["a"], bold = false)
            drawColumns(this, listOf(listOf(join(say.album, say.year))), 134f, k["ink"], 14f, listOf(28f))
            top = 156f
        }
        val height = H - top
        val grid = 34
        val cell = W / grid
        val rows = floor(height / cell).toInt()
        val half = grid / 2
        val blockRows = 4
        val dark = { c: Int, rr: Int -> (c / half + rr / blockRows) % 2 == 1 }
        var rr = 0
        while (rr < rows) {
            var c = 0
            while (c < grid) { fill = if (dark(c, rr)) k["ink"] else k["paper"]; fillRect(c * cell, top + rr * cell, half * cell, min(blockRows, rows - rr) * cell); c += half }
            rr += blockRows
        }
        for (onDark in listOf(false, true)) {
            fill = if (onDark) k["paper"] else k["ink"]
            beginPath()
            for (row in 0 until rows) for (c in 0 until grid) {
                if (dark(c, row) != onDark) continue
                val value = history[floor(c.toFloat() / grid * 16).toInt()][floor((rows - 1 - row).toFloat() / rows * 13).toInt()]
                circle(c * cell + cell / 2, top + row * cell + cell / 2, cell * 0.5f * (0.12f + 0.88f * value))
            }
            fill()
        }
        val hl = height * (0.12f + 0.14f * l)
        val hr = height * (0.12f + 0.14f * r)
        fill = k["a"]; fillRect(W * 0.25f, top + height * 0.22f - hl / 2, W * 0.5f, hl)
        fill = k["b"]; fillRect(W * 0.25f, top + height * 0.7f - hr / 2, W * 0.5f, hr)
        restore()
    }
}

private class Dials : Poster(needsBands = false) {
    private var loud = 0f
    private var peak = 0f
    private var corrV = 0f
    private var drV = 0f

    private fun f1(v: Float): String { val t = (kotlin.math.abs(v) * 10).roundToInt(); return "${if (v < 0) "-" else ""}${t / 10}.${t % 10}" }

    override fun draw(x: PosterCtx) = with(x.g) {
        val l = PosterLook(mapOf("a" to "#e8372c"))
        val ground = Color.of("#dfe0d6")
        val txt = begin(this, x.w, x.h, ground)
        val live = x.lv.live
        val s = if (live) Loudness.shortTerm else Double.NEGATIVE_INFINITY
        val m = if (live) Loudness.momentary else Double.NEGATIVE_INFINITY
        val lufs = if (s.isFinite()) s.toFloat() else if (m.isFinite()) m.toFloat() else Float.NEGATIVE_INFINITY
        val peakDb = if (live) toDb(max(Loudness.truePeakL, Loudness.truePeakR)) else -120f
        val corr = if (live) Loudness.correlation.coerceIn(-1f, 1f) else 0f
        val dr = x.track?.truth?.dr
        loud = follow(loud, if (lufs.isFinite()) (lufs - -40f) / 35f else 0f, x.dt, 0.4f)
        peak = max((peakDb + 40) / 40, peak - 0.25f * x.dt)
        corrV = follow(corrV, (corr + 1) / 2, x.dt, 0.4f)
        drV = follow(drV, if (dr != null) (dr - 4) / 16f else 0f, x.dt, 0.6f)
        val ink = Color.of("#050505")
        val a = l["a"]
        val paper = ground
        var top = 0f
        if (txt) {
            val say = Say(x.track)
            drawColumns(this, say.meta(), 40f, ink)
            drawName(this, say.name, 28f, 152f, W - 56, 84f, ink, bold = false)
            drawName(this, say.title, 28f, 196f, W - 56, 24f, ink, bold = false)
            drawColumns(this, listOf(listOf(say.album).filter { it.isNotEmpty() }), 220f, ink, 14f, listOf(28f))
            top = 250f
        }
        val cw = W / 2
        val ch = (H - top) / 2
        val r = min(cw, ch) * 0.43f
        val items = listOf(
            Triple("loudness", loud, if (lufs.isFinite()) "${f1(lufs)} LUFS" else "–"),
            Triple("true peak", peak, if (peakDb > -90f) "${f1(peakDb)} dB" else "–"),
            Triple("correlation", corrV, "${if (corr >= 0) "+" else "-"}${"%.2f".format(kotlin.math.abs(corr))}"),
            Triple("dynamic range", drV, if (dr != null) "DR$dr" else "–"),
        )
        items.forEachIndexed { i, (label, value, shown) ->
            val x0 = (i % 2) * cw
            val y0 = top + (i / 2) * ch
            val dk = ((i % 2) + (i / 2)) % 2 == 0
            val cx = x0 + cw / 2
            val cy = y0 + ch / 2
            fill = if (dk) ink else a; fillRect(x0, y0, cw, ch)
            save(); beginPath(); circle(cx, cy, r); clip()
            fill = if (dk) a else ink; fillRect(cx - r, cy - r, 2 * r, 2 * r)
            translate(cx, cy); rotate(-PI_F / 4)
            fill = paper
            val edge = (1 - 2 * clamp01(value)) * r
            fillRect(-r, edge, 2 * r, r - edge)
            restore()
            font(13f, true)
            text(label, x0 + 14, y0 + 24, paper)
            text(shown, x0 + 14, y0 + ch - 14, paper)
        }
        restore()
    }
}

// ---- Tunnel, Circuit, Terrain ------------------------------------------------------------------------------------

private fun Cv.line(vararg p: Float) {
    beginPath(); moveTo(p[0], p[1])
    var i = 2
    while (i < p.size) { lineTo(p[i], p[i + 1]); i += 2 }
    stroke()
}

private fun Cv.poly(vararg p: Float) {
    beginPath(); moveTo(p[0], p[1])
    var i = 2
    while (i < p.size) { lineTo(p[i], p[i + 1]); i += 2 }
    closePath()
}

private fun mmss(sec: Float): String { val t = max(0f, sec).toInt(); return "${t / 60}:${(t % 60).toString().padStart(2, '0')}" }

/** The largest font from [size] down at which [text] fits [maxW] (a step of one); leaves the font set. */
private fun Cv.fit(text: String, maxW: Float, start: Float, bold: Boolean, minSize: Float = 8f, italic: Boolean = false): Float {
    var size = start
    font(size, bold, italic = italic)
    while (size > minSize && measure(text) > maxW) { size -= 1f; font(size, bold, italic = italic) }
    return size
}

internal fun lerpColor(a: Int, b: Int, t: Float): Int {
    val x = Color.rgb(a); val y = Color.rgb(b)
    return (0xFF shl 24) or ((x[0] + ((y[0] - x[0]) * t).roundToInt()) shl 16) or ((x[1] + ((y[1] - x[1]) * t).roundToInt()) shl 8) or (x[2] + ((y[2] - x[2]) * t).roundToInt())
}

private class Tunnel : Poster() {
    private val rings = Bars(7)
    private val small = Bars(22)
    private var spin = 0f

    private fun Cv.outline(text: String, x: Float, y: Float, start: Float, maxW: Float, color: Int) {
        if (text.isEmpty()) return
        var size = start
        font(size, true)
        while (size > 9 && measure(text) > maxW) { size -= 1f; font(size, true) }
        lineWidth = max(1.4f, size / 13)
        strokeText(text, x, y, color)
    }

    private fun Cv.plain(text: String, x: Float, y: Float, start: Float, maxW: Float, color: Int) {
        if (text.isEmpty()) return
        var size = start
        font(size, true)
        while (size > 8 && measure(text) > maxW) { size -= 1f; font(size, true) }
        text(text, x, y, color)
    }

    override fun draw(x: PosterCtx) = with(x.g) {
        val l = PosterLook(mapOf("paper" to "#0f0d0d", "a" to "#ff4a35"))
        val txt = begin(this, x.w, x.h, l["paper"])
        val e = rings.update(x.bands, x.dt, 40f, 0.08f, 0.5f)
        val s = small.update(x.bands, x.dt, 40f, 0.08f, 0.5f)
        val lvl = e.sum() / 7
        spin += x.dt * (0.25f + 2.2f * lvl)
        val a = l["a"]
        stroke = a; fill = a; round = true

        val top = 236f; val maxD = 566f; val first = 0.2f
        val weight = FloatArray(7) { j -> (0.55f + 1.1f * min(1f, e[j] * 1.5f)) * (1 + j * 0.28f) }
        val total = weight.sum()
        var acc = 0f
        for (j in 0 until 7) {
            acc += weight[j]
            val d = maxD * (first + (1 - first) * (acc / total))
            lineWidth = 2 + 2.4f * min(1f, e[j] * 1.4f)
            beginPath(); circle(W / 2, top + d / 2, d / 2); stroke()
        }
        val m = 10f
        lineWidth = 2f
        listOf(
            floatArrayOf(m, m, 288f, 78f), floatArrayOf(298f, m, 146f, 78f), floatArrayOf(454f, m, 136f, 146f), floatArrayOf(m, 98f, 434f, 34f), floatArrayOf(m, 140f, 434f, 26f),
            floatArrayOf(298f, 140f, 146f, 26f), floatArrayOf(454f, 166f, 136f, 70f), floatArrayOf(454f, 244f, 136f, 30f), floatArrayOf(508f, 756f, 82f, 34f),
        ).forEach { strokeRect(it[0], it[1], it[2], it[3]) }

        val mid = 83f
        for (i in 0 until 22) {
            val bh = 6 + s[i] * 120 * (0.35f + 0.65f * (i / 22f))
            val xx = 466 + (i / 21f) * 112
            line(xx, mid - bh / 2, xx, mid + bh / 2)
        }
        val gx = 490f; val gy = 201f; val gr = 24f
        lineWidth = 1.6f
        beginPath(); circle(gx, gy, gr); stroke()
        for (i in 0 until 4) { beginPath(); ellipse(gx, gy, max(0.5f, kotlin.math.abs(cos(spin + i * PI_F / 4)) * gr), gr, 0f, 7f); stroke() }
        for (f in listOf(-0.5f, 0f, 0.5f)) {
            val half = kotlin.math.sqrt(1 - f * f) * gr
            line(gx - half, gy + f * gr, gx + half, gy + f * gr)
        }

        if (txt) {
            val say = Say(x.track)
            val parts = say.fmt.split(" ")
            val codec = parts.firstOrNull() ?: ""
            val rest = parts.drop(1).joinToString(" ").trimStart('·', ' ')
            val words = say.name.uppercase().split(" ")
            if (words.size > 1) {
                outline(words[0], 20f, 44f, 30f, 266f, a)
                outline(words.drop(1).joinToString(" "), 20f, 78f, 30f, 266f, a)
            } else outline(words[0], 20f, 60f, 40f, 266f, a)
            outline(codec, 308f, 44f, 28f, 126f, a)
            outline(rest, 308f, 76f, 28f, 126f, a)
            outline((say.title.ifEmpty { say.album }).uppercase(), 20f, 123f, 24f, 414f, a)
            plain(if (say.title.isNotEmpty()) say.album.uppercase() else "", 20f, 158f, 15f, 270f, a)
            plain(say.dr.ifEmpty { say.year }, 308f, 159f, 16f, 126f, a)
            val length = x.track?.duration?.toFloat() ?: 0f
            if (length > 0) plain("${mmss(x.progress * length)} / ${mmss(length)}", 464f, 264f, 15f, 118f, a)
            plain(say.year, 520f, 779f, 16f, 60f, a)
        }
        round = false
        restore()
    }
}

private class Circuit : Poster() {
    private val bars = Bars(7)
    private val ref = Reference()
    private val pk = FloatArray(7)
    private val slow = FloatArray(7)
    private val on = FloatArray(7)
    private val count = FloatArray(4)
    private var step = 0
    private var strobe = 0f
    private var cool = 0f
    private var l = 0f
    private var r = 0f
    private var key = ""
    private var wrapSize = 22f
    private var wrapLines: List<String> = emptyList()

    private val strapTop = 394f
    private val strapBot = 640f
    private val ampX = 312f
    private val gateX = 430f
    private val gateW = 24f
    private val gateH = 20f
    private val domeY = 670f
    private val domeR = 13f
    private val wideX = 536f
    private val wideW = 52f
    private val wideH = 64f
    private val wideY = floatArrayOf(462f, 582f)
    private fun strapX(i: Int) = 46f + i * 38
    private fun rowY(r: Int) = 412f + r * 30
    private fun busX(k: Int) = 350f + k * 14
    private fun countX(k: Int) = 366f + k * 34
    private fun railY(j: Int) = 694f + j * 12

    private fun Cv.dot(x: Float, y: Float) { beginPath(); circle(x, y, 2.4f); fill() }

    /** Fills the current path as far as it is lit, then outlines it. */
    private fun Cv.lit(amount: Float) {
        if (amount > 0.01f) { alpha = clamp01(amount); fill(); alpha = 1f }
        stroke()
    }

    private fun Cv.gatePath(x: Float, y: Float, w: Float, h: Float) {
        beginPath(); moveTo(x, y - h / 2); lineTo(x + w - h / 2, y - h / 2); arc(x + w - h / 2, y, h / 2, -PI_F / 2, PI_F / 2); lineTo(x, y + h / 2); closePath()
    }

    private fun Cv.dome(cx: Float, amount: Float, paper: Int, ink: Int, a: Int) {
        beginPath(); arc(cx, domeY, domeR, PI_F, 0f); closePath(); lit(amount)
        for (j in 0 until 3) {
            val xx = cx - 7 + j * 7
            beginPath(); circle(xx, domeY + 3, 2.6f); fill = paper; fill(); fill = a; stroke()
            line(xx, domeY + 5.6f, xx, railY(j))
            dot(xx, railY(j))
        }
    }

    private fun Cv.wrapTitle(text: String, maxW: Float) {
        val words = text.split(Regex("\\s+"))
        var size = 62f
        while (size >= 22f) {
            font(size, false)
            val lines = mutableListOf<String>()
            var cur = ""
            for (w in words) {
                val next = if (cur.isNotEmpty()) "$cur $w" else w
                if (cur.isEmpty() || measure(next) <= maxW) cur = next else { lines.add(cur); cur = w }
            }
            lines.add(cur)
            if (lines.size <= (if (size > 44) 2 else 3) && lines.all { measure(it) <= maxW }) { wrapSize = size; wrapLines = lines; return }
            size -= 2f
        }
        wrapSize = 22f; wrapLines = listOf(text)
    }

    private fun Cv.boxed(text: String, cx: Float, y: Float, maxW: Float, color: Int) {
        var size = 13f
        font(size, true)
        while (size > 8 && measure(text) > maxW) { size -= 1f; font(size, true) }
        text(text, cx, y, color, Paint.Align.CENTER)
    }

    override fun draw(x: PosterCtx) = with(x.g) {
        val look = PosterLook(mapOf("paper" to "#14161b", "ink" to "#ece4cf", "a" to "#e23a2c"))
        val txt = begin(this, x.w, x.h, look["paper"])
        val dt = x.dt
        val v = bars.update(x.bands, dt, 40f, 0.06f, 0.4f)
        for (i in 0 until 7) {
            pk[i] = max(v[i], pk[i] - dt * 0.22f)
            slow[i] = follow(slow[i], bars.raw[i], dt, 1.2f)
            on[i] = max(clamp01((bars.raw[i] - slow[i] - 0.05f) * 6), on[i] * exp(-dt / 0.14f))
        }
        cool -= dt
        if (on[0] > 0.6f && cool <= 0) { step = (step + 1) % 4; strobe = 1f; cool = 0.28f }
        strobe *= exp(-dt / 0.2f)
        for (j in 0 until 4) count[j] = if (j == step) max(count[j], strobe) else count[j] * exp(-dt / 0.25f)
        val ld = toDb(x.lv.rmsL); val rd = toDb(x.lv.rmsR)
        ref.update(max(ld, rd), dt)
        l = follow(l, ref.norm(ld, 30f), dt, if (ld > l) 0.06f else 0.4f)
        r = follow(r, ref.norm(rd, 30f), dt, if (rd > r) 0.06f else 0.4f)

        val cell = { i: Int, row: Int -> clamp01(v[i] * 8 - (7 - row)) }
        val a = look["a"]; val ink = look["ink"]; val paper = look["paper"]
        stroke = a; fill = a
        val lw = max(1.5f, 0.6f / viewScale)
        lineWidth = lw; round = true

        val label = "40 Hz – 16 kHz"
        font(11f, false, italic = true)
        val gap = if (txt) min(120f, measure(label) / 2 + 8) else 0f
        line(22f, 372f, 22f, 366f, 156 - gap, 366f)
        line(156 + gap, 366f, 290f, 366f, 290f, 372f)
        for (row in 0 until 8) line(0f, rowY(row), ampX, rowY(row))

        for (i in 0 until 7) {
            val sx = strapX(i)
            for (row in 0 until 8) {
                val c = cell(i, row)
                if (c > 0.01f) { alpha = c; fillRect(sx - 4, rowY(row) - 14, 8f, 28f); alpha = 1f }
            }
            strokeRect(sx - 4, strapTop, 8f, strapBot - strapTop)
            fill = ink; fillRect(sx - 4, strapBot - 6 - pk[i] * (strapBot - strapTop - 8), 8f, 4f); fill = a
            line(sx, strapTop, sx, 382f, sx - 14, 382f, sx - 14, 387f)
            line(sx - 20, 387f, sx - 8, 387f)
            line(sx - 18, 390f, sx - 10, 390f)
            line(sx - 16, 393f, sx - 12, 393f)
            line(sx, strapBot, sx, domeY - domeR)
            dome(sx, on[i], paper, ink, a)
        }

        for (row in 0 until 8) {
            var any = 0f; var two = 0f
            for (i in 0 until 7) {
                val c = cell(i, row)
                if (c > any) { two = any; any = c } else if (c > two) two = c
            }
            val y = rowY(row); val gy = y + 5
            beginPath(); moveTo(ampX, y - 10); lineTo(ampX + 20, y); lineTo(ampX, y + 10); closePath(); lit(any)
            line(ampX + 20, y, gateX, y)
            val bx = busX(row % 4)
            line(bx, gy + 5, gateX, gy + 5)
            dot(bx, gy + 5)
            gatePath(gateX, gy, gateW, gateH); lit(two)
            val j = row % 4
            val iy = wideY[if (row < 4) 0 else 1] - 24 + j * 16
            val vx = 478f + j * 10
            line(gateX + gateW, gy, vx, gy, vx, iy, wideX, iy)
        }

        for (k in 0 until 4) {
            val jog = 655f - k * 5
            line(busX(k), rowY(k) + 10, busX(k), jog, countX(k), jog, countX(k), domeY - domeR)
            dome(countX(k), count[k], paper, ink, a)
        }

        listOf(l, r).forEachIndexed { n, level ->
            val y = wideY[n]
            save(); gatePath(wideX, y, wideW, wideH); clip()
            fillRect(wideX, y - wideH / 2, wideW * clamp01(level), wideH)
            restore()
            gatePath(wideX, y, wideW, wideH); stroke()
            line(wideX + wideW, y, W, y)
        }

        line(0f, railY(0), 500f, railY(0))
        lineWidth = lw + 2.5f * strobe
        line(0f, railY(1), if (txt) 530f else W, railY(1))
        lineWidth = lw
        line(0f, railY(2), 500f, railY(2))

        val say = Say(x.track)
        val length = x.track?.duration?.toFloat() ?: 0f
        val parts = say.fmt.split(" ")
        val codec = parts.firstOrNull() ?: ""
        val rest = parts.drop(1).joinToString(" ").trimStart('·', ' ')
        val cells = if (txt) listOf(if (length > 0) mmss(x.progress * length) else "", say.dur, codec, rest, say.dr).filter { it.isNotEmpty() } else listOf("", "", "", "", "")
        val bw = 58f; val stp = bw + 8; val x0 = 140f
        if (cells.isNotEmpty()) {
            dash = floatArrayOf(5f, 4f)
            line(x0 - 12, railY(2), x0 - 12, 738f, x0 + (cells.size - 1) * stp + bw / 2, 738f)
            cells.indices.forEach { n -> line(x0 + n * stp + bw / 2, 738f, x0 + n * stp + bw / 2, 752f) }
            dash = null
            dot(x0 - 12, railY(2))
            cells.forEachIndexed { n, t ->
                strokeRect(x0 + n * stp, 752f, bw, 30f)
                if (t.isNotEmpty()) boxed(t, x0 + n * stp + bw / 2, 772f, bw - 8, a)
            }
        }

        if (txt) {
            font(11f, false, italic = true)
            text(label, 156f, 370f, a, Paint.Align.CENTER)
            text("Strobe", 536f, railY(1) + 4, a)
            text("L", wideX + 2, wideY[0] - wideH / 2 - 6, a)
            text("R", wideX + 2, wideY[1] - wideH / 2 - 6, a)
            val right = 562f; val maxW = 524f
            font(13f, false, spacing = 1f)
            text(join(say.album, say.year).replace(" · ", "  ·  ").uppercase(), right, 62f, a, Paint.Align.RIGHT)
            val title = (say.title.ifEmpty { say.name }).lowercase()
            if (key != title) { wrapTitle(title, maxW); key = title }
            font(wrapSize, false)
            wrapLines.forEachIndexed { n, ln -> text(ln, right, 76 + wrapSize * 0.86f + n * wrapSize * 0.98f, ink, Paint.Align.RIGHT) }
            if (say.title.isNotEmpty()) {
                val artist = say.name.lowercase()
                var size = 46f
                font(size, false)
                while (size > 18 && measure(artist) > maxW) { size -= 1f; font(size, false) }
                text(artist, right, 318f, ink, Paint.Align.RIGHT)
            }
        }
        round = false
        restore()
    }
}

private class Terrain : Poster(needsGonio = true) {
    private val cols = 8
    private val rowsN = 6
    private val bars = Bars(8)
    private val strip = Bars(31)
    private val ref = Reference()
    private val city = Array(6) { FloatArray(8) }
    private var level = 0f; private var l = 0f; private var r = 0f; private var corr = 0f; private var clip = 0f
    private var m = 0f; private var s = 0f; private var i = 0f; private var tp = 0f
    private val ox = 172f; private val oy = 322f; private val tx = 20f; private val ty = 10f; private val tall = 150f
    private fun ax(c: Float, r: Float) = ox + (c - r) * tx
    private fun ay(c: Float, r: Float, h: Float = 0f) = oy + (c + r) * ty - h
    private val ranges = listOf("LOW", "LOW MID", "HIGH MID", "HIGH")

    private fun num(v: Float, digits: Int = 1): String {
        if (!v.isFinite() || v <= -90f) return "–"
        val k = 10f.pow(digits)
        val t = (kotlin.math.abs(v) * k).roundToInt()
        val body = if (digits == 0) "$t" else "${t / k.toInt()}.${(t % k.toInt()).toString().padStart(digits, '0')}"
        return if (v < 0 && t != 0) "-$body" else body
    }

    private fun Cv.label(t: String, x: Float, y: Float, size: Float, color: Int, align: Paint.Align = Paint.Align.LEFT, bold: Boolean = false) {
        font(size, bold); text(t, x, y, color, align)
    }

    override fun draw(x: PosterCtx) = with(x.g) {
        val look = PosterLook(mapOf("paper" to "#0a0605", "a" to "#f2381f", "b" to "#ffb526"))
        val paper = look["paper"]; val a = look["a"]
        val b2 = if (!isDark(paper)) readableOn(paper, look["b"], Palette.Text.toArgb()) else look["b"]
        val txt = begin(this, x.w, x.h, paper)
        val dt = x.dt
        val b = bars.update(x.bands, dt, 40f, 0.08f, 0.5f)
        strip.update(x.bands, dt, 40f, 0.08f, 0.5f)
        level = b.sum() / cols
        for (rr in 0 until rowsN) for (c in 0 until cols) city[rr][c] = follow(city[rr][c], b[c], dt, 1.2f * 1.9f.pow(rowsN - 1 - rr))
        val lv = x.lv
        val ld = toDb(lv.rmsL); val rd = toDb(lv.rmsR)
        ref.update(max(ld, rd), dt)
        l = follow(l, ref.norm(ld, 30f), dt, 0.16f)
        r = follow(r, ref.norm(rd, 30f), dt, 0.16f)
        clip = if (toDb(max(lv.peakL, lv.peakR)) >= -0.5f) 1.5f else max(0f, clip - dt * 2.5f)
        val live = lv.live
        val cr = if (live) Loudness.correlation.coerceIn(-1f, 1f) else 0f
        corr = follow(corr, cr, dt, 0.4f)
        val lm = if (live) Loudness.momentary.toFloat() else Float.NEGATIVE_INFINITY
        val ls = if (live) Loudness.shortTerm.toFloat() else Float.NEGATIVE_INFINITY
        val li = if (live) Loudness.integrated.toFloat() else Float.NEGATIVE_INFINITY
        val loud = { v: Float -> if (v.isFinite()) clamp01((v + 36) / 30) else 0f }
        val tpDb = if (live) toDb(max(Loudness.truePeakL, Loudness.truePeakR)) else -120f
        m = follow(m, loud(lm), dt, 0.3f); s = follow(s, loud(ls), dt, 0.3f); i = follow(i, loud(li), dt, 0.3f)
        tp = max(clamp01((tpDb + 24) / 24), tp - dt * 0.25f)

        val shades = IntArray(9) { lerpColor(a, b2, it / 8f) }
        val shade = { v: Float -> shades[(clamp01(v) * 8).roundToInt()] }
        val say = Say(x.track)
        val length = x.track?.duration?.toFloat() ?: 0f
        val progress = x.progress
        lineWidth = max(1.4f, 0.6f / viewScale); round = true
        stroke = a; fill = a

        strokeRect(22f, 22f, 150f, 24f); strokeRect(172f, 22f, 180f, 24f)
        if (txt) {
            label("NOW PLAYING:", 32f, 38f, 10f, a)
            fit(say.name.uppercase(), 164f, 10f, false); text(say.name.uppercase(), 180f, 38f, a)
        }

        strokeRect(22f, 52f, 330f, 58f)
        for (k in 0 until 31) {
            val bh = 3 + strip.v[k] * 48
            stroke = shade(strip.v[k] * 1.3f - 0.3f)
            strokeRect(28 + k * 10.4f, 106 - bh, 6.4f, bh)
        }
        stroke = a

        line(22f, 134f, 22f, 140f, 352f, 140f, 352f, 134f)
        for (k in 1 until 30) line(22f + k * 11, 140f, 22f + k * 11, if (k % 5 != 0) 137f else 134f)
        if (txt && length > 0) for (k in 0..6) label(mmss(k / 6f * length), 22f + k * 55, 128f, 8f, a, if (k == 0) Paint.Align.LEFT else if (k == 6) Paint.Align.RIGHT else Paint.Align.CENTER)
        val px = 22 + clamp01(progress) * 330
        stroke = b2; poly(px, 146f, px - 7, 154f, px + 7, 154f); stroke(); stroke = a

        listOf(366f to 22f, 397f to 22f, 366f to 53f, 397f to 53f).forEach { (qx, qy) -> strokeRect(qx, qy, 25f, 25f) }
        listOf(l, r).forEachIndexed { k, v -> fill = shade(v * 1.4f - 0.4f); fillRect(374f + k * 31, 43 - v * 17, 9f, v * 17) }
        fill = a
        beginPath(); circle(378.5f, 65.5f, 6f); stroke()
        if (clip > 0.02f) { alpha = min(1f, clip); fill = b2; fill(); fill = a; alpha = 1f }
        val apart = 1 + 2 * (1 - corr)
        for (dx in listOf(-apart, apart)) { beginPath(); circle(409.5f + dx, 65.5f, 5f); stroke() }

        strokeRect(432f, 22f, 146f, 24f); strokeRect(432f, 46f, 146f, 78f)
        if (txt) label("STEREO FIELD", 505f, 38f, 10f, a, Paint.Align.CENTER)
        save(); beginPath(); rect(433f, 47f, 144f, 76f); clip()
        alpha = 0.5f; line(471f, 51f, 539f, 119f); line(539f, 51f, 471f, 119f); alpha = 1f
        if (live) {
            stroke = b2
            beginPath()
            var k = 0
            while (k < 1024) {
                val lx = x.gonioL[k]; val rx = x.gonioR[k]
                val px2 = 505 + ((rx - lx) / 1.4142135f) * 46
                val py2 = 85 - ((lx + rx) / 1.4142135f) * 46
                if (k == 0) moveTo(px2, py2) else lineTo(px2, py2)
                k += 4
            }
            stroke()
        }
        restore()
        stroke = a

        if (txt) {
            listOf("FORMAT:" to say.fmt, "DYNAMIC RANGE:" to say.dr, "TRACK:" to (if (say.no.isNotEmpty()) "#${say.no}" else "")).forEachIndexed { k, (key, value) ->
                label(key, 432f, 142f + k * 14, 9f, a)
                val shown = value.ifEmpty { "–" }
                fit(shown, if (k == 0) 96f else 60f, 9f, false)
                text(shown, 578f, 142f + k * 14, a, Paint.Align.RIGHT)
            }
        }

        // the city
        for (c in 0..cols) line(ax(c.toFloat(), 0f), ay(c.toFloat(), 0f), ax(c.toFloat(), rowsN.toFloat()), ay(c.toFloat(), rowsN.toFloat()))
        for (rr in 0..rowsN) line(ax(0f, rr.toFloat()), ay(0f, rr.toFloat()), ax(cols.toFloat(), rr.toFloat()), ay(cols.toFloat(), rr.toFloat()))
        fill = paper
        for (depth in 0..(cols + rowsN - 2)) for (rr in 0 until rowsN) {
            val c = depth - rr
            if (c < 0 || c >= cols) continue
            val v = city[rr][c]
            val h = v * tall
            val seed = (abs(sin(c * 12.9898f + rr * 78.233f) * 43758.5453f) % 1f)
            val mm = 0.12f + 0.14f * seed
            val cf = c.toFloat(); val rf = rr.toFloat()
            stroke = shade(v * 1.5f - 0.25f)
            if (h < 2) {
                poly(ax(cf + mm, rf + mm), ay(cf + mm, rf + mm), ax(cf + 1 - mm, rf + mm), ay(cf + 1 - mm, rf + mm), ax(cf + 1 - mm, rf + 1 - mm), ay(cf + 1 - mm, rf + 1 - mm), ax(cf + mm, rf + 1 - mm), ay(cf + mm, rf + 1 - mm)); stroke()
                continue
            }
            val f0x = ax(cf + mm, rf + 1 - mm); val f0y = ay(cf + mm, rf + 1 - mm)
            val e0x = ax(cf + 1 - mm, rf + 1 - mm); val e0y = ay(cf + 1 - mm, rf + 1 - mm)
            val b0x = ax(cf + 1 - mm, rf + mm); val b0y = ay(cf + 1 - mm, rf + mm)
            val f1y = ay(cf + mm, rf + 1 - mm, h); val e1y = ay(cf + 1 - mm, rf + 1 - mm, h); val b1y = ay(cf + 1 - mm, rf + mm, h)
            val t0x = ax(cf + mm, rf + mm); val t0y = ay(cf + mm, rf + mm, h)
            poly(f0x, f0y, e0x, e0y, e0x, e1y, f0x, f1y); fill(); stroke()
            poly(e0x, e0y, b0x, b0y, b0x, b1y, e0x, e1y); fill(); stroke()
            poly(t0x, t0y, b0x, b1y, e0x, e1y, f0x, f1y); fill(); stroke()
            if (seed > 0.6f && h > 30) for (k in 1 until 4) {
                val px3 = f0x + (e0x - f0x) * k / 4; val py3 = f0y + (e0y - f0y) * k / 4
                line(px3, py3, px3, py3 - h)
            }
        }
        stroke = a; fill = a
        line(30f, 196f, 62f, 196f); line(46f, 180f, 46f, 212f); line(36f, 206f, 56f, 186f)
        if (txt) {
            val album = say.album.uppercase().replace(Regex("[^A-Z0-9]+"), "_").take(20)
            label(listOf(say.no, album).filter { it.isNotEmpty() }.joinToString("_"), 372f, 178f, 8f, a, Paint.Align.RIGHT)
            label("READING_SPECTRUM", 372f, 190f, 8f, a, Paint.Align.RIGHT)
            label("ROWS/NOW_TO_SLOW", 22f, 506f, 8f, a)
            label("COLS/40HZ_16KHZ", 40f, 518f, 8f, a)
            label("LEVEL/${num(level, 2)}", 58f, 530f, 8f, a)
        }

        line(472f, 196f, 482f, 182f, 578f, 182f, 578f, 196f)
        for (k in 0 until 3) for (j in 0 until 3) { val wx = 500f + k * 26 + j * 5; line(wx + 4, 185f, wx, 189f, wx + 4, 193f) }
        strokeRect(392f, 196f, 186f, 208f)
        ranges.forEachIndexed { k, name ->
            val v = (b[k * 2] + b[k * 2 + 1]) / 2
            val y = 204f + (ranges.size - 1 - k) * 49 // high at the top
            strokeRect(400f, y, 36f, 42f); strokeRect(442f, y, 128f, 42f)
            beginPath()
            for (n in 0..28) {
                val yy = y + 21 - sin(n / 28f * 2 * PI_F * (1 + k * 1.5f)) * (3 + 11 * v)
                if (n == 0) moveTo(404f, yy) else lineTo(404f + n, yy)
            }
            stroke()
            line(442f, y + 21, 450 + v * 50, y + 21)
            if (txt) label(name, 562f, y + 25, 10f, a, Paint.Align.RIGHT)
        }

        if (txt) {
            val title = (say.title.ifEmpty { say.name }).uppercase()
            val year = say.year.takeLast(2)
            font(40f, true, italic = true)
            val yw = if (year.isNotEmpty()) measure(year) + 8 else 0f
            val size = fit(title, 330 - yw, 34f, true, italic = true)
            text(title, 578 - yw, 556f, b2, Paint.Align.RIGHT)
            val tw = measure(title)
            if (year.isNotEmpty()) { font(40f, true, italic = true); text(year, 578f, 556f, b2, Paint.Align.RIGHT) }
            if (say.title.isNotEmpty()) {
                fit(say.name.uppercase(), 330f, 12f, true)
                text(say.name.uppercase(), max(248f, 578 - yw - tw), 552 - size, b2)
            }
        }

        strokeRect(22f, 574f, 556f, 6f)

        listOf(Triple("M", this@Terrain.m, lm), Triple("S", this@Terrain.s, ls), Triple("I", this@Terrain.i, li), Triple("P", tp, tpDb)).forEachIndexed { k, (name, v, db) ->
            val fx = 22f + k * 82
            val top = 658 - v * 44
            alpha = 0.3f; fill = a; fillRect(fx, top, 64f, v * 44); alpha = 1f
            stroke = b2; line(fx, top, fx + 64, top); stroke = a
            poly(fx, 658f, fx, 614f, fx + 6, 606f, fx + 26, 606f, fx + 32, 614f, fx + 64, 614f, fx + 64, 658f); stroke()
            for (q in 0..(v * 3).roundToInt()) { beginPath(); circle(fx + 62 - q * 6, 690f, 1.6f); fill() }
            line(fx + 8, 702f, fx + 8, 712f, fx + 30, 712f)
            strokeRect(fx + 30, 702f, 34f, 20f)
            if (txt) {
                label(name, fx, 696f, 24f, a)
                label(num(db, if (db <= -10) 0 else 1), fx + 47, 716f, 9f, a, Paint.Align.CENTER, true)
            }
        }

        strokeRect(352f, 606f, 162f, 116f)
        line(352f, 622f, 514f, 622f)
        for (k in 0 until 3) { beginPath(); circle(504f - k * 11, 614f, 3f); stroke() }
        if (txt) {
            val code = listOf(
                "var t = play(\"${(say.title.ifEmpty { say.name }).lowercase().take(14)}\");", "",
                "lufs.m = ${num(lm)};", "lufs.s = ${num(ls)};", "peak.tp = ${num(tpDb)};", "corr = ${if (cr >= 0) "+" else ""}${num(cr, 2)};",
            )
            code.forEachIndexed { k, ln -> fit(ln, 148f, 8.5f, false); text(ln, 360f, 638f + k * 13, a) }
        }

        strokeRect(524f, 606f, 54f, 44f); strokeRect(524f, 678f, 54f, 44f)
        if (txt) {
            if (length > 0) {
                fit(mmss(progress * length), 46f, 14f, true); text(mmss(progress * length), 551f, 633f, b2, Paint.Align.CENTER)
                val left = "-${mmss((1 - progress) * length)}"
                fit(left, 46f, 14f, true); text(left, 551f, 705f, b2, Paint.Align.CENTER)
            }
            label("PLAYED", 551f, 664f, 9f, a, Paint.Align.CENTER)
            label("LEFT", 551f, 736f, 9f, a, Paint.Align.CENTER)
            label("MAGNETITE", 352f, 742f, 8f, a)
            line(408f, 739f, 440f, 739f)
            label("POSTER TERRAIN", 448f, 742f, 8f, a)
        }
        round = false
        restore()
    }
}

/** The posters the Android app draws, by their web ids. */
object Posters {
    val catalog: List<Pair<MeterInfo, () -> Poster>> = listOf(
        MeterInfo("poster-lens", "Poster: Lens", 260, 110) to ::Lens,
        MeterInfo("poster-arches", "Poster: Arches", 260, 110) to ::Arches,
        MeterInfo("poster-fan", "Poster: Fan", 260, 110) to ::Fan,
        MeterInfo("poster-lenses", "Poster: Lenses", 260, 110) to ::Lenses,
        MeterInfo("poster-overcome", "Poster: Overcome", 260, 110) to ::Overcome,
        MeterInfo("poster-pillars", "Poster: Pillars", 260, 110) to ::Pillars,
        MeterInfo("poster-facets", "Poster: Facets", 260, 110) to ::Facets,
        MeterInfo("poster-dials", "Poster: Dials", 260, 110) to ::Dials,
        MeterInfo("poster-rings", "Poster: Rings", 260, 110) to ::Rings,
        MeterInfo("poster-halftone", "Poster: Halftone", 260, 110) to ::Halftone,
        MeterInfo("poster-heads", "Poster: Heads", 260, 110) to ::Heads,
        MeterInfo("poster-slides", "Poster: Slides", 260, 110) to ::Slides,
        MeterInfo("poster-tunnel", "Poster: Tunnel", 260, 110) to ::Tunnel,
        MeterInfo("poster-circuit", "Poster: Circuit", 260, 110) to ::Circuit,
        MeterInfo("poster-terrain", "Poster: Terrain", 260, 110) to ::Terrain,
        MeterInfo("poster-airport", "Poster: Airport", 260, 110) to ::Airport,
        MeterInfo("poster-pylon", "Poster: Pylon", 260, 110) to ::Pylon,
        MeterInfo("poster-camera", "Poster: Camera", 260, 110) to ::Camera,
        MeterInfo("poster-stacks", "Poster: Stacks", 260, 110) to ::Stacks,
        MeterInfo("poster-swatches", "Poster: Swatches", 260, 110) to ::Swatches,
        MeterInfo("poster-viewdata", "Poster: Viewdata", 260, 110) to ::Viewdata,
        MeterInfo("poster-prompt", "Poster: Prompt", 260, 110) to ::Prompt,
        MeterInfo("poster-network", "Poster: Network", 260, 110) to ::Network,
        MeterInfo("poster-junction", "Poster: Junction", 260, 110) to ::Junction,
    )

    fun make(id: String): Poster? = catalog.firstOrNull { it.first.id == id }?.second?.invoke()
}

/** One poster, drawn on the player's frame clock like the other meters; the bands are read off the UI thread only for posters that use them. */
@Composable
internal fun PosterMeter(id: String, playing: Boolean, modifier: Modifier, track: Track?, position: () -> Long) {
    val poster = remember(id) { Posters.make(id) } ?: return
    val spectrum = remember { Spectrum(BANDS, 8192, 25.0, 20000.0) }
    val raw = remember { FloatArray(BANDS) { -120f } }
    val g = remember { Cv() }
    val ctx = remember { PosterCtx() }
    MeterCanvas(playing, modifier, work = if (poster.needsBands) { _, lv -> if (lv.live) spectrum.read(raw) else raw.fill(-120f) } else null) { dt, lv ->
        drawIntoCanvas { canvas ->
            g.c = canvas.nativeCanvas
            ctx.g = g; ctx.w = size.width; ctx.h = size.height; ctx.dt = dt; ctx.bands = raw; ctx.lv = lv; ctx.track = track
            ctx.progress = track?.duration?.takeIf { it > 0 }?.let { (position() / 1000f / it.toFloat()).coerceIn(0f, 1f) } ?: 0f
            if (poster.needsGonio && lv.live) app.magnetite.player.MeterTap.recentStereo(ctx.gonioL, ctx.gonioR)
            poster.draw(ctx)
        }
    }
}
