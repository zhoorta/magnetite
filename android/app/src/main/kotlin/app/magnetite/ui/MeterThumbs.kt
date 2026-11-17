package app.magnetite.ui

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Paint
import android.graphics.Path
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.toArgb
import kotlin.math.PI
import kotlin.math.cos
import kotlin.math.sin
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

// Pictures of the meters for the picker, like the web's meter menu. Posters are drawn by the real poster code on a made-up signal (text off);
// the other meters are small stand-ins in the theme's colours (their real drawing reads the live audio). Redone when the theme changes.

const val THUMB_W = 224
const val THUMB_H = 128
private const val POSTER_W = 150
private const val POSTER_H = 200

@Composable
fun rememberMeterThumbs(): Map<String, ImageBitmap> {
    val look = Looking.current
    var pictures by remember(look) { mutableStateOf(emptyMap<String, ImageBitmap>()) }
    LaunchedEffect(look) {
        for (info in MeterCatalog) {
            val bmp = withContext(Dispatchers.Default) { runCatching { if (info.id.startsWith("poster-")) posterThumb(info.id) else plainThumb(info.id) }.getOrNull() } ?: continue
            pictures = pictures + (info.id to bmp.asImageBitmap())
        }
    }
    return pictures
}

private fun posterThumb(id: String): Bitmap {
    val bmp = Bitmap.createBitmap(POSTER_W, POSTER_H, Bitmap.Config.ARGB_8888)
    val g = Cv().also { it.c = Canvas(bmp) }
    val poster = Posters.make(id)!!
    val ctx = PosterCtx().also { it.g = g; it.w = POSTER_W.toFloat(); it.h = POSTER_H.toFloat(); it.progress = 0.4f }
    for (k in 0 until 1024) { ctx.gonioL[k] = 0.4f * sin(k * 0.05f); ctx.gonioR[k] = 0.3f * sin(k * 0.05f + 0.5f) }
    for (frame in 0 until 90) {
        val t = frame / 30f
        ctx.dt = 1 / 30f
        ctx.bands = FloatArray(31) { i -> -80f + 46f * (0.5f + 0.5f * sin(t * (0.9f + i * 0.13f) + i * 1.7f)) * (1 - i / 62f) }
        val l = 0.07f + 0.05f * sin(t * 1.3f)
        ctx.lv = Lv(l, l * 0.9f, l * 3, l * 2.7f, true)
        poster.draw(ctx)
    }
    return bmp
}

