package app.magnetite.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
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
import androidx.compose.ui.unit.dp
import app.magnetite.data.Api
import app.magnetite.data.PlaylistSummary
import kotlinx.coroutines.CancellationException

@Composable
fun PlaylistRow(p: PlaylistSummary, onClick: () -> Unit) {
    androidx.compose.foundation.layout.Row(
        Modifier.fillMaxWidth().clickable(onClick = onClick).padding(start = 8.dp, end = 10.dp, top = 4.dp, bottom = 4.dp),
        verticalAlignment = androidx.compose.ui.Alignment.CenterVertically,
    ) {
        Text(
            nameAndRest(p.name, listOfNotNull("${p.trackCount} tracks", if (p.shared) "shared" else null, p.sharedBy?.let { "from $it" }).joinToString(" · ")),
            Modifier.weight(1f), maxLines = 1, overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis,
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PlaylistsScreen(api: Api, onOpen: (Int) -> Unit) {
    var playlists by remember { mutableStateOf<List<PlaylistSummary>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    suspend fun load() {
        try { playlists = api.playlists(); error = null } catch (e: CancellationException) { throw e } catch (e: Exception) { error = e.message }
    }
    LaunchedEffect(Unit) { load() }

    Scaffold(topBar = { DenseBar("Playlists") }) { padding ->
        val list = playlists
        PullRefresh(padding, ::load) {
            when {
                list == null -> ScrollingMessage(error ?: "Loading…")
                list.isEmpty() -> ScrollingMessage("No playlists yet.")
                else -> Pane("Playlists (${list.size})", Modifier.fillMaxSize()) {
                    LazyColumn(Modifier.fillMaxSize(), contentPadding = androidx.compose.foundation.layout.PaddingValues(vertical = 8.dp)) {
                        items(list, key = { it.id }) { PlaylistRow(it) { onOpen(it.id) } }
                    }
                }
            }
        }
    }
}
