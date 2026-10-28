package com.dorr.app.ui.screens.chat

import android.content.Context
import android.content.Intent
import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.DeleteSweep
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Forward
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.SdCard
import androidx.compose.material.icons.rounded.SdStorage
import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
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
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ChannelStatsDto
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.StorageDto
import com.dorr.app.network.StorageFileDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.ConfirmDialog
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.google.gson.JsonArray
import com.google.gson.JsonNull
import com.google.gson.JsonObject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material.icons.rounded.Language

/*
 * The simple items from the WhatsApp list in the spec's backlog: export a chat (25), manage
 * storage (46–48), a channel's numbers and the reactions it allows (107, 110).
 */

// =============================================================================== export a chat (25)

/** Without media (a .txt) or with it (a .zip): downloaded, then handed to the share sheet. */
@Composable
internal fun ExportChatSheet(conversationId: String, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val host = LocalChat.current
    val preparing = stringResource(R.string.bk_exporting)
    val failed = stringResource(R.string.bk_export_failed)
    fun run(media: Boolean) {
        onDismiss()
        host.showToast(preparing)
        host.scope.launch {
            val file = runCatching { downloadExport(context, conversationId, media) }.getOrNull()
            if (file == null) host.showToast(failed) else shareFile(context, file, if (media) "application/zip" else "text/plain")
        }
    }
    ChoiceSheet(
        title = stringResource(R.string.bk_export),
        subtitle = stringResource(R.string.bk_export_sub),
        options = listOf(
            stringResource(R.string.bk_export_text) to { run(false) },
            stringResource(R.string.bk_export_media) to { run(true) },
        ),
        radios = true,
        onDismiss = onDismiss,
    )
}

private suspend fun downloadExport(context: Context, conversationId: String, media: Boolean): File? = withContext(Dispatchers.IO) {
    val response = ApiClient.backlog.export(chatAuth(), conversationId, if (media) 1 else 0)
    val body = response.body() ?: return@withContext null
    if (!response.isSuccessful) return@withContext null
    val name = Regex("filename=\"?([^\";]+)\"?").find(response.headers()["Content-Disposition"].orEmpty())?.groupValues?.get(1)
        ?: ("DORR-chat." + if (media) "zip" else "txt")
    val dir = File(context.cacheDir, "exports").apply { mkdirs() }
    dir.listFiles()?.forEach { it.delete() }
    File(dir, name).also { out -> body.byteStream().use { input -> out.outputStream().use { input.copyTo(it) } } }
}

