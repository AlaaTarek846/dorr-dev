package com.dorr.app.ui.screens.chat

import android.content.Intent
import android.net.Uri
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.rememberLazyGridState
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Forum
import androidx.compose.material.icons.rounded.GraphicEq
import androidx.compose.material.icons.rounded.Link
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.PhotoLibrary
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AttachmentDto
import com.dorr.app.network.MessageDto
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.launch
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/** One tab of the media page: what the server calls it, its label and icon. */
private data class MediaTab(val key: String, val kind: String, val label: Int, val icon: ImageVector)

private val MediaTabs = listOf(
    MediaTab("media", "media", R.string.ch_media, Icons.Rounded.PhotoLibrary),
    MediaTab("docs", "documents", R.string.ch_docs, Icons.Rounded.Description),
    MediaTab("links", "links", R.string.ch_links, Icons.Rounded.Link),
    MediaTab("audio", "audio", R.string.ch_audio, Icons.Rounded.GraphicEq),
)

/** A tab's pages so far. */
private class MediaFeed {
    var items by mutableStateOf<List<MessageDto>>(emptyList())
    var hasMore by mutableStateOf(true)
    var loading by mutableStateOf(false)
    var loaded by mutableStateOf(false)
}

/**
 * Everything shared in a chat, like WhatsApp's "Media, links and docs": photos & videos in a
 * grid by month, files, links with their card, voice notes and audio. Pages in as you scroll.
 * A tap opens the thing itself; a long press (or the bubble icon) shows it in the chat.
 */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun ChatMediaPage(route: ChRoute.Media) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var tab by remember { mutableStateOf(MediaTabs.firstOrNull { it.key == route.tab } ?: MediaTabs.first()) }
    val feeds = remember { mutableStateMapOf<String, MediaFeed>() }
    var viewer by remember { mutableStateOf<Pair<MessageDto, Int>?>(null) }

    fun feedOf(t: MediaTab) = feeds.getOrPut(t.key) { MediaFeed() }
    // Photos from a day back; files by kind / size (spec 17, 18).
    var date by remember { mutableStateOf<String?>(null) }
    var fileKind by remember { mutableStateOf<String?>(null) }
    var bigOnly by remember { mutableStateOf(false) }
    var bySize by remember { mutableStateOf(false) }

    fun loadMore(t: MediaTab) {
        val feed = feedOf(t)
        if (feed.loading || !feed.hasMore) return
        feed.loading = true
        scope.launch {
            val files = t.key == "docs"
            val page = runCatching {
                ApiClient.chat.gallery(
                    chatAuth(), route.id, t.kind, before = feed.items.lastOrNull()?.id,
                    date = date.takeIf { t.key == "media" }, fileKind = fileKind.takeIf { files }, minSize = if (files && bigOnly) 10L * 1024 * 1024 else null, sort = if (files && bySize) "size" else null,
                ).data
            }.getOrNull()
            if (page != null) {
                feed.items = feed.items + page.messages.filter { m -> feed.items.none { it.id == m.id } }
                feed.hasMore = page.hasMore
            } else {
                feed.hasMore = false
            }
            feed.loaded = true
            feed.loading = false
        }
    }
    LaunchedEffect(tab) { if (!feedOf(tab).loaded) loadMore(tab) }
    fun refilter(t: MediaTab) {
        feeds.remove(t.key)
        loadMore(t)
    }

    val showInChat: (MessageDto) -> Unit = { m -> host.showInChat(route.id, m.id) }
    androidx.activity.compose.BackHandler(viewer != null) { viewer = null }

    Box(Modifier.fillMaxSize()) {
        ChPage(stringResource(R.string.ch_media_title), onBack = { host.pop() }) {
            Column(Modifier.fillMaxSize()) {
                MediaTabsRow(tab, counts = MediaTabs.associate { it.key to feeds[it.key]?.takeIf { f -> f.loaded }?.items?.size }) { tab = it }
                if (tab.key == "media" || tab.key == "docs") MediaFilterRow(
                    files = tab.key == "docs", date = date, fileKind = fileKind, bigOnly = bigOnly, bySize = bySize,
                    onDate = { date = it; refilter(tab) }, onKind = { fileKind = it; refilter(tab) }, onBig = { bigOnly = it; refilter(tab) }, onSize = { bySize = it; refilter(tab) },
                )
                AnimatedContent(targetState = tab, label = "mediaTab", transitionSpec = {
                    val forward = MediaTabs.indexOf(targetState) > MediaTabs.indexOf(initialState)
                    (fadeIn() + slideInHorizontally { if (forward) it / 6 else -it / 6 }) togetherWith fadeOut()
                }) { t ->
                    val feed = feedOf(t)
                    when {
                        !feed.loaded -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ch.Red, strokeWidth = 3.dp) }
                        feed.items.isEmpty() -> ChEmptyState(t.icon, stringResource(t.label), stringResource(R.string.ch_no_media), animated = true)
                        t.key == "media" -> MediaGrid(feed, onMore = { loadMore(t) }, onOpen = { m, i -> viewer = m to i }, onShowInChat = showInChat)
                        else -> MediaList(t, feed, onMore = { loadMore(t) }, onShowInChat = showInChat)
                    }
                }
            }
        }
        viewer?.let { (m, i) -> MediaViewer(m, i) { viewer = null } }
    }
}

