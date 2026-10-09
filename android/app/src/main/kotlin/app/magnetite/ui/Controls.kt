package app.magnetite.ui

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.size
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp

enum class Icon { Previous, Next, Play, Pause }

/** A transport button drawn on a canvas (no icon library needed); [size] is the tap target, the glyph is 40% of it. */
@Composable
fun TransportButton(icon: Icon, description: String, onClick: () -> Unit, size: Dp = 44.dp, color: Color = MaterialTheme.colorScheme.primary.copy(alpha = 0.6f)) {
    Box(
        Modifier.size(size).clickable(role = Role.Button, onClick = onClick).semantics { contentDescription = description },
        contentAlignment = Alignment.Center,
    ) {
        Canvas(Modifier.size(size * 0.4f)) { glyph(icon, color) }
    }
}

private fun DrawScope.glyph(icon: Icon, color: Color) {
    val w = size.width
    val h = size.height
    fun triangle(x: Float, width: Float, right: Boolean) {
        val p = Path().apply {
            if (right) { moveTo(x, 0f); lineTo(x + width, h / 2); lineTo(x, h) } else { moveTo(x + width, 0f); lineTo(x, h / 2); lineTo(x + width, h) }
            close()
        }
        drawPath(p, color)
    }
    when (icon) {
        Icon.Play -> triangle(w * 0.1f, w * 0.85f, true)
        Icon.Pause -> {
            drawRect(color, Offset(w * 0.12f, 0f), Size(w * 0.28f, h))
            drawRect(color, Offset(w * 0.60f, 0f), Size(w * 0.28f, h))
        }
        Icon.Next -> {
            triangle(0f, w * 0.78f, true)
            drawRect(color, Offset(w * 0.82f, 0f), Size(w * 0.18f, h))
        }
        Icon.Previous -> {
            drawRect(color, Offset(0f, 0f), Size(w * 0.18f, h))
            triangle(w * 0.22f, w * 0.78f, false)
        }
    }
}
