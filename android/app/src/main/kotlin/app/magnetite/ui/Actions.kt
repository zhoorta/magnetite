package app.magnetite.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import app.magnetite.data.Album
import app.magnetite.data.Api
import app.magnetite.data.Folder
import app.magnetite.data.PlaylistSummary
import app.magnetite.data.Track
import app.magnetite.player.PlaySource
import app.magnetite.player.PlayerController
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

/** What a long press was on: one of the owner's albums or tracks. */
sealed interface Target {
    data class OfAlbum(val album: Album) : Target
    data class OfTrack(val track: Track) : Target
}

private class Item(val label: String, val danger: Boolean = false, val run: () -> Unit)

private sealed interface Step {
    data object Menu : Step
    data object Move : Step
    data object Playlists : Step
    data object ConfirmDelete : Step
    data class Note(val text: String) : Step
}

/**
 * The ⋯ menu of an album or a track of the owner, opened by a long press: play, analyse, edit, share, add to a playlist,
 * move to a folder (albums) and delete. What the server refuses is shown as a note. [onChanged] says something changed
 * and the screen should reload; [onEdit] opens the edit table of an album.
 */
@Composable
fun ActionHost(
    target: Target?, api: Api, player: PlayerController, folders: List<Folder>,
    onClose: () -> Unit, onChanged: () -> Unit, onEdit: (albumId: Int) -> Unit,
) {
    if (target == null) return
    var step by remember(target) { mutableStateOf<Step>(Step.Menu) }
    val scope = rememberCoroutineScope()
    val title = when (target) { is Target.OfAlbum -> target.album.title; is Target.OfTrack -> target.track.title }
    val shared = when (target) { is Target.OfAlbum -> target.album.shared == true; is Target.OfTrack -> target.track.shared == true }

    /** Runs a server call; the result is a note, and the screen reloads when it worked. */
    fun run(done: String, reload: Boolean = true, block: suspend () -> Unit) = scope.launch {
        try {
            block()
            if (reload) onChanged()
            step = Step.Note(done)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            step = Step.Note(e.message ?: "Failed.")
        }
    }

    when (val s = step) {
        Step.Menu -> {
            val items = buildList {
                add(Item("Play") {
                    scope.launch {
                        try {
                            when (target) {
                                is Target.OfAlbum -> api.album(target.album.id).let { a -> a.tracks.firstOrNull { it.open }?.let { player.play(a.tracks, it, PlaySource.Album(a.id)) } }
                                is Target.OfTrack -> player.play(listOf(target.track), target.track)
                            }
                            onClose()
                        } catch (e: CancellationException) { throw e } catch (e: Exception) { step = Step.Note(e.message ?: "Failed.") }
                    }
                })
                add(Item("Analyse") {
                    when (target) {
                        is Target.OfAlbum -> run("Analysis started.", reload = false) { api.analyse(albumId = target.album.id) }
                        is Target.OfTrack -> run("Analysis started.", reload = false) { api.analyse(trackId = target.track.id) }
                    }
                })
                val editAlbum = when (target) { is Target.OfAlbum -> target.album.id; is Target.OfTrack -> target.track.albumId }
                if (editAlbum != null) add(Item("Edit…") { onClose(); onEdit(editAlbum) })
                add(Item(if (shared) "Stop sharing" else "Share") {
                    val on = !shared
                    when (target) {
                        is Target.OfAlbum -> run(if (on) "Shared." else "No longer shared.") { api.share("albums", target.album.id, on) }
                        is Target.OfTrack -> run(if (on) "Shared." else "No longer shared.") { api.share("tracks", target.track.id, on) }
                    }
                })
                add(Item("Add to playlist…") { step = Step.Playlists })
                if (target is Target.OfAlbum) add(Item("Move to folder…") { step = Step.Move })
                add(Item("Delete…", danger = true) { step = Step.ConfirmDelete })
            }
            ActionList(title, items, onClose)
        }
        Step.Move -> {
            val album = (target as Target.OfAlbum).album
            fun depth(f: Folder): Int { var n = 0; var at = f.parentId; while (at != null && n < 20) { n++; at = folders.firstOrNull { it.id == at }?.parentId }; return n }
            fun ordered(parent: Int?): List<Folder> = folders.filter { it.parentId == parent }.sortedBy { it.name.lowercase() }.flatMap { listOf(it) + ordered(it.id) }
            val items = listOf(Item("(top level)") { run("Moved.") { api.moveAlbum(album.id, null) } }) +
                ordered(null).map { f -> Item("  ".repeat(depth(f)) + f.name) { run("Moved to ${f.name}.") { api.moveAlbum(album.id, f.id) } } }
            ActionList("Move \"$title\" to", items, onClose)
        }
        Step.Playlists -> {
            var lists by remember { mutableStateOf<List<PlaylistSummary>?>(null) }
            androidx.compose.runtime.LaunchedEffect(Unit) {
                try { lists = api.playlists().filter { it.sharedBy == null } } catch (e: CancellationException) { throw e } catch (e: Exception) { step = Step.Note(e.message ?: "Failed.") }
            }
            val items = (lists ?: emptyList()).map { p ->
                Item(p.name) {
                    when (target) {
                        is Target.OfAlbum -> run("Added to ${p.name}.", reload = false) { api.addToPlaylist(p.id, albumId = target.album.id) }
                        is Target.OfTrack -> run("Added to ${p.name}.", reload = false) { api.addToPlaylist(p.id, trackId = target.track.id) }
                    }
                }
            }
            ActionList("Add \"$title\" to", if (lists == null) emptyList() else items.ifEmpty { listOf(Item("No playlists yet") { }) }, onClose)
        }
        Step.ConfirmDelete -> AlertDialog(
            onDismissRequest = onClose,
            title = { Text("Delete \"$title\"?") },
            text = { Text("The files are removed from the server.") },
            confirmButton = {
                TextButton({
                    when (target) {
                        is Target.OfAlbum -> run("Deleted.") { api.deleteAlbum(target.album.id) }
                        is Target.OfTrack -> run("Deleted.") { api.deleteTrack(target.track.id) }
                    }
                }) { Text("Delete", color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = { TextButton(onClose) { Text("Cancel") } },
        )
        is Step.Note -> AlertDialog(
            onDismissRequest = onClose, title = { Text(title) }, text = { Text(s.text) },
            confirmButton = { TextButton(onClose) { Text("OK") } },
        )
    }
}

@Composable
private fun ActionList(title: String, items: List<Item>, onClose: () -> Unit) {
    AlertDialog(
        onDismissRequest = onClose,
        title = { Text(title, maxLines = 2) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                items.forEach { i ->
                    Text(
                        i.label, Modifier.fillMaxWidth().clickable(onClick = i.run).padding(vertical = 11.dp),
                        color = if (i.danger) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurface,
                    )
                }
            }
        },
        confirmButton = { TextButton(onClose) { Text("Close") } },
    )
}
