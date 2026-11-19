package app.magnetite.ui

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Paint
import android.graphics.Path
import android.graphics.PathMeasure
import android.graphics.Typeface
import androidx.core.graphics.PathParser
import app.magnetite.R
import app.magnetite.data.Track
import java.text.Normalizer
import java.util.Locale
import java.util.concurrent.ConcurrentHashMap
import kotlin.math.abs
import kotlin.math.atan2
import kotlin.math.cos
import kotlin.math.exp
import kotlin.math.floor
import kotlin.math.hypot
import kotlin.math.max
import kotlin.math.min
import kotlin.math.pow
import kotlin.math.roundToInt
import kotlin.math.sin

// The nine posters added to the web on 2026-11-18/19 (Airport, Pylon, Camera, Stacks, Swatches, Viewdata, Prompt, Network, Junction), ported from
// `visualizers/poster-*.js` the same way as the ones in Posters.kt: the web's 600 x 800 numbers on `Cv`, theme level 1, no glow (the web's
// `shadowBlur`). The pictures (Airport's chart, Network's station rings, Junction's field) are in `res/raw` and load in the background;
// Prompt's Press Start 2P is `res/font/press_start_2p.ttf` (the WOFF of the web's fontsource package turned into TTF).

/** The pictures and the font the posters share. Loaded on first use, off the UI thread; until a picture has arrived a poster draws without it. */
object PosterAssets {
    private var app: Context? = null
    private val bitmaps = ConcurrentHashMap<Int, Bitmap>()
    private val pending = ConcurrentHashMap.newKeySet<Int>()

    fun init(context: Context) { app = context.applicationContext }

    /** The picture, or null while it loads (`wait` loads it here and now: the thumbnails do). */
    fun bitmap(id: Int, wait: Boolean = false): Bitmap? {
        bitmaps[id]?.let { return it }
        val context = app ?: return null
        if (wait) return decode(context, id)
        if (pending.add(id)) Thread { decode(context, id) }.start()
        return null
    }

    private fun decode(context: Context, id: Int): Bitmap? = runCatching {
        val o = BitmapFactory.Options().apply { inScaled = false; inPreferredConfig = Bitmap.Config.ARGB_8888 }
        BitmapFactory.decodeResource(context.resources, id, o)?.also { it.setHasMipMap(true); bitmaps[id] = it }
    }.getOrNull()

    val pixelFont: Typeface by lazy { app?.let { runCatching { it.resources.getFont(R.font.press_start_2p) }.getOrNull() } ?: Typeface.MONOSPACE }
}

// ---- helpers -----------------------------------------------------------------------------------------------------

private fun clock(sec: Float): String { val t = max(0f, sec).toInt(); return "${t / 60}:${(t % 60).toString().padStart(2, '0')}" }

private fun dbText(lin: Float) = String.format(Locale.US, "%.1f", toDb(lin))

private fun fade(c: Int, a: Float) = (c and 0x00FFFFFF) or ((a.coerceIn(0f, 1f) * 255).roundToInt() shl 24)

private fun atV(v: FloatArray, x: Float): Float {
    val i = x.toInt().coerceIn(0, v.size - 1)
    val f = (x - i).coerceIn(0f, 1f)
    return v[i] * (1 - f) + v[min(v.size - 1, i + 1)] * f
}

/** Mean level of band groups `from` until `to`, brought up so the top of the scale is reachable. */
private fun groupOf(v: FloatArray, from: Int, to: Int, gain: Float = 1.5f): Float {
    var t = 0f
    for (i in from until to) t += v[i]
    return clamp01(t / (to - from) * gain)
}

private fun Cv.seg(x0: Float, y0: Float, x1: Float, y1: Float) { beginPath(); moveTo(x0, y0); lineTo(x1, y1); stroke() }

private fun Cv.shape(vararg p: Float) {
    beginPath(); moveTo(p[0], p[1])
    var i = 2
    while (i < p.size) { lineTo(p[i], p[i + 1]); i += 2 }
}

private fun Cv.dot(x: Float, y: Float, r: Float) { beginPath(); circle(x, y, r) }

private fun Cv.put(s: String, x: Float, y: Float, size: Float, color: Int, weight: Int = 500, align: Paint.Align = Paint.Align.LEFT, spacing: Float = 0f) {
    if (s.isEmpty()) return
    fontW(size, weight, spacing)
    text(s, x, y, color, align)
}

/** The largest size from [start] down to [min] at which [s] fits [maxW]; leaves the font set. */
private fun Cv.fitW(s: String, weight: Int, maxW: Float, start: Float, min: Float = 10f, step: Float = 2f, spacing: Float = 0f): Float {
    var size = start
    fontW(size, weight, spacing)
    while (size > min && measure(s) > maxW) { size -= step; fontW(size, weight, spacing) }
    return size
}

/** One line of big text, shrunk to fit. */
private fun Cv.name(s: String, x: Float, y: Float, maxW: Float, size: Float, color: Int, weight: Int = 700, caps: Boolean = false, spacing: Float = 0f, align: Paint.Align = Paint.Align.LEFT, min: Float = 10f): Float {
    if (s.isEmpty()) return 0f
    val t = if (caps) s.uppercase() else s
    val fs = fitW(t, weight, maxW, size, min, spacing = spacing)
    text(t, x, y, color, align)
    return fs
}

private fun nonEmpty(sep: String, vararg s: String) = s.filter { it.isNotEmpty() }.joinToString(sep)

/** The mixed 32-bit hash of a track that picks its runway or its line (the web's `routeFor` and `lineFor`), as an unsigned value. */
private fun trackHash(t: Track?): Long {
    val key = "${t?.artist ?: ""}${t?.title ?: ""}${t?.number ?: ""}"
    var h = 7
    for (ch in key) h = h * 31 + ch.code
    h = (h xor (h ushr 15)) * -2048144777 // 2246822519 as an Int
    return (h xor (h ushr 13)).toLong() and 0xFFFFFFFFL
}

/** What the posters measure of the music the same way: band groups and their held peaks, the two channels, the loudness, bass onsets. */
private class Meas(
    val n: Int, val attack: Float, val release: Float, val pkDecay: Float = 0.2f, val lvSecs: Float = 0.7f, val peakSecs: Float = 0.5f,
    val onDecay: Float = 3f, val cool: Float = 0.2f,
) {
    val bars = Bars(n)
    private val ref = Reference()
    val pk = FloatArray(n)
    var l = 0f; var r = 0f; var lv = 0f; var peak = 0f
    private var avg = 0f
    private var wait = 0f
    var on = 0f
    var onset = false

    fun update(x: PosterCtx): FloatArray {
        val dt = x.dt
        val v = bars.update(x.bands, dt, 40f, attack, release)
        var mean = 0f
        var top = 0f
        for (i in 0 until n) { pk[i] = max(v[i], pk[i] - dt * pkDecay); mean += v[i] / n; top = max(top, pk[i]) }
        val dl = toDb(x.lv.rmsL)
        val dr = toDb(x.lv.rmsR)
        ref.update(max(dl, dr), dt)
        val nl = ref.norm(dl, 30f)
        val nr = ref.norm(dr, 30f)
        l = follow(l, nl, dt, if (nl > l) attack else release)
        r = follow(r, nr, dt, if (nr > r) attack else release)
        lv = follow(lv, mean, dt, lvSecs)
        peak = follow(peak, top, dt, peakSecs)
        val bass = (bars.raw[0] + bars.raw[1] + bars.raw[2]) / 3
        avg = follow(avg, bass, dt, 2.7f)
        wait -= dt
        on = max(0f, on - dt * onDecay)
        onset = false
        if (wait <= 0f && bass > avg * 1.3f + 0.04f) { on = 1f; wait = cool; onset = true }
        return v
    }
}

// ---- Swatches ----------------------------------------------------------------------------------------------------

internal class Swatches : Poster() {
    private val m = Meas(30, 0.12f, 0.4f)

    private fun onCard(color: Int, card: Int) = if (abs(luminance(color) - luminance(card)) > 0.3f) color else readableOn(card, color, 0xFF161616.toInt(), 0xFFf5f5f5.toInt())

    private fun chunks(say: Say): List<String> {
        val words = say.title.ifEmpty { say.name }.split(Regex("\\s+")).filter { it.isNotEmpty() }
        if (words.size >= 3) return listOf(words[0], words[1], words.drop(2).joinToString(" "))
        return (words + listOf(say.year.ifEmpty { say.no }, say.dur)).filter { it.isNotEmpty() }.take(3)
    }

    override fun draw(x: PosterCtx) {
        with(x.g) {
            val look = PosterLook(mapOf("cream" to "#e8d8c9", "mid" to "#4b607f", "warm" to "#f3701e"))
            val txt = begin(this, x.w, x.h, look["paper"])
            val dark = look["dark"]
            val own = listOf(look["cream"], look["mid"], look["warm"]).map { onCard(it, dark) }
            val v = m.update(x)

            fill = dark; beginPath(); roundRect(14f, 14f, 572f, 772f, 46f); fill()

            val base = listOf(look["cream"], look["mid"], look["warm"])
            val say = Say(x.track)
            val words = chunks(say)
            val groups = listOf(20 to 30, 8 to 20, 0 to 8)
            val labels = listOf("highs", "mids", "bass")
            groups.forEachIndexed { i, (from, to) ->
                val level = min(1f, groupOf(v, from, to, 1.5f))
                val color = lerpColor(base[i], 0xFFffffff.toInt(), level * 0.42f)
                val y = 190f + i * 160
                fill = color; beginPath(); roundRect(40f, y, 520f, 146f, 26f); fill()
                val ink = readableOn(color, dark, 0xFF111111.toInt(), 0xFFf5f5f5.toInt())
                keep {
                    beginPath(); roundRect(40f, y, 520f, 146f, 26f); clip()
                    fill = ink; alpha = 0.85f; fillRect(40f, y + 138, 520f * level, 8f)
                }
                if (txt) {
                    val word = words.getOrElse(i) { "" }.uppercase()
                    val size = fitW(word, 900, 330f, 124f, 30f)
                    put(word, 58f, y + 112 - (124 - size) * 0.22f, size, ink, 900, spacing = -2f)
                    put(labels[i], 544f, y + 56, 46f, ink, 800, Paint.Align.RIGHT, -1f)
                    put("#%06x".format(color and 0xFFFFFF), 544f, y + 122, 38f, ink, 700, Paint.Align.RIGHT, -0.5f)
                }
            }

            if (txt) {
                put(if (say.no.isNotEmpty()) say.no.toInt().toString() else "", 40f, 168f, 158f, own[0], 900, spacing = -6f)
                name(say.album.ifEmpty { say.fmt }, 176f, 80f, 126f, 20f, own[2], 700, caps = true, spacing = 1f, min = 11f)
                fill = own[0]; dot(310f, 74f, 8f); fill()
                name(if (say.title.isNotEmpty()) say.name else "", 176f, 120f, 196f, 52f, own[1], 800, spacing = -1f)
                put("NOW", 560f, 96f, 42f, own[0], 800, Paint.Align.RIGHT, -1f)
                put("PLAYING", 560f, 140f, 42f, own[0], 800, Paint.Align.RIGHT, -1f)
                val length = x.track?.duration?.toFloat() ?: 0f
                put(if (length > 0) clock(x.progress * length) else "", 40f, 750f, 86f, own[1], 900, spacing = -4f)
                put(if (length > 0) "/ ${clock(length)}" else "", 244f, 748f, 22f, own[1], 800, spacing = 1f)
                put(say.fmt, 560f, 724f, 20f, own[1], 700, Paint.Align.RIGHT, 0.5f)
                put(nonEmpty("  ·  ", say.dr, say.no), 560f, 750f, 20f, own[1], 700, Paint.Align.RIGHT, 0.5f)
            }
            restore()
        }
    }
}