@Composable
private fun MediaTabsRow(selected: MediaTab, counts: Map<String, Int?>, onSelect: (MediaTab) -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 10.dp).clip(RoundedCornerShape(18.dp)).background(Ch.Surface).padding(5.dp),
        horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        MediaTabs.forEach { t ->
            val active = t == selected
            val bg by animateColorAsState(if (active) Ch.Red else Color.Transparent, spring(stiffness = 600f), label = "mediaTabBg")
            val fg = if (active) Color.White else Ch.Mut
            Column(
                Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(bg).clickable { onSelect(t) }.padding(vertical = 8.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Icon(t.icon, null, tint = fg, modifier = Modifier.size(19.dp))
                Text(
                    stringResource(t.label) + (counts[t.key]?.takeIf { it > 0 }?.let { " · $it" } ?: ""),
                    color = fg, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, maxLines = 1,
                )
            }
        }
    }
}

/** Photos & videos, three a row, under a header per month. */
@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun MediaGrid(feed: MediaFeed, onMore: () -> Unit, onOpen: (MessageDto, Int) -> Unit, onShowInChat: (MessageDto) -> Unit) {
    val context = LocalContext.current
    val haptic = LocalHapticFeedback.current
    val gridState = rememberLazyGridState()
    // Each file of each message is its own tile; a month header starts each new month.
    val tiles = remember(feed.items) {
        val out = mutableListOf<Any>()
        var month: String? = null
        feed.items.forEach { m ->
            val label = monthLabel(m.createdAt)
            if (label != month) { out += label; month = label }
            m.attachments.forEachIndexed { i, a -> out += Triple(m, i, a) }
        }
        out
    }
    LaunchedEffect(gridState, tiles.size) {
        snapshotFlow { gridState.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0 }
            .distinctUntilChanged()
            .collect { last -> if (last >= tiles.size - 9) onMore() }
    }
    LazyVerticalGrid(
        columns = GridCells.Fixed(3), state = gridState, modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(start = 10.dp, end = 10.dp, bottom = 30.dp),
        horizontalArrangement = Arrangement.spacedBy(4.dp), verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        tiles.forEachIndexed { index, tile ->
            if (tile is String) {
                item(key = "h$index", span = { GridItemSpan(maxLineSpan) }) {
                    Text(tile, color = Ch.Mut, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(start = 6.dp, top = 12.dp, bottom = 4.dp))
                }
            } else {
                @Suppress("UNCHECKED_CAST")
                val entry = tile as Triple<MessageDto, Int, AttachmentDto>
                val (m, i, a) = entry
                item(key = "${m.id}-$i") {
                    val video = m.type == "video" || a.mimeType?.startsWith("video/") == true
                    Box(
                        Modifier.aspectRatio(1f).chStagger(index % 12).clip(RoundedCornerShape(12.dp)).background(Ch.SurfaceMuted)
                            .combinedClickable(
                                onClick = {
                                    if (video) {
                                        ChatViewers.video = a.url
                                    } else {
                                        // The viewer pages through this message's photos.
                                        onOpen(m, m.attachments.filter { it.isImage() }.indexOf(a).coerceAtLeast(0))
                                    }
                                },
                                onLongClick = { haptic.performHapticFeedback(HapticFeedbackType.LongPress); onShowInChat(m) },
                            ),
                    ) {
                        AsyncImage(
                            ApiClient.mediaUrl(if (video) a.thumbnail ?: a.url else a.url), null, imageLoader = chatImages(context),
                            contentScale = ContentScale.Crop, modifier = Modifier.matchParentSize(),
                        )
                        if (video) {
                            Box(Modifier.matchParentSize().background(Brush.verticalGradient(listOf(Color.Transparent, Color.Black.copy(alpha = 0.55f)))))
                            Row(Modifier.align(Alignment.BottomStart).padding(6.dp), verticalAlignment = Alignment.CenterVertically) {
                                Icon(Icons.Rounded.PlayArrow, null, tint = Color.White, modifier = Modifier.size(16.dp))
                                a.durationMs?.let { Text(durationText(it), color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Bold) }
                            }
                        }
                    }
                }
            }
        }
        if (feed.loading) item(span = { GridItemSpan(maxLineSpan) }) { LoadingMore() }
    }
}

