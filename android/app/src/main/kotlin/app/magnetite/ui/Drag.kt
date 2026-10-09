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
): Modifier {
    fun offset() = list.layoutInfo.visibleItemsInfo.firstOrNull { it.key == key }?.offset ?: 0
    fun edge(y: Float) {
        // near the top or bottom of the list: scroll it, so a long list can be dragged through
        val h = list.layoutInfo.viewportSize.height
        if (y < 120f) scope.launch { list.scrollBy(-28f) } else if (y > h - 120f) scope.launch { list.scrollBy(28f) }
    }
    return this
        .pointerInput(key, enabled) {
            if (!enabled) return@pointerInput
            detectDragGesturesAfterLongPress(
                onDragStart = { o ->
                    drag.key = key
                    drag.grabY = o.y
                    drag.fingerY = offset() + o.y
                    onMove(drag.fingerY)
                },
                onDrag = { change, amount ->
                    change.consume()
                    drag.fingerY += amount.y
                    edge(drag.fingerY)
                    onMove(drag.fingerY)
                },
                onDragEnd = { onDrop(); drag.key = null },
                onDragCancel = { drag.key = null },
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
