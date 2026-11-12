package app.magnetite.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** A finding about a file (`level`: problem, warn or note), from the server's FileTruth. */
@Serializable
data class Flag(val code: String, val level: String, val label: String, val detail: String = "")

/** What `magnetite:analyze` measured; only sent for the owner's own tracks. */
@Serializable
data class Truth(
    val analyzed: Boolean = false,
    val dr: Int? = null,
    @SerialName("dr_class") val drClass: String? = null,
    val lufs: Double? = null,
    @SerialName("true_peak") val truePeak: Double? = null,
    @SerialName("sample_rate") val sampleRate: Int? = null,
    val bits: Int? = null,
    @SerialName("declared_bits") val declaredBits: Int? = null,
    val flags: List<Flag> = emptyList(),
)

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
    val truth: Truth? = null,
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
    /** The mean dynamic range of the tracks (the owner's albums only, once analysed). */
    val dr: Int? = null,
    @SerialName("display_artist") val displayArtist: String? = null,
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

/** The user's choices kept on the server: the info level (0 off, 1 DR, 2 DR and findings) and the meter tint. */
@Serializable
data class UiSettings(val info: Int = 2, val tint: Boolean = false)

@Serializable
data class Me(val meters: List<String> = listOf("vu-wide"), val ui: UiSettings = UiSettings())

@Serializable
data class SignedIn(val token: String, val user: UserInfo)

@Serializable
data class UserInfo(val id: Int, val username: String)

@Serializable
data class DeviceCode(
    @SerialName("device_code") val deviceCode: String,
    @SerialName("user_code") val userCode: String,
    @SerialName("verification_url") val verificationUrl: String,
    @SerialName("expires_in") val expiresIn: Int,
    val interval: Int = 5,
)

/** `GET /search`: albums and tracks that match, 25 and 50 a page; `more` says another page follows. */
@Serializable
data class SearchResult(val albums: List<Album> = emptyList(), val tracks: List<Track> = emptyList(), val page: Int = 1, val more: More = More()) {
    val hasMore get() = more.albums || more.tracks
}

@Serializable
data class More(val albums: Boolean = false, val tracks: Boolean = false)
