package app.magnetite

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.setValue
import app.magnetite.data.Api
import app.magnetite.data.Session
import app.magnetite.player.PlayerController
import coil3.ImageLoader
import coil3.PlatformContext
import coil3.SingletonImageLoader
import coil3.network.okhttp.OkHttpNetworkFetcherFactory

class MagnetiteApp : Application(), SingletonImageLoader.Factory {
    lateinit var session: Session
    lateinit var api: Api
    lateinit var player: PlayerController

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