internal fun shareFile(context: Context, file: File, mime: String) {
    val uri = FileProvider.getUriForFile(context, context.packageName + ".files", file)
    val send = Intent(Intent.ACTION_SEND).apply {
        type = mime
        putExtra(Intent.EXTRA_STREAM, uri)
        addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
    }
    runCatching { context.startActivity(Intent.createChooser(send, file.name).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
}

// =============================================================================== manage storage (46–48)

/** My chats' files, biggest first: the large ones, the forwarded ones, all, or one chat's. Pages of 15. */
@Composable
fun StoragePage(conversationId: String? = null, title: String? = null) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var overview by remember { mutableStateOf<StorageDto?>(null) }
    var kind by remember { mutableStateOf(if (conversationId != null) "all" else "large") }
    var items by remember { mutableStateOf<List<StorageFileDto>?>(null) }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var loadingMore by remember { mutableStateOf(false) }
    val selected = remember { mutableStateListOf<String>() }
    var busy by remember { mutableStateOf(false) }
    var confirmDelete by remember { mutableStateOf(false) }
    val deleted = stringResource(R.string.bk_storage_deleted)
    val list = items.orEmpty()
    val allOnList = list.isNotEmpty() && list.all { it.messageId in selected } && !hasMore

    suspend fun load(reset: Boolean) {
        val forKind = kind
        if (forKind == "chats") {
            if (conversationId == null) overview = runCatching { ApiClient.backlog.storage(chatAuth()).data }.getOrNull()
            items = null
            page = 1
            hasMore = false
            loadingMore = false
            return
        }
        if (!reset && (loadingMore || !hasMore || items == null)) return
        if (!reset) loadingMore = true
        if (reset) {
            items = null
            page = 1
            hasMore = false
            loadingMore = false
            if (conversationId == null) overview = runCatching { ApiClient.backlog.storage(chatAuth()).data }.getOrNull()
        }
        val next = if (reset) 1 else page + 1
        val envelope = runCatching { ApiClient.backlog.storageFiles(chatAuth(), forKind, conversationId, page = next, perPage = 15) }.getOrNull()
        if (kind != forKind) return
        val chunk = envelope?.data?.items.orEmpty()
        items = if (reset) chunk else {
            val known = items.orEmpty().map { it.messageId }.toSet()
            items.orEmpty() + chunk.filter { it.messageId.isNotBlank() && it.messageId !in known }
        }
        page = next
        hasMore = envelope?.data?.hasMore == true
        if (!reset) loadingMore = false
    }
    LaunchedEffect(kind) {
        selected.clear()
        load(reset = true)
    }

    if (confirmDelete) {
        ConfirmDialog(
            icon = Icons.Rounded.DeleteSweep,
            title = stringResource(R.string.bk_storage_delete_title),
            message = stringResource(R.string.bk_storage_delete_sub),
            confirmLabel = stringResource(R.string.bk_storage_delete, selected.size),
            loading = busy,
            onConfirm = {
                if (busy || selected.isEmpty()) return@ConfirmDialog
                busy = true
                scope.launch {
                    runCatching {
                        ApiClient.backlog.deleteStorage(chatAuth(), JsonObject().apply { add("messages", JsonArray().apply { selected.forEach { add(it) } }) })
                    }.onSuccess { host.showToast(deleted) }.onFailure { host.showToast(it.apiFailure().message ?: "") }
                    selected.clear()
                    confirmDelete = false
                    load(reset = true)
                    busy = false
                }
            },
            onDismiss = { if (!busy) confirmDelete = false },
        )
    }

    ChPage(title ?: stringResource(R.string.bk_storage), onBack = { host.pop() }, settingsHeader = true) {
        Column(Modifier.fillMaxSize()) {
            overview?.let { o ->
                Column(
                    Modifier.fillMaxWidth().padding(start = 16.dp, end = 16.dp, top = 10.dp)
                        .clip(RoundedCornerShape(20.dp)).background(Brush.linearGradient(listOf(Ch.Red, Ch.RedDeep)))
                        .padding(horizontal = 12.dp, vertical = 10.dp),
                ) {
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f).padding(end = 8.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Box(Modifier.width(3.dp).height(12.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.9f)))
                                Spacer(Modifier.width(6.dp))
                                Text(stringResource(R.string.bk_storage_used), color = Color.White.copy(alpha = 0.88f), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
                            }
                            Text(usageBytes(o.totalBytes), color = Color.White, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 2.dp))
                            Text(stringResource(R.string.bk_storage_files_count, o.files), color = Color.White.copy(alpha = 0.8f), fontSize = 11.sp)
                        }
                        Box(
                            Modifier.size(40.dp).clip(RoundedCornerShape(14.dp)).background(Color.White.copy(alpha = 0.14f)).border(1.dp, Color.White.copy(alpha = 0.35f), RoundedCornerShape(14.dp)),
                            contentAlignment = Alignment.Center,
                        ) {
                            Icon(Icons.Rounded.SdCard, null, tint = Color.White, modifier = Modifier.size(20.dp))
                        }
                    }
                    Box(Modifier.fillMaxWidth().padding(top = 8.dp, bottom = 8.dp).height(1.dp).background(Color.White.copy(alpha = 0.22f)))
                    Row(verticalAlignment = Alignment.Top) {
                        Icon(Icons.Rounded.Info, null, tint = Color.White.copy(alpha = 0.9f), modifier = Modifier.size(14.dp).padding(top = 1.dp))
                        Spacer(Modifier.width(6.dp))
                        Text(
                            stringResource(R.string.bk_storage_sub),
                            color = Color.White.copy(alpha = 0.82f),
                            fontSize = 11.sp,
                            maxLines = 2,
                            overflow = TextOverflow.Ellipsis,
                        )
                    }
                }
            }
            if (conversationId == null) {
                Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(start = 12.dp, end = 12.dp, top = 8.dp, bottom = 2.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    listOf(
                        "large" to stringResource(R.string.bk_storage_large) + (overview?.large?.let { " ($it)" } ?: ""),
                        "forwarded" to stringResource(R.string.bk_storage_forwarded) + (overview?.forwarded?.let { " ($it)" } ?: ""),
                        "all" to stringResource(R.string.bk_storage_all),
                        "chats" to stringResource(R.string.bk_storage_chats),
                    ).forEach { (tab, label) ->
                        BkChip(label, kind == tab) {
                            if (kind == tab) return@BkChip
                            kind = tab
                            items = null
                            hasMore = false
                            page = 1
                            selected.clear()
                        }
                    }
                }
            }
            if (kind != "chats" && !list.isEmpty()) {
                Row(
                    Modifier.fillMaxWidth().padding(horizontal = 12.dp).clickable(enabled = !busy) {
                        if (allOnList) selected.clear()
                        else scope.launch {
                            busy = true
                            var pages = 0
                            while (hasMore && pages < 40) {
                                load(reset = false)
                                pages++
                            }
                            selected.clear()
                            selected.addAll(items.orEmpty().map { it.messageId }.filter { it.isNotBlank() }.take(500))
                            busy = false
                        }
                    }.padding(horizontal = 10.dp, vertical = 4.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        if (allOnList) stringResource(R.string.bk_storage_unselect) else stringResource(R.string.bk_storage_select_all),
                        color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f),
                    )
                    if (selected.isNotEmpty() && !allOnList) {
                        Text("${selected.size}", color = Ch.Red, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(end = 8.dp))
                    }
                    Icon(
                        if (allOnList) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked,
                        null,
                        tint = if (allOnList) Ch.Red else Ch.Soft,
                        modifier = Modifier.size(20.dp),
                    )
                }
            }
            Box(Modifier.weight(1f).fillMaxWidth()) {
                when {
                    kind == "chats" && overview?.chats.isNullOrEmpty() -> ChEmptyState(
                        Icons.Rounded.SdStorage,
                        stringResource(R.string.bk_storage),
                        stringResource(R.string.bk_storage_empty),
                        animated = true,
                    )
                    kind == "chats" -> LazyColumn(contentPadding = PaddingValues(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        items(overview?.chats.orEmpty(), key = { it.conversationId }) { chat ->
                            Row(
                                Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Ch.Surface)
                                    .clickable { host.push(ChRoute.Storage(chat.conversationId, chat.title)) }.padding(12.dp),
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                ChAvatar(null, chat.title, chat.conversationId, size = 40.dp, isGroup = chat.type != "direct")
                                Spacer(Modifier.width(10.dp))
                                Column(Modifier.weight(1f)) {
                                    Text(chat.title.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                    Text("${chat.files}", color = Ch.Mut, fontSize = 12.sp)
                                }
                                Text(usageBytes(chat.bytes), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp)
                            }
                        }
                    }
                    items == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ch.Red) }
                    items.isNullOrEmpty() -> ChEmptyState(
                        Icons.Rounded.SdStorage,
                        stringResource(R.string.bk_storage),
                        stringResource(R.string.bk_storage_empty),
                        animated = true,
                    )
                    else -> key(kind) {
                        val files = items.orEmpty()
                        val listState = rememberLazyListState()
                        LaunchedEffect(files.size, hasMore) {
                            snapshotFlow { listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index to listState.layoutInfo.totalItemsCount }
                                .collect { (last, total) ->
                                    if (last != null && total > 0 && last >= total - 3) {
                                        scope.launch { load(reset = false) }
                                    }
                                }
                        }
                        LazyColumn(state = listState, contentPadding = PaddingValues(start = 12.dp, end = 12.dp, top = 4.dp, bottom = 12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            itemsIndexed(files, key = { i, file -> "${kind}-${file.messageId.ifBlank { "file" }}-$i" }) { _, file ->
                                StorageFileRow(file, selected = file.messageId in selected, onToggle = {
                                    if (file.messageId in selected) selected.remove(file.messageId) else if (file.messageId.isNotBlank()) selected.add(file.messageId)
                                })
                            }
                        }
                    }
                }
            }
            if (selected.isNotEmpty()) {
                ChPrimaryButton(stringResource(R.string.bk_storage_delete, selected.size), Modifier.fillMaxWidth().padding(16.dp), enabled = !busy, icon = Icons.Rounded.DeleteSweep) {
                    confirmDelete = true
                }
            }
        }
    }
}

