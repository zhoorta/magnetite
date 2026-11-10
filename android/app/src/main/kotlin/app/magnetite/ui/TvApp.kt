package app.magnetite.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import app.magnetite.MagnetiteApp
import app.magnetite.data.Album
import app.magnetite.player.PlaySource
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

private enum class TvTab(val label: String) { Library("Library"), Shared("Shared"), Playlists("Playlists"), Meters("Meters"), SignOut("Sign out") }

private sealed interface TvDetail {
    data class Album(val id: Int) : TvDetail
    data class Playlist(val id: Int) : TvDetail
}

/** The remote-first shell for Android TV: a side rail, an album grid, and the player along the bottom. Reuses the phone's detail, player and picker screens. */
@Composable
fun TvApp(app: MagnetiteApp) {
    var signedIn by remember { mutableStateOf(app.session.signedIn) }
    var tab by remember { mutableStateOf(TvTab.Library) }
    var detail by remember { mutableStateOf<TvDetail?>(null) }
    var showNowPlaying by remember { mutableStateOf(false) }
    var pickMeters by remember { mutableStateOf(false) }
    LaunchedEffect(signedIn) { if (signedIn) app.loadMeters() }
    LaunchedEffect(app.authVersion) { signedIn = app.session.signedIn; if (!signedIn) { detail = null; showNowPlaying = false } }
    val scope = androidx.compose.runtime.rememberCoroutineScope()

    if (pickMeters) MeterPicker(app.meters.toList(), { app.setMeters(it) }, { pickMeters = false })
    if (showNowPlaying) BackHandler { showNowPlaying = false }

    if (!signedIn) {
        TvLoginScreen(app.api, app.session.serverUrl, app.session.lastUsername) { signedIn = true }
        return
    }

    Column(Modifier.fillMaxSize()) {
        Row(Modifier.weight(1f)) {
            if (!showNowPlaying) {
                Column(
                    Modifier.fillMaxHeight().width(200.dp).padding(horizontal = 16.dp, vertical = 32.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    Logo(Modifier.padding(bottom = 16.dp).width(96.dp))
                    TvTab.entries.forEach { t ->
                        val selected = tab == t && detail == null
                        Text(
                            t.label,
                            Modifier.fillMaxWidth().tvFocus()
                                .clickable {
                                    when (t) {
                                        TvTab.Meters -> pickMeters = true
                                        TvTab.SignOut -> {
                                            app.player.signOut()
                                            scope.launch { app.api.signOut() }
                                            signedIn = false
                                        }
                                        else -> { tab = t; detail = null }
                                    }
                                }
                                .padding(12.dp),
                            style = MaterialTheme.typography.titleMedium,
                            color = if (selected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface,
                        )
                    }
                }
            }
            Box(Modifier.weight(1f).fillMaxHeight()) {
                val d = detail
                when {
                    showNowPlaying -> NowPlayingScreen(
                        app.player, app.meters, { pickMeters = true }, onClose = { showNowPlaying = false },
                        onOpenSource = {
                            when (val o = app.player.origin()) {
                                is PlaySource.Album -> detail = TvDetail.Album(o.id)
                                is PlaySource.Playlist -> detail = TvDetail.Playlist(o.id)
                                null -> return@NowPlayingScreen
                            }
                            showNowPlaying = false
                        },
                    )
                    d is TvDetail.Album -> AlbumScreen(app.api, app.player, d.id, onBack = { detail = null })
                    d is TvDetail.Playlist -> PlaylistScreen(app.api, app.player, d.id, onBack = { detail = null })
                    tab == TvTab.Shared -> SharedScreen(
                        app.api, app.player,
                        onOpenAlbum = { detail = TvDetail.Album(it) },
                        onOpenPlaylist = { detail = TvDetail.Playlist(it) },
                    )
                    tab == TvTab.Playlists -> PlaylistsScreen(app.api) { detail = TvDetail.Playlist(it) }
                    else -> TvAlbumGrid(app) { detail = TvDetail.Album(it) }
                }
            }
        }
        if (!showNowPlaying || app.meters.isNotEmpty()) {
            HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
            MiniPlayer(app.player, app.meters, !showNowPlaying, { pickMeters = true }) { showNowPlaying = true }
        }
    }
}

/** Every album as a cover grid, folders flattened; the first card takes focus so the remote has somewhere to start. */
@Composable
private fun TvAlbumGrid(app: MagnetiteApp, onOpen: (Int) -> Unit) {
    val albums = remember { mutableStateListOf<Album>() }
    var error by remember { mutableStateOf<String?>(null) }
    val first = remember { FocusRequester() }
    LaunchedEffect(Unit) {
        try {
            var page = 0
            var last = 1
            albums.clear()
            while (page < last) {
                val next = app.api.albums(page + 1)
                albums.addAll(next.data)
                page = next.meta.currentPage
                last = next.meta.lastPage
                if (page == 1 && albums.isNotEmpty()) runCatching { first.requestFocus() }
            }
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message
        }
    }
    if (albums.isEmpty()) {
        Text(error ?: "Loading…", Modifier.padding(32.dp))
        return
    }
    LazyVerticalGrid(
        GridCells.Adaptive(180.dp), Modifier.fillMaxSize(),
        contentPadding = androidx.compose.foundation.layout.PaddingValues(32.dp),
        horizontalArrangement = Arrangement.spacedBy(20.dp), verticalArrangement = Arrangement.spacedBy(20.dp),
    ) {
        items(albums, key = { it.id }) { a ->
            Column(
                Modifier.then(if (a.id == albums.first().id) Modifier.focusRequester(first) else Modifier)
                    .tvFocus().clickable { onOpen(a.id) }.padding(8.dp),
            ) {
                Cover(a.coverUrl, Modifier.fillMaxWidth().aspectRatio(1f))
                Text(a.title, Modifier.padding(top = 8.dp), maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.bodyLarge)
                a.artist?.let { Text(it, maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.bodySmall) }
            }
        }
    }
}
