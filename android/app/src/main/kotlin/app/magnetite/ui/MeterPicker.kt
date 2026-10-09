package app.magnetite.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp

/**
 * Choose up to [MAX_METERS] meters. Tapping adds or removes one; a chosen meter has its position and an up arrow.
 * [onChange] gets the whole list at every change, so it is saved at once (and shared with the web) like there.
 */
@Composable
fun MeterPicker(chosen: List<String>, onChange: (List<String>) -> Unit, onClose: () -> Unit) {
    AlertDialog(
        onDismissRequest = onClose,
        confirmButton = { TextButton(onClose) { Text("Done") } },
        title = { Text("Meters") },
        text = {
            androidx.compose.foundation.layout.Column {
                Text("Up to $MAX_METERS. Saved to your account, so the web player shows the same.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                MeterCatalog.forEach { m ->
                    val at = chosen.indexOf(m.id)
                    val on = at >= 0
                    Row(
                        Modifier.fillMaxWidth().clickable(enabled = on || chosen.size < MAX_METERS) {
                            onChange(if (on) chosen - m.id else chosen + m.id)
                        }.padding(vertical = 10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(if (on) "${at + 1}" else "·", Modifier.padding(end = 12.dp), color = if (on) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant)
                        Text(m.label, Modifier.weight(1f), color = if (on || chosen.size < MAX_METERS) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.onSurfaceVariant)
                        if (on && at > 0) {
                            Text("↑", Modifier.clickable { onChange(chosen.toMutableList().also { it.add(at - 1, it.removeAt(at)) }) }.padding(horizontal = 12.dp), color = MaterialTheme.colorScheme.primary)
                        }
                    }
                }
            }
        },
    )
}