@Composable
private fun StorageFileRow(file: StorageFileDto, selected: Boolean, onToggle: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(if (selected) Ch.Red.copy(alpha = 0.08f) else Ch.Surface)
            .clickable(onClick = onToggle).padding(10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(48.dp).clip(RoundedCornerShape(12.dp)).background(Ch.SurfaceMuted), contentAlignment = Alignment.Center) {
            val thumb = file.thumbnail
            if (!thumb.isNullOrBlank()) {
                AsyncImage(ApiClient.mediaUrl(thumb), null, imageLoader = chatImages(LocalContext.current), contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
            } else {
                Icon(if (file.type == "video") Icons.Rounded.Videocam else Icons.Rounded.Description, null, tint = Ch.Mut)
            }
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(file.name ?: file.type, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 13.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (file.forwarded) Icon(Icons.Rounded.Forward, null, tint = Ch.Soft, modifier = Modifier.size(14.dp).padding(end = 2.dp))
                Text(listOfNotNull(file.chat, listTime(file.createdAt)).joinToString(" · "), color = Ch.Mut, fontSize = 12.sp, maxLines = 1)
            }
        }
        Text(usageBytes(file.bytes), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp)
        Spacer(Modifier.width(8.dp))
        Icon(if (selected) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (selected) Ch.Red else Ch.Soft)
    }
}

