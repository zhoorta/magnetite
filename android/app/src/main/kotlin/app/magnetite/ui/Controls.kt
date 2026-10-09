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

enum class Icon { Previous, Next, Play, Pause, NewFolder, Menu, Back, Meters }

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
        Icon.NewFolder -> {
            // folder outline: tab on the left, body below, a plus in the middle
            val line = h * 0.09f
            val p = Path().apply {
                moveTo(0f, h * 0.12f); lineTo(w * 0.38f, h * 0.12f); lineTo(w * 0.5f, h * 0.28f); lineTo(w, h * 0.28f)
                lineTo(w, h * 0.92f); lineTo(0f, h * 0.92f); close()
            }
            drawPath(p, color, style = androidx.compose.ui.graphics.drawscope.Stroke(line))
            drawLine(color, Offset(w * 0.5f, h * 0.45f), Offset(w * 0.5f, h * 0.77f), line)
            drawLine(color, Offset(w * 0.34f, h * 0.61f), Offset(w * 0.66f, h * 0.61f), line)
        }
        Icon.Back -> {
            // a left chevron
            val line = h * 0.12f
            drawLine(color, Offset(w * 0.65f, h * 0.1f), Offset(w * 0.2f, h * 0.5f), line, androidx.compose.ui.graphics.StrokeCap.Square)
            drawLine(color, Offset(w * 0.2f, h * 0.5f), Offset(w * 0.65f, h * 0.9f), line, androidx.compose.ui.graphics.StrokeCap.Square)
        }
        // level bars of different heights, like a small meter
        Icon.Meters -> listOf(0.45f, 0.9f, 0.65f, 0.3f).forEachIndexed { i, v ->
            drawRect(color, Offset(w * (0.04f + i * 0.25f), h * (1 - v)), Size(w * 0.17f, h * v))
        }
        Icon.Menu -> for (i in 0..2) drawCircle(color, w * 0.1f, Offset(w / 2, h * (0.15f + 0.35f * i)))
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
