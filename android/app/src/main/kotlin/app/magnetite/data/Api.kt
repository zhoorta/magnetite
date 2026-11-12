package app.magnetite.data

import android.os.Build
import android.os.Handler
import android.os.Looper
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.decodeFromJsonElement
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException

/** One row of the edit table. */
class TrackEditRow(val id: Int, val title: String, val artist: String, val number: Int?)

class ApiException(message: String, val code: Int = 0) : IOException(message)

/** Thin client for `/api/v1`. The bearer token is added to every request, including covers and streams (see [client]). */
class Api(private val session: Session) {
    val json = Json { ignoreUnknownKeys = true }

    /** Called (on the main thread) when the server refuses the stored token: revoked, or the user is gone. */
    var onUnauthorized: (() -> Unit)? = null

    val client: OkHttpClient = OkHttpClient.Builder()
        .addInterceptor { chain ->
            val token = session.token
            val request = if (token != null) chain.request().newBuilder().header("Authorization", "Bearer $token").build() else chain.request()
            chain.proceed(request).also {
                // Covers, streams and API calls all pass here; a sign-in attempt has no token yet, so wrong passwords do not count.
                if (it.code == 401 && token != null) Handler(Looper.getMainLooper()).post { onUnauthorized?.invoke() }
            }
        }
        .build()

    private suspend fun call(request: Request): String = withContext(Dispatchers.IO) {
        client.newCall(request).execute().use { response ->
            val body = response.body.string()
            if (!response.isSuccessful) throw ApiException(errorMessage(body) ?: "HTTP ${response.code}", response.code)
            body
        }
    }

    /** Laravel validation errors come as `{message, errors: {field: [..]}}`. */
    private fun errorMessage(body: String): String? = runCatching {
        val obj = json.parseToJsonElement(body).jsonObject
        obj["errors"]?.jsonObject?.values?.firstOrNull()?.let { (it as? kotlinx.serialization.json.JsonArray)?.firstOrNull()?.jsonPrimitive?.contentOrNull }
            ?: obj["message"]?.jsonPrimitive?.contentOrNull
            ?: obj["error"]?.jsonPrimitive?.contentOrNull
    }.getOrNull()

    private fun url(path: String) = session.serverUrl + "/api/v1" + path

    suspend fun signIn(server: String, username: String, password: String): SignedIn {
        session.serverUrl = server
        val payload: JsonObject = buildJsonObject {
            put("username", username)
            put("password", password)
            put("device_name", "${Build.MANUFACTURER} ${Build.MODEL}")
        }
        val body = call(
            Request.Builder().url(url("/token")).header("Accept", "application/json")
                .post(payload.toString().toRequestBody("application/json".toMediaType())).build(),
        )
        return json.decodeFromString<SignedIn>(body).also {
            session.token = it.token
            session.username = it.user.username
            session.lastUsername = it.user.username
        }
    }

    /** Asks the server for a code to show on a screen without a keyboard; the person approves it at [DeviceCode.verificationUrl]. */
    suspend fun startDeviceLink(server: String): DeviceCode {
        session.serverUrl = server
        val payload = buildJsonObject { put("device_name", "${Build.MANUFACTURER} ${Build.MODEL}") }
        return json.decodeFromString(
            call(Request.Builder().url(url("/device-code")).header("Accept", "application/json")
                .post(payload.toString().toRequestBody("application/json".toMediaType())).build()),
        )
    }

    /** Null while the person has not approved yet; throws [ApiException] `expired_token` when the code is gone. */
    suspend fun pollDeviceLink(deviceCode: String): SignedIn? {
        val payload = buildJsonObject { put("device_code", deviceCode) }
        val body = try {
            call(Request.Builder().url(url("/device-token")).header("Accept", "application/json")
                .post(payload.toString().toRequestBody("application/json".toMediaType())).build())
        } catch (e: ApiException) {
            if (e.message == "authorization_pending") return null
            throw e
        }
        return json.decodeFromString<SignedIn>(body).also {
            session.token = it.token
            session.username = it.user.username
            session.lastUsername = it.user.username
        }
    }

    suspend fun signOut() {
        runCatching { call(Request.Builder().url(url("/token")).header("Accept", "application/json").delete().build()) }
        session.clear()
    }

    /** Puts an album in a folder, or back at the top level when [folderId] is null. */
    suspend fun moveAlbum(albumId: Int, folderId: Int?) {
        call(send("/albums/$albumId/folder", "PUT", buildJsonObject { if (folderId != null) put("folder_id", folderId) else put("folder_id", kotlinx.serialization.json.JsonNull) }))
    }

    /** The playlist's new order, as track ids. */
    suspend fun reorderPlaylist(id: Int, trackIds: List<Int>) {
        call(send("/playlists/$id/order", "PUT", buildJsonObject { put("ids", kotlinx.serialization.json.JsonArray(trackIds.map { kotlinx.serialization.json.JsonPrimitive(it) })) }))
    }

