package app.magnetite.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import app.magnetite.data.Album
import app.magnetite.data.Api
import app.magnetite.player.PlayerController
import app.magnetite.player.PlaySource
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.focusable
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.input.key.isShiftPressed
import androidx.compose.ui.input.key.key
import androidx.compose.ui.input.key.onPreviewKeyEvent
import kotlinx.coroutines.launch

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AlbumScreen(api: Api, player: PlayerController, info: Int, id: Int, onBack: () -> Unit, onEdit: (Int) -> Unit = {}, embedded: Boolean = false,
    focus: androidx.compose.ui.focus.FocusRequester = remember { androidx.compose.ui.focus.FocusRequester() }, onSwitchPane: (() -> Unit)? = null, onPickMeters: (() -> Unit)? = null) {
    var album by remember { mutableStateOf<Album?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = androidx.compose.runtime.rememberCoroutineScope()

    suspend fun load() {
        try { album = api.album(id); error = null } catch (e: kotlinx.coroutines.CancellationException) { throw e } catch (e: Exception) { error = e.message }
    }
    LaunchedEffect(id) { load() }

    var actions by remember { mutableStateOf<Target?>(null) }
    var cursor by remember { mutableStateOf(-1) }
    val list = androidx.compose.foundation.lazy.rememberLazyListState()
    val onKey: (androidx.compose.ui.input.key.KeyEvent) -> Boolean = onKey@{ e ->
        if (!e.isPlain()) return@onKey false
        val tracks = album?.tracks ?: return@onKey false
        fun move(to: Int) {
            if (tracks.isEmpty()) return
            cursor = to.coerceIn(0, tracks.lastIndex)
            val shown = list.layoutInfo.visibleItemsInfo
            if (shown.isEmpty() || cursor + 1 <= shown.first().index || cursor + 1 >= shown.last().index) scope.launch { list.animateScrollToItem(cursor + 1) }
        }
        val k = androidx.compose.ui.input.key.Key
        when (e.key) {
            k.J, k.DirectionDown -> { move(if (cursor < 0) 0 else cursor + 1); true }
            k.K, k.DirectionUp -> { move(if (cursor < 0) tracks.lastIndex else cursor - 1); true }
            k.G -> { move(if (e.isShiftPressed) tracks.lastIndex else 0); true }
            k.Enter, k.NumPadEnter, k.L, k.DirectionRight -> {
                tracks.getOrNull(cursor)?.let { if (it.open) player.play(tracks, it, PlaySource.Album(id)) }
                true
            }
            k.Tab, k.H, k.DirectionLeft -> if (onSwitchPane != null) { onSwitchPane(); true } else false
            k.Escape -> { onBack(); true }
            else -> playerKey(e, player)
        }
    }
    ActionHost(actions, api, player, emptyList(), onClose = { actions = null }, onChanged = { scope.launch { load() } }, onEdit = onEdit)
    androidx.activity.compose.BackHandler(onBack = onBack)

    Scaffold(modifier = if (LocalTv.current) Modifier else Modifier.focusRequester(focus).focusable().onPreviewKeyEvent(onKey), topBar = { DenseBar(album?.title ?: "", if (embedded) null else onBack) { if (!embedded && onPickMeters != null) TransportButton(Icon.Meters, "Meters", onPickMeters) } }) { padding ->
        val a = album
        PullRefresh(padding, ::load) {
        if (a == null) {
            ScrollingMessage(error ?: "Loading…")
        } else {
            Pane(listOfNotNull(a.title, a.displayArtist ?: a.artist).joinToString(" — ").ifEmpty { "Album" }, Modifier.fillMaxSize()) {
            LazyColumn(Modifier.fillMaxSize(), state = list, contentPadding = androidx.compose.foundation.layout.PaddingValues(top = 8.dp, bottom = 8.dp)) {
                item {
                    Row(Modifier.padding(start = 10.dp, end = 10.dp, bottom = 8.dp)) {
                        Cover(a.coverUrl, Modifier.size(96.dp))
                        Column(Modifier.padding(start = 12.dp)) {
                            Text(a.title, style = MaterialTheme.typography.titleMedium)
                            a.displayArtist?.let { Text(it, color = MaterialTheme.colorScheme.primary) }
                            val facts = listOfNotNull(a.year?.toString(), "${a.tracks.size} tracks", a.tracks.sumOf { it.duration ?: 0.0 }.takeIf { it > 0 }?.let(::total))
                            Text(facts.joinToString(" · "), color = Palette.Muted, style = MaterialTheme.typography.bodySmall)
                            if (info >= 1) Info.albumDr(a)?.let { Text(Info.dr(it)!!, color = Palette.Muted, style = MaterialTheme.typography.bodySmall) }
                        }
                    }
                }
                itemsIndexed(a.tracks, key = { _, t -> t.id }) { i, track ->
                    // the album's own artist is already in the header; show the track's only when it differs (a compilation)
                    val rest = track.artist?.takeIf { it != (a.displayArtist ?: a.artist) }
                    TrackRow(track, player.currentId == track.id.toString(), { player.play(a.tracks, track, PlaySource.Album(id)) }, leading = "${track.number ?: ""}", info = info, rest = rest, modifier = if (cursor == i) Modifier.background(MaterialTheme.colorScheme.primary.copy(alpha = 0.10f)) else Modifier, onLongClick = if (track.shared != null) ({ actions = Target.OfTrack(track) }) else null)
                }
            }
            }
        }
        }
    }
}

private fun total(seconds: Double): String {
    val m = (seconds / 60).toInt()
    if (m < 1) return "${seconds.toInt()} s"
    return if (m >= 60) "%d h %02d min".format(m / 60, m % 60) else "$m min"
}
