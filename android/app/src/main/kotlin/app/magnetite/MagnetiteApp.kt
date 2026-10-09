package app.magnetite

import android.app.Application
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

    override fun onCreate() {
        super.onCreate()
        session = Session(this)
        api = Api(session)
        player = PlayerController(this, session)
    }

    /** Covers go through the same OkHttp client, so they carry the bearer token. */
    override fun newImageLoader(context: PlatformContext): ImageLoader =
        ImageLoader.Builder(context)
            .components { add(OkHttpNetworkFetcherFactory(callFactory = { api.client })) }
            .build()
}
