package app.magnetite.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.clickable
import androidx.compose.foundation.focusGroup
import androidx.compose.foundation.focusable
import androidx.compose.ui.focus.focusProperties
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.input.key.key
import androidx.compose.ui.input.key.onPreviewKeyEvent
import androidx.compose.ui.input.key.type
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.dp
import app.magnetite.MagnetiteApp
import app.magnetite.player.PlaySource
import kotlinx.coroutines.launch

/** The width, in dp, the TV layout is drawn at: about what a 1080p set reports, a little more so that nothing is huge. */
private const val TV_WIDTH_DP = 1200f

private enum class TvTab(val label: String) { Library("Library"), Shared("Shared"), Playlists("Playlists"), Meters("Meters"), SignOut("Sign out") }

private sealed interface TvDetail {
    data class Album(val id: Int) : TvDetail
    data class Playlist(val id: Int) : TvDetail
    data object Search : TvDetail
    data object Truth : TvDetail
    data class Edit(val id: Int) : TvDetail
}

/**
 * The remote-first shell for Android TV: a side rail, the library on the left with the open album beside it (the tablet's two panes, driven
 * by the remote's focus), and the player strip along the bottom (title, seek bar, the first meter, transport). The layout is drawn at a fixed 1200 dp width and the margins keep clear of the screen's edge (overscan).
 * Reuses the phone's screens; `LocalTv` turns their keyboard shortcuts off and their focus marks on.
 */
