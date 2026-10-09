package app.magnetite.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class Track(
    val id: Int,
    val title: String,
    val artist: String? = null,
    val album: String? = null,
    @SerialName("album_id") val albumId: Int? = null,
    val number: Int? = null,
    val disc: Int? = null,
    val duration: Double? = null,
    val codec: String? = null,
    @SerialName("cover_url") val coverUrl: String? = null,
    val open: Boolean = true,
    @SerialName("stream_url") val streamUrl: String? = null,
    @SerialName("shared_by") val sharedBy: String? = null,
)

@Serializable
data class Album(
    val id: Int,
    val title: String,
    val artist: String? = null,
    val year: Int? = null,
    @SerialName("cover_url") val coverUrl: String? = null,
    @SerialName("track_count") val trackCount: Int? = null,
    val tracks: List<Track> = emptyList(),
    @SerialName("shared_by") val sharedBy: String? = null,
    @SerialName("folder_id") val folderId: Int? = null,
)

@Serializable
data class Folder(
    val id: Int,
    @SerialName("parent_id") val parentId: Int? = null,
    val name: String,
    @SerialName("album_count") val albumCount: Int = 0,
)

@Serializable
data class PlaylistSummary(
    val id: Int,
    val name: String,
    @SerialName("track_count") val trackCount: Int = 0,
    val shared: Boolean = false,
    @SerialName("shared_by") val sharedBy: String? = null,
)

@Serializable
data class Playlist(val id: Int, val name: String, val mine: Boolean = true, val tracks: List<Track> = emptyList())

@Serializable
data class Shared(
    val albums: List<Album> = emptyList(),
    val tracks: List<Track> = emptyList(),
    val playlists: List<PlaylistSummary> = emptyList(),
)

@Serializable
data class Envelope<T>(val data: T)

@Serializable
data class PageMeta(@SerialName("current_page") val currentPage: Int, @SerialName("last_page") val lastPage: Int)

@Serializable
data class Page<T>(val data: List<T>, val meta: PageMeta)

@Serializable
data class SignedIn(val token: String, val user: UserInfo)

@Serializable
data class UserInfo(val id: Int, val username: String)