/** Files, links and voice notes: one row each, newest first. */
@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun MediaList(t: MediaTab, feed: MediaFeed, onMore: () -> Unit, onShowInChat: (MessageDto) -> Unit) {
    val context = LocalContext.current
    val haptic = LocalHapticFeedback.current
    val listState = rememberLazyListState()
    LaunchedEffect(listState, feed.items.size) {
        snapshotFlow { listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0 }
            .distinctUntilChanged()
            .collect { last -> if (last >= feed.items.size - 4) onMore() }
    }
    LazyColumn(state = listState, contentPadding = PaddingValues(start = 14.dp, end = 14.dp, bottom = 30.dp), verticalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxSize()) {
        itemsIndexed(feed.items, key = { _, m -> m.id }) { i, m ->
            val file = m.attachments.firstOrNull()
            val link = if (t.key == "links") m.linkPreview?.url ?: LinkInText.find(m.body.orEmpty())?.value else null
            val open: () -> Unit = {
                when (t.key) {
                    // A PDF opens inside the app; other files in their own app.
                    "docs" -> file?.let {
                        if (ChatViewers.isPdf(it.name, it.mimeType)) ChatViewers.pdf = PdfTarget(it.url, it.name.orEmpty())
                        else runCatching { context.startActivity(Intent(Intent.ACTION_VIEW).setDataAndType(Uri.parse(ApiClient.mediaUrl(it.url)), it.mimeType ?: "*/*")) }
                    }
                    "links" -> link?.let { url -> runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(if (url.startsWith("http")) url else "https://$url"))) } }
                    // Voice notes play in the chat itself.
                    else -> onShowInChat(m)
                }
            }
            Row(
                Modifier.fillMaxWidth().chStagger(i % 12).clip(RoundedCornerShape(18.dp)).background(Ch.Surface)
                    .combinedClickable(onClick = open, onLongClick = { haptic.performHapticFeedback(HapticFeedbackType.LongPress); onShowInChat(m) })
                    .padding(10.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                // The link's picture when it has one, else a tinted icon.
                val image = if (t.key == "links") m.linkPreview?.image else null
                Box(Modifier.size(48.dp).clip(RoundedCornerShape(14.dp)).background(Ch.TintBrush), contentAlignment = Alignment.Center) {
                    if (image != null) {
                        AsyncImage(ApiClient.mediaUrl(image), null, imageLoader = chatImages(context), contentScale = ContentScale.Crop, modifier = Modifier.matchParentSize())
                    } else if (t.key == "docs") {
                        Text(file?.name?.substringAfterLast('.', "")?.take(4)?.uppercase().orEmpty().ifEmpty { "DOC" }, color = Ch.Red, fontSize = 12.sp, fontWeight = FontWeight.Black)
                    } else {
                        Icon(if (t.key == "audio") Icons.Rounded.Mic else Icons.Rounded.Link, null, tint = Ch.Red, modifier = Modifier.size(22.dp))
                    }
                }
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    val title = when (t.key) {
                        "docs" -> file?.name ?: stringResource(R.string.ch_docs)
                        "links" -> m.linkPreview?.title?.takeIf { it.isNotBlank() } ?: link.orEmpty()
                        else -> m.sender?.name ?: stringResource(R.string.ch_you)
                    }
                    Text(title, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    val details = when (t.key) {
                        "docs" -> listOfNotNull(file?.size?.let { fileSizeText(it) }, listTime(m.createdAt))
                        "links" -> listOfNotNull(link?.let { Uri.parse(if (it.startsWith("http")) it else "https://$it").host?.removePrefix("www.") }, listTime(m.createdAt))
                        else -> listOfNotNull(file?.durationMs?.let { durationText(it) }, listTime(m.createdAt))
                    }
                    Text(details.joinToString("  ·  "), color = Ch.Mut, fontSize = 12.5.sp, maxLines = 1)
                }
                // Straight to where it was sent.
                Box(Modifier.size(38.dp).clip(CircleShape).clickable { onShowInChat(m) }, contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Forum, stringResource(R.string.ch_show_in_chat), tint = Ch.Soft, modifier = Modifier.size(20.dp))
                }
            }
        }
        if (feed.loading) item { LoadingMore() }
    }
}

@Composable
private fun LoadingMore() {
    Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) {
        CircularProgressIndicator(color = Ch.Red, strokeWidth = 2.5.dp, modifier = Modifier.size(24.dp))
    }
}

private val LinkInText = Regex("(https?://\\S+|www\\.\\S+)")

/** "September 2026", in the app's language. */
private fun monthLabel(iso: String?): String {
    val instant = parseInstant(iso) ?: return ""
    val locale = java.util.Locale(com.dorr.app.network.AppLocale.current)
    return instant.atZone(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("MMMM yyyy", locale))
}