@OptIn(androidx.compose.ui.ExperimentalComposeUiApi::class)
@Composable
fun TvApp(app: MagnetiteApp) {
    var signedIn by remember { mutableStateOf(app.session.signedIn) }
    var tab by remember { mutableStateOf(TvTab.Library) }
    var detail by remember { mutableStateOf<TvDetail?>(null) }
    var selected by remember { mutableStateOf<Int?>(null) }
    var showNowPlaying by remember { mutableStateOf(false) }
    var fullScreen by remember { mutableStateOf(false) }
    val stripFocus = remember { androidx.compose.ui.focus.FocusRequester() }
    var pickMeters by remember { mutableStateOf(false) }
    LaunchedEffect(signedIn) { if (signedIn) app.loadMe() }
    LaunchedEffect(app.authVersion) { signedIn = app.session.signedIn; if (!signedIn) { detail = null; showNowPlaying = false } }
    val scope = androidx.compose.runtime.rememberCoroutineScope()

    // When a page opens or closes from inside the content, the item that had the focus is gone and the focus falls back to the first one on the
    // screen (the rail); put it back into the content. A choice made on the rail itself keeps the focus there.
    val focusManager = androidx.compose.ui.platform.LocalFocusManager.current
    var fromRail by remember { mutableStateOf(false) }
    var firstRun by remember { mutableStateOf(true) }
    LaunchedEffect(detail) {
        if (firstRun) { firstRun = false; return@LaunchedEffect }
        kotlinx.coroutines.delay(300)
        // the search page puts the focus in its text field itself
        if (!fromRail && detail !is TvDetail.Search) focusManager.moveFocus(androidx.compose.ui.focus.FocusDirection.Right)
        fromRail = false
    }

    // Every TV reports its own density (and 4K ones a huge one): lay the screen out as if it were TV_WIDTH_DP wide, so it looks the same on all of them.
    val d0 = LocalDensity.current
    val widthPx = androidx.compose.ui.platform.LocalConfiguration.current.screenWidthDp * d0.density
    CompositionLocalProvider(LocalDensity provides Density(widthPx / TV_WIDTH_DP, d0.fontScale)) {
        if (pickMeters) MeterPicker(app.meters.toList(), { app.setMeters(it) }, { pickMeters = false })
        if (showNowPlaying) BackHandler { showNowPlaying = false; fullScreen = false }
        if (fullScreen) BackHandler { fullScreen = false }

        if (!signedIn) {
            TvLoginScreen(app.api, app.session.serverUrl, app.session.lastUsername) { signedIn = true }
            return@CompositionLocalProvider
        }

        fun signOut() {
            app.player.signOut()
            scope.launch { app.api.signOut() }
            signedIn = false
        }

        // Full screen: only the meters, nothing else; any key brings the rest back.
        if (showNowPlaying && fullScreen) {
            val fsFocus = remember { androidx.compose.ui.focus.FocusRequester() }
            LaunchedEffect(Unit) { runCatching { fsFocus.requestFocus() } }
            Box(
                Modifier.fillMaxSize().padding(24.dp).focusRequester(fsFocus).focusable().onPreviewKeyEvent { e ->
                    if (e.type == androidx.compose.ui.input.key.KeyEventType.KeyDown) { fullScreen = false; true } else false
                },
            ) {
                NowPlayingScreen(app.player, app.meters, { pickMeters = true }, onClose = { fullScreen = false }, onOpenSource = {}, fullScreen = true)
            }
            return@CompositionLocalProvider
        }

        // Back from a page opened over a tab goes to that tab; from the tab itself, the system leaves the app.
        if (detail != null && !showNowPlaying) BackHandler { detail = null }

        Column(Modifier.fillMaxSize().padding(horizontal = 40.dp, vertical = 20.dp)) {
            Row(Modifier.weight(1f)) {
                if (!showNowPlaying) {
                    Column(Modifier.fillMaxHeight().width(148.dp).padding(end = 12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Logo(Modifier.padding(bottom = 16.dp).width(96.dp))
                        TvTab.entries.forEach { t ->
                            val on = tab == t && detail == null
                            Text(
                                t.label,
                                Modifier.fillMaxWidth().tvFocus()
                                    .clickable {
                                        fromRail = detail != null
                                        when (t) {
                                            TvTab.Meters -> pickMeters = true
                                            TvTab.SignOut -> signOut()
                                            else -> { tab = t; detail = null }
                                        }
                                    }
                                    .padding(horizontal = 10.dp, vertical = 8.dp),
                                style = MaterialTheme.typography.titleMedium,
                                color = if (on) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface,
                            )
                        }
                    }
                }
                // Down from the last row goes to the player strip. Without this the focus search picks the nearest item below by position, which for a short
                // list is one of the rail's (they sit beside the rows).
                Box(
                    Modifier.weight(1f).fillMaxHeight().focusGroup().focusProperties {
                        exit = { dir -> if (dir == androidx.compose.ui.focus.FocusDirection.Down) stripFocus else androidx.compose.ui.focus.FocusRequester.Default }
                    },
                ) {
                    val d = detail
                    when {
                        showNowPlaying -> NowPlayingScreen(
                            app.player, app.meters, { pickMeters = true }, onClose = { showNowPlaying = false },
                            fullScreen = false, onFullScreen = { fullScreen = true },
                            onOpenSource = {
                                when (val o = app.player.origin()) {
                                    is PlaySource.Album -> detail = TvDetail.Album(o.id)
                                    is PlaySource.Playlist -> detail = TvDetail.Playlist(o.id)
                                    null -> return@NowPlayingScreen
                                }
                                showNowPlaying = false
                            },
                        )
                        d is TvDetail.Album -> AlbumScreen(app.api, app.player, app.info, d.id, onBack = { detail = null }, onEdit = { detail = TvDetail.Edit(it) })
                        d is TvDetail.Playlist -> PlaylistScreen(app.api, app.player, d.id, onBack = { detail = null }, info = app.info)
                        d is TvDetail.Search -> SearchScreen(app.api, app.player, app.info, onOpenAlbum = { detail = TvDetail.Album(it) }, onBack = { detail = null })
                        d is TvDetail.Truth -> TruthScreen(app.api, app.player, onBack = { detail = null })
                        d is TvDetail.Edit -> EditScreen(app.api, d.id, onBack = { selected = d.id; detail = null }, onSaved = { selected = d.id; detail = null })
                        tab == TvTab.Shared -> SharedScreen(
                            app.api, app.player,
                            onOpenAlbum = { detail = TvDetail.Album(it) },
                            onOpenPlaylist = { detail = TvDetail.Playlist(it) },
                        )
                        tab == TvTab.Playlists -> PlaylistsScreen(app.api) { detail = TvDetail.Playlist(it) }
                        else -> LibraryPanes(
                            app, wide = true, selected = selected, onSelect = { selected = it }, openAlbum = { selected = it },
                            onSearch = { detail = TvDetail.Search }, onTruth = { detail = TvDetail.Truth }, onEdit = { detail = TvDetail.Edit(it) },
                            onPickMeters = { pickMeters = true }, onPickTheme = { }, onSignOut = ::signOut,
                        )
                    }
                }
            }
            if (!showNowPlaying || app.meters.isNotEmpty()) {
                HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
                TvPlayerStrip(app.player, app.info, app.meters, { showNowPlaying = true }, compact = showNowPlaying, titleFocus = stripFocus)
            }
        }
    }
}