// =============================================================================== a channel's numbers (107) and reactions (110)

@Composable
fun ChannelStatsPage(conversationId: String) {
    val host = LocalChat.current
    var days by remember { mutableStateOf(30) }
    var stats by remember { mutableStateOf<ChannelStatsDto?>(null) }
    LaunchedEffect(days) {
        stats = null
        stats = runCatching { ApiClient.backlog.channelStats(chatAuth(), conversationId, days).data }.getOrNull()
    }
    ChPage(stringResource(R.string.bk_channel_stats), onBack = { host.pop() }) {
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                listOf(7, 30, 90).forEach { d -> BkChip(stringResource(R.string.bk_days, d), days == d) { days = d } }
            }
            val s = stats
            if (s == null) {
                Box(Modifier.fillMaxWidth().padding(40.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ch.Red) }
                return@Column
            }
            Spacer(Modifier.height(14.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                StatTile(stringResource(R.string.bk_channel_followers), s.followers.toString(), Modifier.weight(1f))
                StatTile(stringResource(R.string.bk_channel_joined), "+${s.joined}", Modifier.weight(1f))
                StatTile(stringResource(R.string.bk_channel_left), "−${s.left}", Modifier.weight(1f))
            }
            Spacer(Modifier.height(10.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                StatTile(stringResource(R.string.bk_channel_posts), s.posts.toString(), Modifier.weight(1f))
                StatTile(stringResource(R.string.bk_channel_views), s.views.toString(), Modifier.weight(1f))
                StatTile(stringResource(R.string.bk_channel_reach), "${s.reachPercent}%", Modifier.weight(1f))
            }
            Spacer(Modifier.height(10.dp))
            StatTile(stringResource(R.string.bk_channel_avg), s.averageViews.toString(), Modifier.fillMaxWidth())
            if (s.topPosts.isNotEmpty()) {
                Text(stringResource(R.string.bk_channel_top), color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(top = 18.dp, bottom = 8.dp))
                s.topPosts.forEach { post ->
                    Row(
                        Modifier.fillMaxWidth().padding(bottom = 8.dp).clip(RoundedCornerShape(16.dp)).background(Ch.Surface)
                            .clickable(enabled = post.id != null) { host.focusRequest = conversationId to post.id!!; host.pop() }.padding(12.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(post.preview.orEmpty(), color = Ch.Ink, fontSize = 13.5.sp, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                        Spacer(Modifier.width(10.dp))
                        Text("👁 ${post.views}", color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 12.5.sp)
                    }
                }
            }
        }
    }
}

@Composable
internal fun StatTile(label: String, value: String, modifier: Modifier = Modifier) {
    Column(modifier.clip(RoundedCornerShape(18.dp)).background(Ch.Surface).padding(12.dp)) {
        Text(value, color = Ch.Ink, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold)
        Text(label, color = Ch.Mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/** The common emojis a channel can allow. */
internal val ChannelEmojis = listOf("👍", "❤️", "😂", "😮", "😢", "🙏", "🔥", "👏", "🎉", "😍", "💯", "🤔")

/** Any emoji · none · only these. */
@OptIn(ExperimentalLayoutApi::class, androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
internal fun ChannelReactionsSheet(conversationId: String, current: List<String>?, onDismiss: () -> Unit, onSaved: (ConversationDto) -> Unit) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var mode by remember { mutableStateOf(when { current == null -> "any"; current.isEmpty() -> "none"; else -> "some" }) }
    val picked = remember { mutableStateListOf<String>().apply { addAll(current.orEmpty().ifEmpty { listOf("👍", "❤️") }) } }
    var busy by remember { mutableStateOf(false) }
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(R.string.bk_channel_reactions), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.bk_channel_reactions_sub), color = Ch.Mut, fontSize = 13.sp)
            Spacer(Modifier.height(12.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                listOf("any" to R.string.bk_reactions_any, "some" to R.string.bk_reactions_some, "none" to R.string.bk_reactions_none)
                    .forEach { (key, label) -> BkChip(stringResource(label), mode == key) { mode = key } }
            }
            if (mode == "some") {
                Spacer(Modifier.height(12.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    ChannelEmojis.forEach { e ->
                        val on = e in picked
                        Box(
                            Modifier.size(46.dp).clip(CircleShape).background(if (on) Ch.Red.copy(alpha = 0.15f) else Ch.SurfaceMuted)
                                .clickable { if (on) picked.remove(e) else picked.add(e) },
                            contentAlignment = Alignment.Center,
                        ) { Text(e, fontSize = 22.sp) }
                    }
                }
            }
            Spacer(Modifier.height(18.dp))
            ChPrimaryButton(stringResource(R.string.ch_save), Modifier.fillMaxWidth(), enabled = !busy && (mode != "some" || picked.isNotEmpty())) {
                busy = true
                scope.launch {
                    val body = JsonObject().apply {
                        when (mode) {
                            "any" -> add("reactions", JsonNull.INSTANCE)
                            "none" -> add("reactions", JsonArray())
                            else -> add("reactions", JsonArray().apply { picked.forEach { add(it) } })
                        }
                    }
                    runCatching { ApiClient.backlog.channelReactions(chatAuth(), conversationId, body).data }
                        .onSuccess { c -> c?.let(onSaved); onDismiss() }
                        .onFailure { host.showToast(it.apiFailure().message ?: "") }
                    busy = false
                }
            }
        }
    }
}

@Composable
internal fun BkChip(label: String, selected: Boolean, onClick: () -> Unit) {
    val bg by animateColorAsState(if (selected) Ch.Red else Ch.SurfaceMuted, label = "bkChip")
    Text(
        label, color = if (selected) Color.White else Ch.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1,
        modifier = Modifier.clip(CircleShape).background(bg).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 8.dp),
    )
}

// =============================================================================== a business (111, 116)

/** My messages to customers and theirs to me, over 7 / 30 / 90 days (WhatsApp Business's statistics). */
@Composable
internal fun BusinessStatsCard() {
    var days by remember { mutableStateOf(30) }
    var stats by remember { mutableStateOf<com.dorr.app.network.BusinessStatsDto?>(null) }
    LaunchedEffect(days) { stats = runCatching { ApiClient.backlog.businessStats(chatAuth(), days).data }.getOrNull() }
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp)).background(Ch.Surface).padding(12.dp)) {
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            listOf(7, 30, 90).forEach { d -> BkChip(stringResource(R.string.bk_days, d), days == d) { days = d } }
        }
        Spacer(Modifier.height(10.dp))
        val st = stats
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            StatTile(stringResource(R.string.bk_stats_sent), st?.sent?.toString() ?: "…", Modifier.weight(1f))
            StatTile(stringResource(R.string.bk_stats_delivered), st?.delivered?.toString() ?: "…", Modifier.weight(1f))
            StatTile(stringResource(R.string.bk_stats_read), st?.read?.toString() ?: "…", Modifier.weight(1f))
        }
        Spacer(Modifier.height(8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            StatTile(stringResource(R.string.bk_stats_received), st?.received?.toString() ?: "…", Modifier.weight(1f))
            StatTile(stringResource(R.string.bk_stats_chats), st?.chats?.toString() ?: "…", Modifier.weight(1f))
        }
    }
}

