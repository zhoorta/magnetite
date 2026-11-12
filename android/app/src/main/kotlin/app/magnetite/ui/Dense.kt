package app.magnetite.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.foundation.text.InlineTextContent
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.Placeholder
import androidx.compose.ui.text.PlaceholderVerticalAlign
import androidx.compose.ui.unit.sp
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.withStyle
import androidx.compose.foundation.text.appendInlineContent
import androidx.compose.ui.unit.dp
import app.magnetite.data.Track

/** The dense view's pane: a thin border with the title sitting on it, like the web's `fieldset` panes. */
@Composable
fun Pane(title: String, modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Box(modifier.padding(horizontal = 8.dp, vertical = 4.dp)) {
        Column(Modifier.padding(top = 9.dp).border(1.dp, Palette.Muted.copy(alpha = 0.5f)).padding(top = 6.dp), content = content)
        Text(
            title, Modifier.align(Alignment.TopStart).padding(start = 10.dp).background(Palette.Bg).padding(horizontal = 4.dp),
            color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.labelLarge, maxLines = 1,
        )
    }
}

/** "name  rest": the name in the text colour, the playing marker right after it, the rest dimmed; one ellipsised line (use [PlayInline]). */
fun nameAndRest(name: String, rest: String?, playing: Boolean = false, nameColor: Color = Palette.Text): AnnotatedString = buildAnnotatedString {
    withStyle(SpanStyle(color = nameColor)) { append(name) }
    if (playing) { append(" "); appendInlineContent("play", "*") }
    if (!rest.isNullOrEmpty()) withStyle(SpanStyle(color = Palette.Muted)) { append("  $rest") }
}

/** The inline content that [nameAndRest] asks for: the "playing" marker, as tall as the text. */
val PlayInline = mapOf(
    "play" to InlineTextContent(Placeholder(16.sp, 16.sp, PlaceholderVerticalAlign.TextCenter)) { PlayingMark(Modifier.fillMaxSize(), Palette.Accent2) },
)

/** The colour of a finding: red for a problem, the second accent for a warning. */
fun flagColor(level: String): Color = if (level == "problem") Palette.Red else Palette.Accent2

/** "!" in the colour of the worst finding, or null when the track has none (level 2 only). */
fun Track.mark(level: Int): Pair<String, Color>? =
    if (level < 2) null else Info.findings(this, 1).firstOrNull()?.let { "!" to flagColor(it.level) }

/** The slim top bar of a screen: the name in the accent colour, the actions at the right. */
@Composable
fun DenseBar(title: String, back: (() -> Unit)? = null, actions: @Composable androidx.compose.foundation.layout.RowScope.() -> Unit = {}) {
    androidx.compose.foundation.layout.Row(
        Modifier.fillMaxWidth().background(Palette.Surface).statusBarsPadding().height(48.dp).padding(start = if (back == null) 16.dp else 4.dp, end = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (back != null) TransportButton(Icon.Back, "Back", back)
        Text(title, Modifier.weight(1f), color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.titleMedium, maxLines = 1)
        actions()
    }
}

/** A one-line text field in the dense style: the label dimmed on the left, the value to its right, a rule underneath. */
@Composable
fun DenseField(label: String, value: String, onChange: (String) -> Unit, modifier: Modifier = Modifier, keyboardType: androidx.compose.ui.text.input.KeyboardType = androidx.compose.ui.text.input.KeyboardType.Text) {
    androidx.compose.foundation.layout.Row(modifier.fillMaxWidth().padding(horizontal = 10.dp, vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(label, Modifier.width(72.dp), color = Palette.Muted, style = MaterialTheme.typography.bodySmall)
        androidx.compose.foundation.text.BasicTextField(
            value, onChange, Modifier.weight(1f).dpadLeavesField().border(1.dp, Palette.Muted.copy(alpha = 0.35f)).padding(horizontal = 6.dp, vertical = 5.dp), singleLine = true,
            textStyle = MaterialTheme.typography.bodyMedium.copy(color = Palette.Text),
            cursorBrush = androidx.compose.ui.graphics.SolidColor(Palette.Accent),
            keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(keyboardType = keyboardType),
        )
    }
}
