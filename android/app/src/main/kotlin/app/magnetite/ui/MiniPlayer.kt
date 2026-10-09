package app.magnetite.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import app.magnetite.player.PlayerController

/** Bar at the bottom of every screen once something has been played. */
@Composable
fun MiniPlayer(player: PlayerController, onOpen: () -> Unit) {
    val title = player.title ?: return
    Surface(Modifier.fillMaxWidth().clickable(onClick = onOpen), color = MaterialTheme.colorScheme.surfaceVariant) {
        Row(Modifier.padding(horizontal = 16.dp, vertical = 4.dp), verticalAlignment = androidx.compose.ui.Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(title, maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.titleSmall)
                player.artist?.let { Text(it, maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.bodySmall) }
            }
            TextButton({ player.previous() }) { Text("Prev") }
            TextButton({ player.toggle() }) { Text(if (player.isPlaying) "Pause" else "Play") }
            TextButton({ player.next() }) { Text("Next") }
        }
    }
}
