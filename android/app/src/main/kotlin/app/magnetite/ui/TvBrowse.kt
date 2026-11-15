package app.magnetite.ui

import androidx.compose.foundation.clickable
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
fun FolderGrid(folders: List<Folder>, albums: List<Album>, folderId: Int?, onFolder: (Int?) -> Unit, onAlbum: (Int) -> Unit, playingAlbum: Int? = null) {
    val path = generateSequence(folders.firstOrNull { it.id == folderId }) { f -> folders.firstOrNull { it.id == f.parentId } }.toList().reversed()
    val subfolders = folders.filter { it.parentId == folderId }
    val here = albums.filter { it.folderId == folderId }
    Column(Modifier.fillMaxSize()) {
        Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
            if (folderId != null) {
                Text(
                    "← Back", Modifier.tvFocus().clickable { onFolder(path.dropLast(1).lastOrNull()?.id) }.padding(horizontal = 10.dp, vertical = 6.dp),
                    color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.titleMedium,
                )
            }
            Text(
                (listOf("Library") + path.map { it.name }).joinToString(" / "), Modifier.weight(1f).padding(start = 8.dp),
                color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.titleMedium, maxLines = 1, overflow = TextOverflow.Ellipsis,
            )
            Text("${subfolders.size + here.size}", color = Palette.Muted, style = MaterialTheme.typography.bodySmall)
        }
        if (subfolders.isEmpty() && here.isEmpty()) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { Text("Nothing here", color = Palette.Muted) }
            return@Column
        }
        LazyVerticalGrid(
            GridCells.Adaptive(138.dp), Modifier.fillMaxSize(),
            contentPadding = androidx.compose.foundation.layout.PaddingValues(12.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp), verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            items(subfolders, key = { "f${it.id}" }) { f ->
                Column(Modifier.tvFocus().clickable { onFolder(f.id) }.padding(4.dp)) {
                    Box(
                        Modifier.fillMaxWidth().aspectRatio(1f).border(1.dp, MaterialTheme.colorScheme.outline, MaterialTheme.shapes.small),
                        contentAlignment = Alignment.Center,
                    ) { Text("▸ ${f.albumCount}", color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.headlineSmall) }
                    Text(f.name, Modifier.padding(top = 4.dp), maxLines = 1, overflow = TextOverflow.Ellipsis, color = MaterialTheme.colorScheme.primary)
                    Text(if (f.albumCount == 1) "1 album" else "${f.albumCount} albums", color = Palette.Muted, style = MaterialTheme.typography.bodySmall, maxLines = 1)
                }
            }
            items(here, key = { "a${it.id}" }) { a ->
                Column(Modifier.tvFocus().clickable { onAlbum(a.id) }.padding(4.dp)) {
                    Cover(a.coverUrl, Modifier.fillMaxWidth())
                    Text(a.title, Modifier.padding(top = 4.dp), maxLines = 1, overflow = TextOverflow.Ellipsis, color = if (a.id == playingAlbum) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface)
                    Text(a.displayArtist ?: a.artist ?: "", color = Palette.Muted, style = MaterialTheme.typography.bodySmall, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
            }
        }
    }
}
