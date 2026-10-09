package app.magnetite.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import app.magnetite.data.Track

/** One track in a list. Tracks the viewer may not play (`open = false`) are dimmed and do nothing when tapped. */
@Composable
fun TrackRow(track: Track, playing: Boolean, onClick: () -> Unit, leading: String? = null, modifier: Modifier = Modifier) {
    Row(
        modifier.fillMaxWidth().alpha(if (track.open) 1f else 0.4f)
            .clickable(enabled = track.open, onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 10.dp),
    ) {
        if (leading != null) Text(leading, Modifier.padding(end = 12.dp).width(28.dp))
        Column(Modifier.weight(1f)) {
            Text(
                track.title, maxLines = 1, overflow = TextOverflow.Ellipsis,
                color = if (playing) MaterialTheme.colorScheme.primary else Color.Unspecified,
            )
            val sub = listOfNotNull(track.artist, track.album, track.sharedBy?.let { "from $it" }).joinToString(" · ")
            if (sub.isNotEmpty()) Text(sub, maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.bodySmall)
        }
        track.duration?.let { Text("%d:%02d".format(it.toInt() / 60, it.toInt() % 60)) }
    }
}
