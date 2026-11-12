package app.magnetite.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.key
import androidx.compose.runtime.remember
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
    val library: @Composable () -> Unit = {
        LibraryScreen(
            app.api, app.player, app.info, app::cycleInfo,
            onOpen = { if (wide) onSelect(it) else openAlbum(it) },
            onSearch = onSearch, onTruth = onTruth, onEdit = onEdit,
            focus = libFocus,
            onSwitchPane = if (wide) ({ if (selected != null) runCatching { albumFocus.requestFocus() } }) else null,
            onPickMeters = onPickMeters, onPickTheme = onPickTheme, onSignOut = onSignOut,
            flat = app.flat, onToggleFlat = app::toggleFlat,
        )
    }
    if (!wide) {
        library()
        return
    }
    Row(Modifier.fillMaxSize()) {
        Box(Modifier.weight(1f).fillMaxHeight()) { library() }
        Box(Modifier.weight(1.2f).fillMaxHeight()) {
            if (selected == null) {
                Text("Pick an album", Modifier.align(Alignment.Center), color = Palette.Muted)
            } else {
                key(selected) {
                    AlbumScreen(
                        app.api, app.player, app.info, selected, onBack = { onSelect(null) }, onEdit = onEdit, embedded = true,
                        focus = albumFocus, onSwitchPane = { runCatching { libFocus.requestFocus() } },
                    )
                }
            }
        }
    }
}