// ---- Stacks ------------------------------------------------------------------------------------------------------

internal class Stacks : Poster() {
    private val m = Meas(30, 0.12f, 0.4f)
    private val hist = FloatArray(30)
    private var t = 0f

    private fun Cv.stripe(base: Int, hot: Int, xL: Float, xR: Float, y: Float, level: Float) {
        stroke = lerpColor(base, hot, level * 0.6f)
        beginPath()
        moveTo(xL + 33f / 2, y + 16)
        bezierTo(xL + 24 + 33f / 2, y + 16, xL + 38, y, xL + 74, y)
        lineTo(xR, y)
        stroke()
    }

    override fun draw(x: PosterCtx) {
        with(x.g) {
            val look = PosterLook(mapOf(
                "s0" to "#e8531e", "s1" to "#e63a2e", "s2" to "#e8458f", "core" to "#f28200", "left" to "#e8531e", "right" to "#e63a2e",
                "hotLeft" to "#f6a21a", "hotRight" to "#ee3f9a", "pink" to "#e8458f", "tower" to "#f28200",
            ))
            val txt = begin(this, x.w, x.h, look["paper"])
            val v = m.update(x)
            t += x.dt
            if (t > 0.1f) {
                t = 0f
                System.arraycopy(hist, 0, hist, 1, 29)
                hist[0] = clamp01(m.lv * 1.5f + m.on * 0.2f)
            }
            val lv = clamp01(m.lv * 1.5f)
            val peak = clamp01(m.peak * 1.25f)
            val on = m.on

            fun disc(cx: Float, cy: Float, r: Float, color: Int) { fill = color; beginPath(); circle(cx, cy, r); fill() }
            disc(60f, 100f, 200 + on * 5, look["s0"])
            disc(60f, 100f, 122 + on * 10, look["s1"])
            disc(60f, 100f, 95 + on * 8, look["s2"])
            disc(60f, 100f, 50 + lv * 26, look["core"])

            lineWidth = 33f - 4.5f; round = true
            for (row in 0 until 20) {
                val level = clamp01(atV(v, (19 - row) / 19f * 29f) * 1.25f)
                val y = 140f + row * 33 + 33f / 2
                if (row >= 6) stripe(look["left"], look["hotLeft"], 158f, 330f, y, level)
                stripe(look["right"], look["hotRight"], 352f, 612f, y, level)
            }
            round = false
            fill = look["pink"]
            for (row in 0 until 4) for (i in 0 until 30) {
                alpha = 0.12f + 0.88f * hist[29 - i]
                fillRect(442f + i * 4.2f, 140f + row * 33 + 2.25f, 2.2f, 28.5f)
            }
            alpha = 1f

            // the tower
            val tx = 70f
            fill = look["tower"]
            fillRect(tx - 1.5f, 352f, 3f, 80f)
            disc(tx, 352f, 2.2f, look["tower"])
            for ((y, rx) in listOf(438f to 36f, 451f to 29f, 463f to 21f)) { beginPath(); ellipse(tx, y, rx, 5f, 0f, 7f); fill() }
            shape(tx - 34, 474f, tx + 34, 474f, tx + 9, 520f, tx - 9, 520f); closePath(); fill()
            shape(tx - 9, 518f, tx + 9, 518f, tx + 13, 656f, tx - 13, 656f); closePath(); fill()
            val slat = (800f - 658f) / 22
            val level = clamp01((m.l + m.r) * 0.55f)
            for (i in 0 until 22) {
                alpha = if (i / 22f < level) 1f else 0.2f
                fillRect(tx - 14, 800 - (i + 1) * slat + 1.2f, 28f, slat - 2.6f)
            }
            alpha = 1f
            if (peak > 0.7f) { alpha = (peak - 0.7f) * 3; disc(tx, 350f, 6f, look["core"]); alpha = 1f }

            if (txt) {
                val say = Say(x.track)
                val length = x.track?.duration?.toFloat() ?: 0f
                name(say.title.ifEmpty { say.name }, 580f, 56f, 250f, 40f, look["ink"], 700, spacing = -0.8f, align = Paint.Align.RIGHT)
                fun small(s: String, y: Float) { if (s.isNotEmpty()) { fontW(11f, 400); text(s, 580f, y, look["ink"], Paint.Align.RIGHT) } }
                small(nonEmpty(" · ", if (say.title.isNotEmpty()) say.name else "", say.album).take(36), 78f)
                small(nonEmpty(" · ", say.fmt, say.dr, if (length > 0) "${clock(x.progress * length)} / ${clock(length)}" else ""), 93f)
            }
            restore()
        }
    }
}

// ---- Camera ------------------------------------------------------------------------------------------------------

private val CAM_LEFT = listOf(
    floatArrayOf(107f, 72f), floatArrayOf(100f, 80f), floatArrayOf(100f, 96f), floatArrayOf(94f, 98f), floatArrayOf(94f, 118f), floatArrayOf(70f, 118f),
    floatArrayOf(34f, 118f, 14f, 136f, 14f, 170f), floatArrayOf(9f, 172f), floatArrayOf(9f, 227f), floatArrayOf(14f, 229f), floatArrayOf(14f, 318f),
    floatArrayOf(15f, 320f), floatArrayOf(15f, 378f), floatArrayOf(26f, 380f), floatArrayOf(26f, 386f), floatArrayOf(19f, 388f), floatArrayOf(19f, 499f),
    floatArrayOf(26f, 501f), floatArrayOf(26f, 507f), floatArrayOf(24f, 509f), floatArrayOf(24f, 558f), floatArrayOf(12f, 562f), floatArrayOf(8f, 575f),
    floatArrayOf(-6f, 640f, 2f, 720f, 58f, 758f), floatArrayOf(96f, 782f, 140f, 790f, 190f, 790f),
)
private val CAM_SEAMS = listOf(
    98f to 107f, 116f to 94f, 151f to 9f, 227f to 14f, 318f to 15f, 378f to 26f, 386f to 19f, 440f to 22f, 445f to 22f, 499f to 26f, 507f to 24f, 558f to 24f, 572f to 7f, 580f to 6f,
)
private val CAM_STOPS = listOf("1.8", "2.8", "4", "5.6", "8", "11", "16", "22")
private val CAM_FAN = listOf("4", "5.6", "8", "11", "16", "22")
private val CAM_DIST = listOf("∞", "7", "3", "2", "1", "0,7")

internal class Camera : Poster() {
    private val m = Meas(30, 0.1f, 0.4f)

    /** Text at a point; [flip] turns it upside down about its own middle, as the engraving on the lens it follows is. */
    private fun Cv.engrave(s: String, x: Float, y: Float, size: Float, color: Int, weight: Int = 500, align: Paint.Align = Paint.Align.CENTER, spacing: Float = 0f, flip: Boolean = false) {
        keep {
            if (flip) { translate(x, y - size * 0.36f); rotate(PI_F) } else translate(x, y)
            fontW(size, weight, spacing)
            val a = if (flip) when (align) { Paint.Align.LEFT -> Paint.Align.RIGHT; Paint.Align.RIGHT -> Paint.Align.LEFT; else -> Paint.Align.CENTER } else align
            text(s, 0f, if (flip) size * 0.36f else 0f, color, a)
        }
    }

    override fun draw(x: PosterCtx) {
        with(x.g) {
            val look = PosterLook(mapOf("a" to "#ff3b1f"))
            val txt = begin(this, x.w, x.h, look["paper"])
            val ink = look["ink"]; val dk = look["dark"]; val acc = look["a"]
            val dim = lerpColor(dk, ink, 0.3f)
            val v = m.update(x)
            val lv = clamp01(m.lv * 1.5f)
            val peak = clamp01(m.peak * 1.25f)
            fun mark(s: String, px: Float, py: Float, size: Float, color: Int, weight: Int = 500, flip: Boolean = true) { if (txt) engrave(s, px, py, size, color, weight, flip = flip) }
            fun mir(v: Float) = 600 - v

            round = true; lineWidth = 1.2f; stroke = ink; fill = dk

            // the barrel: one outline down the left side, mirrored on the right
            beginPath(); moveTo(107f, 60f)
            for (seg in CAM_LEFT) if (seg.size == 2) lineTo(seg[0], seg[1]) else bezierTo(seg[0], seg[1], seg[2], seg[3], seg[4], seg[5])
            lineTo(mir(190f), 790f)
            for (i in CAM_LEFT.indices.reversed()) {
                val seg = CAM_LEFT[i]
                val prev = if (i > 0) CAM_LEFT[i - 1] else floatArrayOf(107f, 60f)
                val px = prev[prev.size - 2]; val py = prev[prev.size - 1]
                if (seg.size == 2) lineTo(mir(px), py) else bezierTo(mir(seg[2]), seg[3], mir(seg[0]), seg[1], mir(px), py)
            }
            closePath(); fill(); stroke()
            for ((y, sx) in CAM_SEAMS) seg(sx, y, mir(sx), y)
            seg(107f, 72f, mir(107f), 72f)

            // the mount: a button and a lamp that lights with the loudness
            dot(287f, 86f, 9f); stroke()
            dot(310f, 86f, 7f)
            fill = if (lv > 0.2f) acc else dim
            alpha = 0.4f + lv * 0.6f; fill(); alpha = 1f

            // the aperture ring: knurled strips, the stops lit from the open end by the loudness, an index below
            stroke = ink
            for ((sx, width) in listOf(12f to 30f, 455f to 135f)) {
                val n = floor(width / 3.2f).toInt()
                alpha = 0.55f
                for (i in 0 until n) seg(sx + 2 + i * 3.2f, 156f, sx + 2 + i * 3.2f, 222f)
                alpha = 1f
            }
            val x0 = 60f; val x1 = 366f
            mark("A", 402f, 197f, 15f, ink, 600)
            CAM_STOPS.forEachIndexed { i, s -> mark(s, x0 + i * (x1 - x0) / 7, 196f, 17f, if (i / 7f <= lv * 1.1f) ink else dim, 600) }
            val ix = x0 + clamp01(lv * 1.1f) * (x1 - x0)
            fill = acc; shape(ix, 207f, ix - 5, 217f, ix + 5, 217f); closePath(); fill()
            dot(433f, 184f, 14f); stroke()
            dot(433f, 184f, 8f); fill = if (m.on > 0.2f) acc else dim; fill()

            // the depth-of-field fan: left and right from the middle outwards
            stroke = ink
            seg(299f, 230f, 299f, 316f)
            for ((side, level) in listOf(-1 to m.l, 1 to m.r)) {
                CAM_FAN.forEachIndexed { i, s ->
                    val xn = 299f + side * (28 + i * 34)
                    val xl = 299f + side * (8 + i * 9)
                    val lit = clamp01(level * 1.15f) > (i + 0.5f) / 6
                    stroke = if (lit) ink else dim
                    lineWidth = if (lit) 1.6f else 1.1f
                    shape(xn, 262f, xl, 292f, xl, 312f); stroke()
                    mark(s, xn, 256f, 13f, if (lit) ink else dim)
                }
            }
            stroke = ink; lineWidth = 1.2f
            dot(88f, 272f, 21f); stroke()
            dot(88f, 272f, 13f); fill = acc; alpha = 0.15f + peak * 0.85f; fill(); alpha = 1f

            // the distance window: the focus index slides from infinity to the near end with the time played
            seg(154f, 322f, 154f, 376f)
            seg(431f, 322f, 431f, 376f)
            val d0 = 182f; val d1 = 398f
            val played = clamp01(x.progress)
            CAM_DIST.forEachIndexed { i, s -> mark(s, d0 + i * (d1 - d0) / 5, 356f, 20f, if (abs((1 - played) * 5 - i) < 0.5f) ink else lerpColor(dk, ink, 0.75f)) }
            val fx = d1 - played * (d1 - d0)
            fill = acc
            fillRect(fx - 1.2f, 326f, 2.4f, 14f)
            shape(fx, 344f, fx - 5, 337f, fx + 5, 337f); closePath(); fill()
            mark("M", 509f, 364f, 14f, ink, 600)
            mark("FT", 509f, 346f, 12f, ink, 600)

            // the two knurled rings: 60 ridges each, one band per ridge
            for ((y0, y1, side) in listOf(Triple(386f, 440f, m.l), Triple(445f, 499f, m.r))) {
                lineWidth = 2.2f
                for (i in 0 until 60) {
                    val level = clamp01(atV(v, i / 59f * 29f) * 1.25f * (0.55f + 0.75f * side))
                    stroke = if (level > 0.12f) ink else dim
                    alpha = 0.25f + 0.75f * level
                    seg(28f + i * 544f / 59, y0 + 5, 28f + i * 544f / 59, y1 - 5)
                }
                alpha = 1f
            }

            if (txt) {
                val say = Say(x.track)
                val length = x.track?.duration?.toFloat() ?: 0f
                engrave(say.fmt.uppercase(), 300f, 544f, 26f, ink, 600, spacing = 2f)
                name(say.title.ifEmpty { say.name }, 300f, 650f, 440f, 40f, ink, 600, caps = true, spacing = 2f, align = Paint.Align.CENTER)
                val by = nonEmpty("  ·  ", if (say.title.isNotEmpty()) say.name else "", say.album, say.year).uppercase()
                name(by, 300f, 680f, 440f, 15f, ink, 500, spacing = 2f, align = Paint.Align.CENTER)
                engrave(nonEmpty("   ·   ", if (length > 0) "${clock(x.progress * length)} / ${clock(length)}" else "", say.dr, say.no), 300f, 716f, 13f, ink, spacing = 2f)
            }
            round = false
            restore()
        }
    }
}

