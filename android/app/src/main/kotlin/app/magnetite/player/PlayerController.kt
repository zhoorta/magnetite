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

/** Compose-facing handle on the [PlaybackService]'s player. Tracks the current item and play state. */
class PlayerController(context: Context, private val session: Session) {
    private var controller: MediaController? = null
    private val store = PlaybackStore(context)
    private val handler = Handler(Looper.getMainLooper())

    /** The tracks behind the player's queue, kept so the queue can be saved and restored. */
    private var queue: List<Track> = emptyList()

    var currentId by mutableStateOf<String?>(null)
        private set
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
        c.setMediaItems(queue.map(::mediaItem), saved.index, saved.positionMs)
    }

    private fun save() {
        val c = controller ?: return
        val user = session.username ?: return
        if (queue.isEmpty() || c.mediaItemCount == 0) return
        store.save(user, SavedPlayback(queue, c.currentMediaItemIndex, c.currentPosition))
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
    fun play(tracks: List<Track>, start: Track) {
        val c = controller ?: return
        val playable = tracks.filter { it.open && it.streamUrl != null }
        val index = playable.indexOfFirst { it.id == start.id }.takeIf { it >= 0 } ?: return
        queue = playable
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

    private fun mediaItem(t: Track): MediaItem =
        MediaItem.Builder()
            .setMediaId(t.id.toString())
            .setUri(t.streamUrl!!)
            .setMediaMetadata(
                MediaMetadata.Builder()
                    .setTitle(t.title)
                    .setArtist(t.artist)
                    .setAlbumTitle(t.album)
                    .setArtworkUri(t.coverUrl?.toUri())
                    .build(),
            )
            .build()
}
