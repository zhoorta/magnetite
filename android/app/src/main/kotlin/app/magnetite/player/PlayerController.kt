package app.magnetite.player

import android.content.ComponentName
import android.content.Context
import android.os.Handler
import android.os.Looper
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.core.content.ContextCompat
import androidx.core.net.toUri
import androidx.media3.common.C
import androidx.media3.common.MediaItem
import androidx.media3.common.MediaMetadata
import androidx.media3.common.Player
import androidx.media3.session.MediaController
import androidx.media3.session.SessionToken
import app.magnetite.data.Session
import app.magnetite.data.Track

/** Where a queue came from, so Now Playing can go back to that album or playlist. */
sealed interface PlaySource {
    data class Album(val id: Int) : PlaySource
    data class Playlist(val id: Int) : PlaySource
}

private fun PlaySource.encode() = when (this) { is PlaySource.Album -> "album:$id"; is PlaySource.Playlist -> "playlist:$id" }

private fun decodeSource(s: String?): PlaySource? {
    val (kind, id) = s?.split(':', limit = 2)?.takeIf { it.size == 2 } ?: return null
    val n = id.toIntOrNull() ?: return null
    return when (kind) { "album" -> PlaySource.Album(n); "playlist" -> PlaySource.Playlist(n); else -> null }
}

/** Compose-facing handle on the [PlaybackService]'s player. Tracks the current item and play state. */
class PlayerController(context: Context, private val session: Session) {
    private var controller: MediaController? = null
    private val store = PlaybackStore(context)
    private val handler = Handler(Looper.getMainLooper())

    /** The tracks behind the player's queue, kept so the queue can be saved and restored. */
    private var queue: List<Track> = emptyList()
    private var source: PlaySource? = null

    /** The album or playlist the queue was started from; for a mixed list (shared tracks) the album of the current track. */
    fun origin(): PlaySource? =
        source ?: queue.firstOrNull { it.id.toString() == currentId }?.albumId?.let { PlaySource.Album(it) }

    var currentId by mutableStateOf<String?>(null)
        private set
    /** The album the current track belongs to, whatever the queue was started from; the library marks it. */
    var albumId by mutableStateOf<Int?>(null)
        private set
    /** The track that is playing, with what is known about the file (format, DR); read through [currentId] so it follows changes. */
    val current: Track? get() = currentId?.let { id -> queue.firstOrNull { it.id.toString() == id } }

    /** "track 6 of 11" when the queue is one album, else just the track number when there is one (the web's `trackPosition`). */
    fun positionText(): String? {
        val id = currentId ?: return null
        val i = queue.indexOfFirst { it.id.toString() == id }.takeIf { it >= 0 } ?: return null
        val t = queue[i]
        if (t.albumId != null && queue.all { it.albumId == t.albumId }) return "track ${i + 1} of ${queue.size}"
        return t.edit?.trackNumber?.let { "track $it" } ?: t.number?.let { "track $it" }
    }

    var title by mutableStateOf<String?>(null)
        private set
    var artist by mutableStateOf<String?>(null)
        private set
    var isPlaying by mutableStateOf(false)
        private set
    var coverUrl by mutableStateOf<String?>(null)
        private set
    var durationMs by mutableStateOf(0L)
        private set
    var positionMs by mutableStateOf(0L)
        private set

    init {
        val token = SessionToken(context, ComponentName(context, PlaybackService::class.java))
        val future = MediaController.Builder(context, token).buildAsync()
        future.addListener({
            val c = future.get()
            controller = c
            if (c.mediaItemCount == 0) restore(c)
            c.addListener(object : Player.Listener {
                override fun onEvents(player: Player, events: Player.Events) {
                    sync(player)
                    if (events.containsAny(Player.EVENT_MEDIA_ITEM_TRANSITION, Player.EVENT_IS_PLAYING_CHANGED)) save()
                }
            })
            sync(c)
        }, ContextCompat.getMainExecutor(context))
    }

    private fun sync(player: Player) {
        currentId = player.currentMediaItem?.mediaId
        albumId = queue.firstOrNull { it.id.toString() == currentId }?.albumId
        title = player.mediaMetadata.title?.toString()
        artist = player.mediaMetadata.artist?.toString()
        val wasPlaying = isPlaying
        isPlaying = player.isPlaying
        if (isPlaying && !wasPlaying) handler.postDelayed(ticker, 5_000)
        coverUrl = player.mediaMetadata.artworkUri?.toString()
        durationMs = player.duration.takeIf { it != C.TIME_UNSET } ?: 0L
        positionMs = player.currentPosition
    }

    /** Put the saved queue back, paused: nothing starts until the person presses play. */
    private fun restore(c: MediaController) {
        val user = session.username ?: return
        val saved = store.load(user) ?: return
        queue = saved.queue
        source = decodeSource(saved.source)
        c.setMediaItems(queue.map(::mediaItem), saved.index, saved.positionMs)
    }

    private fun save() {
        val c = controller ?: return
        val user = session.username ?: return
        // The queue may have been replaced from outside the app (a car): save only what this screen's list still describes.
        if (queue.isEmpty() || c.mediaItemCount != queue.size) return
        store.save(user, SavedPlayback(queue, c.currentMediaItemIndex, c.currentPosition, source?.encode()))
    }

    // While playing, the position moves without any event, so write it every few seconds.
    private val ticker = object : Runnable {
        override fun run() {
            save()
            if (isPlaying) handler.postDelayed(this, 5_000)
        }
    }

    /** Sign out: stop, empty the player (the notification goes away) and forget what was saved. */
    fun signOut() {
        controller?.run {
            pause()
            clearMediaItems()
        }
        queue = emptyList()
        source = null
        session.username?.let(store::forget)
    }

    /** Called by the screen while it shows a position; the player does not push position changes. */
    fun refreshPosition() {
        val c = controller ?: return
        positionMs = c.currentPosition
        durationMs = c.duration.takeIf { it != C.TIME_UNSET } ?: durationMs
    }

    fun seekTo(ms: Long) {
        controller?.seekTo(ms)
        positionMs = ms
    }

    /** Replace the queue with the tracks the viewer may play (`stream_url` is null for the others) and start at [start]. */
    fun play(tracks: List<Track>, start: Track, from: PlaySource? = null) {
        val c = controller ?: return
        val playable = tracks.filter { it.open && it.streamUrl != null }
        val index = playable.indexOfFirst { it.id == start.id }.takeIf { it >= 0 } ?: return
        queue = playable
        source = from
        c.setMediaItems(playable.map(::mediaItem), index, 0)
        c.prepare()
        c.play()
    }

    fun toggle() {
        val c = controller ?: return
        if (c.isPlaying) {
            c.pause()
        } else {
            // A restored queue is not prepared yet (no network work until the person asks for sound).
            if (c.playbackState == Player.STATE_IDLE) c.prepare()
            c.play()
        }
    }

    fun next() = controller?.seekToNext()
    fun previous() = controller?.seekToPrevious()

    private fun mediaItem(t: Track): MediaItem = t.toMediaItem()
}