// ---- Pylon -------------------------------------------------------------------------------------------------------

private class PylonDot(val c: Int, val r: Int, val jx: Float, val jy: Float)

private val PYLON_DOTS: List<PylonDot> = run {
    var seed = 7L
    val rnd = { seed = (seed * 16807) % 2147483647; seed / 2147483647.0 }
    List(12 * 9) { i -> PylonDot(i % 12, i / 12, ((rnd() - 0.5) * 7).toFloat(), ((rnd() - 0.5) * 7).toFloat()) }
}

private class PylonTier(val y: Float, val len: Float, val from: Int, val to: Int)
private val PYLON_TIERS = listOf(PylonTier(380f, 98f, 20, 30), PylonTier(470f, 130f, 8, 20), PylonTier(560f, 162f, 0, 8))
private val PYLON_BOLT = listOf(0.58f to 0f, 0.04f to 0.56f, 0.4f to 0.56f, 0.26f to 1f, 0.96f to 0.38f, 0.56f to 0.38f, 0.8f to 0f)

internal class Pylon : Poster() {
    private val m = Meas(30, 0.07f, 0.5f, onDecay = 3f, cool = 0.16f)
    private val rg = FloatArray(44)
    private val hist = ArrayDeque<FloatArray>().apply { repeat(12) { add(FloatArray(9)) } }
    private var wave = 9f
    private var rot = 0f
    private var t = 0f
    private var clk = 0f

    private val cx = 300f
    private val top = 330f
    private val base = 690f
    private fun half(y: Float) = 12 + (y - top) * (80f / (base - top))

    private fun Cv.bolt(look: PosterLook, x: Float, y: Float, w: Float, h: Float, v: Float) {
        val pts = PYLON_BOLT.flatMap { (u, t) -> listOf(x + u * w, y + t * h) }.toFloatArray()
        keep {
            shape(*pts); closePath(); fill = look["paper"]; fill()
            keep { clip(); fill = look["a"]; alpha = 0.9f; fillRect(x - 2, y + h * (1 - v), w + 4, h * v + 2) }
            lineWidth = 2f; stroke = look["ink"]; shape(*pts); closePath(); stroke()
        }
    }

    private fun Cv.star(look: PosterLook, cx: Float, cy: Float, r: Float, values: FloatArray, rot: Float = 0f) {
        val n = values.size
        keep {
            lineWidth = 1.4f; stroke = look["ink"]
            dot(cx, cy, r); stroke()
            val pts = FloatArray(n * 4)
            for (i in 0 until n * 2) {
                val a = rot + i * PI_F / n - PI_F / 2
                val l = if (i % 2 == 0) 0.16f + 0.8f * values[i / 2] else 0.1f
                pts[i * 2] = cx + cos(a) * r * l * 0.96f
                pts[i * 2 + 1] = cy + sin(a) * r * l * 0.96f
            }
            shape(*pts); closePath(); fill = look["a"]; fill()
        }
    }

    private fun Cv.titleLines(s: String, maxW: Float): Pair<Float, List<String>> {
        val words = s.split(Regex("\\s+")).filter { it.isNotEmpty() }
        var size = 84f
        while (size >= 30f) {
            fontW(size, 900, -size * 0.03f)
            val lines = mutableListOf<String>()
            var cur = ""
            for (w in words) {
                val next = if (cur.isNotEmpty()) "$cur $w" else w
                if (cur.isEmpty() || measure(next) <= maxW) cur = next else { lines.add(cur); cur = w }
            }
            lines.add(cur)
            if (lines.size <= 3 && lines.all { measure(it) <= maxW }) return size to lines
            size -= 2f
        }
        return 30f to listOf(s)
    }

    override fun draw(x: PosterCtx) {
        with(x.g) {
            val look = PosterLook(mapOf("a" to "#ff3b1f"))
            val txt = begin(this, x.w, x.h, look["paper"])
            val ink = look["ink"]; val paper = look["paper"]; val acc = look["a"]
            val dt = x.dt
            val v = m.update(x)
            wave += dt; clk += dt
            if (m.onset) { wave = 0f; rot += 0.5f }
            t += dt
            if (t > 0.25f) {
                t = 0f
                val row = hist.removeFirst()
                for (k in 0 until 9) row[k] = groupOf(v, floor(k * 30 / 9f).toInt(), kotlin.math.ceil((k + 1) * 30 / 9f).toInt())
                hist.addLast(row)
            }
            val lvv = m.lv
            round = true
            val front = wave * 460

            // the rings: calm, thin, one colour, a faint ripple on an onset
            stroke = ink
            for (i in 0 until 44) {
                rg[i] = follow(rg[i], clamp01(atV(v, i / 43f * 29f) * 1.25f), dt, 3.2f)
                val radius = 34 + i * 5.6f
                val ripple = if (wave < 1f) exp(-((radius - front) * (radius - front)) / 900f) * (1 - wave) else 0f
                alpha = clamp01(0.42f + rg[i] * 0.14f + ripple * 0.06f)
                lineWidth = 1 + rg[i] * 0.25f
                dot(cx, 340f, radius); stroke()
            }
            alpha = 1f

            // the pylon: a mask in the paper colour first, then the lattice
            fill = paper
            shape(cx - 12, top, cx + 12, top, cx + 92, base, cx - 92, base); closePath(); fill()
            for (tier in PYLON_TIERS) {
                shape(cx - half(tier.y), tier.y, cx - tier.len, tier.y + 4, cx - half(tier.y + 28), tier.y + 28, cx + half(tier.y + 28), tier.y + 28, cx + tier.len, tier.y + 4, cx + half(tier.y), tier.y)
                closePath(); fill()
            }
            stroke = ink; lineWidth = 2f
            seg(cx - 12, top, cx - 92, base)
            seg(cx + 12, top, cx + 92, base)
            lineWidth = 1.1f
            var y = top
            while (y < base - 1) {
                val y1 = min(base, y + 30)
                seg(cx - half(y), y, cx + half(y1), y1)
                seg(cx + half(y), y, cx - half(y1), y1)
                seg(cx - half(y1), y1, cx + half(y1), y1)
                seg(cx - half(y), y, cx - half(y1) * 0.9f, y1 - 14)
                seg(cx + half(y), y, cx + half(y1) * 0.9f, y1 - 14)
                y += 30
            }
            lineWidth = 1.6f
            seg(cx, top, cx, top - 40)
            dot(cx, top - 42, 3f); stroke()

            // the three tiers: the arm, its insulators and its wires, lit and trembling with the tier's level
            PYLON_TIERS.forEachIndexed { ti, tier ->
                val level = groupOf(v, tier.from, tier.to)
                val lit = level > 0.4f
                for (side in intArrayOf(-1, 1)) {
                    val bx = cx + side * half(tier.y)
                    val tx = cx + side * tier.len
                    stroke = if (lit) acc else ink
                    alpha = 0.55f + level * 0.45f
                    lineWidth = 1.4f + level * 1.8f
                    seg(bx, tier.y, tx, tier.y + 4)
                    seg(cx + side * half(tier.y + 28), tier.y + 28, tx, tier.y + 4)
                    lineWidth = 1f
                    for (j in 1 until 5) {
                        val u = j / 5f
                        val lowX = cx + side * half(tier.y + 28)
                        seg(bx + (tx - bx) * u, tier.y + 4 * u, lowX + (tx - lowX) * u, tier.y + 28 - 24 * u)
                    }
                    alpha = 1f
                    for (j in 0 until 4) {
                        beginPath(); ellipse(tx, tier.y + 12 + j * 6.5f, 4.4f, 2.2f, 0f, 7f)
                        fill = if (lit && j < level * 4.5f) acc else paper
                        fill()
                        lineWidth = 1.1f; stroke = ink; stroke()
                    }
                    val ex = if (side < 0) -8f else 608f
                    val ey = tier.y + 52 + ti * 6
                    val sag = 26 + level * 3 * sin(clk * (6 + ti * 2.3f) + side)
                    stroke = if (lit) acc else ink
                    alpha = 0.75f; lineWidth = 1.2f
                    beginPath(); moveTo(tx, tier.y + 38); quadTo((tx + ex) / 2, (tier.y + 38 + ey) / 2 + sag, ex, ey); stroke()
                    alpha = 1f
                }
            }
            // sparks at the lowest tier on a bass onset
            if (m.on > 0.05f) {
                stroke = acc; alpha = m.on; lineWidth = 1.5f
                for (side in intArrayOf(-1, 1)) {
                    val sx = cx + side * PYLON_TIERS[2].len
                    val sy = PYLON_TIERS[2].y + 40
                    for (j in 0 until 8) {
                        val a = j * PI_F / 4 + rot
                        val len = 6 + (1 - m.on) * 16
                        seg(sx + cos(a) * 3, sy + sin(a) * 3, sx + cos(a) * len, sy + sin(a) * len)
                    }
                }
                alpha = 1f
            }

            // the bolts: left, loudness, right
            bolt(look, 40f, 570f, 46f, 120f, clamp01(m.l * 1.1f))
            bolt(look, 88f, 546f, 58f, 144f, clamp01(lvv * 1.5f))
            bolt(look, 148f, 570f, 46f, 120f, clamp01(m.r * 1.1f))
            alpha = 1f

            // the dots: the last three seconds, newest on the right
            for (d in PYLON_DOTS) {
                val level = hist[d.c][8 - d.r]
                dot(400f + d.c * 14 + d.jx + d.r * 1.5f, 540f + d.r * 16 + d.jy, 0.8f + level * 4.2f)
                if (level > 0.5f) { fill = acc; alpha = 1f } else { fill = ink; alpha = 0.25f + level * 0.7f }
                fill()
            }
            alpha = 1f

            // the stars: the spectrum in polar form, the held peaks, the onsets
            star(look, 52f, 764f, 24f, FloatArray(8) { i -> groupOf(v, floor(i * 30 / 8f).toInt(), kotlin.math.ceil((i + 1) * 30 / 8f).toInt()) })
            star(look, 108f, 764f, 24f, FloatArray(12) { i -> clamp01(atV(m.pk, i / 11f * 29f) * 1.25f) })
            star(look, 164f, 764f, 24f, floatArrayOf(0.35f + m.on * 0.65f, 0.2f + lvv, 0.35f + m.on * 0.65f, 0.2f + lvv).map { clamp01(it) }.toFloatArray(), rot)

            // the ring in the corner: the time played
            round = false
            stroke = ink; alpha = 0.35f; lineWidth = 1f
            dot(540f, 66f, 34f); stroke()
            alpha = 1f; stroke = acc; lineWidth = 4f
            beginPath(); arc(540f, 66f, 34f, -PI_F / 2, -PI_F / 2 + clamp01(x.progress) * PI_F * 2); stroke()

            if (txt) {
                val say = Say(x.track)
                val length = x.track?.duration?.toFloat() ?: 0f
                val time = if (length > 0) clock(x.progress * length) else ""
                val main = say.title.ifEmpty { say.name }.uppercase()
                put(nonEmpty("  ·  ", say.album, say.year).uppercase(), 36f, 30f, 9f, ink, 600, spacing = 2f)
                val (size, lines) = titleLines(main, 470f)
                lines.forEachIndexed { i, s ->
                    val ty = 36 + size * 0.92f + i * size * 0.9f
                    fontW(size, 900, -size * 0.03f)
                    round = true; lineWidth = 7f
                    strokeText(s, 34f, ty, paper)
                    text(s, 34f, ty, ink)
                    round = false
                }
                if (say.title.isNotEmpty()) {
                    val ty = 36 + size * 0.92f + (lines.size - 1) * size * 0.9f + 26
                    fontW(13f, 700, 3f)
                    val width = measure(say.name.uppercase())
                    fill = paper; fillRect(30f, ty - 14, width + 10, 20f)
                    put(say.name.uppercase(), 36f, ty, 13f, ink, 700, spacing = 3f)
                }
                put(time, 540f, 70f, 11f, ink, 700, Paint.Align.CENTER)
                // the vertical readout: the loudness in dB and the time
                keep {
                    translate(18f, 470f); rotate(-PI_F / 2)
                    put(nonEmpty("  —  ", "${dbText((x.lv.rmsL + x.lv.rmsR) / 2)} DB", if (length > 0) "$time / ${clock(length)}" else ""), 0f, 0f, 10f, ink, 700, Paint.Align.CENTER, 2.5f)
                }
                stroke = ink; lineWidth = 1f
                seg(30f, 360f, 30f, 600f)
                shape(26f, 592f, 30f, 600f, 34f, 592f); stroke()
                // the small print and the paragraph
                listOf(
                    if (say.fmt.isNotEmpty()) "FORMAT ${say.fmt.uppercase()}" else "", if (say.dr.isNotEmpty()) "DYNAMIC RANGE ${say.dr}" else "",
                    if (say.no.isNotEmpty()) "TRACK ${say.no}" else "", "LEFT / LOUDNESS / RIGHT",
                ).filter { it.isNotEmpty() }.forEachIndexed { i, s -> put(s, 36f, 706f + i * 9.5f, 7f, ink, 700) }
                val para = listOf(
                    "$main IS TRACK ${say.no}${if (say.album.isNotEmpty()) " ON ${say.album.uppercase()}" else ""}${if (say.year.isNotEmpty()) " (${say.year})" else ""}.",
                    if (say.fmt.isNotEmpty()) "A ${say.fmt.uppercase()} FILE${if (say.dur.isNotEmpty()) ", ${say.dur} LONG" else ""}${if (say.dr.isNotEmpty()) ", WITH A DYNAMIC RANGE OF ${say.dr}" else ""}." else "",
                    "NOW ${dbText(x.lv.rmsL)} DB LEFT, ${dbText(x.lv.rmsR)} DB RIGHT.",
                    "THE RINGS ARE THE SPECTRUM, THE PYLON ITS THREE TIERS.",
                ).filter { it.isNotEmpty() }.joinToString(" ")
                fontW(7.6f, 700)
                val rows = mutableListOf<String>()
                var cur = ""
                for (w in para.split(" ")) {
                    val next = if (cur.isNotEmpty()) "$cur $w" else w
                    if (cur.isEmpty() || measure(next) <= 340f) cur = next else { rows.add(cur); cur = w }
                }
                rows.add(cur)
                rows.take(7).forEachIndexed { i, s -> put(s, 232f, 710f + i * 10.4f, 7.6f, ink, 700) }
            }
            round = false
            restore()
        }
    }
}

