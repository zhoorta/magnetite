package app.magnetite.ui

import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ColorScheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.platform.LocalView
import androidx.core.view.WindowCompat
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.graphics.lerp
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.dp
import app.magnetite.R

/** A theme of the web app (resources/js/magnetite/themes.json); Terminal is the default. Fonts are system families only. */
class Look(
    val id: String, val name: String, val dark: Boolean,
    val bg: Long, val surface: Long, val text: Long, val muted: Long, val accent: Long, val accent2: Long,
    val radius: Int, val font: FontFamily,
)

val Looks = listOf(
    Look("terminal", "Terminal", true, 0xFF000000, 0xFF07140A, 0xFFB6FFBD, 0xFF4F8A57, 0xFF39FF6A, 0xFFB6FF3A, 0, FontFamily.Monospace),
    Look("late-night-fm", "Late-night FM", true, 0xFF0B0D14, 0xFF141824, 0xFFE6E8EF, 0xFF8A90A6, 0xFFFF5A5F, 0xFFFFB86B, 12, FontFamily.SansSerif),
    Look("paper-ink", "Paper & ink", false, 0xFFF6F1E7, 0xFFECE4D3, 0xFF1D1B17, 0xFF6F685A, 0xFFB5482F, 0xFF1D1B17, 2, FontFamily.Serif),
    Look("vintage-vu", "Vintage VU", true, 0xFF17110B, 0xFF241A10, 0xFFF3E3C0, 0xFFA08A62, 0xFFE8432E, 0xFFFFB347, 8, FontFamily.Monospace),
    Look("tube-radio", "Tube radio", true, 0xFF120D08, 0xFF1D140C, 0xFFEAD9B0, 0xFF8F7A55, 0xFFC9953A, 0xFF7DFF6A, 6, FontFamily.Serif),
    Look("amber-receiver", "Amber receiver", true, 0xFF0D0905, 0xFF1A1208, 0xFFFFD9A0, 0xFF9A7A4A, 0xFFFF9D2E, 0xFFFFB347, 6, FontFamily.SansSerif),
    Look("graphite", "Graphite", true, 0xFF121212, 0xFF1A1A1A, 0xFFD8D8D8, 0xFF8A8A8A, 0xFFF0605D, 0xFFE8B45A, 0, FontFamily.Monospace),
)

/** The chosen look. Reading it in composition or in a draw block makes that code follow a change. */
object Looking {
    var current by mutableStateOf(Looks[0])
    fun byId(id: String?) = Looks.firstOrNull { it.id == id } ?: Looks[0]
}

/** The colours of the chosen look, for the canvas-drawn meters and panels (they cannot read MaterialTheme). */
object Palette {
    val Bg get() = Color(Looking.current.bg)
    val Surface get() = Color(Looking.current.surface)
    val Text get() = Color(Looking.current.text)
    val Muted get() = Color(Looking.current.muted)
    val Accent get() = Color(Looking.current.accent)
    val Accent2 get() = Color(Looking.current.accent2)
    val Red get() = if (Looking.current.dark) Color(0xFFFF4D3D) else Color(0xFFC62828)
}

private fun scheme(l: Look): ColorScheme {
    val bg = Color(l.bg); val surface = Color(l.surface); val text = Color(l.text); val muted = Color(l.muted)
    val accent = Color(l.accent); val accent2 = Color(l.accent2)
    val outline = lerp(bg, muted, 0.4f)
    val on = if (l.dark) bg else Color.White
    val base = if (l.dark) darkColorScheme() else lightColorScheme()
    return base.copy(
        primary = accent, onPrimary = on,
        primaryContainer = surface, onPrimaryContainer = accent,
        secondary = accent2, onSecondary = on,
        secondaryContainer = surface, onSecondaryContainer = accent,
        tertiary = accent2,
        background = bg, onBackground = text,
        surface = bg, onSurface = text,
        surfaceVariant = surface, onSurfaceVariant = muted,
        surfaceContainer = surface, surfaceContainerHigh = surface, surfaceContainerHighest = surface,
        outline = muted, outlineVariant = outline,
        error = Palette.Red, onError = on,
    )
}

private fun typography(font: FontFamily) = Typography().let {
    fun f(s: TextStyle) = s.copy(fontFamily = font)
    it.copy(
        displayLarge = f(it.displayLarge), displayMedium = f(it.displayMedium), displaySmall = f(it.displaySmall),
        headlineLarge = f(it.headlineLarge), headlineMedium = f(it.headlineMedium), headlineSmall = f(it.headlineSmall),
        titleLarge = f(it.titleLarge), titleMedium = f(it.titleMedium), titleSmall = f(it.titleSmall),
        bodyLarge = f(it.bodyLarge), bodyMedium = f(it.bodyMedium), bodySmall = f(it.bodySmall),
        labelLarge = f(it.labelLarge), labelMedium = f(it.labelMedium), labelSmall = f(it.labelSmall),
    )
}

private fun shapes(radius: Int): Shapes {
    val r = RoundedCornerShape(radius.dp)
    return Shapes(r, r, r, r, r)
}

@Composable
fun MagnetiteTheme(content: @Composable () -> Unit) {
    val l = Looking.current
    // The window behind the app and the system bar icons follow the theme too (the XML theme is black).
    val view = LocalView.current
    if (!view.isInEditMode) SideEffect {
        val window = (view.context as android.app.Activity).window
        window.decorView.setBackgroundColor(l.bg.toInt())
        WindowCompat.getInsetsController(window, view).apply { isAppearanceLightStatusBars = !l.dark; isAppearanceLightNavigationBars = !l.dark }
    }
    MaterialTheme(colorScheme = scheme(l), typography = typography(l.font), shapes = shapes(l.radius), content = content)
}

/** Pick one of the web themes; the choice is applied and saved at once. */
@Composable
fun ThemePicker(onPick: (Look) -> Unit, onClose: () -> Unit) {
    AlertDialog(
        onDismissRequest = onClose,
        confirmButton = { TextButton(onClose) { Text("Done") } },
        title = { Text("Theme") },
        text = {
            Column {
                Looks.forEach { l ->
                    Row(
                        Modifier.fillMaxWidth().clickable { onPick(l) }.padding(vertical = 10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(if (l.id == Looking.current.id) "●" else "○", Modifier.padding(end = 12.dp), color = MaterialTheme.colorScheme.primary)
                        Text(l.name, Modifier.weight(1f))
                        for (c in listOf(l.bg, l.accent, l.accent2)) {
                            Box(Modifier.padding(start = 4.dp).size(14.dp).background(Color(c)).border(1.dp, MaterialTheme.colorScheme.outline))
                        }
                    }
                }
            }
        },
    )
}

/** The landing page logo (public/logo.svg rendered at 1024 px): two wide meters over the name. */
@Composable
fun Logo(modifier: Modifier = Modifier) {
    Image(
        painterResource(R.drawable.logo_full), contentDescription = "Magnetite",
        modifier = modifier.widthIn(max = 360.dp).fillMaxWidth(), contentScale = ContentScale.FillWidth,
    )
}
