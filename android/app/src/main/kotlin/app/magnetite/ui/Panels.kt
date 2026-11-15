package app.magnetite.ui

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.withFrameNanos
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.unit.dp
import app.magnetite.player.MeterTap
import app.magnetite.player.Spectrum
import androidx.compose.foundation.background

val MeterGreen = Color(0xFF3FCF6D)
val MeterAmber = Color(0xFFF5A524)
val MeterRed = Color(0xFFE5484D)
private val PanelBg = Color(0xFF0A0C0B)

/** The dark recessed panel every meter sits in. */
@Composable
fun MeterPanel(modifier: Modifier = Modifier, content: @Composable () -> Unit) {
    Box(modifier.clip(RoundedCornerShape(6.dp)).background(PanelBg).padding(horizontal = 10.dp, vertical = 8.dp)) { content() }
}

/** A small dark panel for one meter or poster, the thumbnail in the player bars: no padding, the meter fills it. */
@Composable
fun MeterThumb(modifier: Modifier = Modifier, content: @Composable () -> Unit) {
    Box(modifier.clip(RoundedCornerShape(4.dp)).background(PanelBg)) { content() }
}

/** Colour for a position on a -60..0 dB scale: green, amber from -18 dB, red from -6 dB. */
fun meterColor(db: Float): Color = when {
    db > -6f -> MeterRed
    db > -18f -> MeterAmber
    else -> MeterGreen
}

/** Runs [step] every frame while [active], and a little longer until [settled] says the picture has gone still. */
@Composable
private fun Frames(active: Boolean, settled: () -> Boolean, step: (dt: Float) -> Unit) {
    LaunchedEffect(active) {
        var last = 0L
        while (true) {
            val now = withFrameNanos { it }
            val dt = if (last == 0L) 0f else (now - last) / 1e9f
            last = now
            step(dt)
            if (!active && settled()) break
        }
    }
}

/** Spectrum bars from [MeterTap], log-spaced, each bar coloured by its height. Rise at once, fall at a fixed rate. */
@Composable
fun SpectrumPanel(playing: Boolean, modifier: Modifier = Modifier) {
    val bands = 48
    val spectrum = remember { Spectrum(bands) }
    val raw = remember { FloatArray(bands) }
    var shown by remember { mutableStateOf(FloatArray(bands)) }
    Frames(playing, { shown.all { it <= 0f } }) { dt ->
        val fresh = playing && System.nanoTime() - MeterTap.snapshot().atNanos < 250_000_000L
        if (fresh) spectrum.read(raw) else raw.fill(-120f)
        shown = FloatArray(bands) { i -> maxOf(((raw[i] + 80f) / 80f).coerceIn(0f, 1f), shown[i] - dt * 1.2f) }
    }
    MeterPanel(modifier.fillMaxWidth()) {
        Canvas(Modifier.fillMaxWidth().height(96.dp)) {
            val gap = 2f
            val w = (size.width - gap * (bands - 1)) / bands
            val rows = 24
            val rowH = size.height / rows
            for (b in 0 until bands) {
                val lit = (shown[b] * rows).toInt()
                for (r in 0 until lit) {
                    // Scale of the bar: 0..1 of -80..0 dB, coloured on the same dB steps as the level bars.
                    val db = -80f + (r + 1f) / rows * 80f
                    drawRect(meterColor(db), Offset(b * (w + gap), size.height - (r + 1) * rowH), Size(w, rowH - 1.5f))
                }
            }
        }
    }
}

/** Waveform of the latest ~46 ms, drawn as a green line on a faint centre line. */
@Composable
fun ScopePanel(playing: Boolean, modifier: Modifier = Modifier) {
    val n = 1024
    val buf = remember { FloatArray(n) }
    var tick by remember { mutableStateOf(0) }
    Frames(playing, { true }) { _ ->
        if (playing && System.nanoTime() - MeterTap.snapshot().atNanos < 250_000_000L) MeterTap.recent(buf) else buf.fill(0f)
        tick++
    }
    MeterPanel(modifier.fillMaxWidth()) {
        Canvas(Modifier.fillMaxWidth().height(56.dp)) {
            tick // read so the canvas redraws every frame
            val mid = size.height / 2
            drawLine(Color(0xFF1E2622), Offset(0f, mid), Offset(size.width, mid), 1f)
            val path = Path()
            for (i in 0 until n) {
                val x = i * size.width / (n - 1)
                val y = mid - buf[i].coerceIn(-1f, 1f) * mid
                if (i == 0) path.moveTo(x, y) else path.lineTo(x, y)
            }
            drawPath(path, MeterGreen, style = Stroke(width = 2f))
        }
    }
}
