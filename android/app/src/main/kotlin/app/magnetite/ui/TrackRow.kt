package app.magnetite.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import app.magnetite.data.Track

/** What follows the title, dimmed: artist, album, who shared it. */
fun defaultRest(track: Track): String? =
    listOfNotNull(track.artist, track.album, track.sharedBy?.let { "from $it" }).joinToString(" · ").ifEmpty { null }

/**
 * One track as one line, like the dense library: number, title (with the artist dimmed after it), the `!` of its worst finding,
 * DR and the time. [info] is the user's info level (0 off, 1 DR, 2 DR and findings). Tracks the viewer may not play
 * (`open = false`) are dimmed and do nothing when tapped.
 */
@Composable
fun TrackRow(
    track: Track, playing: Boolean, onClick: () -> Unit, leading: String? = null, modifier: Modifier = Modifier,
    info: Int = 0, rest: String? = defaultRest(track),
) {
    Row(
        modifier.fillMaxWidth().alpha(if (track.open) 1f else 0.4f)
            .tvFocus()
            .clickable(enabled = track.open, onClick = onClick)
            .padding(start = 8.dp, end = 10.dp, top = 4.dp, bottom = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (leading != null) Text(leading, Modifier.width(26.dp).padding(end = 6.dp), color = Palette.Muted, textAlign = TextAlign.End, style = MaterialTheme.typography.bodySmall)
        Text(
            nameAndRest(track.title, rest, playing, if (playing) Palette.Accent else Palette.Text),
            Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis, inlineContent = PlayInline,
        )
        track.mark(info)?.let { (m, c) -> Text(m, Modifier.padding(start = 6.dp), color = c, style = MaterialTheme.typography.bodyMedium) }
        if (info >= 1) Info.dr(track.truth?.dr)?.let { Text(it, Modifier.padding(start = 8.dp), color = Palette.Muted, style = MaterialTheme.typography.bodySmall) }
        track.duration?.let { Text("%d:%02d".format(it.toInt() / 60, it.toInt() % 60), Modifier.padding(start = 8.dp), color = Palette.Muted, style = MaterialTheme.typography.bodySmall) }
    }
}
