package app.magnetite.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.border
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import androidx.compose.runtime.remember
import kotlinx.coroutines.launch
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import app.magnetite.data.Album
import app.magnetite.data.Folder

/**
 * The right-hand side of the TV library when no album is open: what is in the folder shown, as the web's library would list it, but with
 * the covers. Subfolders first (OK goes into one), then the albums (OK opens it in this pane). Above it the path and a Back that goes up one folder.
 * [folderId] null is the top level.
 */
@Composable
fun FolderGrid(folders: List<Folder>, albums: List<Album>, folderId: Int?, onFolder: (Int?) -> Unit, onAlbum: (Int) -> Unit, playingAlbum: Int? = null, flat: Boolean = false, returnTo: Int? = null,
    info: Int = 0, onActions: ((Album) -> Unit)? = null, onFolderActions: ((Folder) -> Unit)? = null, actions: @Composable androidx.compose.foundation.layout.RowScope.() -> Unit = {}) {
    val path = generateSequence(folders.firstOrNull { it.id == folderId }) { f -> folders.firstOrNull { it.id == f.parentId } }.toList().reversed()
    // The flat view has no folders: every album, in the library's own order.
    val subfolders = if (flat) emptyList() else folders.filter { it.parentId == folderId }
    val here = if (flat) albums.toList() else albums.filter { it.folderId == folderId }
    val gridState = androidx.compose.foundation.lazy.grid.rememberLazyGridState()
    val returnFocus = remember { androidx.compose.ui.focus.FocusRequester() }
    // Coming back from an album: show its cover (a third of the way down, so some context is above it) and put the focus on it, not on the first one.
    LaunchedEffect(Unit) {
        val at = here.indexOfFirst { it.id == returnTo }
        if (returnTo == null || at < 0) return@LaunchedEffect
        kotlinx.coroutines.delay(100)
        gridState.scrollToItem(subfolders.size + at, -gridState.layoutInfo.viewportSize.height / 3)
        kotlinx.coroutines.delay(100)
        runCatching { returnFocus.requestFocus() }
    }
    Column(Modifier.fillMaxSize()) {
        Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
            if (folderId != null && !flat) {
                Text(
                    "← Back", Modifier.tvFocus().clickable { onFolder(path.dropLast(1).lastOrNull()?.id) }.padding(horizontal = 10.dp, vertical = 6.dp),
                    color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.titleMedium,
                )
            }
            if (flat) {
                Text("Library / all albums", Modifier.weight(1f).padding(start = 8.dp), color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.titleMedium, maxLines = 1)
            } else {
                // every part of the path but the last is a stop, so a deep folder is one press from the top
                Row(Modifier.weight(1f).padding(start = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                    val crumbs = listOf<Pair<String, Int?>>("Library" to null) + path.map { it.name to it.id }
                    crumbs.forEachIndexed { i, (name, id) ->
                        if (i > 0) Text(" / ", color = Palette.Muted, style = MaterialTheme.typography.titleMedium)
                        val last = i == crumbs.lastIndex
                        Text(
                            name, if (last) Modifier else Modifier.tvFocus().clickable { onFolder(id) }.padding(horizontal = 4.dp, vertical = 4.dp),
                            color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.titleMedium, maxLines = 1, overflow = TextOverflow.Ellipsis,
                        )
                    }
                }
            }
            actions()
        }
        if (subfolders.isEmpty() && here.isEmpty()) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { Text("Nothing here", color = Palette.Muted) }
            return@Column
        }
        LazyVerticalGrid(
            GridCells.Adaptive(138.dp), Modifier.fillMaxSize(), state = gridState,
            contentPadding = androidx.compose.foundation.layout.PaddingValues(12.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp), verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            items(subfolders, key = { "f${it.id}" }) { f ->
                val holdsPlaying = playingAlbum?.let { p ->
                    var at = albums.firstOrNull { it.id == p }?.folderId
                    var steps = 0
                    var found = false
                    while (at != null && steps++ < 50 && !found) { if (at == f.id) found = true else at = folders.firstOrNull { it.id == at }?.parentId }
                    found
                } == true
                Column(Modifier.tvFocus().combinedClickable(onClick = { onFolder(f.id) }, onLongClick = onFolderActions?.let { fa -> { fa(f) } }).padding(4.dp)) {
                    Box(
                        Modifier.fillMaxWidth().aspectRatio(1f).border(1.dp, MaterialTheme.colorScheme.outline, MaterialTheme.shapes.small),
                        contentAlignment = Alignment.Center,
                    ) { Text("▸ ${f.albumCount}", color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.headlineSmall) }
                    Text((if (holdsPlaying) "♪ " else "") + f.name, Modifier.padding(top = 4.dp), maxLines = 1, overflow = TextOverflow.Ellipsis, color = MaterialTheme.colorScheme.primary)
                    Text(if (f.albumCount == 1) "1 album" else "${f.albumCount} albums", color = Palette.Muted, style = MaterialTheme.typography.bodySmall, maxLines = 1)
                }
            }
            items(here, key = { "a${it.id}" }) { a ->
                Column(Modifier.then(if (a.id == returnTo) Modifier.focusRequester(returnFocus) else Modifier).tvFocus().combinedClickable(onClick = { onAlbum(a.id) }, onLongClick = onActions?.let { f -> { f(a) } }).padding(4.dp)) {
                    Box {
                        Cover(a.coverUrl, Modifier.fillMaxWidth())
                        // what the list shows with the info level: the DR (level 1 and up) and a ! when the album has problems (level 2)
                        Info.dr(a.dr)?.takeIf { info >= 1 }?.let {
                            Text(it, Modifier.align(Alignment.BottomEnd).padding(4.dp).background(Palette.Bg.copy(alpha = 0.8f)).padding(horizontal = 4.dp), color = Palette.Muted, style = MaterialTheme.typography.labelSmall)
                        }
                        if (info >= 2 && (a.problems ?: 0) > 0) {
                            Text("!", Modifier.align(Alignment.TopEnd).padding(4.dp).background(Palette.Bg.copy(alpha = 0.8f)).padding(horizontal = 5.dp), color = Palette.Red, style = MaterialTheme.typography.labelMedium)
                        }
                    }
                    Text(a.title, Modifier.padding(top = 4.dp), maxLines = 1, overflow = TextOverflow.Ellipsis, color = if (a.id == playingAlbum) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface)
                    Text(a.displayArtist ?: a.artist ?: "", color = Palette.Muted, style = MaterialTheme.typography.bodySmall, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
            }
        }
    }
}

/** What the TV's folder dialogs are doing: the menu a long press on a folder opens, and the name or confirm steps after it. */
sealed interface TvFolderDialog {
    data class Menu(val folder: Folder) : TvFolderDialog
    data class Create(val parent: Int?) : TvFolderDialog
    data class Rename(val id: Int, val name: String) : TvFolderDialog
    data class Delete(val id: Int, val name: String) : TvFolderDialog
}

@Composable
fun TvFolderDialogs(state: TvFolderDialog?, onState: (TvFolderDialog?) -> Unit, api: app.magnetite.data.Api, onChanged: () -> Unit) {
    val d = state ?: return
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    var name by remember(d) { androidx.compose.runtime.mutableStateOf((d as? TvFolderDialog.Rename)?.name ?: "") }
    var problem by remember(d) { androidx.compose.runtime.mutableStateOf<String?>(null) }
    fun run(block: suspend () -> Unit) = scope.launch {
        try { block(); onState(null); onChanged() } catch (e: kotlinx.coroutines.CancellationException) { throw e } catch (e: Exception) { problem = e.message }
    }
    if (d is TvFolderDialog.Menu) {
        androidx.compose.material3.AlertDialog(
            onDismissRequest = { onState(null) },
            title = { Text(d.folder.name) },
            text = {
                Column {
                    listOf<Pair<String, () -> Unit>>(
                        "New subfolder" to { onState(TvFolderDialog.Create(d.folder.id)) },
                        "Rename" to { onState(TvFolderDialog.Rename(d.folder.id, d.folder.name)) },
                        "Delete" to { onState(TvFolderDialog.Delete(d.folder.id, d.folder.name)) },
                    ).forEach { (label, go) -> Text(label, Modifier.fillMaxWidth().tvFocus().clickable(onClick = go).padding(horizontal = 10.dp, vertical = 10.dp), style = MaterialTheme.typography.titleMedium) }
                }
            },
            confirmButton = {},
            dismissButton = { androidx.compose.material3.TextButton({ onState(null) }) { Text("Cancel") } },
        )
        return
    }
    androidx.compose.material3.AlertDialog(
        onDismissRequest = { onState(null) },
        title = {
            Text(when (d) {
                is TvFolderDialog.Create -> if (d.parent == null) "New folder" else "New subfolder"
                is TvFolderDialog.Rename -> "Rename folder"
                is TvFolderDialog.Delete -> "Delete \"${d.name}\"?"
                is TvFolderDialog.Menu -> ""
            })
        },
        text = {
            Column {
                if (d is TvFolderDialog.Delete) Text("Its albums and subfolders move up one level. No music is deleted.")
                else androidx.compose.material3.OutlinedTextField(name, { name = it }, Modifier.dpadLeavesField(), singleLine = true, label = { Text("Name") })
                problem?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            }
        },
        confirmButton = {
            androidx.compose.material3.TextButton(enabled = d is TvFolderDialog.Delete || name.isNotBlank(), onClick = {
                when (d) {
                    is TvFolderDialog.Create -> run { api.createFolder(name.trim(), d.parent) }
                    is TvFolderDialog.Rename -> run { api.renameFolder(d.id, name.trim()) }
                    is TvFolderDialog.Delete -> run { api.deleteFolder(d.id) }
                    is TvFolderDialog.Menu -> {}
                }
            }) { Text(if (d is TvFolderDialog.Delete) "Delete" else "Save") }
        },
        dismissButton = { androidx.compose.material3.TextButton({ onState(null) }) { Text("Cancel") } },
    )
}

/** The TV library's top bar actions, on the cover grid and on the album page: Search, Meters and the menu. [onNewFolder] only in the folder view. */
@Composable
fun androidx.compose.foundation.layout.RowScope.TvHeaderActions(
    app: app.magnetite.MagnetiteApp, onSearch: () -> Unit, onPickMeters: () -> Unit, onTruth: () -> Unit, onPickTheme: () -> Unit, onSignOut: () -> Unit,
    onNewFolder: (() -> Unit)?, onFullScreen: () -> Unit,
) {
    var menu by remember { androidx.compose.runtime.mutableStateOf(false) }
    TransportButton(Icon.Search, "Search", onSearch)
    TransportButton(Icon.Meters, "Meters", onPickMeters)
    TransportButton(Icon.Fullscreen, "Full screen", onFullScreen)
    Box {
        TransportButton(Icon.Menu, "Menu", { menu = true })
        androidx.compose.material3.DropdownMenu(menu, { menu = false }) {
            androidx.compose.material3.DropdownMenuItem(text = { Text("View: " + if (app.flat) "flat" else "folders") }, onClick = { menu = false; app.toggleFlat() })
            if (onNewFolder != null) androidx.compose.material3.DropdownMenuItem(text = { Text("New folder") }, onClick = { menu = false; onNewFolder() })
            androidx.compose.material3.DropdownMenuItem(text = { Text("Truth") }, onClick = { menu = false; onTruth() })
            androidx.compose.material3.DropdownMenuItem(text = { Text("Info: " + listOf("off", "DR", "all")[app.info]) }, onClick = { app.cycleInfo() })
            androidx.compose.material3.DropdownMenuItem(text = { Text("Theme") }, onClick = { menu = false; onPickTheme() })
            androidx.compose.material3.DropdownMenuItem(text = { Text("Sign out") }, onClick = { menu = false; onSignOut() })
        }
    }
}
