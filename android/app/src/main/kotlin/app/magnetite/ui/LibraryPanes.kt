package app.magnetite.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.focusGroup
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Text
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
) {
    val libFocus = remember { FocusRequester() }
    val albumFocus = remember { FocusRequester() }
    val tv = LocalTv.current
    // On a TV the right pane shows the contents of a folder with the covers when no album is open; the lists are the library screen's own.
    val albums = remember { androidx.compose.runtime.mutableStateListOf<app.magnetite.data.Album>() }
    val folders = remember { androidx.compose.runtime.mutableStateListOf<app.magnetite.data.Folder>() }
    var gridFolder by remember { mutableStateOf<Int?>(null) }
    val rightFocus = remember { FocusRequester() }
    // A step made inside the right pane (a cover, Back) takes the focus along; one made in the tree leaves it there.
    var fromRight by remember { mutableStateOf(false) }
    LaunchedEffect(selected, gridFolder) {
        if (tv && fromRight) { fromRight = false; kotlinx.coroutines.delay(150); runCatching { rightFocus.requestFocus() } }
    }
    // Back: close the open album (to the grid), then go up a folder; at the top level the system decides.
    BackHandler(enabled = tv && wide && (selected != null || gridFolder != null)) {
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
    Row(Modifier.fillMaxSize()) {
        Box(Modifier.weight(1f).fillMaxHeight()) { library() }
        Box(Modifier.weight(1.2f).fillMaxHeight().focusRequester(rightFocus).focusGroup()) {
            if (selected == null && tv) {
                FolderGrid(
                    folders, albums, gridFolder, playingAlbum = app.player.albumId,
                    onFolder = { fromRight = true; gridFolder = it },
                    onAlbum = { fromRight = true; onSelect(it) },
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
    }
}
