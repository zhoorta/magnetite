package app.magnetite

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.clickable
import androidx.compose.ui.Alignment
import androidx.compose.ui.unit.dp
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import kotlinx.coroutines.launch
import app.magnetite.ui.AlbumScreen
import app.magnetite.ui.LibraryScreen
import app.magnetite.ui.LoginScreen
import app.magnetite.ui.MagnetiteTheme
import app.magnetite.ui.MeterPicker
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

        // Android 13+ shows the media notification only with the first; playback works without it.
        // Android 17+ blocks connections to a private-network server (home, 10.0.2.2) without the second.
        requestPermissions(arrayOf(android.Manifest.permission.POST_NOTIFICATIONS, "android.permission.ACCESS_LOCAL_NETWORK"), 1)

        setContent {
            MagnetiteTheme {
                var signedIn by remember { mutableStateOf(app.session.signedIn) }
                var tab by remember { mutableStateOf(Tab.Library) }
                var detail by remember { mutableStateOf<Detail?>(null) }
                var showNowPlaying by remember { mutableStateOf(false) }
                var pickMeters by remember { mutableStateOf(false) }
                if (pickMeters) MeterPicker(app.meters.toList(), { app.setMeters(it) }, { pickMeters = false })
                val scope = androidx.compose.runtime.rememberCoroutineScope()
                androidx.compose.runtime.LaunchedEffect(signedIn) { if (signedIn) app.loadMeters() }
                androidx.compose.runtime.LaunchedEffect(app.authVersion) { signedIn = app.session.signedIn; if (!signedIn) { detail = null; showNowPlaying = false } }

                Column(Modifier.fillMaxSize()) {
                    Box(Modifier.weight(1f)) {
                        val d = detail
                        when {
                            !signedIn -> LoginScreen(app.api, app.session.serverUrl, app.session.lastUsername) { signedIn = true }
                            showNowPlaying -> NowPlayingScreen(app.player, app.meters, { pickMeters = true }, onClose = { showNowPlaying = false })
                            d is Detail.Album -> AlbumScreen(app.api, app.player, d.id, onBack = { detail = null })
                            d is Detail.Playlist -> PlaylistScreen(app.api, app.player, d.id, onBack = { detail = null })
                            tab == Tab.Library -> LibraryScreen(
                                app.api,
                                onOpen = { detail = Detail.Album(it) },
                                onPickMeters = { pickMeters = true },
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
                    if (signedIn && (!showNowPlaying || app.meters.isNotEmpty())) {
                        HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
                        MiniPlayer(app.player, app.meters, !showNowPlaying, { pickMeters = true }) { showNowPlaying = true }
                        HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
                        Row(Modifier.fillMaxWidth().height(40.dp)) {
                            Tab.entries.forEach { t ->
                                val selected = tab == t && detail == null
                                Box(
                                    Modifier.weight(1f).fillMaxHeight().clickable { tab = t; detail = null },
                                    contentAlignment = Alignment.Center,
                                ) {
                                    Text(
                                        t.label, style = MaterialTheme.typography.labelLarge,
                                        color = if (selected) MaterialTheme.colorScheme.primary.copy(alpha = 0.6f) else MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
