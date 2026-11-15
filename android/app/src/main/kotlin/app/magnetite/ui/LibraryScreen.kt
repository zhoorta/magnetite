package app.magnetite.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.focusable
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.input.key.onPreviewKeyEvent
import androidx.compose.ui.input.key.isAltPressed
import androidx.compose.ui.input.key.isCtrlPressed
import androidx.compose.ui.input.key.isMetaPressed
import androidx.compose.ui.input.key.isShiftPressed
import androidx.compose.ui.input.key.key
import androidx.compose.ui.input.key.type
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import app.magnetite.data.Album
import app.magnetite.data.Api
import app.magnetite.data.Folder
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.combinedClickable
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.OutlinedTextField
import androidx.compose.runtime.rememberCoroutineScope
import kotlinx.coroutines.launch
import coil3.compose.AsyncImage
import androidx.compose.ui.Alignment
import app.magnetite.player.PlayerController
import kotlinx.coroutines.CancellationException

@OptIn(ExperimentalMaterial3Api::class, ExperimentalFoundationApi::class)
@Composable
fun LibraryScreen(api: Api, player: PlayerController, info: Int, onCycleInfo: () -> Unit, onOpen: (Int) -> Unit, onSearch: () -> Unit, onTruth: () -> Unit, onEdit: (Int) -> Unit, focus: androidx.compose.ui.focus.FocusRequester = remember { androidx.compose.ui.focus.FocusRequester() }, onSwitchPane: (() -> Unit)? = null, onPickMeters: () -> Unit, onPickTheme: () -> Unit, onSignOut: () -> Unit, flat: Boolean = false, onToggleFlat: () -> Unit = {},
    sharedAlbums: androidx.compose.runtime.snapshots.SnapshotStateList<Album>? = null, sharedFolders: androidx.compose.runtime.snapshots.SnapshotStateList<Folder>? = null, onFolder: ((Int) -> Unit)? = null) {
    val albums = sharedAlbums ?: remember { mutableStateListOf<Album>() }
    var page by remember { mutableStateOf(0) }
    var lastPage by remember { mutableStateOf(1) }
    var error by remember { mutableStateOf<String?>(null) }
    val folders = sharedFolders ?: remember { mutableStateListOf<Folder>() }

    suspend fun loadMore() {
        if (page >= lastPage) return
        try {
            val next = api.albums(page + 1)
            albums.addAll(next.data)
            page = next.meta.currentPage
            lastPage = next.meta.lastPage
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message
        }
    }

    val scope = rememberCoroutineScope()
    /** null = no dialog; otherwise what the name field is for. */
    var dialog by remember { mutableStateOf<FolderDialog?>(null) }
    var menuFor by remember { mutableStateOf<Int?>(null) }

    suspend fun reload() {
        try {
            val f = api.folders()
            folders.clear(); folders.addAll(f)
            albums.clear(); page = 0; lastPage = 1
            loadMore()
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message
        }
    }
    fun refresh() = scope.launch { reload() }

    // Albums are paged and a folder's albums can be on any page, so load them all; the folder list is flat.
    LaunchedEffect(Unit) {
        try {
            folders.clear()
            folders.addAll(api.folders())
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message
        }
        if (albums.isEmpty()) loadMore()
    }
    LaunchedEffect(page) { if (page in 1 until lastPage) loadMore() }

    val expanded = remember { mutableStateListOf<Int>() }

    /** The folder, or one inside it, holds the album that is playing. */
    fun holdsPlaying(f: Folder): Boolean {
        val playing = player.albumId ?: return false
        var at = albums.firstOrNull { it.id == playing }?.folderId
        var steps = 0
        while (at != null && steps++ < 50) {
            if (at == f.id) return true
            at = folders.firstOrNull { it.id == at }?.parentId
        }
        return false
    }

    // Long-press an album and drag it onto a folder (or onto another album, to join that album's folder).
    val list = androidx.compose.foundation.lazy.rememberLazyListState()
    val drag = remember { DragState() }
    var hover by remember { mutableStateOf<Any?>(null) }

    var actions by remember { mutableStateOf<Target?>(null) }
    ActionHost(actions, api, player, folders.toList(), onClose = { actions = null }, onChanged = { refresh() }, onEdit = onEdit)

    dialog?.let { d ->
        var name by remember(d) { mutableStateOf((d as? FolderDialog.Rename)?.name ?: "") }
        var problem by remember(d) { mutableStateOf<String?>(null) }
        fun run(block: suspend () -> Unit) = scope.launch {
            try {
                block()
                dialog = null
                refresh()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                problem = e.message
            }
        }
        AlertDialog(
            onDismissRequest = { dialog = null },
            title = {
                Text(when (d) {
                    is FolderDialog.Create -> if (d.parent == null) "New folder" else "New subfolder"
                    is FolderDialog.Rename -> "Rename folder"
                    is FolderDialog.Delete -> "Delete \"${d.name}\"?"
                })
            },
            text = {
                Column {
                    if (d is FolderDialog.Delete) Text("Its albums and subfolders move up one level. No music is deleted.")
                    else OutlinedTextField(name, { name = it }, Modifier.dpadLeavesField(), singleLine = true, label = { Text("Name") })
                    problem?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                }
            },
            confirmButton = {
                TextButton(enabled = d is FolderDialog.Delete || name.isNotBlank(), onClick = {
                    when (d) {
                        is FolderDialog.Create -> run { api.createFolder(name.trim(), d.parent) }
                        is FolderDialog.Rename -> run { api.renameFolder(d.id, name.trim()) }
                        is FolderDialog.Delete -> run { api.deleteFolder(d.id) }
                    }
                }) { Text(if (d is FolderDialog.Delete) "Delete" else "Save") }
            },
            dismissButton = { TextButton({ dialog = null }) { Text("Cancel") } },
        )
    }

    /** Folders inside [f] (itself included), for the mean DR of everything under it. */
    fun under(f: Folder): Set<Int> {
        val ids = mutableSetOf(f.id)
        var grew = true
        while (grew) {
            grew = false
            for (x in folders) if (x.parentId in ids && ids.add(x.id)) grew = true
        }
        return ids
    }

    /** The rows as shown: folders (open ones with their contents) and the albums at the top level, with their depth. */
    fun tree(parent: Int?, depth: Int): List<Pair<Int, Any>> =
        folders.filter { it.parentId == parent }.flatMap { f ->
            listOf<Pair<Int, Any>>(depth to f) +
                if (f.id in expanded) tree(f.id, depth + 1) + albums.filter { it.folderId == f.id }.map { depth + 1 to it as Any } else emptyList()
        }
    val rows = if (flat) albums.map { 0 to it as Any } else tree(null, 0) + albums.filter { it.folderId == null }.map { 0 to it as Any }
    fun rowKey(r: Pair<Int, Any>) = if (r.second is Folder) "f${(r.second as Folder).id}" else "a${(r.second as Album).id}"

    // Keys, for a hardware keyboard: the web's j k h l, Enter, / and the player's keys. The cursor shows once a key was used.
    var cursor by remember { mutableStateOf<String?>(null) }
    var keysUsed by remember { mutableStateOf(false) }
    val tv = LocalTv.current
    // A remote moves the focus from row to row by itself (every row is focusable), so the keyboard cursor is for phones and tablets.
    androidx.compose.runtime.LaunchedEffect(Unit) { if (!tv) runCatching { focus.requestFocus() } }
    val firstRow = remember { androidx.compose.ui.focus.FocusRequester() }
    // the first row only exists once the list has been laid out: try again for a moment
    androidx.compose.runtime.LaunchedEffect(rows.isNotEmpty()) {
        if (!tv || rows.isEmpty()) return@LaunchedEffect
        repeat(15) {
            if (runCatching { firstRow.requestFocus() }.isSuccess) return@LaunchedEffect
            kotlinx.coroutines.delay(100)
        }
    }

    fun moveCursor(d: Int) {
        if (rows.isEmpty()) return
        val i = rows.indexOfFirst { rowKey(it) == cursor }
        val n = (if (i < 0) (if (d > 0) 0 else rows.lastIndex) else i + d).coerceIn(0, rows.lastIndex)
        cursor = rowKey(rows[n])
        val shown = list.layoutInfo.visibleItemsInfo
        if (shown.isEmpty() || n <= shown.first().index || n >= shown.last().index) scope.launch { list.animateScrollToItem((n - 2).coerceAtLeast(0)) }
    }
    fun jump(last: Boolean) { if (rows.isNotEmpty()) { cursor = rowKey(rows[if (last) rows.lastIndex else 0]); scope.launch { list.scrollToItem(if (last) rows.lastIndex else 0) } } }
    fun current() = rows.firstOrNull { rowKey(it) == cursor }?.second
    fun openCurrent() {
        when (val c = current()) {
            is Folder -> { if (c.id in expanded) expanded.remove(c.id) else expanded.add(c.id); onFolder?.invoke(c.id) }
            is Album -> onOpen(c.id)
        }
    }
    fun right() {
        when (val c = current()) {
            is Folder -> if (c.id !in expanded) expanded.add(c.id)
            is Album -> onOpen(c.id)
        }
    }
    fun left() {
        when (val c = current()) {
            is Folder -> if (c.id in expanded) expanded.remove(c.id) else c.parentId?.let { cursor = "f$it" }
            is Album -> c.folderId?.let { cursor = "f$it" }
        }
    }
    val onKey: (androidx.compose.ui.input.key.KeyEvent) -> Boolean = onKey@{ e ->
        if (!e.isPlain()) return@onKey false
        val k = androidx.compose.ui.input.key.Key
        val handled = when (e.key) {
            k.J, k.DirectionDown -> { moveCursor(1); true }
            k.K, k.DirectionUp -> { moveCursor(-1); true }
            k.L, k.DirectionRight -> { right(); true }
            k.H, k.DirectionLeft -> { left(); true }
            k.Enter, k.NumPadEnter -> { openCurrent(); true }
            k.G -> { jump(e.isShiftPressed); true }
            k.Slash -> { onSearch(); true }
            k.I -> if (e.isShiftPressed) { onCycleInfo(); true } else false
            k.Tab -> if (onSwitchPane != null) { onSwitchPane(); true } else false
            else -> playerKey(e, player)
        }
        if (handled) keysUsed = true
        handled
    }

    Scaffold(modifier = if (tv) Modifier else Modifier.focusRequester(focus).focusable().onPreviewKeyEvent(onKey), topBar = {
        DenseBar("Library") {
            TransportButton(Icon.Search, "Search", onSearch)
            TransportButton(Icon.Meters, "Meters", onPickMeters)
            var menu by remember { mutableStateOf(false) }
            androidx.compose.foundation.layout.Box {
                TransportButton(Icon.Menu, "Menu", { menu = true })
                DropdownMenu(menu, { menu = false }) {
                    DropdownMenuItem(text = { Text("View: " + if (flat) "flat" else "folders") }, onClick = { menu = false; onToggleFlat() })
                    DropdownMenuItem(text = { Text("New folder") }, onClick = { menu = false; dialog = FolderDialog.Create(null) })
                    DropdownMenuItem(text = { Text("Truth") }, onClick = { menu = false; onTruth() })
                    DropdownMenuItem(text = { Text("Info: " + listOf("off", "DR", "all")[info]) }, onClick = { onCycleInfo() })
                    DropdownMenuItem(text = { Text("Theme") }, onClick = { menu = false; onPickTheme() })
                    DropdownMenuItem(text = { Text("Sign out") }, onClick = { menu = false; onSignOut() })
                }
            }
        }
    }) { padding ->
        PullRefresh(padding, ::reload) {
        Pane("Library (${albums.size})", Modifier.fillMaxSize()) {
        LazyColumn(Modifier.fillMaxSize(), state = list, contentPadding = androidx.compose.foundation.layout.PaddingValues(top = 8.dp, bottom = 8.dp)) {
            items(rows, key = { (_, r) -> if (r is Folder) "f${r.id}" else "a${(r as Album).id}" }) { (depth, r) ->
                if (r is Folder) {
                    val open = r.id in expanded
                    Row(
                        Modifier.fillMaxWidth().background(if (hover == "f${r.id}") MaterialTheme.colorScheme.primary.copy(alpha = 0.18f) else if (keysUsed && cursor == "f${r.id}") MaterialTheme.colorScheme.primary.copy(alpha = 0.10f) else androidx.compose.ui.graphics.Color.Transparent).tvFocus().then(if (tv && "f${r.id}" == rowKey(rows.first())) Modifier.focusRequester(firstRow) else Modifier).combinedClickable(
                            onClick = { if (open) expanded.remove(r.id) else expanded.add(r.id); onFolder?.invoke(r.id) },
                            onLongClick = { menuFor = r.id },
                        )
                            .padding(start = 8.dp + 16.dp * depth, end = 10.dp, top = 4.dp, bottom = 4.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(nameAndRest((if (open) "▾ " else "▸ ") + r.name, null, holdsPlaying(r), MaterialTheme.colorScheme.primary), Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis, inlineContent = PlayInline)
                        if (info >= 2) {
                            val ids = under(r)
                            if (albums.any { it.folderId in ids && (it.problems ?: 0) > 0 }) Text("!", Modifier.padding(start = 6.dp), color = Palette.Red)
                        }
                        if (info >= 1) {
                            val ids = under(r)
                            Info.dr(Info.mean(albums.filter { it.folderId in ids }.mapNotNull { it.dr }))?.let { Text(it, Modifier.padding(start = 8.dp), color = Palette.Muted, style = MaterialTheme.typography.bodySmall) }
                        }
                        Text("${r.albumCount}", Modifier.padding(start = 8.dp).width(28.dp), color = Palette.Muted, style = MaterialTheme.typography.bodySmall, textAlign = androidx.compose.ui.text.style.TextAlign.End)
                        DropdownMenu(expanded = menuFor == r.id, onDismissRequest = { menuFor = null }) {
                            DropdownMenuItem(text = { Text("New subfolder") }, onClick = { menuFor = null; dialog = FolderDialog.Create(r.id) })
                            DropdownMenuItem(text = { Text("Rename") }, onClick = { menuFor = null; dialog = FolderDialog.Rename(r.id, r.name) })
                            DropdownMenuItem(text = { Text("Delete") }, onClick = { menuFor = null; dialog = FolderDialog.Delete(r.id, r.name) })
                        }
                    }
                } else {
                    val album = r as Album
                    val key = "a${album.id}"
                    Row(
                        Modifier.fillMaxWidth()
                            .background(if (hover == key) MaterialTheme.colorScheme.primary.copy(alpha = 0.18f) else if (keysUsed && cursor == key) MaterialTheme.colorScheme.primary.copy(alpha = 0.10f) else androidx.compose.ui.graphics.Color.Transparent)
                            .dragRow(
                                drag, list, scope, key,
                                onLongPress = { hover = null; actions = Target.OfAlbum(album) },
                                onMove = { y ->
                                    hover = list.rowAt(y, key)?.key?.takeIf { it != "a${album.id}" }
                                },
                                onDrop = {
                                    val target = hover as? String
                                    hover = null
                                    // where the album would go: a folder row is the folder, an album row is that album's folder
                                    val to: Int? = when {
                                        target == null -> album.folderId
                                        target.startsWith("f") -> target.drop(1).toInt()
                                        else -> albums.firstOrNull { "a${it.id}" == target }?.folderId
                                    }
                                    if (target != null && to != album.folderId) {
                                        scope.launch {
                                            try { api.moveAlbum(album.id, to); if (to != null && to !in expanded) expanded.add(to); refresh() }
                                            catch (e: CancellationException) { throw e } catch (e: Exception) { error = e.message }
                                        }
                                    }
                                },
                            )
                            .tvFocus()
                            .then(if (tv && key == rowKey(rows.first())) Modifier.focusRequester(firstRow) else Modifier)
                            .then(
                                // a remote's long press (hold the centre button) opens the menu; a finger's long press is the drag's
                                if (tv) Modifier.combinedClickable(onClick = { onOpen(album.id) }, onLongClick = { actions = Target.OfAlbum(album) })
                                else Modifier.clickable { if (!drag.busy()) onOpen(album.id) },
                            )
                            .padding(start = 8.dp + 16.dp * depth, end = 10.dp, top = 4.dp, bottom = 4.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(
                            nameAndRest(album.title, album.displayArtist ?: album.artist, player.albumId == album.id), Modifier.weight(1f),
                            maxLines = 1, overflow = TextOverflow.Ellipsis, inlineContent = PlayInline,
                        )
                        if (info >= 2 && (album.problems ?: 0) > 0) Text("!", Modifier.padding(start = 6.dp), color = Palette.Red)
                        if (info >= 1) Info.dr(album.dr)?.let { Text(it, Modifier.padding(start = 8.dp), color = Palette.Muted, style = MaterialTheme.typography.bodySmall) }
                    }
                }
            }
            item {
                error?.let { Text(it, Modifier.padding(16.dp), color = MaterialTheme.colorScheme.error) }
            }
        }
        }
        }
    }
}

@Composable
fun Cover(url: String?, modifier: Modifier = Modifier) {
    AsyncImage(
        model = url,
        contentDescription = null,
        contentScale = ContentScale.Crop,
        modifier = modifier.aspectRatio(1f).clip(MaterialTheme.shapes.small),
    )
}

private sealed interface FolderDialog {
    data class Create(val parent: Int?) : FolderDialog
    data class Rename(val id: Int, val name: String) : FolderDialog
    data class Delete(val id: Int, val name: String) : FolderDialog
}
