package app.magnetite.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

/**
 * Pull down to reload. [reload] runs while the spinner shows and reports its own errors, so a failed refresh
 * leaves what is on screen. [content] must scroll (a LazyColumn, or [ScrollingMessage] for empty and loading states),
 * or there is nothing to pull.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PullRefresh(padding: PaddingValues, reload: suspend () -> Unit, content: @Composable () -> Unit) {
    var refreshing by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    PullToRefreshBox(
        isRefreshing = refreshing,
        onRefresh = {
            scope.launch {
                refreshing = true
                try { reload() } catch (e: CancellationException) { throw e } catch (_: Exception) { } finally { refreshing = false }
            }
        },
        modifier = Modifier.fillMaxSize().padding(padding),
    ) { content() }
}

/** A short message that can still be pulled to refresh. */
@Composable
fun ScrollingMessage(text: String) {
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState())) {
        androidx.compose.material3.Text(text, Modifier.padding(16.dp))
    }
}
