package app.magnetite.player

import androidx.core.net.toUri
import androidx.media3.common.MediaItem
import androidx.media3.common.MediaMetadata
import app.magnetite.data.Album
import app.magnetite.data.Api
import app.magnetite.data.PlaylistSummary
import app.magnetite.data.Track
import java.util.concurrent.ConcurrentHashMap

/**
 * What a car (Android Auto) or any other media browser sees of the library: Library (folders and albums), Playlists and
 * Shared, with albums and playlists opening to their tracks. Everything comes from the same API as the app, so a person
 * sees only what they may play; ids are the API's own, prefixed with the kind.
 */
class LibraryTree(private val api: Api) {
    private val tracks = ConcurrentHashMap<String, Track>()
    private val siblings = ConcurrentHashMap<String, List<String>>()

    fun root(): MediaItem = browsable(ROOT, "Magnetite")

    suspend fun children(parentId: String): List<MediaItem> = when {
        parentId == ROOT -> listOf(browsable(LIBRARY, "Library"), browsable(PLAYLISTS, "Playlists"), browsable(SHARED, "Shared"))
        parentId == LIBRARY -> folderContents(null)
        parentId.startsWith("folder:") -> folderContents(parentId.substringAfter(':').toIntOrNull())
        parentId.startsWith("album:") -> trackItems(api.album(parentId.substringAfter(':').toInt()).tracks)
        parentId == PLAYLISTS -> api.playlists().map(::playlistItem)
        parentId.startsWith("playlist:") -> trackItems(api.playlist(parentId.substringAfter(':').toInt()).tracks)
        parentId == SHARED -> api.shared().let { s -> s.albums.map(::albumItem) + s.playlists.map(::playlistItem) + trackItems(s.tracks) }
        else -> emptyList()
    }

    /**
     * Turns what a browser asks to play into queue items. A browser that sends one track gets the whole list that track
     * was browsed in, starting there, so tapping a song in an album plays the album on.
     */
    fun resolve(items: List<MediaItem>, startIndex: Int): Pair<List<MediaItem>, Int> {
        val first = items.firstOrNull() ?: return items to startIndex
        if (items.size == 1) {
            val list = siblings[first.mediaId]?.mapNotNull { tracks[it] }.orEmpty()
            val at = list.indexOfFirst { it.id.toString() == first.mediaId }
            if (at >= 0) return list.map { it.toMediaItem() } to at
        }
        return items.map { item -> if (item.localConfiguration != null) item else tracks[item.mediaId]?.toMediaItem() ?: item } to startIndex
    }

    private suspend fun folderContents(parent: Int?): List<MediaItem> {
        val folders = api.folders().filter { it.parentId == parent }.map { browsable("folder:${it.id}", it.name) }
        val albums = allAlbums().filter { it.folderId == parent }.map(::albumItem)
        return folders + albums
    }

    private suspend fun allAlbums(): List<Album> {
        val out = mutableListOf<Album>()
        var page = 1
        do {
            val p = api.albums(page)
            out += p.data
            page = p.meta.currentPage + 1
        } while (page <= p.meta.lastPage)
        return out
    }

    private fun trackItems(list: List<Track>): List<MediaItem> {
        val playable = list.filter { it.open && it.streamUrl != null }
        val ids = playable.map { it.id.toString() }
        playable.forEach { tracks[it.id.toString()] = it; siblings[it.id.toString()] = ids }
        return playable.map { it.toMediaItem() }
    }

    private fun albumItem(a: Album) = MediaItem.Builder()
        .setMediaId("album:${a.id}")
        .setMediaMetadata(
            MediaMetadata.Builder().setTitle(a.title).setArtist(a.artist).setArtworkUri(a.coverUrl?.toUri())
                .setIsBrowsable(true).setIsPlayable(false).setMediaType(MediaMetadata.MEDIA_TYPE_ALBUM).build(),
        ).build()

    private fun playlistItem(p: PlaylistSummary) = MediaItem.Builder()
        .setMediaId("playlist:${p.id}")
        .setMediaMetadata(
            MediaMetadata.Builder().setTitle(p.name).setIsBrowsable(true).setIsPlayable(false)
                .setMediaType(MediaMetadata.MEDIA_TYPE_PLAYLIST).build(),
        ).build()

    private fun browsable(id: String, title: String) = MediaItem.Builder()
        .setMediaId(id)
        .setMediaMetadata(
            MediaMetadata.Builder().setTitle(title).setIsBrowsable(true).setIsPlayable(false)
                .setMediaType(MediaMetadata.MEDIA_TYPE_FOLDER_MIXED).build(),
        ).build()

    companion object {
        const val ROOT = "root"
        const val LIBRARY = "library"
        const val PLAYLISTS = "playlists"
        const val SHARED = "shared"
    }
}
