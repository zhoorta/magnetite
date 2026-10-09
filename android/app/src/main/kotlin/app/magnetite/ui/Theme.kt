package app.magnetite.ui

import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.dp
import app.magnetite.R

/** The Terminal theme of the web app (resources/js/magnetite/themes.json): black, phosphor green, monospace, square corners. */
object Terminal {
    val Bg = Color(0xFF000000)
    val Surface = Color(0xFF07140A)
    val Text = Color(0xFFB6FFBD)
    val Muted = Color(0xFF4F8A57)
    val Accent = Color(0xFF39FF6A)
    val Accent2 = Color(0xFFB6FF3A)
    val Red = Color(0xFFFF4D3D)
}

private val colors = darkColorScheme(
    primary = Terminal.Accent, onPrimary = Terminal.Bg,
    primaryContainer = Terminal.Surface, onPrimaryContainer = Terminal.Accent,
    secondary = Terminal.Accent2, onSecondary = Terminal.Bg,
    secondaryContainer = Terminal.Surface, onSecondaryContainer = Terminal.Accent,
    tertiary = Terminal.Accent2,
    background = Terminal.Bg, onBackground = Terminal.Text,
    surface = Terminal.Bg, onSurface = Terminal.Text,
    surfaceVariant = Terminal.Surface, onSurfaceVariant = Terminal.Muted,
    surfaceContainer = Terminal.Surface, surfaceContainerHigh = Terminal.Surface, surfaceContainerHighest = Terminal.Surface,
    outline = Terminal.Muted, outlineVariant = Color(0xFF1F6B33),
    error = Terminal.Red, onError = Terminal.Bg,
)

private fun mono(base: TextStyle) = base.copy(fontFamily = FontFamily.Monospace)

private val typography = Typography().let {
    it.copy(
        displayLarge = mono(it.displayLarge), displayMedium = mono(it.displayMedium), displaySmall = mono(it.displaySmall),
        headlineLarge = mono(it.headlineLarge), headlineMedium = mono(it.headlineMedium), headlineSmall = mono(it.headlineSmall),
        titleLarge = mono(it.titleLarge), titleMedium = mono(it.titleMedium), titleSmall = mono(it.titleSmall),
        bodyLarge = mono(it.bodyLarge), bodyMedium = mono(it.bodyMedium), bodySmall = mono(it.bodySmall),
        labelLarge = mono(it.labelLarge), labelMedium = mono(it.labelMedium), labelSmall = mono(it.labelSmall),
    )
}

private val square = RoundedCornerShape(0.dp)
private val shapes = Shapes(square, square, square, square, square)

@Composable
fun MagnetiteTheme(content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = colors, typography = typography, shapes = shapes, content = content)
}

/** The landing page logo (public/logo.svg rendered at 1024 px): two wide meters over the name. */
@Composable
fun Logo(modifier: Modifier = Modifier) {
    Image(
        painterResource(R.drawable.logo_full), contentDescription = "Magnetite",
        modifier = modifier.widthIn(max = 360.dp).fillMaxWidth(), contentScale = ContentScale.FillWidth,
    )
}
