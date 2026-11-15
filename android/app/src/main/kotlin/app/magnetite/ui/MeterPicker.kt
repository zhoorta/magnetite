package app.magnetite.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp

/**
 * Choose up to [MAX_METERS] meters, as pictures like the web's meter menu: the meters two across, the posters three across. Tapping adds or
 * removes one; a chosen one has its position on it, and the order list below moves it up or removes it. [onChange] gets the whole list at every change, so it
 * is saved at once (and shared with the web) like there.
 */
@Composable
fun MeterPicker(chosen: List<String>, onChange: (List<String>) -> Unit, onClose: () -> Unit) {
    val pictures = rememberMeterThumbs()
    val plain = MeterCatalog.filter { !it.id.startsWith("poster-") }
    val posters = MeterCatalog.filter { it.id.startsWith("poster-") }

    @Composable
    fun Tile(m: MeterInfo, label: String, ratio: Float, modifier: Modifier) {
        val at = chosen.indexOf(m.id)
        val on = at >= 0
        // A full list dims the unchosen tiles but leaves them focusable, so a remote can still move through the whole list.
        val enabled = on || chosen.size < MAX_METERS
        Column(modifier.alpha(if (enabled) 1f else 0.4f).clip(RoundedCornerShape(6.dp)).tvFocus().clickable { if (enabled) onChange(if (on) chosen - m.id else chosen + m.id) }.padding(3.dp)) {
            Box(Modifier.fillMaxWidth().aspectRatio(ratio).clip(RoundedCornerShape(4.dp)).background(MaterialTheme.colorScheme.surfaceVariant).border(BorderStroke(if (on) 2.dp else 1.dp, if (on) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outline), RoundedCornerShape(4.dp))) {
                pictures[m.id]?.let { Image(it, null, Modifier.fillMaxSize(), contentScale = ContentScale.Crop) }
                if (on) Text("${at + 1}", Modifier.align(Alignment.TopEnd).padding(3.dp).background(MaterialTheme.colorScheme.primary, RoundedCornerShape(50)).padding(horizontal = 6.dp), color = MaterialTheme.colorScheme.onPrimary, style = MaterialTheme.typography.labelSmall)
            }
            Text(label, Modifier.padding(top = 2.dp), maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.labelSmall, color = if (on) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }

    @Composable
    fun Grid(items: List<MeterInfo>, across: Int, ratio: Float, short: (MeterInfo) -> String) {
        items.chunked(across).forEach { row ->
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                row.forEach { Tile(it, short(it), ratio, Modifier.weight(1f)) }
                repeat(across - row.size) { Box(Modifier.weight(1f)) }
            }
        }
    }

    AlertDialog(
        onDismissRequest = onClose,
        confirmButton = { TextButton(onClose) { Text("Done") } },
        title = { Text("Meters") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Text("Up to $MAX_METERS. Saved to your account, so the web player shows the same.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                Text("Meters", Modifier.padding(top = 12.dp, bottom = 2.dp), style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                Grid(plain, 2, 7f / 4f) { it.label }
                Text("Posters", Modifier.padding(top = 12.dp, bottom = 2.dp), style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                Grid(posters, 3, 3f / 4f) { it.label.removePrefix("Poster: ") }
                if (chosen.isNotEmpty()) {
                    Text("Order", Modifier.padding(top = 12.dp, bottom = 2.dp), style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    chosen.forEachIndexed { at, id ->
                        Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                            Text("${at + 1}", Modifier.padding(end = 12.dp), color = MaterialTheme.colorScheme.primary)
                            Text(MeterCatalog.firstOrNull { it.id == id }?.label ?: id, Modifier.weight(1f))
                            if (at > 0) Text("↑", Modifier.clip(RoundedCornerShape(6.dp)).tvFocus().clickable { onChange(chosen.toMutableList().also { it.add(at - 1, it.removeAt(at)) }) }.padding(horizontal = 12.dp, vertical = 4.dp), color = MaterialTheme.colorScheme.primary)
                            Text("×", Modifier.clip(RoundedCornerShape(6.dp)).tvFocus().clickable { onChange(chosen - id) }.padding(horizontal = 12.dp, vertical = 4.dp), color = MaterialTheme.colorScheme.primary)
                        }
                    }
                }
            }
        },
    )
}