// ---- Airport -----------------------------------------------------------------------------------------------------

// The chart is an FAA airport diagram (public domain) baked into a mask (res/raw/poster_airport_chart.png); the overlays are placed in its own
// pixel space (130 dpi of the page, 700 x 1073) and mapped to the poster.
private const val AP_PAGE_W = 700f
private const val AP_PAGE_H = 1073f
private const val AP_SC = H / AP_PAGE_H
private const val AP_OX = (W - AP_PAGE_W * AP_SC) / 2
private fun apX(x: Float) = AP_OX + x * AP_SC
private fun apY(y: Float) = y * AP_SC

private const val RWL_X = 248.5f; private const val RWL_N = 292f; private const val RWL_S = 809f
private const val RWR_X = 508.5f; private const val RWR_N = 308f; private const val RWR_S = 824f
private const val RWS_X = 217.5f; private const val RWS_N = 528f; private const val RWS_S = 687f
private val AP_ROUTES = listOf(
    floatArrayOf(90f, 317f, 355f, 583f), floatArrayOf(RWL_X, RWL_S, RWL_X, RWL_N), floatArrayOf(RWR_X, RWR_S, RWR_X, RWR_N), floatArrayOf(RWS_X, RWS_S, RWS_X, RWS_N),
)

/** Which runway the plane uses: one of four, fixed for a track. */
internal fun routeFor(track: Track?) = (trackHash(track) % 4).toInt()

internal class Airport : Poster() {
    private val m = Meas(30, 0.08f, 0.45f, lvSecs = 0.4f, peakSecs = 0.3f)
    private var ping = 9f

    private fun greenish(c: Int): Boolean { val (r, g, b) = Color.rgb(c); return g > r + 4 && g > b + 4 }

    /** A runway's lights: a lit centreline and two rows of edge lights, filled to [frac] from the start. */
    private fun Cv.strip(ax: Float, ay: Float, bx: Float, by: Float, frac: Float, acc: Int, ink: Int) {
        val len = hypot(bx - ax, by - ay)
        val ux = (bx - ax) / len
        val uy = (by - ay) / len
        val n = floor(len / 6.6f).toInt()
        round = false
        stroke = acc; lineWidth = 2.4f; dash = floatArrayOf(5f, 4f)
        beginPath(); moveTo(ax, ay); lineTo(ax + ux * len * frac, ay + uy * len * frac); stroke()
        dash = null
        fill = ink; alpha = 0.3f
        beginPath()
        for (i in 0 until n) { val t = (i + 0.5f) / n; if (t >= frac) for (side in intArrayOf(-1, 1)) circle(ax + ux * len * t - uy * side * 7.2f, ay + uy * len * t + ux * side * 7.2f, 1.1f) }
        fill()
        alpha = 1f; fill = acc
        beginPath()
        for (i in 0 until n) { val t = (i + 0.5f) / n; if (t < frac) for (side in intArrayOf(-1, 1)) circle(ax + ux * len * t - uy * side * 7.2f, ay + uy * len * t + ux * side * 7.2f, 1.7f) }
        fill()
    }

    private fun Cv.plane(px: Float, py: Float, angle: Float, acc: Int, paper: Int) {
        keep {
            translate(px, py); rotate(angle); c.scale(1.25f, 1.25f)
            shape(13f, 0f, 3f, -2.2f, -2f, -13f, -5.5f, -13f, -3.6f, -2.2f, -9f, -1.4f, -10.5f, -5f, -13f, -5f, -12f, 0f, -13f, 5f, -10.5f, 5f, -9f, 1.4f, -3.6f, 2.2f, -5.5f, 13f, -2f, 13f, 3f, 2.2f)
            closePath(); fill = acc; lineWidth = 1f; stroke = paper; fill(); stroke()
        }
    }

    override fun draw(x: PosterCtx) {
        with(x.g) {
            val look = PosterLook(mapOf("a" to "#c4247a"))
            val txt = begin(this, x.w, x.h, look["paper"])
            val paper = look["paper"]; val ink = look["ink"]
            // the accent is the printed chart's red-pink only while the paper is light; a dark paper takes the mockup's night (amber) or radar (green) look
            val acc = if (isDark(paper)) (if (greenish(paper)) 0xFFd7ff6a.toInt() else 0xFFffb21e.toInt()) else look["a"]
            val v = m.update(x)
            ping += x.dt
            if (m.onset) ping = 0f

            PosterAssets.bitmap(R.raw.poster_airport_chart, x.sync)?.let { image(it, AP_OX, 0f, AP_PAGE_W * AP_SC, AP_PAGE_H * AP_SC, ink) }

            round = true
            strip(apX(RWL_X), apY(RWL_S), apX(RWL_X), apY(RWL_N), clamp01(m.l * 1.1f), acc, ink)
            strip(apX(RWR_X), apY(RWR_S), apX(RWR_X), apY(RWR_N), clamp01(m.r * 1.1f), acc, ink)
            strip(apX(RWS_X), apY(RWS_S), apX(RWS_X), apY(RWS_N), clamp01(m.peak * 1.25f), acc, ink)
            strip(apX(90f), apY(317f), apX(355f), apY(583f), clamp01(m.lv * 1.6f), acc, ink)

            // the plane rolls down this track's runway as the track plays
            val rt = AP_ROUTES[routeFor(x.track)]
            val ax = apX(rt[0]); val ay = apY(rt[1]); val bx = apX(rt[2]); val by = apY(rt[3])
            val tp = 0.03f + 0.94f * clamp01(x.progress)
            val px = ax + (bx - ax) * tp
            val py = ay + (by - ay) * tp
            round = true
            stroke = acc; lineWidth = 3f
            beginPath(); moveTo(ax, ay); lineTo(px, py); stroke()
            plane(px, py, atan2(by - ay, bx - ax), acc, paper)

            // the 30 bands east of the right-hand runway, bass at the south end, a dot at each held peak
            round = false
            for (i in 0 until 30) {
                val y = apY(818 - i * (500f / 29))
                val x0 = apX(520f)
                alpha = 0.28f; stroke = ink; lineWidth = 1f; seg(x0, y, x0 + 26, y)
                alpha = 1f; stroke = acc; lineWidth = 2.6f; seg(x0, y, x0 + clamp01(v[i] * 1.25f) * 26, y)
                fill = acc; dot(x0 + clamp01(m.pk[i] * 1.25f) * 26, y, 1.8f); fill()
            }

            // the terminal flashes, its gate lights follow band groups, the tower pings
            fill = acc; alpha = m.on * 0.5f
            beginPath(); ellipse(apX(410f), apY(492f), 58 * AP_SC, 14 * AP_SC, 0f, 7f); fill()
            for (i in 0 until 12) {
                val k = floor(i * 30 / 12f).toInt()
                val lit = (v[k] + v[min(29, k + 1)]) * 0.7f > 0.35f
                alpha = if (lit) 1f else 0.3f
                fill = if (lit) acc else ink
                dot(apX(360 + i * 9.4f), apY(510f), 1.7f); fill()
            }
            alpha = 1f
            if (ping < 0.6f) {
                alpha = 1 - ping / 0.6f; stroke = acc; lineWidth = 1.6f
                dot(apX(334f), apY(437f), 3 + ping * 38); stroke()
                alpha = 1f
            }

            if (txt) {
                val say = Say(x.track)
                val main = say.title.ifEmpty { say.name }
                val who = nonEmpty(", ", if (say.title.isNotEmpty()) say.name else "", say.album).uppercase()
                val size = fitW(main, 700, 178f, 17f, 11f, 1f)
                val length = x.track?.duration?.toFloat() ?: 0f
                put(main, apX(668f), apY(58f), size, ink, 700, Paint.Align.RIGHT)
                put(who, apX(668f), apY(76f), 9.5f, ink, 500, Paint.Align.RIGHT)
                put(who, apX(668f), apY(1004f), 9.5f, ink, 500, Paint.Align.RIGHT)
                put(main, apX(668f), apY(1023f), size, ink, 700, Paint.Align.RIGHT)
                listOf("FORMAT" to true, say.fmt to false, "DYNAMIC RANGE" to true, say.dr to false, "LENGTH" to true, say.dur to false, "TRACK" to true, say.no to false)
                    .forEachIndexed { i, (s, head) -> put(s, apX(36f), apY(100f + i * 14), 8.2f, ink, if (head) 700 else 500) }
                put(nonEmpty(" ", say.album, say.year).uppercase(), apX(134f), apY(102f), 8.2f, ink)
                listOf("RWY 17R-35L LEFT   RWY 17L-35R RIGHT", "RWY 13-31 LOUDNESS   RWY 18-36 PEAK", "BARS EAST OF 17L-35R: 30 BANDS")
                    .forEachIndexed { i, s -> put(s, apX(134f), apY(124f + i * 14), 7.4f, ink) }
                put("LEVELS (DB)", apX(44f), apY(878f), 8.2f, ink, 700)
                listOf("RWY 17R-35L" to dbText(x.lv.rmsL), "RWY 17L-35R" to dbText(x.lv.rmsR), "RWY 13-31" to dbText((x.lv.rmsL + x.lv.rmsR) / 2), "RWY 18-36" to dbText(max(x.lv.peakL, x.lv.peakR)))
                    .forEachIndexed { i, (n, value) ->
                        put(n, apX(44f), apY(896f + i * 15), 8.2f, ink)
                        put(value, apX(230f), apY(896f + i * 15), 8.2f, ink, 500, Paint.Align.RIGHT)
                    }
                keep {
                    translate(apX(18f), apY(540f)); rotate(-PI_F / 2)
                    put(nonEmpty(" · ", say.fmt, say.dr, say.dur), 0f, 3f, 8f, ink, 500, Paint.Align.CENTER, 0.8f)
                }
                keep {
                    translate(apX(686f), apY(540f)); rotate(PI_F / 2)
                    put(nonEmpty(" · ", if (say.no.isNotEmpty()) "TRACK ${say.no}" else "", say.year, if (length > 0) clock(x.progress * length) else ""), 0f, 3f, 8f, ink, 500, Paint.Align.CENTER, 0.8f)
                }
            }
            restore()
        }
    }
}

