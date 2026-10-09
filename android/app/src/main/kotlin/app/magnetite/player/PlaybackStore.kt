package app.magnetite.player

import android.content.Context
import app.magnetite.data.Track
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json

@Serializable
data class SavedPlayback(val queue: List<Track>, val index: Int, val positionMs: Long)

/**
 * Queue, current track and position, per user, so the app comes back where it was (paused). Like the web player's
 * `localStorage`. It holds track metadata and stream urls, never the token; signing out forgets it.
 */
class PlaybackStore(context: Context) {
    private val prefs = context.getSharedPreferences("playback", Context.MODE_PRIVATE)
    private val json = Json { ignoreUnknownKeys = true }

    fun save(username: String, state: SavedPlayback) {
        prefs.edit().putString(username, json.encodeToString(state)).apply()
    }

    fun load(username: String): SavedPlayback? =
        prefs.getString(username, null)?.let { runCatching { json.decodeFromString<SavedPlayback>(it) }.getOrNull() }
            ?.takeIf { it.index in it.queue.indices }

    fun forget(username: String) {
        prefs.edit().remove(username).apply()
    }
}
