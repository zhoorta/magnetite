package app.magnetite.player

import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import androidx.media3.common.AudioAttributes
import androidx.media3.common.C
import androidx.media3.datasource.DataSourceBitmapLoader
import androidx.media3.datasource.okhttp.OkHttpDataSource
import androidx.media3.exoplayer.DefaultRenderersFactory
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.audio.AudioSink
import androidx.media3.exoplayer.audio.DefaultAudioSink
import androidx.media3.exoplayer.source.DefaultMediaSourceFactory
import androidx.media3.common.MediaItem
import androidx.media3.session.LibraryResult
import androidx.media3.session.MediaLibraryService
import androidx.media3.session.MediaSession
import androidx.media3.session.SessionResult
import com.google.common.collect.ImmutableList
import com.google.common.util.concurrent.Futures
import com.google.common.util.concurrent.ListenableFuture
import com.google.common.util.concurrent.SettableFuture
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import app.magnetite.MagnetiteApp
import app.magnetite.MainActivity

/**
 * Owns the player and the media session, so playback, the notification, lock-screen controls and Bluetooth keys
 * outlive the activity. Streams and cover art go through the app's OkHttp client, which adds the Bearer token.
 */
class PlaybackService : MediaLibraryService() {
    private var session: MediaLibrarySession? = null
    private val job = SupervisorJob()
    private val work = CoroutineScope(job + Dispatchers.IO)

    override fun onCreate() {
        super.onCreate()
        val api = (application as MagnetiteApp).api
        val http = OkHttpDataSource.Factory(api.client)

        // The renderers are the stock ones; only the audio sink gets MeterTap added to its processors.
        val renderers = object : DefaultRenderersFactory(this) {
            override fun buildAudioSink(context: Context, enableFloatOutput: Boolean, enableAudioTrackPlaybackParams: Boolean): AudioSink =
                DefaultAudioSink.Builder(context)
                    .setEnableFloatOutput(enableFloatOutput)
                    .setEnableAudioTrackPlaybackParams(enableAudioTrackPlaybackParams)
                    .setAudioProcessors(arrayOf(MeterTap))
                    .build()
        }
        val player = ExoPlayer.Builder(this, renderers)
            .setMediaSourceFactory(DefaultMediaSourceFactory(this).setDataSourceFactory(http))
            .setAudioAttributes(AudioAttributes.Builder().setContentType(C.AUDIO_CONTENT_TYPE_MUSIC).setUsage(C.USAGE_MEDIA).build(), true)
            .setHandleAudioBecomingNoisy(true)
            .build()

        player.addListener(object : androidx.media3.common.Player.Listener {
            override fun onMediaItemTransition(mediaItem: androidx.media3.common.MediaItem?, reason: Int) = Loudness.reset()
        })

        val app = application as MagnetiteApp
        val tree = LibraryTree(api)

        /** Runs [block] off the main thread and hands back its result; a network failure is an error for the browser, not a crash. */
        fun <T : Any> future(block: suspend () -> LibraryResult<T>): ListenableFuture<LibraryResult<T>> {
            val f = SettableFuture.create<LibraryResult<T>>()
            work.launch {
                f.set(try { block() } catch (e: CancellationException) { throw e } catch (e: Exception) { android.util.Log.w("Magnetite", "Browsing the library failed", e); LibraryResult.ofError(LibraryResult.RESULT_ERROR_IO) })
            }
            return f
        }

        val callback = object : MediaLibrarySession.Callback {
            override fun onGetLibraryRoot(session: MediaLibrarySession, browser: MediaSession.ControllerInfo, params: LibraryParams?): ListenableFuture<LibraryResult<MediaItem>> =
                Futures.immediateFuture(
                    if (app.session.signedIn) LibraryResult.ofItem(tree.root(), params)
                    else LibraryResult.ofError(SessionResult.RESULT_ERROR_SESSION_AUTHENTICATION_EXPIRED),
                )

            override fun onGetChildren(session: MediaLibrarySession, browser: MediaSession.ControllerInfo, parentId: String, page: Int, pageSize: Int, params: LibraryParams?): ListenableFuture<LibraryResult<ImmutableList<MediaItem>>> =
                future { LibraryResult.ofItemList(ImmutableList.copyOf(tree.children(parentId).drop(page * pageSize).take(pageSize)), params) }

            override fun onGetItem(session: MediaLibrarySession, browser: MediaSession.ControllerInfo, mediaId: String): ListenableFuture<LibraryResult<MediaItem>> =
                Futures.immediateFuture(LibraryResult.ofError(LibraryResult.RESULT_ERROR_NOT_SUPPORTED))

            override fun onSetMediaItems(mediaSession: MediaSession, controller: MediaSession.ControllerInfo, mediaItems: MutableList<MediaItem>, startIndex: Int, startPositionMs: Long): ListenableFuture<MediaSession.MediaItemsWithStartPosition> {
                val (items, start) = tree.resolve(mediaItems, startIndex)
                return Futures.immediateFuture(MediaSession.MediaItemsWithStartPosition(items, start, startPositionMs))
            }

            override fun onAddMediaItems(mediaSession: MediaSession, controller: MediaSession.ControllerInfo, mediaItems: MutableList<MediaItem>): ListenableFuture<MutableList<MediaItem>> =
                Futures.immediateFuture(tree.resolve(mediaItems, 0).first.toMutableList())
        }

        session = MediaLibrarySession.Builder(this, player, callback)
            .setBitmapLoader(DataSourceBitmapLoader.Builder(this).setDataSourceFactory(http).build())
            .setSessionActivity(
                PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT),
            )
            .build()
    }

    override fun onGetSession(controllerInfo: MediaSession.ControllerInfo): MediaLibrarySession? = session

    /** Swiping the app away stops playback only when nothing is playing; music keeps going otherwise. */
    override fun onTaskRemoved(rootIntent: Intent?) {
        val player = session?.player
        if (player == null || !player.playWhenReady || player.mediaItemCount == 0) stopSelf()
    }

    override fun onDestroy() {
        session?.run {
            player.release()
            release()
        }
        session = null
        job.cancel()
        super.onDestroy()
    }
}
