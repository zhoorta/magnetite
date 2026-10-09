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
import androidx.compose.foundation.clickable

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AlbumScreen(api: Api, player: PlayerController, id: Int, onBack: () -> Unit) {
    var album by remember { mutableStateOf<Album?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(id) {
        try { album = api.album(id) } catch (e: kotlinx.coroutines.CancellationException) { throw e } catch (e: Exception) { error = e.message }
    }

    androidx.activity.compose.BackHandler(onBack = onBack)

    Scaffold(topBar = {
        TopAppBar(title = { Text(album?.title ?: "") }, navigationIcon = { TextButton(onBack) { Text("Back") } })
    }) { padding ->
        val a = album
        if (a == null) {
            Text(error ?: "Loading…", Modifier.padding(padding).padding(16.dp))
        } else {
            LazyColumn(Modifier.fillMaxSize().padding(padding)) {
                item {
                    Row(Modifier.padding(16.dp)) {
                        Cover(a.coverUrl, Modifier.size(120.dp))
                        Column(Modifier.padding(start = 16.dp)) {
                            Text(a.title, style = MaterialTheme.typography.titleLarge)
                            Text(listOfNotNull(a.artist, a.year?.toString()).joinToString(" · "))
                        }
                    }
                }
                items(a.tracks, key = { it.id }) { track ->
                    TrackRow(track, player.currentId == track.id.toString(), { player.play(a.tracks, track) }, leading = "${track.number ?: ""}")
                }
            }
        }
    }
}
