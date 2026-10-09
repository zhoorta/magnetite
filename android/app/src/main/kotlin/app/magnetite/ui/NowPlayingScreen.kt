package app.magnetite.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Slider
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import app.magnetite.player.PlayerController
import kotlinx.coroutines.delay

private fun clock(ms: Long): String {
    val s = (ms / 1000).toInt().coerceAtLeast(0)
    return "%d:%02d".format(s / 60, s % 60)
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NowPlayingScreen(player: PlayerController, meters: List<String>, onPickMeters: () -> Unit, onClose: () -> Unit) {
    BackHandler(onBack = onClose)

    // While the finger is on the slider, show its value instead of the playing position.
    var dragging by remember { mutableStateOf<Float?>(null) }

    LaunchedEffect(player.isPlaying) {
        player.refreshPosition()
        while (player.isPlaying) {
            delay(500)
            player.refreshPosition()
        }
    }

    Scaffold(topBar = {
        TopAppBar(title = { Text("Now playing") }, navigationIcon = { TransportButton(Icon.Back, "Back", onClose) }, actions = { TransportButton(Icon.Meters, "Meters", onPickMeters) })
    }) { padding ->
        if (meters.isNotEmpty()) {
            // With meters chosen this page is the meters, big; the mini player below keeps the title and controls.
            androidx.compose.foundation.layout.BoxWithConstraints(Modifier.fillMaxSize().padding(padding).padding(horizontal = 12.dp, vertical = 8.dp)) {
                val gap = 8.dp
                val room = this.maxHeight
                Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(gap, Alignment.CenterVertically)) {
                    meters.forEach { id ->
                        // Fill the room, but not past what the meter can use: a pair of wide meters in a full screen cell would float in empty space.
                        val wanted = (MeterCatalog.firstOrNull { it.id == id }?.heightDp ?: 150) * 2.2f
                        val h = minOf((room - gap * (meters.size - 1)) / meters.size, wanted.dp)
                        MeterPanel(Modifier.fillMaxWidth().height(h)) { MeterView(id, player.isPlaying, fill = true) }
                    }
                }
            }
            return@Scaffold
        }
        Column(
            Modifier.fillMaxSize().padding(padding).padding(horizontal = 24.dp).verticalScroll(androidx.compose.foundation.rememberScrollState()),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Spacer(Modifier.height(16.dp))
            Cover(player.coverUrl, Modifier.fillMaxWidth(0.6f))
            Spacer(Modifier.height(24.dp))
            Text(player.title ?: "", style = MaterialTheme.typography.headlineSmall, textAlign = TextAlign.Center)
            Text(player.artist ?: "", style = MaterialTheme.typography.bodyLarge, textAlign = TextAlign.Center)
            Spacer(Modifier.height(16.dp))

            val duration = player.durationMs.coerceAtLeast(1L)
            val shown = dragging ?: (player.positionMs.toFloat() / duration)
            Slider(
                value = shown.coerceIn(0f, 1f),
                onValueChange = { dragging = it },
                onValueChangeFinished = {
                    dragging?.let { player.seekTo((it * duration).toLong()) }
                    dragging = null
                },
                enabled = player.durationMs > 0,
            )
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text(clock((shown * duration).toLong()), style = MaterialTheme.typography.bodySmall)
                Text(clock(player.durationMs), style = MaterialTheme.typography.bodySmall)
            }

            Spacer(Modifier.height(16.dp))
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                TransportButton(Icon.Previous, "Previous", { player.previous() }, size = 64.dp)
                TransportButton(if (player.isPlaying) Icon.Pause else Icon.Play, if (player.isPlaying) "Pause" else "Play", { player.toggle() }, size = 80.dp)
                TransportButton(Icon.Next, "Next", { player.next() }, size = 64.dp)
            }
        }
    }
}
