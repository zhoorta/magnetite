package app.magnetite.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.focusable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
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
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.input.key.Key
import androidx.compose.ui.input.key.KeyEventType
import androidx.compose.ui.input.key.key
import androidx.compose.ui.input.key.onKeyEvent
import androidx.compose.ui.input.key.type
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import app.magnetite.player.PlayerController
import kotlinx.coroutines.delay

private fun clock(ms: Long): String {
    val s = (ms / 1000).toInt().coerceAtLeast(0)
    return "%d:%02d".format(s / 60, s % 60)
}

/**
 * The player along the bottom of the TV: title, artist and the format line on the left (select it to open Now Playing), the seek bar under
 * them (focus it and press left or right to seek 10 s), the first chosen meter in the middle and the transport buttons on the right.
 */
@Composable
fun TvPlayerStrip(player: PlayerController, info: Int, meters: List<String>, onOpen: () -> Unit) {
    val title = player.title ?: return

    LaunchedEffect(player.isPlaying) {
        player.refreshPosition()
        while (player.isPlaying) {
            delay(500)
            player.refreshPosition()
        }
    }

    Surface(Modifier.fillMaxWidth(), color = MaterialTheme.colorScheme.surfaceVariant) {
        Row(
            Modifier.fillMaxWidth().height(136.dp).padding(horizontal = 16.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.SpaceBetween) {
                Column(Modifier.fillMaxWidth().tvFocus().clickable(onClick = onOpen).padding(horizontal = 6.dp)) {
                    Text(title, maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.titleSmall)
                    player.artist?.let { Text(it, maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.primary) }
                    player.current?.let { t ->
                        val line = Info.line(t, info)
                        if (line.isNotEmpty()) Text(line, maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.labelSmall, color = Palette.Muted)
                    }
                }
                val duration = player.durationMs.coerceAtLeast(1L)
                var focused by remember { mutableStateOf(false) }
                Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(horizontal = 6.dp)) {
                    Text(clock(player.positionMs), style = MaterialTheme.typography.labelSmall)
                    Box(
                        Modifier.weight(1f).height(28.dp).padding(horizontal = 8.dp)
                            .onFocusChanged { focused = it.isFocused }
                            .focusable()
                            .onKeyEvent { e ->
                                if (e.type != KeyEventType.KeyDown) return@onKeyEvent false
                                when (e.key) {
                                    Key.DirectionLeft -> { player.seekTo((player.positionMs - 10_000).coerceAtLeast(0)); true }
                                    Key.DirectionRight -> { player.seekTo((player.positionMs + 10_000).coerceAtMost(duration)); true }
                                    else -> false
                                }
                            },
                        contentAlignment = Alignment.CenterStart,
                    ) {
                        val h = if (focused) 8.dp else 3.dp
                        Box(Modifier.fillMaxWidth().height(h).background(MaterialTheme.colorScheme.outlineVariant))
                        Box(Modifier.fillMaxWidth((player.positionMs.toFloat() / duration).coerceIn(0f, 1f)).height(h).background(MaterialTheme.colorScheme.primary))
                    }
                    Text(clock(player.durationMs), style = MaterialTheme.typography.labelSmall)
                }
            }
            if (meters.isNotEmpty()) {
                MeterPanel(Modifier.width(440.dp).fillMaxHeight()) { MeterView(meters.first(), player.isPlaying, fill = true) }
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                TransportButton(Icon.Previous, "Previous", { player.previous() }, size = 52.dp)
                TransportButton(if (player.isPlaying) Icon.Pause else Icon.Play, if (player.isPlaying) "Pause" else "Play", { player.toggle() }, size = 52.dp)
                TransportButton(Icon.Next, "Next", { player.next() }, size = 52.dp)
            }
        }
    }
}