// ---- Viewdata ----------------------------------------------------------------------------------------------------

private val TT_BLACK = 0xFF000000.toInt(); private val TT_GREEN = 0xFF2bff4a.toInt(); private val TT_YELLOW = 0xFFffee2e.toInt()
private val TT_RED = 0xFFff3b2e.toInt(); private val TT_CYAN = 0xFF2ee6ff.toInt(); private val TT_WHITE = 0xFFf4f4f4.toInt(); private val TT_BLUE = 0xFF1a2cff.toInt()

private fun pts(vararg p: Float) = Array(p.size / 2) { floatArrayOf(p[it * 2], p[it * 2 + 1]) }

private fun quad(a: FloatArray, c: FloatArray, b: FloatArray, n: Int = 18) = Array(n + 1) { i ->
    val t = i.toFloat() / n; val u = 1 - t
    floatArrayOf(u * u * a[0] + 2 * u * t * c[0] + t * t * b[0], u * u * a[1] + 2 * u * t * c[1] + t * t * b[1])
}

private val VD_A = pts(232f, 452f, 322f, 452f, 322f, 604f)
private val VD_B = pts(284f, 604f, 284f, 490f, 234f, 490f)
private val VD_C = pts(346f, 640f, 372f, 652f, 396f, 650f)
private val VD_D = pts(430f, 724f, 430f, 746f, 250f, 746f, 250f, 654f, 264f, 654f)
private val VD_E = pts(140f, 616f, 140f, 566f)
private val VD_F = quad(floatArrayOf(214f, 206f), floatArrayOf(310f, 206f), floatArrayOf(424f, 240f))

private fun plen(p: Array<FloatArray>): Float { var d = 0f; for (i in 1 until p.size) d += hypot(p[i][0] - p[i - 1][0], p[i][1] - p[i - 1][1]); return d }

private fun along(p: Array<FloatArray>, dist: Float): FloatArray {
    var d = dist
    for (i in 1 until p.size) {
        val l = hypot(p[i][0] - p[i - 1][0], p[i][1] - p[i - 1][1])
        if (d <= l) { val k = d / l; return floatArrayOf(p[i - 1][0] + (p[i][0] - p[i - 1][0]) * k, p[i - 1][1] + (p[i][1] - p[i - 1][1]) * k) }
        d -= l
    }
    return p[p.size - 1]
}

private fun Cv.trace(p: Array<FloatArray>) {
    beginPath()
    p.forEachIndexed { i, q -> if (i > 0) lineTo(q[0], q[1]) else moveTo(q[0], q[1]) }
}

internal class Viewdata : Poster() {
    private val m = Meas(20, 0.08f, 0.4f, pkDecay = 0.25f, lvSecs = 1.6f, onDecay = 3.2f, cool = 0.16f)
    private var dial = 0f
    private var key = 0
    private var keyT = 0f
    private var phase = 0f
    private val mono = Typeface.create(Typeface.MONOSPACE, Typeface.BOLD)

    private fun Cv.arrow(p: Array<FloatArray>, tint: Int, size: Float = 9f) {
        val (ex, ey) = p[p.size - 1].let { it[0] to it[1] }
        val (px, py) = p[p.size - 2].let { it[0] to it[1] }
        val a = atan2(ey - py, ex - px)
        beginPath()
        moveTo(ex + 2 * cos(a), ey + 2 * sin(a))
        lineTo(ex - size * cos(a - 0.42f), ey - size * sin(a - 0.42f))
        lineTo(ex - size * cos(a + 0.42f), ey - size * sin(a + 0.42f))
        closePath()
        val f = fill; fill = tint; fill(); fill = f
        stroke()
    }

    /** An oblique box: the front, the top and the right side. */
    private fun Cv.box3(x: Float, y: Float, w: Float, h: Float, dx: Float, dy: Float, ground: Int) {
        keep {
            fill = ground
            shape(x, y, x + dx, y + dy, x + w + dx, y + dy, x + w, y); closePath(); fill(); stroke()
            shape(x + w, y, x + w + dx, y + dy, x + w + dx, y + h + dy, x + w, y + h); closePath(); fill(); stroke()
            fillRect(x, y, w, h); strokeRect(x, y, w, h)
        }
    }

    /** A disk (a cylinder) filled as far as [amount] from the bottom. */
    private fun Cv.disk(cx: Float, cy: Float, rx: Float, ry: Float, h: Float, amount: Float, paper: Int, acc: Int) {
        val bottom = cy + h
        beginPath(); moveTo(cx - rx, cy); lineTo(cx - rx, bottom)
        ellipse(cx, bottom, rx, ry, PI_F, 0f, true)
        lineTo(cx + rx, cy)
        ellipse(cx, cy, rx, ry, 0f, PI_F, true)
        closePath()
        keep {
            fill = paper; fill(); clip()
            alpha = 0.85f; fill = acc; fillRect(cx - rx - 2, bottom + ry - amount * (h + ry), rx * 2 + 4, h + ry * 2)
        }
        stroke()
        beginPath(); ellipse(cx, cy, rx, ry, 0f, 7f)
        keep { fill = paper; fill() }
        stroke()
    }

    private fun Cv.dashed(on: Boolean) { if (on) { dash = floatArrayOf(9f, 7f); dashPhase = ((-phase % 16f) + 16f) % 16f } else { dash = null; dashPhase = 0f } }

