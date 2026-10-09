package app.magnetite.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.aspectRatio
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
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import app.magnetite.data.Album
import app.magnetite.data.Api
import coil3.compose.AsyncImage
import kotlinx.coroutines.CancellationException

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LibraryScreen(api: Api, onOpen: (Int) -> Unit, onSignOut: () -> Unit) {
    val albums = remember { mutableStateListOf<Album>() }
    var page by remember { mutableStateOf(0) }
    var lastPage by remember { mutableStateOf(1) }
    var error by remember { mutableStateOf<String?>(null) }

    suspend fun loadMore() {
        if (page >= lastPage) return
        try {
            val next = api.albums(page + 1)
            albums.addAll(next.data)
            page = next.meta.currentPage
            lastPage = next.meta.lastPage
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message
        }
    }

    LaunchedEffect(Unit) { if (albums.isEmpty()) loadMore() }

    Scaffold(topBar = {
        TopAppBar(
            title = { Text("Library") },
            actions = { TextButton(onSignOut) { Text("Sign out") } },
        )
    }) { padding ->
        LazyColumn(Modifier.fillMaxSize().padding(padding)) {
            items(albums, key = { it.id }) { album ->
                Row(Modifier.fillMaxWidth().clickable { onOpen(album.id) }.padding(horizontal = 16.dp, vertical = 8.dp)) {
                    Cover(album.coverUrl, Modifier.size(56.dp))
                    Column(Modifier.padding(start = 12.dp)) {
                        Text(album.title, maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.titleMedium)
                        Text(
                            listOfNotNull(album.artist, album.year?.toString(), album.trackCount?.let { "$it tracks" }).joinToString(" · "),
                            maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.bodySmall,
                        )
                    }
                }
            }
            item {
                if (page in 1 until lastPage) LaunchedEffect(page) { loadMore() }
                error?.let { Text(it, Modifier.padding(16.dp), color = MaterialTheme.colorScheme.error) }
            }
        }
    }
}

@Composable
fun Cover(url: String?, modifier: Modifier = Modifier) {
    AsyncImage(
        model = url,
        contentDescription = null,
        contentScale = ContentScale.Crop,
        modifier = modifier.aspectRatio(1f).clip(MaterialTheme.shapes.small),
    )
}
