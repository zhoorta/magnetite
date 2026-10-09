package app.magnetite

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Text
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import kotlinx.coroutines.launch
import app.magnetite.ui.AlbumScreen
import app.magnetite.ui.LibraryScreen
import app.magnetite.ui.LoginScreen
import app.magnetite.ui.MiniPlayer
import app.magnetite.ui.NowPlayingScreen
import app.magnetite.ui.PlaylistScreen
import app.magnetite.ui.PlaylistsScreen
import app.magnetite.ui.SharedScreen

private enum class Tab(val label: String) { Library("Library"), Shared("Shared"), Playlists("Playlists") }

/** A page opened from a tab; Back returns to the tab. */
private sealed interface Detail {
    data class Album(val id: Int) : Detail
    data class Playlist(val id: Int) : Detail
}

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val app = application as MagnetiteApp

        // Android 13+ shows the media notification only with this permission; playback works without it.
        requestPermissions(arrayOf(android.Manifest.permission.POST_NOTIFICATIONS), 1)

        setContent {
            MaterialTheme(colorScheme = darkColorScheme()) {
                var signedIn by remember { mutableStateOf(app.session.signedIn) }
                var tab by remember { mutableStateOf(Tab.Library) }
                var detail by remember { mutableStateOf<Detail?>(null) }
                var showNowPlaying by remember { mutableStateOf(false) }
                val scope = androidx.compose.runtime.rememberCoroutineScope()

                Column(Modifier.fillMaxSize()) {
                    Box(Modifier.weight(1f)) {
                        val d = detail
                        when {
                            !signedIn -> LoginScreen(app.api, app.session.serverUrl) { signedIn = true }
                            showNowPlaying -> NowPlayingScreen(app.player, onClose = { showNowPlaying = false })
                            d is Detail.Album -> AlbumScreen(app.api, app.player, d.id, onBack = { detail = null })
                            d is Detail.Playlist -> PlaylistScreen(app.api, app.player, d.id, onBack = { detail = null })
                            tab == Tab.Library -> LibraryScreen(
                                app.api,
                                onOpen = { detail = Detail.Album(it) },
                                onSignOut = {
                                    // The player forgets the saved queue while the user name is still known.
                                    app.player.signOut()
                                    scope.launch { app.api.signOut() }
                                    signedIn = false
                                },
                            )
                            tab == Tab.Shared -> SharedScreen(
                                app.api, app.player,
                                onOpenAlbum = { detail = Detail.Album(it) },
                                onOpenPlaylist = { detail = Detail.Playlist(it) },
                            )
                            else -> PlaylistsScreen(app.api) { detail = Detail.Playlist(it) }
                        }
                    }
                    if (signedIn && !showNowPlaying) {
                        MiniPlayer(app.player) { showNowPlaying = true }
                        NavigationBar {
                            Tab.entries.forEach { t ->
                                NavigationBarItem(
                                    selected = tab == t && detail == null,
                                    onClick = { tab = t; detail = null },
                                    icon = {},
                                    label = { Text(t.label) },
                                    alwaysShowLabel = true,
                                )
                            }
                        }
                    }
                }
            }
        }
    }
}