    /** The user's meter list (`users.meters`) and interface choices (`users.ui`). */
    suspend fun me(): Me = json.decodeFromString(call(get("/me")))

    /** Only what is given changes (the server merges). */
    suspend fun saveUi(info: Int? = null, flat: Boolean? = null) {
        call(send("/settings/ui", "PUT", buildJsonObject { if (info != null) put("info", info); if (flat != null) put("flat", flat) }))
    }

    suspend fun saveMeters(ids: List<String>) {
        call(send("/settings/meters", "PUT", buildJsonObject { put("ids", kotlinx.serialization.json.JsonArray(ids.map { kotlinx.serialization.json.JsonPrimitive(it) })) }))
    }

    suspend fun albums(page: Int): Page<Album> =
        json.decodeFromString(call(get("/albums?page=$page")))

    suspend fun search(q: String, page: Int = 1): SearchResult =
        json.decodeFromString(call(get("/search?q=" + java.net.URLEncoder.encode(q, "UTF-8") + "&page=$page")))

    /** The owner's analysed tracks with their measurements, 500 to a page (the Truth view loads them all). */
    suspend fun truthTracks(page: Int): Page<Track> =
        json.decodeFromString(call(get("/tracks?truth=1&per_page=500&page=$page")))

    suspend fun folders(): List<Folder> =
        json.decodeFromString<Envelope<List<Folder>>>(call(get("/folders"))).data

    suspend fun createFolder(name: String, parentId: Int?) {
        call(send("/folders", "POST", buildJsonObject { put("name", name); if (parentId != null) put("parent_id", parentId) }))
    }

    suspend fun renameFolder(id: Int, name: String) {
        call(send("/folders/$id", "PATCH", buildJsonObject { put("name", name) }))
    }

    /** Albums and subfolders move up to the parent; no music is deleted. */
    suspend fun deleteFolder(id: Int) {
        call(Request.Builder().url(url("/folders/$id")).header("Accept", "application/json").delete().build())
    }

    /** Starts the analysis of one album or one track (the server needs a queue worker). */
    suspend fun analyse(albumId: Int? = null, trackId: Int? = null) {
        call(send("/analysis", "POST", buildJsonObject { if (albumId != null) put("album_id", albumId); if (trackId != null) put("track_id", trackId) }))
    }

    /** [kind] is `albums`, `tracks` or `playlists`. */
    suspend fun share(kind: String, id: Int, on: Boolean) {
        call(if (on) send("/share/$kind/$id", "POST", buildJsonObject { }) else send("/share/$kind/$id", "DELETE", buildJsonObject { }))
    }

    suspend fun deleteAlbum(id: Int) { call(send("/albums/$id", "DELETE", buildJsonObject { })) }

    suspend fun deleteTrack(id: Int) { call(send("/tracks/$id", "DELETE", buildJsonObject { })) }

    suspend fun addToPlaylist(playlistId: Int, albumId: Int? = null, trackId: Int? = null) {
        call(send("/playlists/$playlistId/tracks", "POST", buildJsonObject { if (albumId != null) put("album_id", albumId); if (trackId != null) put("track_id", trackId) }))
    }

    /** The edit table: the album's fields and its changed tracks, saved together or not at all. */
    suspend fun saveDetails(albumId: Int, title: String, artist: String, releaseDate: String, label: String, tracks: List<TrackEditRow>) {
        call(send("/albums/$albumId/details", "PATCH", buildJsonObject {
            put("title", title); put("artist", artist); put("release_date", releaseDate); put("label", label)
            put("tracks", kotlinx.serialization.json.JsonArray(tracks.map { t ->
                buildJsonObject { put("id", t.id); put("title", t.title); put("artist", t.artist); if (t.number != null) put("track_number", t.number) else put("track_number", kotlinx.serialization.json.JsonNull) }
            }))
        }))
    }

    private fun send(path: String, method: String, body: JsonObject) =
        Request.Builder().url(url(path)).header("Accept", "application/json")
            .method(method, body.toString().toRequestBody("application/json".toMediaType())).build()

    suspend fun album(id: Int): Album =
        json.decodeFromString<Envelope<Album>>(call(get("/albums/$id"))).data

    suspend fun playlists(): List<PlaylistSummary> =
        json.decodeFromString<Envelope<List<PlaylistSummary>>>(call(get("/playlists"))).data

    suspend fun playlist(id: Int): Playlist =
        json.decodeFromString<Envelope<Playlist>>(call(get("/playlists/$id"))).data

    suspend fun shared(): Shared = json.decodeFromString(call(get("/shared")))

    private fun get(path: String) = Request.Builder().url(url(path)).header("Accept", "application/json").build()
}
