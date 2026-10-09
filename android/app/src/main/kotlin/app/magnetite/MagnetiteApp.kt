package app.magnetite

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.setValue
import app.magnetite.data.Api
import app.magnetite.data.Session
import app.magnetite.player.PlayerController
import kotlinx.coroutines.launch
import coil3.ImageLoader
import coil3.PlatformContext
import coil3.SingletonImageLoader
import coil3.network.okhttp.OkHttpNetworkFetcherFactory

class MagnetiteApp : Application(), SingletonImageLoader.Factory {
    lateinit var session: Session
    lateinit var api: Api
    lateinit var player: PlayerController

    /** The meters shown in the player, in panel order; kept in step with `users.meters` on the server. */
    val meters = androidx.compose.runtime.mutableStateListOf("vu-wide")
    private val scope = kotlinx.coroutines.CoroutineScope(kotlinx.coroutines.SupervisorJob() + kotlinx.coroutines.Dispatchers.Main)

    suspend fun loadMeters() {
        runCatching { api.meters() }.onSuccess { meters.clear(); meters.addAll(it) }
    }

    fun setMeters(ids: List<String>) {
        meters.clear(); meters.addAll(ids)
        scope.launch { runCatching { api.saveMeters(ids) } }
    }

    /** Bumped when the session ends without the user asking, so the UI goes back to the sign-in screen. */
    var authVersion by mutableIntStateOf(0)

    override fun onCreate() {
        super.onCreate()
        session = Session(this)
        api = Api(session)
        player = PlayerController(this, session)
        api.onUnauthorized = {
            // The first refused request ends the session; the ones still in flight find it already over.
            if (session.signedIn) {
                player.signOut()
                session.clear()
                authVersion++
            }
        }
    }

    /** Covers go through the same OkHttp client, so they carry the bearer token. */
    override fun newImageLoader(context: PlatformContext): ImageLoader =
        ImageLoader.Builder(context)
            .components { add(OkHttpNetworkFetcherFactory(callFactory = { api.client })) }
            .build()
}