private fun plainThumb(id: String): Bitmap {
    val bmp = Bitmap.createBitmap(THUMB_W, THUMB_H, Bitmap.Config.ARGB_8888)
    val c = Canvas(bmp)
    val w = THUMB_W.toFloat(); val h = THUMB_H.toFloat()
    val accent = Palette.Accent.toArgb(); val accent2 = Palette.Accent2.toArgb(); val muted = Palette.Muted.toArgb(); val text = Palette.Text.toArgb(); val red = Palette.Red.toArgb()
    c.drawColor(0xFF0A0C0B.toInt())
    val p = Paint(Paint.ANTI_ALIAS_FLAG)
    fun fill(col: Int, a: Float = 1f) { p.style = Paint.Style.FILL; p.color = col; p.alpha = (a * 255).toInt() }
    fun line(col: Int, width: Float, a: Float = 1f) { p.style = Paint.Style.STROKE; p.color = col; p.strokeWidth = width; p.alpha = (a * 255).toInt() }
    when (id) {
        "vu-wide" -> {
            // the real meter's look: two shallow windows with a long arc scale, ticks, a red zone and a needle that pivots far below
            val gap = h * 0.06f
            val bw = minOf(w - gap * 2, (h - gap * 3) / 2 * 4.2f); val bh = bw / 4.2f
            val theta = 14 * PI / 180; val r = (bw * 0.43 / sin(theta)).toFloat()
            listOf(0.62f, 0.5f).forEachIndexed { i, v ->
                val x = (w - bw) / 2; val y = (h - (bh * 2 + gap)) / 2 + i * (bh + gap)
                val cx = x + bw / 2; val py = y + bh * 0.42f + r
                fun ang(d: Float) = -theta + 2 * theta * ((d + 30f) / 33f)
                c.save(); c.clipRect(x, y, x + bw, y + bh)
                fill(Palette.Surface.toArgb()); c.drawRoundRect(x, y, x + bw, y + bh, bh * 0.1f, bh * 0.1f, p)
                line(red, bh * 0.07f); val a0 = ang(-6f) - PI / 2; val a1 = ang(3f) - PI / 2
                c.drawArc(cx - r, py - r, cx + r, py + r, Math.toDegrees(a0).toFloat(), Math.toDegrees(a1 - a0).toFloat(), false, p)
                var d = -30f
                while (d <= 3f) {
                    val major = d.toInt() % 6 == 0
                    line(text, if (major) bh * 0.03f else bh * 0.015f)
                    val s1 = r - bh * 0.05f; val s2 = r - bh * (if (major) 0.16f else 0.11f)
                    c.drawLine(cx + (sin(ang(d)) * s1).toFloat(), py - (cos(ang(d)) * s1).toFloat(), cx + (sin(ang(d)) * s2).toFloat(), py - (cos(ang(d)) * s2).toFloat(), p)
                    d += 2f
                }
                fill(text); p.textSize = bh * 0.24f; p.textAlign = Paint.Align.LEFT; c.drawText(if (i == 0) "L" else "R", x + bw * 0.035f, y + bh * 0.3f, p)
                fill(red, 0.18f); c.drawCircle(x + bw * 0.965f, y + bh * 0.2f, bh * 0.05f, p)
                val a = -theta + 2 * theta * v
                line(text, maxOf(1.5f, bh * 0.03f))
                c.drawLine(cx + (sin(a) * (r - bh * 1.3f)).toFloat(), py - (cos(a) * (r - bh * 1.3f)).toFloat(), cx + (sin(a) * (r + bh * 0.06f)).toFloat(), py - (cos(a) * (r + bh * 0.06f)).toFloat(), p)
                c.restore()
            }
        }
        "vu-needle" -> listOf(0.3f, 0.42f).forEachIndexed { i, v ->
            val cx = w * (0.27f + i * 0.46f); val cy = h * 0.88f; val r = w * 0.2f
            line(muted, 2f); c.drawArc(cx - r, cy - r, cx + r, cy + r, 228f, 84f, false, p)
            val a = (-48 + 96 * v) * PI / 180
            line(text, 2.5f); c.drawLine(cx, cy, cx + (sin(a) * r * 1.2).toFloat(), cy - (cos(a) * r * 1.2).toFloat(), p)
        }
        "vu-leds" -> for (row in 0 until 2) for (k in 0 until 16) {
            val on = k < if (row == 0) 11 else 9
            fill(if (k > 12) red else if (k > 9) accent2 else accent, if (on) 1f else 0.15f)
            c.drawRect(w * 0.1f + k * w * 0.05f, h * (0.28f + row * 0.3f), w * 0.1f + k * w * 0.05f + w * 0.04f, h * (0.28f + row * 0.3f) + h * 0.2f, p)
        }
        "magic-eye" -> listOf(0.5f, 0.35f).forEachIndexed { i, v ->
            val cx = w * (0.3f + i * 0.4f); val cy = h * 0.5f; val r = h * 0.32f
            fill(muted, 0.2f); c.drawCircle(cx, cy, r, p)
            fill(accent); c.drawArc(cx - r, cy - r, cx + r, cy + r, -90 - 180 * (1 - v), 360 * (1 - v), true, p)
            fill(0xFF0A0C0B.toInt()); c.drawCircle(cx, cy, r * 0.25f, p)
        }
        "scope" -> {
            val path = Path()
            for (x in 0..80) { val t = x / 80f; val y = h * 0.5f - h * 0.28f * (sin(t * 6 * PI) * 0.7 + sin(t * 17 * PI) * 0.3).toFloat(); if (x == 0) path.moveTo(w * 0.06f, y) else path.lineTo(w * (0.06f + 0.88f * t), y) }
            line(muted, 1f, 0.4f); c.drawLine(w * 0.06f, h * 0.5f, w * 0.94f, h * 0.5f, p)
            line(accent, 2.5f); c.drawPath(path, p)
        }
        "goniometer" -> {
            val cx = w / 2; val cy = h / 2; val r = h * 0.42f
            line(muted, 1f, 0.5f); c.drawCircle(cx, cy, r, p); c.drawLine(cx, cy - r, cx, cy + r, p); c.drawLine(cx - r, cy, cx + r, cy, p)
            fill(accent, 0.7f)
            for (k in 0 until 220) { val t = k * 0.21; val m = sin(t * 1.3) * 0.7 * (0.6 + 0.4 * sin(t * 0.37)); val s = sin(t * 2.9 + 1) * 0.28; c.drawCircle(cx + (s * r).toFloat(), cy - (m * r).toFloat(), 1.8f, p) }
        }
        "loudness" -> listOf(0.62f, 0.55f, 0.5f).forEachIndexed { i, v ->
            val y = h * (0.18f + i * 0.24f)
            fill(muted, 0.25f); c.drawRect(w * 0.08f, y, w * 0.7f, y + h * 0.1f, p)
            fill(if (i == 2) accent2 else accent); c.drawRect(w * 0.08f, y, w * (0.08f + 0.62f * v), y + h * 0.1f, p)
            fill(text); p.textSize = h * 0.16f; p.textAlign = Paint.Align.RIGHT; c.drawText("-${14 + i}.${i + 2}", w * 0.94f, y + h * 0.12f, p)
        }
        "third-octave" -> for (i in 0 until 31) {
            val v = (0.15f + 0.8f * (0.5f + 0.5f * sin(i * 0.55f + 0.6f)) * (1 - i / 60f)).coerceIn(0f, 1f)
            val segs = (v * 10).toInt()
            for (s in 0 until segs) { fill(if (s > 8) accent2 else accent); val x = w * 0.06f + i * w * 0.0295f; val y = h * 0.92f - (s + 1) * h * 0.083f; c.drawRect(x, y, x + w * 0.02f, y + h * 0.065f, p) }
        }
        "spectrogram" -> for (x in 0 until 56) for (y in 0 until 32) {
            val v = (0.5f + 0.5f * sin(x * 0.35f + y * 0.5f)) * (1 - y / 38f) * (0.6f + 0.4f * sin(x * 0.9f + 2))
            fill(androidx.compose.ui.graphics.lerp(Palette.Bg, if (v > 0.55f) Palette.Accent2 else Palette.Accent, (v * 1.4f).coerceIn(0f, 1f)).toArgb())
            c.drawRect(w * 0.04f + x * w * 0.0171f, h * 0.04f + y * h * 0.03f, w * 0.04f + (x + 1) * w * 0.0171f, h * 0.04f + (y + 1) * h * 0.03f, p)
        }
    }
    return bmp
}
