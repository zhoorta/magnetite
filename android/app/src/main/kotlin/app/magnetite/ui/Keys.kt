package app.magnetite.ui

import androidx.compose.ui.input.key.Key
import androidx.compose.ui.input.key.KeyEvent
import androidx.compose.ui.input.key.KeyEventType
import androidx.compose.ui.input.key.isAltPressed
import androidx.compose.ui.input.key.isCtrlPressed
import androidx.compose.ui.input.key.isMetaPressed
import androidx.compose.ui.input.key.isShiftPressed
import androidx.compose.ui.input.key.key
import androidx.compose.ui.input.key.type
import app.magnetite.player.PlayerController

/** A key press without Ctrl, Alt or Meta: the keys of a hardware keyboard, as in the web and the terminal client. */
fun KeyEvent.isPlain() = type == KeyEventType.KeyDown && !isCtrlPressed && !isAltPressed && !isMetaPressed

/** The player's keys, the same on every screen: Space play/pause, `n` next, `p` previous, `,` and `.` seek 10 s. */
fun playerKey(e: KeyEvent, player: PlayerController): Boolean = when {
    e.key == Key.Spacebar -> { player.toggle(); true }
    e.key == Key.N && !e.isShiftPressed -> { player.next(); true }
    e.key == Key.P && !e.isShiftPressed -> { player.previous(); true }
    e.key == Key.Comma -> { player.seekTo((player.positionMs - 10_000).coerceAtLeast(0)); true }
    e.key == Key.Period -> { player.seekTo(player.positionMs + 10_000); true }
    else -> false
}
