package app.magnetite.ui

import androidx.compose.foundation.gestures.detectDragGesturesAfterLongPress
import androidx.compose.foundation.gestures.scrollBy
import androidx.compose.foundation.lazy.LazyListItemInfo
import androidx.compose.foundation.lazy.LazyListState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch

/**
 * Long-press-and-drag for rows of a LazyColumn. The row being dragged follows the finger (`fingerY` is the finger's
 * position in the list's viewport, so it stays right while the list scrolls under it); what the drag means is up to
 * the screen: [dragRow] reports every finger position and the drop.
 */
class DragState {
    var key by mutableStateOf<Any?>(null)
    var fingerY by mutableFloatStateOf(0f)
    var grabY = 0f
    var lastEnd = 0L

    /** A long press is going on or has just ended: the release must not count as a tap on the row. */
    fun busy() = key != null || android.os.SystemClock.uptimeMillis() - lastEnd < 400
}

/** The visible row under the finger, other than the one being dragged. */
fun LazyListState.rowAt(y: Float, except: Any?): LazyListItemInfo? =
    layoutInfo.visibleItemsInfo.firstOrNull { it.key != except && y >= it.offset && y < it.offset + it.size }

fun Modifier.dragRow(
    drag: DragState,
    list: LazyListState,
    scope: CoroutineScope,
    key: Any,
    enabled: Boolean = true,
    onMove: (fingerY: Float) -> Unit,
    onDrop: () -> Unit,
    onLongPress: (() -> Unit)? = null,
): Modifier {
    fun offset() = list.layoutInfo.visibleItemsInfo.firstOrNull { it.key == key }?.offset ?: 0
    fun edge(y: Float) {
        // near the top or bottom of the list: scroll it, so a long list can be dragged through
        val h = list.layoutInfo.viewportSize.height
        if (y < 120f) scope.launch { list.scrollBy(-28f) } else if (y > h - 120f) scope.launch { list.scrollBy(28f) }
    }
    var moved = false
    return this
        .pointerInput(key, enabled) {
            if (!enabled) return@pointerInput
            detectDragGesturesAfterLongPress(
                onDragStart = { o ->
                    moved = false
                    drag.key = key
                    drag.grabY = o.y
                    drag.fingerY = offset() + o.y
                    onMove(drag.fingerY)
                },
                onDrag = { change, amount ->
                    change.consume()
                    if (kotlin.math.abs(amount.y) > 0.5f || kotlin.math.abs(amount.x) > 0.5f) moved = true
                    drag.fingerY += amount.y
                    edge(drag.fingerY)
                    onMove(drag.fingerY)
                },
                onDragEnd = { drag.lastEnd = android.os.SystemClock.uptimeMillis(); if (!moved && onLongPress != null) onLongPress() else onDrop(); drag.key = null },
                // a release without any movement ends the gesture as a cancel: that is the plain long press
                onDragCancel = { drag.lastEnd = android.os.SystemClock.uptimeMillis(); drag.key = null; if (!moved) onLongPress?.invoke() },
            )
        }
        .graphicsLayer {
            if (drag.key == key) {
                translationY = drag.fingerY - drag.grabY - offset()
                alpha = 0.85f
                scaleX = 1.02f
                scaleY = 1.02f
            }
        }
}