    override fun draw(x: PosterCtx) {
        with(x.g) {
            val look = PosterLook(mapOf("a" to "#c2331f"))
            val txt = begin(this, x.w, x.h, look["paper"])
            val ink = look["ink"]; val acc = look["a"]; val paper = look["paper"]
            val v = m.update(x)
            if (m.onset) { dial += 0.9f; key = (key + 5) % 12; keyT = 1f }
            keyT = max(0f, keyT - x.dt * 2.5f)
            var mean = 0f
            for (i in 0 until 20) mean += v[i] / 20
            phase += x.dt * (34 + mean * 200)
            val flash = m.on
            val say = Say(x.track)
            val length = x.track?.duration?.toFloat() ?: 0f
            fun label(s: String, px: Float, py: Float, size: Float = 9.5f, align: Paint.Align = Paint.Align.LEFT) { if (txt) put(s, px, py, size, ink, 400, align) }

            lineWidth = 3f; round = true; stroke = ink; fill = ink

            // the heading: album and year, the title in at most two lines, the artist
            if (txt) {
                put(nonEmpty("  ·  ", say.album, say.year).uppercase(), 40f, 50f, 12f, ink, 400, spacing = 2.6f)
                val words = say.title.ifEmpty { say.name }.uppercase().split(Regex("\\s+"))
                var size = 40f
                var lines: List<String>? = null
                while (size >= 20f && lines == null) {
                    fontW(size, 700, 3f)
                    val ls = mutableListOf<String>()
                    var cur = ""
                    for (w in words) {
                        val next = if (cur.isNotEmpty()) "$cur $w" else w
                        if (cur.isEmpty() || measure(next) <= 520f) cur = next else { ls.add(cur); cur = w }
                    }
                    ls.add(cur)
                    if (ls.size <= 2 && ls.all { measure(it) <= 520f }) lines = ls else size -= 2f
                }
                val fit = lines ?: listOf(say.title.ifEmpty { say.name }.uppercase())
                if (lines == null) size = 20f
                fit.forEachIndexed { i, s -> put(s, 40f, 92f + i * (size + 6), size, ink, 700, spacing = 3f) }
                if (say.title.isNotEmpty()) put(say.name.uppercase(), 40f, 92f + fit.size * (size + 6) + 4, 14f, acc, 700, spacing = 3.4f)
            }

            // the radio mast and its arcs: four band groups
            val mx = 96f; val top = 190f; val base = 300f
            fun half(y: Float) = 1.5f + 25f * (y - top) / (base - top)
            seg(mx - half(base), base, mx - 1.5f, top)
            seg(mx + half(base), base, mx + 1.5f, top)
            lineWidth = 2.2f
            for (i in 0 until 6) {
                val y0 = top + 12 + i * 18
                val y1 = y0 + 18
                if (i % 2 == 1) seg(mx - half(y0), y0, mx + half(y1), y1) else seg(mx + half(y0), y0, mx - half(y1), y1)
                seg(mx - half(y1), y1, mx + half(y1), y1)
            }
            listOf(0 to 5, 5 to 10, 10 to 15, 15 to 20).forEachIndexed { i, (from, to) ->
                val level = groupOf(v, from, to, 1.6f)
                stroke = if (level > 0.25f) acc else ink
                alpha = 0.3f + 0.7f * level
                lineWidth = 2.6f + level * 2
                beginPath(); arc(mx + 4, top + 4, 34f + i * 20, -0.62f, 0.62f); stroke()
            }
            alpha = 1f; stroke = ink

            // the broadcast, the aerial and its mast down to the TV
            lineWidth = 1.6f
            dashed(true); trace(VD_F); stroke(); dashed(false)
            arrow(VD_F, if (flash > 0.3f) acc else ink, 10f)
            if (txt) listOf("REGULAR PROGRAM AND TELETEXT", "ONE-WAY BROADCAST SERVICES;", "SAME TRANSMISSION AS TV").forEachIndexed { i, s -> label(s, 200f, 272f + i * 13) }
            lineWidth = 3f
            seg(404f, 244f, 572f, 244f)
            val teeth = groupOf(v, 8, 20)
            for (i in 0 until 24) {
                val tx = 408f + i * 7
                stroke = if (i / 24f < teeth) acc else ink
                lineWidth = 2f
                seg(tx - 5, 236f, tx + 3, 252f)
            }
            stroke = ink; lineWidth = 3f
            seg(572f, 222f, 572f, 266f); seg(572f, 232f, 584f, 220f); seg(572f, 252f, 584f, 240f)
            lineWidth = 1.8f
            seg(520f, 252f, 520f, 556f)
            lineWidth = 3f

            // the computer cabinet: seven lamps (band groups) and a disk that fills with the loudness
            box3(70f, 400f, 120f, 160f, 36f, -30f, paper)
            for (i in 0 until 7) {
                val level = groupOf(v, floor(i * 20 / 7f).toInt(), kotlin.math.ceil((i + 1) * 20 / 7f).toInt(), 1.6f)
                lineWidth = 1.8f
                if (level > 0.05f) { fill = acc; alpha = 0.2f + level * 0.8f; fillRect(82f + i * 14, 414f, 9f, 9f); alpha = 1f }
                strokeRect(82f + i * 14, 414f, 9f, 9f)
            }
            lineWidth = 3f
            disk(130f, 494f, 38f, 11f, 48f, clamp01(m.lv * 1.5f), paper, acc)
            if (txt) { label("COMPUTER", 70f, 326f, 11f); label("(PROVIDING THE PAGES OF THIS", 70f, 341f); label("ALBUM AND ALL THE OTHERS)", 70f, 354f) }

            // the organisations (the file) and the disk that fills with the time played
            box3(80f, 640f, 120f, 50f, 40f, -26f, paper)
            disk(50f, 604f, 22f, 8f, 40f, clamp01(x.progress), paper, acc)
            if (txt) { label("ORGANIZATIONS", 40f, 716f, 10f); label("CONTRIBUTING ${say.fmt.uppercase()}".take(30), 40f, 730f, 10f) }

            // the telephone network: the left and right level
            lineWidth = 1.8f
            dashed(true); strokeRect(270f, 430f, 80f, 110f); dashed(false)
            fill = acc; alpha = 0.85f
            fillRect(292f, 534f - m.l * 36, 8f, m.l * 36); fillRect(306f, 534f - m.r * 36, 8f, m.r * 36)
            alpha = 1f
            if (txt) { label("TELEPHONE", 360f, 456f, 10.5f); label("NETWORK", 360f, 471f, 10.5f) }

            // the flowing lines: solid for a page, dashed for a request, with packets riding them
            fun flow(p: Array<FloatArray>, dashedLine: Boolean) {
                lineWidth = 1.8f
                dashed(dashedLine); trace(p); stroke(); dashed(false)
                val len = plen(p)
                for (j in 0 until 3) {
                    val (px, py) = along(p, (phase * 1.1f + j * len / 3) % len).let { it[0] to it[1] }
                    dot(px, py, 3.6f)
                    if (dashedLine) { keep { fill = paper; fill(); stroke = acc; stroke() } } else { keep { fill = acc; fill() } }
                }
                arrow(p, if (flash > 0.3f) acc else ink, 9f)
                lineWidth = 3f
            }
            flow(VD_A, false); flow(VD_B, true); flow(VD_C, false); flow(VD_D, true); flow(VD_E, false)

            // the telephone: its dial spins on each onset
            fill = paper
            shape(264f, 662f, 264f, 650f, 276f, 630f, 332f, 630f, 346f, 650f, 346f, 662f); closePath(); fill(); stroke()
            beginPath(); moveTo(274f, 634f); quadTo(268f, 610f, 296f, 612f); lineTo(318f, 618f); stroke()
            dot(324f, 642f, 11f); fill(); lineWidth = 2f; stroke()
            fill = ink
            for (i in 0 until 8) {
                val angle = dial + i * PI_F * 2 / 10
                dot(324f + 7 * cos(angle), 642f + 7 * sin(angle), 1.2f); fill()
            }
            if (txt) { label("VIEWDATA CALL", 270f, 692f); label("VIA TELEPHONE", 270f, 705f) }

            // the TV and its teletext page: 20 x 14 cells, each 2 x 3 of mosaic
            fill = paper; lineWidth = 3f
            shape(392f, 570f, 416f, 553f, 560f, 553f, 576f, 570f); closePath(); fill(); stroke()
            beginPath(); roundRect(392f, 568f, 184f, 156f, 8f); fill(); stroke()
            lineWidth = 1.6f
            for (i in 0 until 12) seg(540f, 586f + i * 9, 566f, 586f + i * 9)
            fill = TT_BLACK
            beginPath(); roundRect(402f, 577f, 130f, 130f, 14f); fill()
            lineWidth = 2.4f; stroke()
            val ax = 408f; val ay = 583f; val cw = 5.9f; val rh = 8.43f; val cols = 20
            fun cells(s: String, row: Int, color: Int) {
                fontFace(9f, mono)
                for (i in s.indices) if (s[i] != ' ') text(s[i].toString(), ax + (i + 0.5f) * cw, ay + (row + 0.78f) * rh, color, Paint.Align.CENTER)
            }
            val foot = "${say.fmt.uppercase()} ${say.dr}".take(13).padEnd(13) + (if (say.no.isNotEmpty()) say.no.padStart(cols - 13) else "")
            val subr = 33
            val sw = cw / 2
            val sh = rh / 3
            for (j in 0 until cols * 2) {
                val xx = (j + 0.5f) / 2 / (cols - 1) * 19
                val n = (clamp01(atV(v, xx) * 1.25f) * subr).roundToInt()
                val peak = (clamp01(atV(m.pk, xx) * 1.25f) * subr).roundToInt()
                for (k in 0 until n) {
                    val f = k.toFloat() / subr
                    fill = if (f < 0.55f) TT_GREEN else if (f < 0.82f) TT_YELLOW else TT_RED
                    fillRect(ax + j * sw + 0.25f, ay + 2 * rh + (subr - 1 - k) * sh + 0.25f, sw - 0.5f, sh - 0.5f)
                }
                if (peak > n && peak > 0) { fill = TT_CYAN; fillRect(ax + j * sw + 0.25f, ay + 2 * rh + (subr - peak) * sh + 0.25f, sw - 0.5f, sh - 0.5f) }
            }
            if (txt) {
                fill = TT_BLUE; fillRect(ax, ay, cols * cw, rh)
                cells("P${100 + (say.no.toIntOrNull() ?: 0)} MAGNETITE ${if (length > 0) clock(x.progress * length).padStart(5, ' ') else ""}".take(cols), 0, TT_WHITE)
                val tt = say.title.ifEmpty { say.name }.uppercase()
                val line = if (tt.length <= cols) tt.padStart((cols + tt.length) / 2).padEnd(cols) else {
                    val scroll = "$tt   $tt"
                    val off = floor(phase / 9).toInt() % (tt.length + 3)
                    scroll.substring(off, off + cols)
                }
                cells(line, 1, TT_YELLOW)
                fill = TT_BLACK; fillRect(ax, ay + 13 * rh, cols * cw, rh)
                cells(foot, 13, TT_CYAN)
                label("VIEWDATA", 510f, 518f, 10.5f, Paint.Align.RIGHT)
                label("AND TV", 510f, 533f, 10.5f, Paint.Align.RIGHT)
            }

            // the keypad: a key is pressed on each onset
            fun kp(u: Float, vv: Float) = floatArrayOf(514f + u * (548f - 514f) + vv * (530f - 514f), 746f + u * (741f - 746f) + vv * (785f - 746f))
            fill = paper; lineWidth = 3f
            run { val a = kp(0f, 0f); val b = kp(1f, 0f); val c2 = kp(1f, 1f); val d = kp(0f, 1f); shape(a[0], a[1], b[0], b[1], c2[0], c2[1], d[0], d[1], a[0], a[1]); fill(); stroke() }
            for (i in 0 until 12) {
                val u = 0.2f + (i % 3) * 0.26f
                val vv = 0.12f + (i / 3) * 0.22f
                val a = kp(u, vv); val b = kp(u + 0.16f, vv); val c2 = kp(u + 0.16f, vv + 0.12f); val d = kp(u, vv + 0.12f)
                lineWidth = 1.2f
                shape(a[0], a[1], b[0], b[1], c2[0], c2[1], d[0], d[1], a[0], a[1])
                if (i == key && keyT > 0f) keep { fill = acc; alpha = keyT; fill() }
                stroke()
            }
            lineWidth = 1.4f
            seg(540f, 726f, 540f, 740f)
            if (txt) label("KEYPAD", 500f, 790f, 10.5f, Paint.Align.RIGHT)

            // the legend
            lineWidth = 1.8f
            dashed(true); seg(40f, 770f, 92f, 770f); dashed(false)
            seg(40f, 788f, 92f, 788f)
            if (txt) { label("USER REQUESTS INFORMATION", 106f, 774f, 10.5f); label("COMPUTER RETURNS A VIEWDATA PAGE", 106f, 792f, 10.5f) }
            round = false
            restore()
        }
    }
}

// ---- Prompt ------------------------------------------------------------------------------------------------------

internal class Prompt : Poster() {
    private val m = Meas(30, 0.07f, 0.4f, cool = 0.16f)
    private var seed = 11L
    private val glyphs = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789#@%&$?!*+=/<>"
    private fun rnd(): Float { seed = (seed * 16807) % 2147483647; return (seed / 2147483647.0).toFloat() }
    private fun glyph(): Char = if (rnd() < 0.14f) ' ' else glyphs[floor(rnd() * glyphs.length).toInt()]
    private val grid = Array(BLOCK_ROWS) { CharArray(COLS) { glyph() } }
    private var clk = 0f

    private companion object {
        const val COLS = 40; const val CELL_W = 13f; const val ROW_H = 15f; const val LEFT = 40f; const val TOP = 20f
        const val BLOCK_ROWS = 18; const val BLOCK_AT = 3
    }

    /** Text in the characters the pixel font has: capitals without accents. */
    private fun plain(s: String?) = Normalizer.normalize((s ?: "").uppercase(), Normalizer.Form.NFD).replace(Regex("\\p{Mn}"), "").replace("ß", "SS")