/** A business's card on its chat info: about, address (maps), email, website. */
@Composable
internal fun BusinessCardInfo(b: com.dorr.app.network.BusinessHoursDto) {
    val context = LocalContext.current
    fun open(uri: String) = runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, android.net.Uri.parse(uri)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
    Column(Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 6.dp).clip(RoundedCornerShape(22.dp)).background(Ch.Surface).padding(vertical = 6.dp)) {
        Text(stringResource(R.string.bk_business_card), color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(horizontal = 16.dp, vertical = 6.dp))
        b.about?.takeIf { it.isNotBlank() }?.let { Text(it, color = Ch.Ink, fontSize = 14.sp, modifier = Modifier.padding(horizontal = 16.dp, vertical = 4.dp)) }
        b.address?.takeIf { it.isNotBlank() }?.let { a -> SettingRow(androidx.compose.material.icons.Icons.Rounded.Place, a) { open("geo:0,0?q=" + android.net.Uri.encode(a)) } }
        b.email?.takeIf { it.isNotBlank() }?.let { e -> SettingRow(androidx.compose.material.icons.Icons.Rounded.Email, e) { open("mailto:$e") } }
        b.website?.takeIf { it.isNotBlank() }?.let { w -> SettingRow(androidx.compose.material.icons.Icons.Rounded.Language, w.removePrefix("https://").removePrefix("http://")) { open(w) } }
    }
}
