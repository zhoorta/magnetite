package app.magnetite.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.clickable
import androidx.compose.ui.focus.focusRequester
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.verticalScroll
import androidx.compose.ui.input.pointer.PointerEventPass
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.input.pointer.positionChange
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.fillMaxHeight
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
fun NowPlayingScreen(
    player: PlayerController, meters: List<String>, onPickMeters: () -> Unit, onClose: () -> Unit, onOpenSource: () -> Unit,
    fullScreen: Boolean = false, onFullScreen: (() -> Unit)? = null, info: Int = 2,
) {
    BackHandler(onBack = onClose)
    // On a TV the page opens with the focus on "Full screen", the one thing to do here with a remote
    val fullFocus = remember { androidx.compose.ui.focus.FocusRequester() }
    val onTv = LocalTv.current
    LaunchedEffect(onFullScreen != null) { if (onFullScreen != null && onTv) { delay(200); runCatching { fullFocus.requestFocus() } } }
    val scroll = androidx.compose.foundation.rememberScrollState()

    // While the finger is on the slider, show its value instead of the playing position.
    var dragging by remember { mutableStateOf<Float?>(null) }

    LaunchedEffect(player.isPlaying) {
        player.refreshPosition()
        while (player.isPlaying) {
            delay(500)
            player.refreshPosition()
        }
    }

    // A swipe down anywhere closes the page (back to the mini player). Watched in the Initial pass without consuming, so the slider and the
    // scrolling still work; a page scrolled down only closes once it is back at the top.
    val closeSwipe = Modifier.pointerInput(Unit) {
        val threshold = 96.dp.toPx()
        awaitEachGesture {
            val down = awaitFirstDown(requireUnconsumed = false, pass = PointerEventPass.Initial)
            val atTop = scroll.value == 0
            var dx = 0f
            var dy = 0f
            do {
                val event = awaitPointerEvent(PointerEventPass.Initial)
                val c = event.changes.firstOrNull { it.id == down.id } ?: break
                dx += c.positionChange().x
                dy += c.positionChange().y
            } while (c.pressed)
            if (atTop && dy > threshold && dy > kotlin.math.abs(dx) * 2) onClose()
        }
    }

    Scaffold(modifier = closeSwipe, topBar = {
        // In full screen (a TV) nothing is left but the meters.
        if (!fullScreen) TopAppBar(
            title = { Text("Now playing") }, navigationIcon = { TransportButton(Icon.Back, "Back", onClose) },
            actions = {
                onFullScreen?.let { Text("Full screen", Modifier.focusRequester(fullFocus).tvFocus().clickable(onClick = it).padding(horizontal = 12.dp, vertical = 8.dp), color = MaterialTheme.colorScheme.primary) }
                TransportButton(Icon.Meters, "Meters", onPickMeters)
            },
        )
    }) { padding ->
        if (meters.isNotEmpty()) {
            // With meters chosen this page is the meters, big; the mini player below keeps the title and controls.
            androidx.compose.foundation.layout.BoxWithConstraints(Modifier.fillMaxSize().padding(padding).padding(horizontal = 12.dp, vertical = 8.dp)) {
                val gap = 8.dp
                val room = this.maxHeight
                if (LocalTv.current || (fullScreen && maxWidth > maxHeight)) {
                    // On a TV (full screen or not): two meters to a row (four make 2 x 2) instead of one above the other; an odd last one takes the whole row.
                    val rows = meters.chunked(2)
                    val cellH = (room - gap * (rows.size - 1)) / rows.size
                    Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(gap)) {
                        rows.forEach { row ->
                            Row(Modifier.fillMaxWidth().height(cellH), horizontalArrangement = Arrangement.spacedBy(gap)) {
                                row.forEach { id -> MeterPanel(Modifier.weight(1f).fillMaxSize()) { MeterView(id, player.isPlaying, fill = true, track = player.current, position = { player.positionMs }) } }
                            }
                        }
                    }
                    return@BoxWithConstraints
                }
                Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(gap, Alignment.CenterVertically)) {
                    meters.forEach { id ->
                        // Fill the room, but not past what the meter can use: a pair of wide meters in a full screen cell would float in empty space.
                        val wanted = (MeterCatalog.firstOrNull { it.id == id }?.heightDp ?: 150) * 2.2f
                        // Posters are portrait and use the whole screen; the other meters keep their shape, so they stay at their natural size.
                        val h = if (fullScreen && id.startsWith("poster-")) (room - gap * (meters.size - 1)) / meters.size else minOf((room - gap * (meters.size - 1)) / meters.size, wanted.dp)
                        MeterPanel(Modifier.fillMaxWidth().height(h)) { MeterView(id, player.isPlaying, fill = true, track = player.current, position = { player.positionMs }) }
                    }
                }
            }
            return@Scaffold
        }
        // No meters chosen: the cover and what the web's player says about the track. The transport and the seek bar are the player strip's, below.
        androidx.compose.foundation.layout.BoxWithConstraints(Modifier.fillMaxSize().padding(padding).padding(16.dp)) {
            val sideBySide = maxWidth > 600.dp
            if (sideBySide) {
                val side = minOf(maxHeight, maxWidth * 0.45f)
                Row(Modifier.fillMaxSize(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(24.dp)) {
                    Cover(player.coverUrl, Modifier.size(side).tvFocus().clickable(onClick = onOpenSource))
                    Column(Modifier.weight(1f).fillMaxHeight().verticalScroll(scroll), verticalArrangement = Arrangement.Center) { TrackDetails(player, info, Alignment.Start) }
                }
            } else {
                Column(Modifier.fillMaxSize().verticalScroll(scroll), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                    Cover(player.coverUrl, Modifier.fillMaxWidth(0.7f).clickable(onClick = onOpenSource))
                    Spacer(Modifier.height(20.dp))
                    TrackDetails(player, info, Alignment.CenterHorizontally)
                }
            }
        }
    }
}

/** What the web's player says about the playing track: title, artist, album and year, the position in the queue, the format line and the findings. */
@Composable
private fun TrackDetails(player: PlayerController, info: Int, align: Alignment.Horizontal) {
    val t = player.current
    val text = if (align == Alignment.Start) TextAlign.Start else TextAlign.Center
    Column(Modifier.fillMaxWidth(), horizontalAlignment = align, verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(player.title ?: "", style = MaterialTheme.typography.headlineSmall, textAlign = text)
        player.artist?.let { Text(it, style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.primary, textAlign = text) }
        val album = listOfNotNull(t?.album, t?.year?.toString()).joinToString(" · ")
        if (album.isNotEmpty()) Text(album, color = Palette.Muted, textAlign = text)
        player.positionText()?.let { Text(it, color = Palette.Muted, style = MaterialTheme.typography.bodySmall, textAlign = text) }
        if (t != null) {
            val line = Info.line(t, info)
            if (line.isNotEmpty()) Text(line, color = Palette.Muted, style = MaterialTheme.typography.bodySmall, textAlign = text, modifier = Modifier.padding(top = 6.dp))
            if (info >= 2) Info.findings(t, 3).forEach { f -> Text("! " + f.label, color = flagColor(f.level), style = MaterialTheme.typography.bodySmall, textAlign = text) }
        }
    }
}
