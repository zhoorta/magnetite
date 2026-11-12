package app.magnetite.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import app.magnetite.data.Album
import app.magnetite.data.Api
import app.magnetite.data.Track
import app.magnetite.player.PlayerController
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Search the library and the shared area (`GET /search`): results as you type (two letters or more, a short delay, an older
 * answer is dropped), the albums first and then the tracks; a tap opens an album or plays a track with the hits as the queue.
 * `key:value` filters (year, dr, codec, artist, album) are the server's.
 */
@Composable
fun SearchScreen(api: Api, player: PlayerController, info: Int, onOpenAlbum: (Int) -> Unit, onBack: () -> Unit) {
    var text by remember { mutableStateOf("") }
    val albums = remember { mutableStateListOf<Album>() }
    val tracks = remember { mutableStateListOf<Track>() }
    var more by remember { mutableStateOf(false) }
    var page by remember { mutableStateOf(1) }
    var note by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val focus = remember { FocusRequester() }

    androidx.activity.compose.BackHandler(onBack = onBack)
    LaunchedEffect(Unit) { focus.requestFocus() }

    LaunchedEffect(text) {
        val q = text.trim()
        if (q.length < 2) { albums.clear(); tracks.clear(); more = false; note = null; return@LaunchedEffect }
        delay(250)
        try {
            val r = api.search(q)
            albums.clear(); albums.addAll(r.albums); tracks.clear(); tracks.addAll(r.tracks)
            page = 1; more = r.hasMore
            note = if (r.albums.isEmpty() && r.tracks.isEmpty()) "Nothing found." else null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            note = e.message
        }
    }

    Scaffold(topBar = { DenseBar("Search", onBack) }) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            Pane("Find", Modifier.fillMaxWidth()) {
                Row(Modifier.padding(horizontal = 10.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text("/ ", color = MaterialTheme.colorScheme.primary)
                    Box(Modifier.weight(1f)) {
                        BasicTextField(
                            text, { text = it }, Modifier.fillMaxWidth().dpadLeavesField().focusRequester(focus), singleLine = true,
                            textStyle = MaterialTheme.typography.bodyLarge.copy(color = Palette.Text),
                            cursorBrush = SolidColor(Palette.Accent),
                            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search), keyboardActions = KeyboardActions(onSearch = {}),
                        )
                        if (text.isEmpty()) Text("title, artist, year:2019, dr:<8", color = Palette.Muted, maxLines = 1)
                    }
                }
            }
            Pane("Results", Modifier.weight(1f).fillMaxWidth()) {
                LazyColumn(Modifier.fillMaxSize(), contentPadding = androidx.compose.foundation.layout.PaddingValues(vertical = 8.dp)) {
                    note?.let { item { Text(it, Modifier.padding(horizontal = 10.dp), color = Palette.Muted) } }
                    items(albums, key = { "a${it.id}" }) { a ->
                        Row(Modifier.fillMaxWidth().tvFocus().clickable { onOpenAlbum(a.id) }.padding(start = 8.dp, end = 10.dp, top = 4.dp, bottom = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                            Text("▸ ", color = Palette.Muted)
                            Text(nameAndRest(a.title, a.displayArtist ?: a.artist, player.albumId == a.id), Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis, inlineContent = PlayInline)
                            if (info >= 1) Info.dr(a.dr)?.let { Text(it, Modifier.padding(start = 8.dp), color = Palette.Muted, style = MaterialTheme.typography.bodySmall) }
                        }
                    }
                    items(tracks, key = { "t${it.id}" }) { t ->
                        TrackRow(t, player.currentId == t.id.toString(), { player.play(tracks.toList(), t) }, info = info, rest = listOfNotNull(t.artist, t.album).joinToString(" · ").ifEmpty { null })
                    }
                    if (more) item {
                        Text(
                            "more…", Modifier.fillMaxWidth().tvFocus().clickable {
                                scope.launch {
                                    try {
                                        val r = api.search(text.trim(), page + 1)
                                        albums.addAll(r.albums); tracks.addAll(r.tracks); page = r.page; more = r.hasMore
                                    } catch (e: CancellationException) { throw e } catch (e: Exception) { note = e.message }
                                }
                            }.padding(horizontal = 10.dp, vertical = 10.dp),
                            color = MaterialTheme.colorScheme.primary,
                        )
                    }
                }
            }
        }
    }
}
