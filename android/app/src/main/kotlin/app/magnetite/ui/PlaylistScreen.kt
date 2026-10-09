package app.magnetite.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
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

    LaunchedEffect(id) {
        try { playlist = api.playlist(id) } catch (e: CancellationException) { throw e } catch (e: Exception) { error = e.message }
    }
    BackHandler(onBack = onBack)

    Scaffold(topBar = {
        TopAppBar(title = { Text(playlist?.name ?: "") }, navigationIcon = { TransportButton(Icon.Back, "Back", onBack) })
    }) { padding ->
        val p = playlist
        if (p == null) {
            Text(error ?: "Loading…", Modifier.padding(padding).padding(16.dp))
        } else {
            LazyColumn(Modifier.fillMaxSize().padding(padding)) {
                // A playlist may repeat a track, so the position is the key, not the id.
                itemsIndexed(p.tracks, key = { i, _ -> i }) { i, track ->
                    TrackRow(track, player.currentId == track.id.toString(), { player.play(p.tracks, track) }, leading = "${i + 1}")
                }
            }
        }
    }
}
