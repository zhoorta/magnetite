package app.magnetite.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.focusable
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.input.key.isShiftPressed
import androidx.compose.ui.input.key.key
import androidx.compose.ui.input.key.onPreviewKeyEvent
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import app.magnetite.data.Api
import app.magnetite.data.Track
import app.magnetite.player.PlayerController
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

/** The ways to order the Truth list; the web's `TRUTH_SORTS`. */
private val SORTS = listOf("findings", "DR", "loudness", "peak", "title")

/** How bad a track looks: a problem counts 100, a warning 10; not measured is 0. */
private fun severity(t: Track) = (t.truth?.flags ?: emptyList()).sumOf { when (it.level) { "problem" -> 100; "warn" -> 10; else -> 0 }.toInt() }

/** Ascending or descending on a number, the unmeasured always last. */
private fun byNumber(get: (Track) -> Double?, dir: Int) = Comparator<Track> { a, b ->
    val x = get(a); val y = get(b)
    when {
        x == null -> if (y == null) 0 else 1
        y == null -> -1
        else -> dir * x.compareTo(y)
    }
}

private fun order(sort: String): Comparator<Track> {
    val title = compareBy<Track, String>(String.CASE_INSENSITIVE_ORDER) { it.title }
    return when (sort) {
        "DR" -> byNumber({ it.truth?.dr?.toDouble() }, 1).then(title)
        "loudness" -> byNumber({ it.truth?.lufs }, -1).then(title)
        "peak" -> byNumber({ it.truth?.truePeak }, -1).then(title)
        "title" -> title
        else -> Comparator<Track> { a, b -> severity(b) - severity(a) }
            .then { a, b -> Info.findings(b, 9).size - Info.findings(a, 9).size }
            .then(byNumber({ it.truth?.dr?.toDouble() }, 1)).then(title)
    }
}

/**
 * Every analysed file of the library in one list, the worst first (the web's truth view and the terminal client's table).
 * The sort and the "only findings" filter are in the top bar; a tap plays from there with the list as the queue.
 */
@Composable
fun TruthScreen(api: Api, player: PlayerController, onBack: () -> Unit) {
    val all = remember { mutableStateListOf<Track>() }
    var loaded by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var sort by remember { mutableStateOf(SORTS[0]) }
    var only by remember { mutableStateOf(false) }

    androidx.activity.compose.BackHandler(onBack = onBack)
    LaunchedEffect(Unit) {
        try {
            var page = 1
            while (true) {
                val r = api.truthTracks(page)
                all.addAll(r.data)
                if (page >= r.meta.lastPage) break
                page++
            }
            loaded = true
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message
        }
    }

    val rows = remember(all.size, sort, only) { all.filter { !only || Info.hasProblem(it) }.sortedWith(order(sort)) }

    var cursor by remember { mutableStateOf(-1) }
    val list = androidx.compose.foundation.lazy.rememberLazyListState()
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    val focus = remember { androidx.compose.ui.focus.FocusRequester() }
    LaunchedEffect(Unit) { runCatching { focus.requestFocus() } }
    val onKey: (androidx.compose.ui.input.key.KeyEvent) -> Boolean = onKey@{ e ->
        if (!e.isPlain()) return@onKey false
        fun move(to: Int) {
            if (rows.isEmpty()) return
            cursor = to.coerceIn(0, rows.lastIndex)
            val shown = list.layoutInfo.visibleItemsInfo
            if (shown.isEmpty() || cursor <= shown.first().index || cursor >= shown.last().index) scope.launch { list.animateScrollToItem((cursor - 2).coerceAtLeast(0)) }
        }
        val k = androidx.compose.ui.input.key.Key
        when (e.key) {
            k.J, k.DirectionDown -> { move(if (cursor < 0) 0 else cursor + 1); true }
            k.K, k.DirectionUp -> { move(if (cursor < 0) rows.lastIndex else cursor - 1); true }
            k.G -> { move(if (e.isShiftPressed) rows.lastIndex else 0); true }
            k.Enter, k.NumPadEnter -> { rows.getOrNull(cursor)?.let { player.play(rows, it) }; true }
            k.S -> { sort = SORTS[(SORTS.indexOf(sort) + 1) % SORTS.size]; true }
            k.O -> { only = !only; true }
            k.Escape, k.Backspace, k.H, k.DirectionLeft -> { onBack(); true }
            else -> playerKey(e, player)
        }
    }

    Scaffold(modifier = Modifier.focusRequester(focus).focusable().onPreviewKeyEvent(onKey), topBar = {
        DenseBar("Truth", onBack) {
            Text(
                "sort: $sort", Modifier.clickable { sort = SORTS[(SORTS.indexOf(sort) + 1) % SORTS.size] }.padding(horizontal = 10.dp, vertical = 12.dp),
                color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.labelLarge,
            )
            Text(
                if (only) "[x] only !" else "[ ] only !", Modifier.clickable { only = !only }.padding(horizontal = 10.dp, vertical = 12.dp),
                color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.labelLarge,
            )
        }
    }) { padding ->
        Pane("Files (${rows.size}${if (loaded) "" else "…"})", Modifier.fillMaxSize().padding(padding)) {
            LazyColumn(Modifier.fillMaxSize(), state = list, contentPadding = androidx.compose.foundation.layout.PaddingValues(vertical = 8.dp)) {
                error?.let { item { Text(it, Modifier.padding(10.dp), color = MaterialTheme.colorScheme.error) } }
                if (loaded && rows.isEmpty()) item { Text(if (only) "No findings." else "Nothing analysed yet.", Modifier.padding(horizontal = 10.dp), color = Palette.Muted) }
                itemsIndexed(rows, key = { _, t -> t.id }) { i, t ->
                    val playing = player.currentId == t.id.toString()
                    Column(Modifier.fillMaxWidth().background(if (cursor == i) MaterialTheme.colorScheme.primary.copy(alpha = 0.10f) else androidx.compose.ui.graphics.Color.Transparent).clickable { player.play(rows, t) }.padding(start = 8.dp, end = 10.dp, top = 4.dp, bottom = 4.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(
                                nameAndRest(t.title, t.artist, playing, if (playing) Palette.Accent else Palette.Text), Modifier.weight(1f),
                                maxLines = 1, overflow = TextOverflow.Ellipsis, inlineContent = PlayInline,
                            )
                            Info.dr(t.truth?.dr)?.let { Text(it, Modifier.padding(start = 8.dp), color = Palette.Muted, style = MaterialTheme.typography.bodySmall) }
                        }
                        val f = Info.findings(t, 3)
                        Text(
                            buildAnnotatedString {
                                f.forEachIndexed { i, x -> withStyle(SpanStyle(color = flagColor(x.level))) { append((if (i > 0) "  " else "") + "! ${x.label}") } }
                                withStyle(SpanStyle(color = Palette.Muted)) { append((if (f.isEmpty()) "" else "  ") + listOfNotNull(Info.formatText(t).ifEmpty { null }, Info.lufsText(t)).joinToString(" · ")) }
                            },
                            maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.labelSmall,
                        )
                    }
                }
            }
        }
    }
}