    override fun draw(x: PosterCtx) {
        with(x.g) {
            val look = PosterLook(mapOf("paper" to "#000000"))
            val ink = look["ink"]; val paper = look["paper"]
            val txt = begin(this, x.w, x.h, paper)
            val v = m.update(x)
            clk += x.dt
            // the block: a character changes at a rate that follows its column's band; an onset rewrites a few on the left
            for (c in 0 until COLS) {
                val level = clamp01(atV(v, c / (COLS - 1f) * 29f) * 1.25f)
                val chance = (level * level * 0.7f + (if (m.on > 0.9f && c < 12) 6f else 0f) * 0.04f) * x.dt
                for (row in 0 until BLOCK_ROWS) if (rnd() < chance) grid[row][c] = glyph()
            }
            fontFace(CELL_W, PosterAssets.pixelFont)
            fun baseline(row: Int) = TOP + row * ROW_H + 12
            fun put(row: Int, col: Int, s: String, color: Int = ink) = text(s, LEFT + col * CELL_W, baseline(row), color)
            fun middle(row: Int, s: String) = put(row, (COLS - s.length) / 2, s)
            fun inverse(row: Int, col: Int, width: Int, s: String) {
                fill = ink; fillRect(LEFT + col * CELL_W, TOP + row * ROW_H - 1, width * CELL_W, ROW_H + 1)
                put(row, col + (width - s.length) / 2, s, paper)
            }
            for (row in 0 until BLOCK_ROWS) put(BLOCK_AT + row, 0, String(grid[row]))

            // the time played as a progress bar of characters (a part of the picture, like the block)
            val cells = 34
            val done = (clamp01(x.progress) * cells).roundToInt()
            middle(45, "[${"#".repeat(done)}${".".repeat(cells - done)}]")

            if (txt) {
                val say = Say(x.track)
                val parts = say.fmt.split(" · ")
                val codec = parts.getOrElse(0) { "" }
                val depth = parts.getOrElse(1) { "" }
                val length = x.track?.duration?.toFloat() ?: 0f
                val nm = plain(say.name)
                if (say.album.isNotEmpty()) middle(1, plain("${say.album}${if (say.year.isNotEmpty()) " (${say.year})" else ""}").take(COLS))
                // the title bar: letter-spaced when it fits, a marquee when it does not
                val title = plain(say.title.ifEmpty { say.name })
                val spaced = title.split(" ").joinToString("   ") { it.toCharArray().joinToString(" ") }
                val bar = when {
                    spaced.length <= 32 -> spaced
                    title.length <= 32 -> title
                    else -> { val loop = "$title   $title"; val off = floor(clk * 4).toInt() % (title.length + 3); loop.substring(off, off + 32) }
                }
                inverse(22, 3, 34, bar)
                if (say.title.isNotEmpty()) middle(24, nm.take(COLS))
                middle(27, "WIEDERGABE")
                fun row(y: Int, label: String, value: String) = put(y, 6, label.padEnd(28 - value.length) + value)
                row(29, "FORMAT", "<${plain(codec).ifEmpty { "--" }}>")
                row(30, "ABTASTRATE", "<${plain(depth).replace(" ", "").ifEmpty { "--" }}>")
                row(31, "DYNAMIK", "<${plain(say.dr).ifEmpty { "--" }}>")
                row(32, "DAUER", "<${if (length > 0) clock(length) else "--"}>")
                fun level(v: Float) = "<${"#".repeat((clamp01(v) * 10).roundToInt()).padEnd(10, ' ')}>"
                row(34, "PEGEL LINKS", level(m.l))
                row(35, "PEGEL RECHTS", level(m.r))
                val peakText = "<${dbText(max(x.lv.peakL, x.lv.peakR))}DB>"
                val peakRow = "SPITZE".padEnd(28 - peakText.length) + peakText
                if (m.on > 0.4f) {
                    fill = ink; fillRect(LEFT + 5 * CELL_W, TOP + 36 * ROW_H - 1, 30 * CELL_W, ROW_H + 1)
                    put(36, 6, peakRow, paper)
                } else put(36, 6, peakRow)
                row(38, "NAECHSTER TITEL", "<N>")
                row(39, "PROGRAMM-ENDE", "<E>")
                put(42, 14, "IHRE WAHL ?")
                if (floor(clk * 2).toInt() % 2 == 1) { fill = ink; fillRect(LEFT + 25 * CELL_W + 2, TOP + 42 * ROW_H + 1, CELL_W - 3, ROW_H - 3) }
                if (length > 0) middle(46, "${clock(x.progress * length)} / ${clock(length)}")
                inverse(50, 8, 24, "TITEL ${say.no.ifEmpty { "--" }}  MAGNETITE")
            }
            restore()
        }
    }
}

// ---- Network -----------------------------------------------------------------------------------------------------

// The Berlin U-Bahn map (Wikimedia Commons, File:U-Bahn_Berlin_-_Netzplan.svg by Arbalete, CC BY-SA 4.0), turned a quarter. The path data is a modified
// copy of the drawing's own, so the poster prints the credit (see CREDITS.md).
private const val NW_CREDIT = "Map: Arbalete, CC BY-SA 4.0, Wikimedia Commons (modified)"
private const val MAP_W = 1660.094f
private const val MAP_H = 1003.688f
private const val NW_SCALE = 0.42f

private class NwRoute(val line: String, val width: Float, val dashed: Boolean, val length: Float, val d: String) {
    val path: Path by lazy { PathParser.createPathFromPathData(d) ?: Path() }
    val measure: PathMeasure by lazy { PathMeasure(path, false) }
}

private val NW_ROUTES = listOf(
    NwRoute("U5", 8f, false, 970.5f, "m 5108.9751,1174.2292 c 0,0 591.1222,2e-4 600.2262,2e-4 9.104,0 14.1007,5.8084 14.1007,5.8084 l 56.621,56.6212 c 0,0 4.7336,5.8088 14.1028,5.8089 9.3692,0 14.2304,-5.9221 14.2304,-5.9221 l 171.8271,-171.8269"),
    NwRoute("U8", 8f, false, 666.4f, "M 5386.7812,1537.8275 V 1153.795 c 0,0 0.574,-7.6404 -5.8635,-14.0779 -3.2842,-3.2841 -88.7947,-88.795 -188.5758,-188.57603"),
    NwRoute("U6", 8f, false, 834.2f, "m 5269.0224,1666.8405 v -498.6816 c 0,0 0.574,-7.6403 -5.8635,-14.0778 -6.4375,-6.4375 -226.1742,-226.17432 -226.1742,-226.17432"),
    NwRoute("U6", 4f, true, 153f, "m 5038.7739,929.92796 c -6.4375,-6.4375 -108.1742,-108.17432 -108.1742,-108.17432"),
    NwRoute("U7", 8f, false, 812.7f, "m 5053.8075,1447.536 53.5478,-2e-4 c 0,0 7.6468,0.6134 14.1269,-5.8668 26.3834,-26.3835 76.4211,-76.4207 76.4211,-76.4207 0,0 5.0603,-5.8483 14.1643,-5.8483 0,0 7.8339,-0.4179 14.1464,5.8945 6.3127,6.3125 57.4028,57.7563 57.4028,57.7563 0,0 4.8894,5.8269 14.1392,5.8266 9.2501,3e-4 128.3917,10e-5 128.3917,10e-5 0,0 7.7924,-0.5044 14.136,5.8395 6.3439,6.3436 256.4139,256.4139 256.4139,256.4139"),
    NwRoute("U7", 8f, false, 912.9f, "m 5080.2396,1447.5231 h -232.68 c 0,0 -7.7436,0.5695 -14.1738,-5.8606 -6.4304,-6.4305 -24.5872,-24.5873 -24.5872,-24.5873 0,0 -5.8935,-4.9886 -5.8935,-14.1762 v -243.682 c 0,0 0.464,-7.7892 -5.8116,-14.0647 -6.2755,-6.2756 -12.551,-5.9221 -14.2304,-5.922 -1.6795,-10e-5 -338.7044,-10e-5 -338.7044,-10e-5"),
    NwRoute("U9", 8f, false, 318.8f, "m 4901.2653,1623.5379 c 0,0 34.4558,-34.4558 40.831,-40.8308 6.375,-6.375 5.8321,-14.1121 5.8321,-14.1121 v -245.4154"),
    NwRoute("U9", 8f, false, 485.3f, "m 4947.9284,1334.5938 v -133.1046 c 0,0 -0.663,-7.635 5.8336,-14.1315 l 237.8972,-237.89721"),
    NwRoute("U8", 8f, false, 236f, "m 5084.0858,756.08708 v 78.56239 c 0,0 -0.574,7.64044 5.8635,14.07794 3.2842,3.28422 0.4636,0.46339 100.2446,100.24447"),
    NwRoute("U3", 8f, false, 1007.3f, "m 4664.7384,1671.2355 317.7876,-317.7877 c 0,0 5.2643,-5.8695 14.1706,-5.8695 8.9063,0 542.1549,0 542.1549,0"),
    NwRoute("U1", 8f, false, 659.3f, "m 4880.2939,1335.6442 659.2502,2e-4"),
    NwRoute("U2", 8f, false, 419.9f, "m 4803.3694,1260.4551 c 70.6487,-10e-5 113.8576,-10e-5 117.5589,-10e-5 9.1251,-10e-5 14.1385,5.8283 14.1385,5.8283 l 52.1155,52.1155 c 0,0 5.2646,5.8696 14.1707,5.8694 2.3364,10e-5 40.1973,2e-4 68.1435,2e-4 0,0 7.1953,-0.1555 12.7962,5.4429 l 38.1975,38.1975 c 0,0 5.1767,5.864 14.114,5.8641 8.9374,0 13.6874,-5.375 14.1876,-5.875 0.5001,-0.5001 5.8126,-5.3125 5.8122,-14.1249 0,-3.7054 0.088,-13.6545 0.088,-13.6135"),
    NwRoute("U2", 8f, false, 243.3f, "m 4801.6015,1260.4548 c -101.9792,0 -126.3024,0 -126.3024,0 0,0 -7.4734,0.861 -14.1468,-5.8124 -6.6733,-6.6733 -71.6117,-71.6119 -71.6117,-71.6119"),
    NwRoute("U2", 8f, false, 184.8f, "m 5154.605,1336.1594 c 0,-20.2688 0,-59.938 0,-59.938 0,0 -0.2552,-8.049 5.8383,-14.1429 6.0938,-6.0937 13.1251,-5.8749 14.1876,-5.8749 0.549,0 57.2178,0 93.4176,0"),
    NwRoute("U2", 8f, false, 377.8f, "m 5252.7607,1256.2036 c 31.6906,10e-5 43.7308,0 43.7308,0 0,0 7.9799,0.326 14.1048,-5.7992 6.1249,-6.1251 122.4735,-122.4736 122.4735,-122.4736 0,0 5.8331,-5.1409 5.8332,-14.1123 0,-8.9715 -1e-4,-129.53908 -1e-4,-129.53908"),
    NwRoute("U4", 8f, false, 181.9f, "m 5053.8422,1348.8155 3e-4,181.9355"),
)

// The nine lines are nine band groups, the bass first.
private val NW_ORDER = listOf("U9", "U8", "U7", "U6", "U5", "U3", "U1", "U2", "U4")
private val NW_COLOR = mapOf("U1" to "#7dad4c", "U2" to "#da421e", "U3" to "#007a5b", "U4" to "#f0d722", "U5" to "#7e5330", "U6" to "#8c6dab", "U7" to "#528dba", "U8" to "#224f86", "U9" to "#f3791d").mapValues { Color.of(it.value) }
private val NW_COLOR_DARK = NW_COLOR + mapOf("U3" to Color.of("#13a37c"), "U5" to Color.of("#b98457"), "U8" to Color.of("#4f86c8"))
private val NW_LONGEST: Map<String, NwRoute> = NW_ROUTES.groupBy { it.line }.mapValues { (_, r) -> r.maxByOrNull { it.length }!! }

/** The line the train runs on: one of the nine, fixed for a track. */
internal fun lineFor(track: Track?) = NW_ORDER[(trackHash(track) % NW_ORDER.size).toInt()]

internal class Network : Poster(needsGonio = false) {
    private val bars = Bars(NW_ORDER.size)
    private val pk = FloatArray(NW_ORDER.size)
    private val pos = FloatArray(2)

