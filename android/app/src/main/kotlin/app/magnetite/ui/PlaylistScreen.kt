package app.magnetite.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.rememberCoroutineScope
import app.magnetite.data.Track
import kotlinx.coroutines.launch
import androidx.compose.material3.ExperimentalMaterial3Api
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
import app.magnetite.data.Api
import app.magnetite.data.Playlist
import app.magnetite.player.PlayerController
import kotlinx.coroutines.CancellationException

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PlaylistScreen(api: Api, player: PlayerController, id: Int, onBack: () -> Unit) {
    var playlist by remember { mutableStateOf<Playlist?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    // Rows carry their own id: the position changes while dragging, so it cannot be the key.
    val rows = remember { mutableStateListOf<Pair<Int, Track>>() }
    val list = rememberLazyListState()
    val drag = remember { DragState() }
    val scope = rememberCoroutineScope()

    suspend fun load() {
        try {
            val p = api.playlist(id)
            playlist = p
            rows.clear(); rows.addAll(p.tracks.mapIndexed { i, t -> i to t })
            error = null
        } catch (e: CancellationException) { throw e } catch (e: Exception) { error = e.message }
    }
    LaunchedEffect(id) {
        try { load() } catch (e: CancellationException) { throw e } catch (e: Exception) { error = e.message }
    }
    BackHandler(onBack = onBack)

    Scaffold(topBar = {
        TopAppBar(title = { Text(playlist?.name ?: "") }, navigationIcon = { TransportButton(Icon.Back, "Back", onBack) })
    }) { padding ->
        val p = playlist
        PullRefresh(padding, ::load) {
        if (p == null) {
            ScrollingMessage(error ?: "Loading…")
        } else {
            // Long-press a track and drag it to a new place; your own playlists only. The order is saved when you let go.
            LazyColumn(Modifier.fillMaxSize(), state = list) {
                itemsIndexed(rows, key = { _, r -> r.first }) { i, (uid, track) ->
                    TrackRow(
                        track, player.currentId == track.id.toString(), { player.play(rows.map { it.second }, track) }, leading = "${i + 1}",
                        modifier = Modifier.dragRow(
                            drag, list, scope, uid, enabled = p.mine,
                            onMove = { y ->
                                val over = list.rowAt(y, uid)
                                val from = rows.indexOfFirst { it.first == uid }
                                if (over != null && from >= 0 && over.index != from) rows.add(over.index, rows.removeAt(from))
                            },
                            onDrop = {
                                scope.launch {
                                    try { api.reorderPlaylist(p.id, rows.map { it.second.id }) } catch (e: CancellationException) { throw e } catch (e: Exception) { error = e.message; load() }
                                }
                            },
                        ),
                    )
                }
            }
        }
        }
    }
}
