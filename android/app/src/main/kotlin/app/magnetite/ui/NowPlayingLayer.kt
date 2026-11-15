package app.magnetite.ui

import android.app.Activity
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.expandVertically
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.focusable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.input.key.onPreviewKeyEvent
import androidx.compose.ui.input.key.type
import androidx.compose.ui.input.pointer.PointerEventPass
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.unit.dp
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import app.magnetite.MagnetiteApp
import kotlinx.coroutines.delay

private const val IDLE_MS = 3_000L

/**
 * Now Playing on a phone, like the web's full screen: the meters over everything, and the controls (a top bar and the player bar) hide after
 * three seconds without a touch, taking the system bars with them; any touch brings them back. Back, the arrow or a swipe down leave.
 */
@Composable
fun NowPlayingLayer(app: MagnetiteApp, onPickMeters: () -> Unit, onClose: () -> Unit, onOpenSource: () -> Unit) {
    var chrome by remember { mutableStateOf(true) }
    val lastTouch = remember { longArrayOf(System.nanoTime()) }
    // A poster fills the screen and the controls lie over it; with other meters, or none, they sit above and below the picture and, when
    // hidden, the picture takes their room. Either way they hide after three seconds.
    val hasPoster = app.meters.any { it.startsWith("poster-") }

    // Any touch (watched before the meters and the controls see it, never consumed) shows the controls and restarts the countdown.
    LaunchedEffect(chrome) {
        lastTouch[0] = System.nanoTime()
        while (chrome) {
            delay(250)
            if (System.nanoTime() - lastTouch[0] > IDLE_MS * 1_000_000L) chrome = false
        }
    }

    val view = LocalView.current
    DisposableEffect(chrome) {
        val controller = (view.context as? Activity)?.window?.let { WindowCompat.getInsetsController(it, view) }
        controller?.systemBarsBehavior = WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
        if (chrome) controller?.show(WindowInsetsCompat.Type.systemBars()) else controller?.hide(WindowInsetsCompat.Type.systemBars())
        onDispose { controller?.show(WindowInsetsCompat.Type.systemBars()) }
    }

    BackHandler(onBack = onClose)
    Box(
        Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background).pointerInput(Unit) {
            awaitPointerEventScope {
                while (true) {
                    awaitPointerEvent(PointerEventPass.Initial)
                    lastTouch[0] = System.nanoTime()
                    chrome = true
                }
            }
        },
    ) {
        val topBar = @Composable {
            Surface(color = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.92f)) {
                Row(Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 4.dp, vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                    TransportButton(Icon.Back, "Back", onClose)
                    Text("Now playing", Modifier.weight(1f).padding(start = 8.dp), style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onSurface)
                    TransportButton(Icon.Meters, "Meters", onPickMeters)
                }
            }
        }
        val playerBar = @Composable {
            Surface(color = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.92f)) {
                MiniPlayer(app.player, app.info, app.meters, false, onPickMeters = {}, onOpenSource = onOpenSource) {}
            }
        }
        if (hasPoster) {
            NowPlayingScreen(app.player, app.meters, onPickMeters = onPickMeters, onClose = onClose, onOpenSource = onOpenSource, fullScreen = true, info = app.info)
            AnimatedVisibility(chrome, Modifier.align(Alignment.TopCenter), enter = fadeIn(), exit = fadeOut()) { topBar() }
            AnimatedVisibility(chrome, Modifier.align(Alignment.BottomCenter), enter = fadeIn(), exit = fadeOut()) { playerBar() }
        } else {
            Column(Modifier.fillMaxSize()) {
                AnimatedVisibility(chrome, enter = fadeIn() + expandVertically(), exit = fadeOut() + shrinkVertically()) { topBar() }
                Box(Modifier.weight(1f).fillMaxWidth()) {
                    NowPlayingScreen(app.player, app.meters, onPickMeters = onPickMeters, onClose = onClose, onOpenSource = onOpenSource, fullScreen = true, info = app.info)
                }
                AnimatedVisibility(chrome, enter = fadeIn() + expandVertically(), exit = fadeOut() + shrinkVertically()) { playerBar() }
            }
        }
    }
}

