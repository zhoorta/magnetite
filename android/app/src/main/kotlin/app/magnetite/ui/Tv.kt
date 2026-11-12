package app.magnetite.ui

import android.app.UiModeManager
import android.content.Context
import android.content.res.Configuration
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.composed
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.input.key.key
import androidx.compose.ui.input.key.onPreviewKeyEvent
import androidx.compose.ui.input.key.type
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp

/** True on Android TV and other 10-foot devices; the UI then uses the remote-first screens. */
fun isTelevision(context: Context): Boolean =
    (context.getSystemService(Context.UI_MODE_SERVICE) as UiModeManager).currentModeType == Configuration.UI_MODE_TYPE_TELEVISION

/** Are we on a TV? Set once at the top (`MainActivity`); screens use it to turn the touch and keyboard shortcuts off and the remote's focus on. */
val LocalTv = androidx.compose.runtime.staticCompositionLocalOf { false }

/** Marks the focused item for a remote: a tinted background. Put it before `clickable` in the chain so it sees that focus. Does nothing off a TV. */
fun Modifier.tvFocus(): Modifier = composed {
    if (!LocalTv.current) return@composed Modifier
    var focused by remember { mutableStateOf(false) }
    val tint = MaterialTheme.colorScheme.primary
    Modifier.onFocusChanged { focused = it.isFocused }
        .background(if (focused) tint.copy(alpha = 0.28f) else Color.Transparent, RoundedCornerShape(8.dp))
        .border(if (focused) 2.dp else 0.dp, if (focused) tint else Color.Transparent, RoundedCornerShape(8.dp))
}

/** On a TV a text field would keep the remote's up and down keys for itself (they move its cursor); let them move the focus to the next item instead. */
fun Modifier.dpadLeavesField(): Modifier = composed {
    if (!LocalTv.current) return@composed Modifier
    val focus = androidx.compose.ui.platform.LocalFocusManager.current
    Modifier.onPreviewKeyEvent { e ->
        if (e.type != androidx.compose.ui.input.key.KeyEventType.KeyDown) return@onPreviewKeyEvent false
        when (e.key) {
            androidx.compose.ui.input.key.Key.DirectionDown -> focus.moveFocus(androidx.compose.ui.focus.FocusDirection.Down)
            androidx.compose.ui.input.key.Key.DirectionUp -> focus.moveFocus(androidx.compose.ui.focus.FocusDirection.Up)
            else -> false
        }
    }
}
