package app.magnetite.data

import android.os.Build
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException

class ApiException(message: String, val code: Int = 0) : IOException(message)

/** Thin client for `/api/v1`. The bearer token is added to every request, including covers and streams (see [client]). */
class Api(private val session: Session) {
    val json = Json { ignoreUnknownKeys = true }

    val client: OkHttpClient = OkHttpClient.Builder()
        .addInterceptor { chain ->
            val token = session.token
            val request = if (token != null) chain.request().newBuilder().header("Authorization", "Bearer $token").build() else chain.request()
            chain.proceed(request)
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
        }
    }

    suspend fun signOut() {
        runCatching { call(Request.Builder().url(url("/token")).header("Accept", "application/json").delete().build()) }
        session.clear()
    }

    suspend fun albums(page: Int): Page<Album> =
        json.decodeFromString(call(get("/albums?page=$page")))

    suspend fun album(id: Int): Album =
        json.decodeFromString<Envelope<Album>>(call(get("/albums/$id"))).data

    suspend fun playlists(): List<PlaylistSummary> =
        json.decodeFromString<Envelope<List<PlaylistSummary>>>(call(get("/playlists"))).data

    suspend fun playlist(id: Int): Playlist =
        json.decodeFromString<Envelope<Playlist>>(call(get("/playlists/$id"))).data

    suspend fun shared(): Shared = json.decodeFromString(call(get("/shared")))

    private fun get(path: String) = Request.Builder().url(url(path)).header("Accept", "application/json").build()
}
