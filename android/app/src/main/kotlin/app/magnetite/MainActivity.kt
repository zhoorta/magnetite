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
import app.magnetite.ui.LibraryPanes
import app.magnetite.ui.LocalTv
import app.magnetite.ui.LoginScreen
import app.magnetite.ui.MagnetiteTheme
import app.magnetite.ui.MeterPicker
import app.magnetite.ui.ThemePicker
import app.magnetite.ui.MiniPlayer
import app.magnetite.player.PlaySource
import androidx.compose.ui.focus.focusProperties
import androidx.compose.foundation.background
import androidx.compose.ui.input.pointer.pointerInput
import app.magnetite.ui.NowPlayingLayer
import app.magnetite.ui.NowPlayingScreen
import app.magnetite.ui.PlaylistScreen
import app.magnetite.ui.PlaylistsScreen
import app.magnetite.ui.SharedScreen
import app.magnetite.ui.SearchScreen
import app.magnetite.ui.TruthScreen
import app.magnetite.ui.Palette
import app.magnetite.ui.EditScreen
import app.magnetite.ui.TvApp
import app.magnetite.ui.isTelevision

private enum class Tab(val label: String) { Library("Library"), Shared("Shared"), Playlists("Playlists") }

/** A page opened from a tab; Back returns to the tab. */
private sealed interface Detail {
    data class Album(val id: Int) : Detail
    data class Playlist(val id: Int) : Detail
    data object Search : Detail
    data object Truth : Detail
    data class Edit(val id: Int) : Detail
}

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val app = application as MagnetiteApp

        // Android 13+ shows the media notification only with the first; playback works without it.
        // Android 17+ blocks connections to a private-network server (home, 10.0.2.2) without the second.
        requestPermissions(arrayOf(android.Manifest.permission.POST_NOTIFICATIONS, "android.permission.ACCESS_LOCAL_NETWORK"), 1)

        preferHighRefreshRate()

        setContent {
            MagnetiteTheme {
                val tv = isTelevision(this@MainActivity)
                if (tv) {
                    androidx.compose.runtime.CompositionLocalProvider(LocalTv provides true) { TvApp(app) }
                    return@MagnetiteTheme
                }
                var signedIn by remember { mutableStateOf(app.session.signedIn) }
                var tab by remember { mutableStateOf(Tab.Library) }
                var detail by remember { mutableStateOf<Detail?>(null) }
                var showNowPlaying by remember { mutableStateOf(false) }
                // On a wide screen (a tablet, an unfolded phone, a window) the library and the open album sit side by side.
                val wide = androidx.compose.ui.platform.LocalConfiguration.current.screenWidthDp >= 720
                var selected by remember { mutableStateOf<Int?>(null) }
                var pickMeters by remember { mutableStateOf(false) }
                var pickTheme by remember { mutableStateOf(false) }
                if (pickTheme) ThemePicker({ app.setLook(it) }, { pickTheme = false })
                if (pickMeters) MeterPicker(app.meters.toList(), { app.setMeters(it) }, { pickMeters = false })
                val scope = androidx.compose.runtime.rememberCoroutineScope()
                androidx.compose.runtime.LaunchedEffect(signedIn) { if (signedIn) app.loadMe() }
                androidx.compose.runtime.LaunchedEffect(app.authVersion) { signedIn = app.session.signedIn; if (!signedIn) { detail = null; showNowPlaying = false } }

                Box(Modifier.fillMaxSize()) {
                Column(Modifier.fillMaxSize()) {
                    Box(Modifier.weight(1f)) {
                        val d = detail
                        if (!signedIn) LoginScreen(app.api, app.session.serverUrl, app.session.lastUsername) { signedIn = true }
                        // The library and the open page stay composed under Now Playing (their scroll, folders and selection survive it); they only stop taking focus.
                        else Box(Modifier.fillMaxSize().then(if (showNowPlaying) Modifier.focusProperties { canFocus = false } else Modifier)) { when {
                            d is Detail.Album && !wide -> AlbumScreen(app.api, app.player, app.info, d.id, onBack = { detail = null }, onEdit = { detail = Detail.Edit(it) }, onPickMeters = { pickMeters = true })
                            d is Detail.Playlist -> PlaylistScreen(app.api, app.player, d.id, onBack = { detail = null }, info = app.info)
                            d is Detail.Search -> SearchScreen(app.api, app.player, app.info, onOpenAlbum = { detail = Detail.Album(it) }, onBack = { detail = null })
                            d is Detail.Edit -> EditScreen(app.api, d.id, onBack = { if (wide) { selected = d.id; detail = null } else detail = Detail.Album(d.id) }, onSaved = { if (wide) { selected = d.id; detail = null } else detail = Detail.Album(d.id) })
                            d is Detail.Truth -> TruthScreen(app.api, app.player, onBack = { detail = null })
                            tab == Tab.Library || (d is Detail.Album && wide) -> {
                                androidx.compose.runtime.LaunchedEffect(d) { if (wide && d is Detail.Album) { selected = d.id; detail = null } }
                                LibraryPanes(
                                    app, wide, selected, { selected = it }, { detail = Detail.Album(it) },
                                    onSearch = { detail = Detail.Search }, onTruth = { detail = Detail.Truth }, onEdit = { detail = Detail.Edit(it) },
                                    onPickMeters = { pickMeters = true }, onPickTheme = { pickTheme = true },
                                    onSignOut = {
                                        // The player forgets the saved queue while the user name is still known.
                                        app.player.signOut()
                                        scope.launch { app.api.signOut() }
                                        signedIn = false
                                    },
                                )
                            }
                            tab == Tab.Shared -> SharedScreen(
                                app.api, app.player,
                                onOpenAlbum = { detail = Detail.Album(it) },
                                onOpenPlaylist = { detail = Detail.Playlist(it) },
                            )
                            else -> PlaylistsScreen(app.api) { detail = Detail.Playlist(it) }
                        } }
                    }
                    if (signedIn) {
                        HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
                        MiniPlayer(
                            app.player, app.info, app.meters, !showNowPlaying, { pickMeters = true },
                            onOpenSource = {
                                when (val o = app.player.origin()) {
                                    is PlaySource.Album -> { tab = Tab.Library; detail = Detail.Album(o.id) }
                                    is PlaySource.Playlist -> detail = Detail.Playlist(o.id)
                                    null -> showNowPlaying = true
                                }
                            },
                        ) { showNowPlaying = true }
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
                // Now Playing covers everything, the player bar and tabs included; its own bars hide after a few seconds and come back on a touch.
                if (signedIn && showNowPlaying) NowPlayingLayer(
                    app, onPickMeters = { pickMeters = true }, onClose = { showNowPlaying = false },
                    onOpenSource = {
                        when (val o = app.player.origin()) {
                            is PlaySource.Album -> detail = Detail.Album(o.id)
                            is PlaySource.Playlist -> detail = Detail.Playlist(o.id)
                            null -> return@NowPlayingLayer
                        }
                        showNowPlaying = false
                    },
                )
                }
            }
        }
    }

    /** The meters animate without touch, which makes adaptive-refresh phones drop to 60 Hz or less; ask for the fastest mode at the current resolution. */
    @Suppress("DEPRECATION")
    private fun preferHighRefreshRate() {
        val display = (if (android.os.Build.VERSION.SDK_INT >= 30) display else windowManager.defaultDisplay) ?: return
        val current = display.mode
        val best = display.supportedModes
            .filter { it.physicalWidth == current.physicalWidth && it.physicalHeight == current.physicalHeight }
            .maxByOrNull { it.refreshRate } ?: return
        window.attributes = window.attributes.also { it.preferredDisplayModeId = best.modeId }
    }
}
