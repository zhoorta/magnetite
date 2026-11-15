package app.magnetite.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.focusGroup
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.foundation.clickable
import androidx.compose.material3.MaterialTheme
import androidx.compose.ui.unit.dp
import androidx.compose.material3.Text
import kotlinx.coroutines.launch
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.key
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import app.magnetite.MagnetiteApp

/**
 * The Library tab. Narrow: the library alone (an album opens as a page, [openAlbum]). Wide (a tablet, an unfolded phone, a TV): the library on
 * the left and the open album on the right, [selected] being its id. Shared by the phone/tablet shell and the TV shell.
 */
@Composable
fun LibraryPanes(
    app: MagnetiteApp, wide: Boolean, selected: Int?, onSelect: (Int?) -> Unit, openAlbum: (Int) -> Unit,
    onSearch: () -> Unit, onTruth: () -> Unit, onEdit: (Int) -> Unit, onPickMeters: () -> Unit, onPickTheme: () -> Unit, onSignOut: () -> Unit,
    onFullScreen: () -> Unit = {},
) {
    val libFocus = remember { FocusRequester() }
    val albumFocus = remember { FocusRequester() }
    val tv = LocalTv.current
    // On a TV the right pane shows the contents of a folder with the covers when no album is open; the lists are the library screen's own.
    val albums = remember { androidx.compose.runtime.mutableStateListOf<app.magnetite.data.Album>() }
    val folders = remember { androidx.compose.runtime.mutableStateListOf<app.magnetite.data.Folder>() }
    var gridFolder by androidx.compose.runtime.saveable.rememberSaveable { mutableStateOf<Int?>(null) }
    val rightFocus = remember { FocusRequester() }
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    // On a TV the library is the cover grid only (no tree): folders as tiles, a path on top, or every album in the flat view.
    val flatTv = tv && wide
    var folderDialog by remember { mutableStateOf<TvFolderDialog?>(null) }
    var gridActions by remember { mutableStateOf<Target?>(null) }

    /** The flat view has no library screen to load the lists, so this does (and reloads them after an action). The old lists stay until the new ones are in. */
    suspend fun reloadAll() {
        try {
            val f = app.api.folders()
            val all = mutableListOf<app.magnetite.data.Album>()
            var page = 0
            var last = 1
            while (page < last) {
                val r = app.api.albums(page + 1)
                all.addAll(r.data)
                page = r.meta.currentPage
                last = r.meta.lastPage
            }
            folders.clear(); folders.addAll(f)
            albums.clear(); albums.addAll(all)
        } catch (e: kotlinx.coroutines.CancellationException) {
            throw e
        } catch (_: Exception) {
        }
    }
    LaunchedEffect(flatTv) { if (flatTv) reloadAll() }
    // The flat view opens with the focus on the first cover.
    LaunchedEffect(flatTv, albums.isNotEmpty()) {
        if (flatTv && albums.isNotEmpty() && selected == null) { kotlinx.coroutines.delay(150); runCatching { rightFocus.requestFocus() } }
    }
    if (tv && wide) TvFolderDialogs(folderDialog, { folderDialog = it }, app.api, onChanged = { scope.launch { reloadAll() } })
    if (tv && wide) ActionHost(gridActions, app.api, app.player, folders.toList(), onClose = { gridActions = null }, onChanged = { scope.launch { reloadAll() } }, onEdit = onEdit)
    // A step made inside the right pane (a cover, Back) takes the focus along; one made in the tree leaves it there.
    var fromRight by remember { mutableStateOf(false) }
    // The album last opened: the grid scrolls to its cover and focuses it when the album is closed (see FolderGrid.returnTo).
    var lastAlbum by androidx.compose.runtime.saveable.rememberSaveable { mutableStateOf<Int?>(null) }
    LaunchedEffect(selected) { if (selected != null) lastAlbum = selected }
    LaunchedEffect(selected, gridFolder) {
        if (tv && fromRight) {
            fromRight = false
            // back to the grid with the album's cover in it: the grid takes the focus itself, a group focus would land on the first cover
            val coverShown = selected == null && albums.any { it.id == lastAlbum && (app.flat || it.folderId == gridFolder) }
            if (!coverShown) { kotlinx.coroutines.delay(150); runCatching { rightFocus.requestFocus() } }
        }
    }
    // Back: close the open album (to the grid), then go up a folder; at the top level the system decides.
    BackHandler(enabled = tv && wide && (selected != null || (!app.flat && gridFolder != null))) {
        fromRight = true
        if (selected != null) onSelect(null) else gridFolder = folders.firstOrNull { it.id == gridFolder }?.parentId
    }
    val library: @Composable () -> Unit = {
        LibraryScreen(
            app.api, app.player, app.info, app::cycleInfo,
            onOpen = { if (wide) onSelect(it) else openAlbum(it) },
            onSearch = onSearch, onTruth = onTruth, onEdit = onEdit,
            focus = libFocus,
            onSwitchPane = if (wide) ({ if (selected != null) runCatching { albumFocus.requestFocus() } }) else null,
            onPickMeters = onPickMeters, onPickTheme = onPickTheme, onSignOut = onSignOut,
            flat = app.flat, onToggleFlat = app::toggleFlat,
            sharedAlbums = if (tv && wide) albums else null, sharedFolders = if (tv && wide) folders else null,
            onFolder = if (tv && wide) ({ id -> gridFolder = id; onSelect(null) }) else null,
        )
    }
    if (!wide) {
        library()
        return
    }
    val right: @Composable androidx.compose.foundation.layout.BoxScope.() -> Unit = {
        if (selected == null && tv) {
            FolderGrid(
                folders, albums, gridFolder, playingAlbum = app.player.albumId, flat = app.flat, returnTo = lastAlbum, info = app.info,
                onFolder = { fromRight = true; gridFolder = it },
                onAlbum = { fromRight = true; onSelect(it) },
                onActions = { gridActions = Target.OfAlbum(it) },
                onFolderActions = { folderDialog = TvFolderDialog.Menu(it) },
                // There is no tree on the left on a TV, so its actions are up here.
                actions = if (!flatTv || app.flat) ({}) else ({
                    Text("+ Folder", Modifier.tvFocus().clickable { folderDialog = TvFolderDialog.Create(gridFolder) }.padding(horizontal = 10.dp, vertical = 6.dp), color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.titleMedium)
                }),
            )
        } else if (selected == null) {
            Text("Pick an album", Modifier.align(Alignment.Center), color = Palette.Muted)
        } else {
            key(selected) {
                AlbumScreen(
                    app.api, app.player, app.info, selected, onBack = { fromRight = true; onSelect(null) }, onEdit = onEdit, embedded = true,
                    focus = albumFocus, onSwitchPane = { runCatching { libFocus.requestFocus() } },
                )
            }
        }
    }
    // On a TV: no list on the left, the covers (or the open album) take the whole width.
    if (flatTv) {
        Box(Modifier.fillMaxSize().focusRequester(rightFocus).focusGroup(), content = right)
        return
    }
    Row(Modifier.fillMaxSize()) {
        Box(Modifier.weight(1f).fillMaxHeight()) { library() }
        Box(Modifier.weight(1.2f).fillMaxHeight().focusRequester(rightFocus).focusGroup(), content = right)
    }
}
