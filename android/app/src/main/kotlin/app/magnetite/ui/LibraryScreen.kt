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
import app.magnetite.data.Folder
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.combinedClickable
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.OutlinedTextField
import androidx.compose.runtime.rememberCoroutineScope
import kotlinx.coroutines.launch
import coil3.compose.AsyncImage
import kotlinx.coroutines.CancellationException

@OptIn(ExperimentalMaterial3Api::class, ExperimentalFoundationApi::class)
@Composable
fun LibraryScreen(api: Api, onOpen: (Int) -> Unit, onPickMeters: () -> Unit, onSignOut: () -> Unit) {
    val albums = remember { mutableStateListOf<Album>() }
    var page by remember { mutableStateOf(0) }
    var lastPage by remember { mutableStateOf(1) }
    var error by remember { mutableStateOf<String?>(null) }
    val folders = remember { mutableStateListOf<Folder>() }

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

    val scope = rememberCoroutineScope()
    /** null = no dialog; otherwise what the name field is for. */
    var dialog by remember { mutableStateOf<FolderDialog?>(null) }
    var menuFor by remember { mutableStateOf<Int?>(null) }

    fun refresh() = scope.launch {
        try {
            val f = api.folders()
            folders.clear(); folders.addAll(f)
            albums.clear(); page = 0; lastPage = 1
            loadMore()
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message
        }
    }

    // Albums are paged and a folder's albums can be on any page, so load them all; the folder list is flat.
    LaunchedEffect(Unit) {
        try {
            folders.clear()
            folders.addAll(api.folders())
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message
        }
        if (albums.isEmpty()) loadMore()
    }
    LaunchedEffect(page) { if (page in 1 until lastPage) loadMore() }

    val expanded = remember { mutableStateListOf<Int>() }

    dialog?.let { d ->
        var name by remember(d) { mutableStateOf((d as? FolderDialog.Rename)?.name ?: "") }
        var problem by remember(d) { mutableStateOf<String?>(null) }
        fun run(block: suspend () -> Unit) = scope.launch {
            try {
                block()
                dialog = null
                refresh()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                problem = e.message
            }
        }
        AlertDialog(
            onDismissRequest = { dialog = null },
            title = {
                Text(when (d) {
                    is FolderDialog.Create -> if (d.parent == null) "New folder" else "New subfolder"
                    is FolderDialog.Rename -> "Rename folder"
                    is FolderDialog.Delete -> "Delete \"${d.name}\"?"
                })
            },
            text = {
                Column {
                    if (d is FolderDialog.Delete) Text("Its albums and subfolders move up one level. No music is deleted.")
                    else OutlinedTextField(name, { name = it }, singleLine = true, label = { Text("Name") })
                    problem?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                }
            },
            confirmButton = {
                TextButton(enabled = d is FolderDialog.Delete || name.isNotBlank(), onClick = {
                    when (d) {
                        is FolderDialog.Create -> run { api.createFolder(name.trim(), d.parent) }
                        is FolderDialog.Rename -> run { api.renameFolder(d.id, name.trim()) }
                        is FolderDialog.Delete -> run { api.deleteFolder(d.id) }
                    }
                }) { Text(if (d is FolderDialog.Delete) "Delete" else "Save") }
            },
            dismissButton = { TextButton({ dialog = null }) { Text("Cancel") } },
        )
    }

    Scaffold(topBar = {
        TopAppBar(
            title = { Text("Library") },
            actions = {
                TransportButton(Icon.NewFolder, "New folder", { dialog = FolderDialog.Create(null) })
                var menu by remember { mutableStateOf(false) }
                androidx.compose.foundation.layout.Box {
                    TransportButton(Icon.Menu, "Menu", { menu = true })
                    DropdownMenu(menu, { menu = false }) {
                        DropdownMenuItem(text = { Text("Meters") }, onClick = { menu = false; onPickMeters() })
                        DropdownMenuItem(text = { Text("Sign out") }, onClick = { menu = false; onSignOut() })
                    }
                }
            },
        )
    }) { padding ->
        LazyColumn(Modifier.fillMaxSize().padding(padding)) {
            fun tree(parent: Int?, depth: Int): List<Pair<Int, Any>> =
                folders.filter { it.parentId == parent }.flatMap { f ->
                    listOf<Pair<Int, Any>>(depth to f) +
                        if (f.id in expanded) tree(f.id, depth + 1) + albums.filter { it.folderId == f.id }.map { depth + 1 to it as Any } else emptyList()
                }
            val rows = tree(null, 0) + albums.filter { it.folderId == null }.map { 0 to it as Any }
            items(rows, key = { (_, r) -> if (r is Folder) "f${r.id}" else "a${(r as Album).id}" }) { (depth, r) ->
                if (r is Folder) {
                    val open = r.id in expanded
                    Row(
                        Modifier.fillMaxWidth().combinedClickable(
                            onClick = { if (open) expanded.remove(r.id) else expanded.add(r.id) },
                            onLongClick = { menuFor = r.id },
                        )
                            .padding(start = 16.dp + 20.dp * depth, end = 16.dp, top = 14.dp, bottom = 14.dp),
                    ) {
                        Text((if (open) "▾ " else "▸ ") + r.name, Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.titleMedium)
                        Text("${r.albumCount}", style = MaterialTheme.typography.bodySmall)
                        DropdownMenu(expanded = menuFor == r.id, onDismissRequest = { menuFor = null }) {
                            DropdownMenuItem(text = { Text("New subfolder") }, onClick = { menuFor = null; dialog = FolderDialog.Create(r.id) })
                            DropdownMenuItem(text = { Text("Rename") }, onClick = { menuFor = null; dialog = FolderDialog.Rename(r.id, r.name) })
                            DropdownMenuItem(text = { Text("Delete") }, onClick = { menuFor = null; dialog = FolderDialog.Delete(r.id, r.name) })
                        }
                    }
                } else {
                    val album = r as Album
                    Row(Modifier.fillMaxWidth().clickable { onOpen(album.id) }.padding(start = 16.dp + 20.dp * depth, end = 16.dp, top = 8.dp, bottom = 8.dp)) {
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
            }
            item {
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

private sealed interface FolderDialog {
    data class Create(val parent: Int?) : FolderDialog
    data class Rename(val id: Int, val name: String) : FolderDialog
    data class Delete(val id: Int, val name: String) : FolderDialog
}