    override fun draw(x: PosterCtx) {
        with(x.g) {
            val look = PosterLook(emptyMap())
            val paper = look["paper"]; val ink = look["ink"]
            val txt = begin(this, x.w, x.h, paper)
            val lines = if (isDark(paper)) NW_COLOR_DARK else NW_COLOR
            val v = bars.update(x.bands, x.dt, 40f, 0.15f, 0.5f)
            for (i in NW_ORDER.indices) pk[i] = max(v[i], pk[i] - x.dt * 0.18f)
            val train = lineFor(x.track)
            val here = 0.02f + 0.96f * clamp01(x.progress)
            val level = NW_ORDER.mapIndexed { i, l -> l to clamp01(v[i] * 1.5f) }.toMap()
            val held = NW_ORDER.mapIndexed { i, l -> l to clamp01(pk[i] * 1.5f) }.toMap()

            // the routes, dim, then lit from the start as far as the level; the station rings over them, all in the map's own coordinates
            keep {
                translate(300f, 412f); rotate(-PI_F / 2); scale(NW_SCALE); translate(-MAP_W / 2, -MAP_H / 2)
                keep {
                    translate(-4368.7187f, -728.25f)
                    round = true
                    for (route in NW_ROUTES) {
                        lineWidth = route.width
                        dash = if (route.dashed) floatArrayOf(4f, 4f) else null
                        stroke = ink; alpha = 0.16f; strokePath(route.path); alpha = 1f
                        val shown = if (route === NW_LONGEST[train] && route.line == train) max(level.getValue(route.line), here) else level.getValue(route.line)
                        stroke = lines.getValue(route.line)
                        if (route.dashed) { alpha = 0.25f + 0.75f * shown; strokePath(route.path); alpha = 1f }
                        else { dash = floatArrayOf(route.length * shown, route.length * 2); strokePath(route.path) }
                    }
                    dash = null
                    // a dot at each line's held peak, and the train on this track's line
                    for ((line, route) in NW_LONGEST) {
                        val f = clamp01(if (line == train) here else held.getValue(line))
                        val mm = route.measure
                        if (!mm.getPosTan(min(route.length * f, mm.length), pos, null)) continue
                        val isTrain = line == train
                        fill = paper; dot(pos[0], pos[1], if (isTrain) 11f else 6f); fill()
                        lineWidth = if (isTrain) 5f else 3f; stroke = lines.getValue(line); stroke()
                        if (isTrain) { fill = lines.getValue(line); dot(pos[0], pos[1], 3.6f); fill() }
                    }
                }
                PosterAssets.bitmap(R.raw.poster_berlin_ink, x.sync)?.let { image(it, 0f, 0f, MAP_W, MAP_H, ink) }
            }
            round = false

            if (txt) {
                val say = Say(x.track)
                val length = x.track?.duration?.toFloat() ?: 0f
                val muted = fade(ink, 0.6f)
                name(say.title.ifEmpty { say.name }, 30f, 56f, 520f, 34f, ink, 700, spacing = -0.6f)
                put(nonEmpty(" · ", if (say.title.isNotEmpty()) say.name else "", say.album, say.year), 30f, 74f, 12f, muted, 500, spacing = 0.2f)
                put(nonEmpty(" · ", say.fmt, say.dr), 570f, 36f, 12f, muted, 500, Paint.Align.RIGHT, 0.2f)
                // the key: the nine lines as badges, the bass first, a mark under the line this track's train runs on
                NW_ORDER.forEachIndexed { i, line ->
                    val bx = 30f + i * 60
                    fill = lines.getValue(line); beginPath(); roundRect(bx, 768f, 26f, 14f, 3f); fill()
                    put(line, bx + 13, 779f, 10f, 0xFFffffff.toInt(), 700, Paint.Align.CENTER)
                    if (line == train) { fill = ink; shape(bx + 13, 784f, bx + 8, 790f, bx + 18, 790f); closePath(); fill() }
                }
                put("BASS", 30f, 794f, 8f, muted, 600, spacing = 1.5f)
                put("HIGHS", 30f + 8 * 60 + 26, 794f, 8f, muted, 600, Paint.Align.RIGHT, 1.5f)
                put(if (length > 0) "TIME  ${clock(x.progress * length)} / ${clock(length)}" else "", 283f, 794f, 8f, muted, 600, Paint.Align.CENTER, 1.5f)
                put(NW_CREDIT, 570f, 52f, 6.5f, muted, 700, Paint.Align.RIGHT)
            }
            restore()
        }
    }
}

// ---- Junction ----------------------------------------------------------------------------------------------------

// A railway track layout (King's Cross and St Pancras). `res/raw/poster_junction_field.png` is the drawing made into a field, 690 x 1188: for every
// pixel of a track the distance along the connected tracks to each station (red: St Pancras, green: King's Cross, steps of 8 pixels, 255 = not
// connected) and how black the pixel was (blue). Its source and licence are not known (see CREDITS.md).
private const val FIELD_W = 690
private const val FIELD_H = 1188
private const val JN_SCALE = 800f / FIELD_H
private const val JN_LEFT = (600f - FIELD_W * JN_SCALE) / 2
private const val HISTORY = 256
private const val STEP = 0.04f

private class JField(val n: Int, val index: IntArray, val left: IntArray, val right: IntArray, val cover: IntArray)

private var jfield: JField? = null

private fun junctionField(wait: Boolean): JField? {
    jfield?.let { return it }
    val bmp = PosterAssets.bitmap(R.raw.poster_junction_field, wait) ?: return null
    if (bmp.width != FIELD_W || bmp.height != FIELD_H) return null
    val px = IntArray(FIELD_W * FIELD_H)
    bmp.getPixels(px, 0, FIELD_W, 0, 0, FIELD_W, FIELD_H)
    var n = 0
    for (p in px) if ((p and 0xFF) > 12) n++
    val f = JField(n, IntArray(n), IntArray(n), IntArray(n), IntArray(n))
    var k = 0
    for (i in px.indices) {
        val p = px[i]
        if ((p and 0xFF) <= 12) continue
        f.index[k] = i; f.left[k] = (p shr 16) and 0xFF; f.right[k] = (p shr 8) and 0xFF; f.cover[k] = p and 0xFF
        k++
    }
    jfield = f
    return f
}

internal class Junction : Poster() {
    private val bars = Bars(30)
    private val ref = Reference()
    private var l = 0f
    private var r = 0f
    private var acc = 0f
    private val lb = FloatArray(HISTORY); private val lm = FloatArray(HISTORY); private val lh = FloatArray(HISTORY)
    private val rb = FloatArray(HISTORY); private val rm = FloatArray(HISTORY); private val rh = FloatArray(HISTORY)
    private var surface: Bitmap? = null
    private var pixels: IntArray? = null
    private var paperKey = 0
    private var primed = false

    private fun push(v: FloatArray) {
        for (h in arrayOf(lb, lm, lh, rb, rm, rh)) System.arraycopy(h, 0, h, 1, HISTORY - 1)
        val wl = 0.6f + 0.7f * l
        val wr = 0.6f + 0.7f * r
        val bass = groupOf(v, 0, 8) * 0.7f
        val mid = groupOf(v, 8, 20) * 1.1f
        val high = groupOf(v, 20, 30) * 1.5f
        lb[0] = clamp01(bass * wl); lm[0] = clamp01(mid * wl); lh[0] = clamp01(high * wl)
        rb[0] = clamp01(bass * wr); rm[0] = clamp01(mid * wr); rh[0] = clamp01(high * wr)
    }

    override fun draw(x: PosterCtx) {
        with(x.g) {
            val look = PosterLook(mapOf("paper" to "#0b0c0f", "ink" to "#f2f0ea", "a" to "#ff5a36", "b" to "#ffd23f", "c" to "#4fd1ff"))
            val paper = look["paper"]; val ink = look["ink"]
            val txt = begin(this, x.w, x.h, paper)
            val v = bars.update(x.bands, x.dt, 40f, 0.05f, 0.3f)
            val dl = toDb(x.lv.rmsL)
            val dr = toDb(x.lv.rmsR)
            ref.update(max(dl, dr), x.dt)
            val nl = ref.norm(dl, 30f)
            val nr = ref.norm(dr, 30f)
            l = follow(l, nl, x.dt, if (nl > l) 0.05f else 0.3f)
            r = follow(r, nr, x.dt, if (nr > r) 0.05f else 0.3f)
            acc += x.dt
            while (acc >= STEP) { acc -= STEP; push(v) }

            val set = junctionField(x.sync)
            if (set != null) {
                val bmp = surface ?: Bitmap.createBitmap(FIELD_W, FIELD_H, Bitmap.Config.ARGB_8888).also { surface = it }
                val data = pixels ?: IntArray(FIELD_W * FIELD_H).also { pixels = it }
                if (!primed || paperKey != paper) { data.fill(paper or (0xFF shl 24)); paperKey = paper; primed = true }
                val br = (paper shr 16) and 0xFF; val bg = (paper shr 8) and 0xFF; val bb = paper and 0xFF
                val unlit = lerpColor(paper, ink, 0.45f)
                val lr = (unlit shr 16) and 0xFF; val lg = (unlit shr 8) and 0xFF; val lbl = unlit and 0xFF
                val c1 = look["a"]; val c2 = look["b"]; val c3 = look["c"]
                val r1 = (c1 shr 16) and 0xFF; val g1 = (c1 shr 8) and 0xFF; val b1 = c1 and 0xFF
                val r2 = (c2 shr 16) and 0xFF; val g2 = (c2 shr 8) and 0xFF; val b2 = c2 and 0xFF
                val r3 = (c3 shr 16) and 0xFF; val g3 = (c3 shr 8) and 0xFF; val b3 = c3 and 0xFF
                val stride = if (viewScale < 0.25f) 3 else 1 // a thumbnail needs a third of the pixels
                var k = 0
                while (k < set.n) {
                    val il = set.left[k]; val ir = set.right[k]
                    val cover = set.cover[k] / 255f
                    var bass = 0f; var mid = 0f; var high = 0f
                    if (il < 255) { bass = lb[il]; mid = lm[il]; high = lh[il] }
                    if (ir < 255) { bass = max(bass, rb[ir]); mid = max(mid, rm[ir]); high = max(high, rh[ir]) }
                    val strength = min(1f, max(0f, max(bass, max(mid, high)) - 0.3f) * 1.9f)
                    val b2p = bass * bass; val m2p = mid * mid; val h2p = high * high
                    val wb = b2p * b2p * b2p; val wm = m2p * m2p * m2p; val wh = h2p * h2p * h2p
                    val total = wb + wm + wh + 1e-6f
                    val red = br * (1 - cover) + (lr * (1 - strength) + (r1 * wb + r2 * wm + r3 * wh) / total * strength) * cover
                    val grn = bg * (1 - cover) + (lg * (1 - strength) + (g1 * wb + g2 * wm + g3 * wh) / total * strength) * cover
                    val blu = bb * (1 - cover) + (lbl * (1 - strength) + (b1 * wb + b2 * wm + b3 * wh) / total * strength) * cover
                    data[set.index[k]] = (0xFF shl 24) or (red.toInt().coerceIn(0, 255) shl 16) or (grn.toInt().coerceIn(0, 255) shl 8) or blu.toInt().coerceIn(0, 255)
                    k += stride
                }
                bmp.setPixels(data, 0, FIELD_W, 0, 0, FIELD_W, FIELD_H)
                image(bmp, JN_LEFT, 0f, FIELD_W * JN_SCALE, FIELD_H * JN_SCALE)
            }

            if (txt) {
                val say = Say(x.track)
                val muted = lerpColor(paper, ink, 0.55f)
                val length = x.track?.duration?.toFloat() ?: 0f
                name(say.title.ifEmpty { say.name }, 22f, 686f, 200f, 34f, ink, 700, spacing = -0.6f)
                put(if (say.title.isNotEmpty()) say.name else "", 22f, 712f, 12f, muted)
                put(nonEmpty(" · ", say.album, say.year), 22f, 727f, 11f, muted)
                put(nonEmpty(" · ", say.fmt, say.dr), 22f, 742f, 11f, muted)
                put(if (length > 0) "${clock(x.progress * length)} / ${clock(length)}" else "", 22f, 757f, 11f, muted)
            }
            restore()
        }
    }
}
