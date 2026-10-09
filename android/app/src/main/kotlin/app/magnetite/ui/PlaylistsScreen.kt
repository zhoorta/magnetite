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
    Column(Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 12.dp)) {
        Text(p.name, style = MaterialTheme.typography.titleMedium)
        Text(
            listOfNotNull("${p.trackCount} tracks", if (p.shared) "shared" else null, p.sharedBy?.let { "from $it" }).joinToString(" · "),
            style = MaterialTheme.typography.bodySmall,
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PlaylistsScreen(api: Api, onOpen: (Int) -> Unit) {
    var playlists by remember { mutableStateOf<List<PlaylistSummary>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) {
        try { playlists = api.playlists() } catch (e: CancellationException) { throw e } catch (e: Exception) { error = e.message }
    }

    Scaffold(topBar = { TopAppBar(title = { Text("Playlists") }) }) { padding ->
        val list = playlists
        when {
            list == null -> Text(error ?: "Loading…", Modifier.padding(padding).padding(16.dp))
            list.isEmpty() -> Text("No playlists yet.", Modifier.padding(padding).padding(16.dp))
            else -> LazyColumn(Modifier.fillMaxSize().padding(padding)) {
                items(list, key = { it.id }) { PlaylistRow(it) { onOpen(it.id) } }
            }
        }
    }
}
