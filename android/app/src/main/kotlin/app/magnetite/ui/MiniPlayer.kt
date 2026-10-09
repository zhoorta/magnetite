package app.magnetite.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectHorizontalDragGestures
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import app.magnetite.player.PlayerController
import kotlinx.coroutines.delay

private fun clock(ms: Long): String {
    val s = (ms / 1000).toInt().coerceAtLeast(0)
    return "%d:%02d".format(s / 60, s % 60)
}

/** Bar at the bottom of every screen once something has been played: title, controls and a seek bar. */
@Composable
fun MiniPlayer(player: PlayerController, onOpen: () -> Unit) {
    val title = player.title ?: return
    var dragging by remember { mutableStateOf<Float?>(null) }

    LaunchedEffect(player.isPlaying) {
        player.refreshPosition()
        while (player.isPlaying) {
            delay(500)
            player.refreshPosition()
        }
    }

    Surface(Modifier.fillMaxWidth(), color = MaterialTheme.colorScheme.surfaceVariant) {
        Column {
            MeterPanel(Modifier.padding(horizontal = 12.dp, vertical = 6.dp)) { LevelBars(player.isPlaying) }
            Row(Modifier.padding(start = 16.dp, end = 8.dp, top = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f).clickable(onClick = onOpen)) {
                    Text(title, maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.titleSmall)
                    player.artist?.let { Text(it, maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.bodySmall) }
                }
                TransportButton(Icon.Previous, "Previous", { player.previous() })
                TransportButton(if (player.isPlaying) Icon.Pause else Icon.Play, if (player.isPlaying) "Pause" else "Play", { player.toggle() })
                TransportButton(Icon.Next, "Next", { player.next() })
            }
            val duration = player.durationMs.coerceAtLeast(1L)
            val fraction = dragging ?: (player.positionMs.toFloat() / duration)
            Row(Modifier.padding(horizontal = 16.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(clock((fraction * duration).toLong()), style = MaterialTheme.typography.labelSmall)
                SeekBar(
                    fraction.coerceIn(0f, 1f), player.durationMs > 0,
                    onDrag = { dragging = it },
                    onDone = { f -> dragging = null; f?.let { player.seekTo((it * duration).toLong()) } },
                    modifier = Modifier.weight(1f).padding(horizontal = 8.dp),
                )
                Text(clock(player.durationMs), style = MaterialTheme.typography.labelSmall)
            }
        }
    }
}

/** A thin seek bar: tap to jump, drag to scrub; the seek happens when the finger lifts. */
@Composable
private fun SeekBar(value: Float, enabled: Boolean, onDrag: (Float) -> Unit, onDone: (Float?) -> Unit, modifier: Modifier = Modifier) {
    var last by remember { mutableStateOf<Float?>(null) }
    Box(
        modifier.height(24.dp).pointerInput(enabled) {
            if (!enabled) return@pointerInput
            detectTapGestures { o -> onDone((o.x / size.width).coerceIn(0f, 1f)) }
        }.pointerInput(enabled) {
            if (!enabled) return@pointerInput
            detectHorizontalDragGestures(
                onDragStart = { o -> last = (o.x / size.width).coerceIn(0f, 1f).also(onDrag) },
                onHorizontalDrag = { change, _ -> last = (change.position.x / size.width).coerceIn(0f, 1f).also(onDrag) },
                onDragEnd = { onDone(last) },
                onDragCancel = { onDone(null) },
            )
        },
        contentAlignment = Alignment.CenterStart,
    ) {
        Box(Modifier.fillMaxWidth().height(3.dp).background(MaterialTheme.colorScheme.outlineVariant))
        Box(Modifier.fillMaxWidth(value).height(3.dp).background(MaterialTheme.colorScheme.primary))
    }
}

private const val FLOOR_DB = -60f

/** dBFS to 0..1 on a -60..0 scale. */
private fun level(v: Float): Float = if (v <= 0f) 0f else ((20f * kotlin.math.log10(v) - FLOOR_DB) / -FLOOR_DB).coerceIn(0f, 1f)

/**
 * Stereo LED-style level bars from [MeterTap]: RMS lights the segments, the peak shows as a single segment that falls back slowly.
 * Redrawn every frame only while playing; paused or stopped, the bars fall to nothing and drawing stops.
 */
@Composable
private fun LevelBars(playing: Boolean, modifier: Modifier = Modifier) {
    var rms by remember { mutableStateOf(floatArrayOf(0f, 0f)) }
    var peak by remember { mutableStateOf(floatArrayOf(0f, 0f)) }
    LaunchedEffect(playing) {
        var last = 0L
        while (true) {
            val now = androidx.compose.runtime.withFrameNanos { it }
            val dt = if (last == 0L) 0f else (now - last) / 1e9f
            last = now
            val l = app.magnetite.player.MeterTap.snapshot()
            val fresh = playing && System.nanoTime() - l.atNanos < 250_000_000L
            val tr = floatArrayOf(if (fresh) level(l.rmsL) else 0f, if (fresh) level(l.rmsR) else 0f)
            val tp = floatArrayOf(if (fresh) level(l.peakL) else 0f, if (fresh) level(l.peakR) else 0f)
            // Rise at once, fall at a fixed rate.
            val fall = dt * 1.2f
            rms = floatArrayOf(maxOf(tr[0], rms[0] - fall), maxOf(tr[1], rms[1] - fall))
            peak = floatArrayOf(maxOf(tp[0], peak[0] - fall * 0.5f), maxOf(tp[1], peak[1] - fall * 0.5f))
            if (!playing && rms.all { it <= 0f } && peak.all { it <= 0f }) break
        }
    }
    val off = androidx.compose.ui.graphics.Color(0xFF1E2622)
    val ink = androidx.compose.ui.graphics.Color(0xFF8A9A92)
    val label = MaterialTheme.typography.labelSmall
    Row(modifier, verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.padding(end = 6.dp), verticalArrangement = androidx.compose.foundation.layout.Arrangement.spacedBy(2.dp)) {
            Text("L", style = label, color = ink)
            Text("R", style = label, color = ink)
        }
        // Segmented like an LED meter: green up to -18 dB, amber to -6 dB, red above. Lit segments follow the RMS level; the peak segment holds.
        androidx.compose.foundation.Canvas(Modifier.weight(1f).height(18.dp)) {
            val n = 40
            val gap = 2f
            val segW = (size.width - gap * (n - 1)) / n
            val h = size.height / 2 - 1.5f
            for (ch in 0..1) {
                val y = ch * (h + 3f)
                for (i in 0 until n) {
                    val pos = (i + 1f) / n
                    val db = FLOOR_DB + pos * -FLOOR_DB
                    val color = meterColor(db)
                    val lit = rms[ch] >= pos - 0.5f / n || (peak[ch] >= pos - 0.5f / n && peak[ch] < pos + 0.5f / n)
                    drawRect(
                        if (lit) color else off,
                        androidx.compose.ui.geometry.Offset(i * (segW + gap), y),
                        androidx.compose.ui.geometry.Size(segW, h),
                    )
                }
            }
        }
    }
}