/**
 * Now Playing on a TV: the meters over everything, and the controls (a top bar and the player strip) hide after three seconds without a key or a
 * touch. While they are hidden the first key only shows them again (it is not passed on, so a stray press cannot skip a track or pause).
 * Back leaves; the strip's title opens the album or playlist the music came from.
 */
@Composable
fun TvNowPlayingLayer(app: MagnetiteApp, onPickMeters: () -> Unit, onClose: () -> Unit, onOpenSource: () -> Unit) {
    var chrome by remember { mutableStateOf(true) }
    val lastInput = remember { longArrayOf(System.nanoTime()) }
    val rootFocus = remember { androidx.compose.ui.focus.FocusRequester() }
    val stripFocus = remember { androidx.compose.ui.focus.FocusRequester() }
    val hasPoster = app.meters.any { it.startsWith("poster-") }

    LaunchedEffect(chrome) {
        lastInput[0] = System.nanoTime()
        if (chrome) {
            delay(150)
            runCatching { stripFocus.requestFocus() }
            while (chrome) {
                delay(250)
                if (System.nanoTime() - lastInput[0] > IDLE_MS * 1_000_000L) chrome = false
            }
        } else {
            runCatching { rootFocus.requestFocus() }
        }
    }

    BackHandler(onBack = onClose)
    Box(
        Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)
            .focusRequester(rootFocus).focusable()
            .onPreviewKeyEvent { e ->
                if (e.type != androidx.compose.ui.input.key.KeyEventType.KeyDown) return@onPreviewKeyEvent false
                if (!chrome) { chrome = true; true } else { lastInput[0] = System.nanoTime(); false }
            }
            .pointerInput(Unit) {
                awaitPointerEventScope {
                    while (true) {
                        awaitPointerEvent(PointerEventPass.Initial)
                        lastInput[0] = System.nanoTime()
                        chrome = true
                    }
                }
            },
    ) {
        val topBar = @Composable {
            Surface(color = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.92f)) {
                Row(Modifier.fillMaxWidth().padding(horizontal = 40.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    TransportButton(Icon.Back, "Back", onClose)
                    Text("Now playing", Modifier.weight(1f).padding(start = 8.dp), style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onSurface)
                    TransportButton(Icon.Meters, "Meters", onPickMeters)
                }
            }
        }
        val strip = @Composable {
            Box(Modifier.padding(horizontal = 40.dp, vertical = 20.dp)) {
                TvPlayerStrip(app.player, app.info, app.meters, onOpenSource, compact = true, titleFocus = stripFocus)
            }
        }
        if (hasPoster) {
            Box(Modifier.fillMaxSize().padding(24.dp)) {
                NowPlayingScreen(app.player, app.meters, onPickMeters = onPickMeters, onClose = onClose, onOpenSource = onOpenSource, fullScreen = true, info = app.info)
            }
            AnimatedVisibility(chrome, Modifier.align(Alignment.TopCenter), enter = fadeIn(), exit = fadeOut()) { topBar() }
            AnimatedVisibility(chrome, Modifier.align(Alignment.BottomCenter), enter = fadeIn(), exit = fadeOut()) { strip() }
        } else {
            Column(Modifier.fillMaxSize()) {
                AnimatedVisibility(chrome, enter = fadeIn() + expandVertically(), exit = fadeOut() + shrinkVertically()) { topBar() }
                Box(Modifier.weight(1f).fillMaxWidth().padding(24.dp)) {
                    NowPlayingScreen(app.player, app.meters, onPickMeters = onPickMeters, onClose = onClose, onOpenSource = onOpenSource, fullScreen = true, info = app.info)
                }
                AnimatedVisibility(chrome, enter = fadeIn() + expandVertically(), exit = fadeOut() + shrinkVertically()) { strip() }
            }
        }
    }
}
