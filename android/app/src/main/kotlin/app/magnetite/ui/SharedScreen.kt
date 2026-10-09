package app.magnetite.ui

import androidx.compose.foundation.clickable
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
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import app.magnetite.data.Api
import app.magnetite.data.Shared
import app.magnetite.player.PlayerController
import kotlinx.coroutines.CancellationException

@Composable
private fun Heading(text: String) =
    Text(text, Modifier.padding(start = 16.dp, top = 16.dp, bottom = 4.dp), style = MaterialTheme.typography.titleSmall, color = MaterialTheme.colorScheme.primary)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SharedScreen(api: Api, player: PlayerController, onOpenAlbum: (Int) -> Unit, onOpenPlaylist: (Int) -> Unit) {
    var shared by remember { mutableStateOf<Shared?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    suspend fun load() {
        try { shared = api.shared(); error = null } catch (e: CancellationException) { throw e } catch (e: Exception) { error = e.message }
    }
    LaunchedEffect(Unit) { load() }

    Scaffold(topBar = { TopAppBar(title = { Text("Shared") }) }) { padding ->
        val s = shared
        PullRefresh(padding, ::load) {
        if (s == null) {
            ScrollingMessage(error ?: "Loading…")
        } else if (s.albums.isEmpty() && s.tracks.isEmpty() && s.playlists.isEmpty()) {
            ScrollingMessage("Nothing is shared yet.")
        } else {
            LazyColumn(Modifier.fillMaxSize()) {
                if (s.albums.isNotEmpty()) item { Heading("Albums") }
                items(s.albums, key = { "a${it.id}" }) { album ->
                    Row(Modifier.fillMaxWidth().clickable { onOpenAlbum(album.id) }.padding(horizontal = 16.dp, vertical = 8.dp)) {
                        Cover(album.coverUrl, Modifier.size(56.dp))
                        Column(Modifier.padding(start = 12.dp)) {
                            Text(album.title, maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.titleMedium)
                            Text(
                                listOfNotNull(album.artist, album.sharedBy?.let { "from $it" }).joinToString(" · "),
                                maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.bodySmall,
                            )
                        }
                    }
                }
                if (s.tracks.isNotEmpty()) item { Heading("Tracks") }
                items(s.tracks, key = { "t${it.id}" }) { track ->
                    TrackRow(track, player.currentId == track.id.toString(), { player.play(s.tracks, track) })
                }
                if (s.playlists.isNotEmpty()) item { Heading("Playlists") }
                items(s.playlists, key = { "p${it.id}" }) { PlaylistRow(it) { onOpenPlaylist(it.id) } }
            }
        }
        }
    }
}
