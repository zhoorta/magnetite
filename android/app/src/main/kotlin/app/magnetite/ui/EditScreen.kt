package app.magnetite.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
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
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import app.magnetite.data.Api
import app.magnetite.data.TrackEditRow
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

/** One row being edited; kept as plain strings until saved. */
private class EditRow(val id: Int, number: String, title: String, artist: String) {
    var number by mutableStateOf(number)
    var title by mutableStateOf(title)
    var artist by mutableStateOf(artist)
}

/**
 * The web's edit table on a phone: the album's title, artist, release date and label, and a line per track (number, title,
 * artist). One save writes everything or nothing (`PATCH /albums/{id}/details`); the files and their tags are never touched.
 */
@Composable
fun EditScreen(api: Api, albumId: Int, onBack: () -> Unit, onSaved: () -> Unit) {
    var loaded by remember { mutableStateOf(false) }
    var title by remember { mutableStateOf("") }
    var artist by remember { mutableStateOf("") }
    var date by remember { mutableStateOf("") }
    var label by remember { mutableStateOf("") }
    val rows = remember { mutableStateListOf<EditRow>() }
    var error by remember { mutableStateOf<String?>(null) }
    var saving by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()

    androidx.activity.compose.BackHandler(onBack = onBack)
    LaunchedEffect(albumId) {
        try {
            val a = api.album(albumId)
            title = a.title; artist = a.artist ?: ""; date = a.releaseDate ?: a.year?.toString() ?: ""; label = a.label ?: ""
            rows.clear()
            rows.addAll(a.tracks.map { EditRow(it.id, it.edit?.trackNumber?.toString() ?: it.number?.toString() ?: "", it.edit?.title ?: it.title, it.edit?.artist ?: "") })
            loaded = true
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message
        }
    }

    fun save() {
        if (saving) return
        saving = true
        scope.launch {
            try {
                api.saveDetails(albumId, title, artist, date, label, rows.map { TrackEditRow(it.id, it.title, it.artist, it.number.trim().toIntOrNull()) })
                onSaved()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.message
                saving = false
            }
        }
    }

    Scaffold(topBar = {
        DenseBar("Edit", onBack) {
            Text("save", Modifier.tvFocus().clickable(enabled = loaded && !saving) { save() }.padding(horizontal = 14.dp, vertical = 12.dp), color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.labelLarge)
        }
    }) { padding ->
        Pane("Album", Modifier.fillMaxSize().padding(padding)) {
            LazyColumn(Modifier.fillMaxSize(), contentPadding = androidx.compose.foundation.layout.PaddingValues(bottom = 24.dp)) {
                error?.let { item { Text(it, Modifier.padding(10.dp), color = MaterialTheme.colorScheme.error) } }
                if (loaded) {
                    item { DenseField("title", title, { title = it }) }
                    item { DenseField("artist", artist, { artist = it }) }
                    item { DenseField("date", date, { date = it }, keyboardType = KeyboardType.Number) }
                    item { DenseField("label", label, { label = it }) }
                    item { Heading("tracks") }
                    itemsIndexed(rows, key = { _, r -> r.id }) { _, r ->
                        androidx.compose.foundation.layout.Column(Modifier.fillMaxWidth().padding(vertical = 4.dp)) {
                            DenseField("#", r.number, { r.number = it }, keyboardType = KeyboardType.Number)
                            DenseField("title", r.title, { r.title = it })
                            DenseField("artist", r.artist, { r.artist = it })
                        }
                    }
                } else if (error == null) {
                    item { Text("Loading…", Modifier.padding(10.dp), color = Palette.Muted) }
                }
            }
        }
    }
}
