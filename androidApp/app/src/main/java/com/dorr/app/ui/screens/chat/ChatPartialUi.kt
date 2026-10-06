package com.dorr.app.ui.screens.chat

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.ExperimentalFoundationApi
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
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AlternateEmail
import androidx.compose.material.icons.rounded.CallMissed
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.NotificationsActive
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Psychology
import androidx.compose.material.icons.rounded.Reply
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.ThumbDown
import androidx.compose.material.icons.rounded.ThumbUp
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.CatchUpDto
import com.dorr.app.network.ChatMoneyDto
import com.dorr.app.network.DecisionRoomDto
import com.dorr.app.network.FolderDto
import com.dorr.app.network.MessageDto
import com.dorr.app.network.MsgRefDto
import com.dorr.app.network.PrivacyCenterDto
import com.dorr.app.network.StarFolderDto
import com.dorr.app.network.StarFoldersDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.wallet.PadResult
import com.dorr.app.ui.screens.wallet.WaPinPad
import com.dorr.app.ui.screens.wallet.formatMinor
import com.google.gson.JsonNull
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import retrofit2.HttpException
import java.time.Instant
import java.time.ZoneOffset

/*
 * The chat items that were partial, closed (docs/remaining_chat.md): reading comfort (6), folder
 * looks (1), favourites folders (24), search by kind (21), media by date / files by kind and size
 * (17, 18), upload quality by connection (13), a PIN for one chat (25), @usernames (81), the
 * priority inbox (114), "what I missed" (123), money in a chat (66), the decision room (153) and
 * the privacy center (127).
 */

// =============================================================================== 6 + 13 my reading & upload choices

/** Per-phone chat choices: text size, a compact list, and how photos are sent. */
object ChatPrefs {
    var fontScale by mutableFloatStateOf(1f)
        private set
    var compactList by mutableStateOf(false)
        private set
    /** auto (by the connection) · high · saver */
    var uploadQuality by mutableStateOf("auto")
        private set
    private var loaded = false

    fun load(context: Context) {
        if (loaded) return
        val p = context.getSharedPreferences("chat_prefs", Context.MODE_PRIVATE)
        fontScale = p.getFloat("font_scale", 1f)
        compactList = p.getBoolean("compact_list", false)
        uploadQuality = p.getString("upload_quality", "auto") ?: "auto"
        loaded = true
    }

    fun save(context: Context, scale: Float = fontScale, compact: Boolean = compactList, quality: String = uploadQuality) {
        fontScale = scale
        compactList = compact
        uploadQuality = quality
        context.getSharedPreferences("chat_prefs", Context.MODE_PRIVATE).edit()
            .putFloat("font_scale", scale).putBoolean("compact_list", compact).putString("upload_quality", quality).apply()
    }

    /**
     * Longest side and JPEG quality for a photo now (spec 13): on Wi-Fi or a fast line close to the
     * original, on a slow one much lighter — or what I chose.
     */
    fun photoBudget(context: Context): Pair<Int, Int> {
        load(context)
        return when (uploadQuality) {
            "high" -> 2560 to 90
            "saver" -> 1024 to 70
            else -> {
                val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager
                val caps = cm?.getNetworkCapabilities(cm.activeNetwork)
                val kbps = caps?.linkDownstreamBandwidthKbps ?: 0
                when {
                    caps == null -> 1600 to 82
                    caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_NOT_METERED) -> 2048 to 88
                    kbps in 1..1500 -> 1024 to 70
                    else -> 1600 to 82
                }
            }
        }
    }
}

/** Text size, a compact chat list and upload quality, with a live preview bubble. */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun ChatComfortSheet(onDismiss: () -> Unit) {
    val context = LocalContext.current
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(R.string.cp_comfort), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(14.dp))
            // The bubble as it will look.
            Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.CenterEnd) {
                Text(
                    stringResource(R.string.cp_preview_text), color = Ch.OutText, fontSize = (15.5f * ChatPrefs.fontScale).sp, lineHeight = (21f * ChatPrefs.fontScale).sp,
                    modifier = Modifier.clip(RoundedCornerShape(20.dp)).background(Ch.OutBubble).padding(horizontal = 14.dp, vertical = 10.dp),
                )
            }
            Spacer(Modifier.height(14.dp))
            Text(stringResource(R.string.cp_text_size), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(6.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf(0.9f to "A-", 1f to "A", 1.15f to "A+", 1.3f to "A++").forEach { (scale, label) ->
                    Pill(label, ChatPrefs.fontScale == scale, Modifier.weight(1f)) { ChatPrefs.save(context, scale = scale) }
                }
            }
            Spacer(Modifier.height(10.dp))
            ToggleRow(Icons.Rounded.Notes, stringResource(R.string.cp_compact), ChatPrefs.compactList, stringResource(R.string.cp_compact_sub)) { ChatPrefs.save(context, compact = it) }
            Spacer(Modifier.height(10.dp))
            Text(stringResource(R.string.cp_upload), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(6.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("auto" to R.string.cp_upload_auto, "high" to R.string.cp_upload_high, "saver" to R.string.cp_upload_saver).forEach { (key, label) ->
                    Pill(stringResource(label), ChatPrefs.uploadQuality == key, Modifier.weight(1f)) { ChatPrefs.save(context, quality = key) }
                }
            }
            Text(stringResource(R.string.cp_upload_sub), color = Ch.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 6.dp))
        }
    }
}

@Composable
private fun Pill(label: String, on: Boolean, modifier: Modifier = Modifier, onClick: () -> Unit) {
    val bg by animateColorAsState(if (on) Ch.Red else Ch.SurfaceMuted, label = "pill")
    Text(
        label, color = if (on) Color.White else Ch.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, textAlign = androidx.compose.ui.text.style.TextAlign.Center,
        modifier = modifier.clip(RoundedCornerShape(14.dp)).background(bg).clickable(onClick = onClick).padding(vertical = 10.dp),
    )
}

// =============================================================================== 1 folder looks

internal val LookColors = listOf(null, "#2563EB", "#16A34A", "#D97706", "#DB2777", "#7C3AED", "#0891B2", "#DC2626")
private val FolderEmojis = listOf(null, "💼", "👨‍👩‍👧", "❤️", "🎓", "🏠", "⚽", "✈️", "🛒", "⭐")

internal fun lookColor(hex: String?): Color? = runCatching { hex?.let { Color(android.graphics.Color.parseColor(it)) } }.getOrNull()

/** A colour and an emoji for a folder's chip. */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun FolderLookSheet(folder: FolderDto, onDismiss: () -> Unit, onSaved: (FolderDto) -> Unit) {
    val scope = rememberCoroutineScope()
    var color by remember { mutableStateOf(folder.color) }
    var emoji by remember { mutableStateOf(folder.emoji) }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(R.string.cp_folder_look), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(12.dp))
            // Preview of the chip.
            Row(
                Modifier.clip(RoundedCornerShape(19.dp)).background(lookColor(color) ?: Ch.Red).padding(horizontal = 18.dp, vertical = 9.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) { Text(listOfNotNull(emoji, folder.name).joinToString(" "), color = Color.White, fontWeight = FontWeight.Bold) }
            Spacer(Modifier.height(14.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                LookColors.forEach { c ->
                    Box(
                        Modifier.size(34.dp).clip(CircleShape).background(lookColor(c) ?: Ch.Red)
                            .border(3.dp, if (color == c) Ch.Ink.copy(alpha = 0.5f) else Color.Transparent, CircleShape).clickable { color = c },
                    )
                }
            }
            Spacer(Modifier.height(12.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                FolderEmojis.forEach { e ->
                    Box(
                        Modifier.size(40.dp).clip(CircleShape).background(if (emoji == e) Ch.Red.copy(alpha = 0.16f) else Ch.SurfaceMuted).clickable { emoji = e },
                        contentAlignment = Alignment.Center,
                    ) { if (e == null) Icon(Icons.Rounded.Close, null, tint = Ch.Mut, modifier = Modifier.size(18.dp)) else Text(e, fontSize = 19.sp) }
                }
            }
            Spacer(Modifier.height(16.dp))
            ChPrimaryButton(stringResource(R.string.ch_save), modifier = Modifier.fillMaxWidth()) {
                scope.launch {
                    runCatching { ApiClient.chat.renameFolder(chatAuth(), folder.id, mapOf("color" to (color ?: ""), "emoji" to (emoji ?: ""))).data }
                        .getOrNull()?.let { onSaved(it.copy(conversationIds = folder.conversationIds)) }
                    onDismiss()
                }
            }
        }
    }
}

// =============================================================================== 24 favourites folders

/** Star into a folder: "Favourites" (no folder), one of mine, or a new one. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun StarFolderSheet(onDismiss: () -> Unit, onPick: (Int?) -> Unit) {
    val scope = rememberCoroutineScope()
    var data by remember { mutableStateOf<StarFoldersDto?>(null) }
    var creating by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { data = runCatching { ApiClient.more.starFolders(chatAuth()).data }.getOrNull() ?: StarFoldersDto() }

    if (creating) {
        TextInputSheet(stringResource(R.string.cp_new_star_folder), action = stringResource(R.string.ch_save), onDismiss = { creating = false }) { name ->
            scope.launch {
                runCatching { ApiClient.more.createStarFolder(chatAuth(), JsonObject().apply { addProperty("name", name) }).data }.getOrNull()?.let { onPick(it.id) }
                onDismiss()
            }
        }
        return
    }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 22.dp)) {
            Text(stringResource(R.string.cp_star_into), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(10.dp))
            FolderRow("⭐", stringResource(R.string.cp_favourites), null) { onPick(null); onDismiss() }
            data?.folders?.forEach { f -> FolderRow(f.emoji ?: "📁", f.name, lookColor(f.color)) { onPick(f.id); onDismiss() } }
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable { creating = true }.padding(vertical = 12.dp, horizontal = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Add, null, tint = Ch.Red, modifier = Modifier.size(22.dp))
                Spacer(Modifier.width(10.dp))
                Text(stringResource(R.string.cp_new_star_folder), color = Ch.Red, fontSize = 15.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}

@Composable
private fun FolderRow(emoji: String, name: String, tint: Color?, count: Int? = null, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable(onClick = onClick).padding(vertical = 10.dp, horizontal = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background((tint ?: Ch.Red).copy(alpha = 0.14f)), contentAlignment = Alignment.Center) { Text(emoji, fontSize = 18.sp) }
        Spacer(Modifier.width(10.dp))
        Text(name, color = Ch.Ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
        count?.let { Text("$it", color = Ch.Mut, fontSize = 12.5.sp) }
    }
}

/** My favourites: all, the ones in no folder, and each folder of mine. A long press on a folder manages it. */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun FavouritesPage() {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var folders by remember { mutableStateOf<StarFoldersDto?>(null) }
    var selected by remember { mutableStateOf<String?>(null) } // null all · "none" · id
    var list by remember { mutableStateOf<List<MessageDto>?>(null) }
    var managing by remember { mutableStateOf<StarFolderDto?>(null) }
    var renaming by remember { mutableStateOf<StarFolderDto?>(null) }
    var creating by remember { mutableStateOf(false) }
    var moving by remember { mutableStateOf<MessageDto?>(null) }

    suspend fun reload() {
        folders = runCatching { ApiClient.more.starFolders(chatAuth()).data }.getOrNull() ?: StarFoldersDto()
        list = runCatching { ApiClient.more.starred(chatAuth(), selected).data }.getOrNull().orEmpty()
    }
    LaunchedEffect(selected) { list = null; reload() }

    ChPage(stringResource(R.string.ch_starred_title), onBack = { host.pop() }) {
        Column(Modifier.fillMaxSize()) {
            Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 14.dp, vertical = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FolderChip(stringResource(R.string.cp_all), selected == null, null) { selected = null }
                FolderChip("⭐ " + stringResource(R.string.cp_favourites), selected == "none", null) { selected = "none" }
                folders?.folders?.forEach { f ->
                    FolderChip(listOfNotNull(f.emoji, f.name, "(${f.count})").joinToString(" "), selected == f.id.toString(), lookColor(f.color), onLong = { managing = f }) { selected = f.id.toString() }
                }
                Box(Modifier.size(36.dp).clip(CircleShape).background(Ch.Surface).clickable { creating = true }, contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Add, null, tint = Ch.Red, modifier = Modifier.size(20.dp))
                }
            }
            val items = list
            when {
                items == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ch.Red) }
                items.isEmpty() -> ChEmptyState(Icons.Rounded.Star, stringResource(R.string.ch_starred_title), stringResource(R.string.ch_no_starred), animated = true)
                else -> LazyColumn(contentPadding = PaddingValues(vertical = 6.dp)) {
                    itemsIndexed(items, key = { _, m -> m.id }) { i, m ->
                        Column(Modifier.fillMaxWidth().chStagger(i).combinedClickable(onClick = { host.focusRequest = m.conversationId to m.id; host.push(ChRoute.Conversation(m.conversationId)) }, onLongClick = { moving = m }).padding(vertical = 6.dp)) {
                            Row(Modifier.padding(horizontal = 18.dp), verticalAlignment = Alignment.CenterVertically) {
                                ChAvatar(m.sender?.avatar, m.sender?.name, m.sender?.key, size = 26.dp)
                                Spacer(Modifier.width(8.dp))
                                Text(if (m.sender?.isMe == true) stringResource(R.string.ch_you) else m.sender?.name.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.weight(1f))
                                Text(listTime(m.createdAt), color = Ch.Soft, fontSize = 11.5.sp)
                            }
                            MessageRow(UiMessage(m), firstInRun = true, lastInRun = true, isGroup = false, actions = BubbleActions({}, {}, {}, {}, { _, _ -> }, { host.openWalletQr(it) }, {}))
                        }
                    }
                }
            }
        }
    }

    if (creating) TextInputSheet(stringResource(R.string.cp_new_star_folder), action = stringResource(R.string.ch_save), onDismiss = { creating = false }) { name ->
        scope.launch { runCatching { ApiClient.more.createStarFolder(chatAuth(), JsonObject().apply { addProperty("name", name) }) }; reload() }
    }
    managing?.let { f ->
        ChoiceSheet(
            title = f.name,
            options = listOf(
                stringResource(R.string.ch_rename_folder) to { renaming = f },
                stringResource(R.string.ch_delete_folder) to {
                    scope.launch { runCatching { ApiClient.more.deleteStarFolder(chatAuth(), f.id) }; if (selected == f.id.toString()) selected = null; reload() }
                    Unit
                },
            ),
            onDismiss = { managing = null },
        )
    }
    renaming?.let { f ->
        TextInputSheet(stringResource(R.string.ch_rename_folder), initial = f.name, action = stringResource(R.string.ch_save), onDismiss = { renaming = null }) { name ->
            scope.launch { runCatching { ApiClient.more.updateStarFolder(chatAuth(), f.id, JsonObject().apply { addProperty("name", name) }) }; reload() }
        }
    }
    moving?.let { m ->
        StarFolderSheet(onDismiss = { moving = null }) { folderId ->
            scope.launch {
                runCatching { ApiClient.more.star(chatAuth(), m.id, JsonObject().apply { addProperty("starred", true); folderId?.let { addProperty("folder_id", it) } }) }
                reload()
            }
        }
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun FolderChip(label: String, on: Boolean, tint: Color?, onLong: (() -> Unit)? = null, onClick: () -> Unit) {
    val color = tint ?: Ch.Red
    Text(
        label, color = if (on) Color.White else Ch.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold,
        modifier = Modifier.clip(RoundedCornerShape(18.dp)).background(if (on) color else Ch.Surface)
            .combinedClickable(onClick = onClick, onLongClick = onLong).padding(horizontal = 14.dp, vertical = 8.dp),
    )
}

// =============================================================================== 21 search by kind

internal val SearchKinds = listOf(
    "image" to R.string.cp_k_photos, "video" to R.string.cp_k_videos, "voice" to R.string.cp_k_voice, "document" to R.string.cp_k_files,
    "link" to R.string.cp_k_links, "location" to R.string.cp_k_places, "poll" to R.string.cp_k_polls, "money" to R.string.cp_k_money,
)

/** Under the search bar: narrow to kinds of message (with or without words). */
@Composable
fun SearchKindChips(selected: Set<String>, onToggle: (String) -> Unit) {
    Row(Modifier.fillMaxWidth().background(Ch.Bg).horizontalScroll(rememberScrollState()).padding(horizontal = 12.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        SearchKinds.forEach { (key, label) ->
            val on = key in selected
            Text(
                stringResource(label), color = if (on) Color.White else Ch.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(CircleShape).background(if (on) Ch.Red else Ch.Surface).clickable { onToggle(key) }.padding(horizontal = 12.dp, vertical = 7.dp),
            )
        }
    }
}

// =============================================================================== 17 + 18 media filters

/** Media tab: from a day back. Files tab: by kind, big ones, biggest first. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun MediaFilterRow(files: Boolean, date: String?, fileKind: String?, bigOnly: Boolean, bySize: Boolean, onDate: (String?) -> Unit, onKind: (String?) -> Unit, onBig: (Boolean) -> Unit, onSize: (Boolean) -> Unit) {
    var picking by remember { mutableStateOf(false) }
    Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 12.dp, vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
        if (!files) {
            FilterChip(date?.let { stringResource(R.string.cp_until, it) } ?: stringResource(R.string.cp_pick_date), date != null, Icons.Rounded.CalendarMonth) { if (date != null) onDate(null) else picking = true }
        } else {
            listOf("pdf" to "PDF", "word" to "Word", "excel" to "Excel", "slides" to "Slides", "archive" to "ZIP").forEach { (k, l) ->
                FilterChip(l, fileKind == k, null) { onKind(if (fileKind == k) null else k) }
            }
            FilterChip(stringResource(R.string.cp_other), fileKind == "other", null) { onKind(if (fileKind == "other") null else "other") }
            FilterChip(stringResource(R.string.cp_big_files), bigOnly, null) { onBig(!bigOnly) }
            FilterChip(stringResource(R.string.cp_by_size), bySize, null) { onSize(!bySize) }
        }
    }
    if (picking) {
        val state = rememberDatePickerState()
        DatePickerDialog(
            onDismissRequest = { picking = false },
            confirmButton = {
                TextButton({ state.selectedDateMillis?.let { onDate(Instant.ofEpochMilli(it).atZone(ZoneOffset.UTC).toLocalDate().toString()) }; picking = false }) {
                    Text(stringResource(R.string.ch_ok), color = Ch.Red, fontWeight = FontWeight.Bold)
                }
            },
            dismissButton = { TextButton({ picking = false }) { Text(stringResource(R.string.ch_cancel), color = Ch.Mut) } },
        ) { DatePicker(state) }
    }
}

@Composable
private fun FilterChip(label: String, on: Boolean, icon: ImageVector?, onClick: () -> Unit) {
    Row(
        Modifier.clip(CircleShape).background(if (on) Ch.Red else Ch.Surface).border(1.dp, if (on) Color.Transparent else Ch.Line, CircleShape).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 7.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        icon?.let { Icon(it, null, tint = if (on) Color.White else Ch.Red, modifier = Modifier.size(15.dp)); Spacer(Modifier.width(5.dp)) }
        Text(label, color = if (on) Color.White else Ch.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
        if (on && icon != null) { Spacer(Modifier.width(4.dp)); Icon(Icons.Rounded.Close, null, tint = Color.White, modifier = Modifier.size(14.dp)) }
    }
}

// =============================================================================== 25 a PIN for one chat

/** Unlocked this visit (each PIN chat on its own); cleared when the app goes to the background. */
object ChatPinLock {
    val unlocked = mutableSetOf<String>()
}

private fun lockFailure(e: Throwable): PadResult {
    val failure = e.apiFailure()
    if (e is HttpException && e.code() == 429) return PadResult.Locked(System.currentTimeMillis() + 5 * 60_000)
    return PadResult.Error(failure.message ?: "")
}

/** Instead of a PIN-locked chat until its own PIN is entered. */
@Composable
fun PinGate(conversationId: String, title: String, onUnlocked: () -> Unit) {
    Column(Modifier.fillMaxSize().background(Ch.Bg).padding(horizontal = 16.dp, vertical = 24.dp)) {
        WaPinPad(
            title = stringResource(R.string.cp_pin_enter),
            sub = title,
            icon = Icons.Rounded.Lock,
            modifier = Modifier.fillMaxWidth().height(540.dp),
            onComplete = { pin ->
                try {
                    ApiClient.more.unlock(chatAuth(), conversationId, JsonObject().apply { addProperty("pin", pin) })
                    ChatPinLock.unlocked += conversationId
                    onUnlocked()
                    PadResult.Ok
                } catch (e: Exception) {
                    lockFailure(e)
                }
            },
        )
    }
}

/** Set (twice, to be sure), change or remove this chat's own PIN. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChatPinSheet(conversationId: String, hasPin: Boolean, onDismiss: () -> Unit, onChanged: (Boolean) -> Unit) {
    val host = LocalChat.current
    var step by remember { mutableStateOf(if (hasPin) "current" else "new") } // current · new · confirm
    var current by remember { mutableStateOf<String?>(null) }
    var first by remember { mutableStateOf("") }
    var removing by remember { mutableStateOf(false) }
    val saved = stringResource(R.string.cp_pin_saved)
    val mismatch = stringResource(R.string.cp_pin_mismatch)
    val removed = stringResource(R.string.cp_pin_removed)

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 12.dp).padding(bottom = 16.dp)) {
            if (hasPin && step == "current") {
                Row(Modifier.fillMaxWidth().padding(horizontal = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Pill(stringResource(R.string.cp_pin_change), !removing, Modifier.weight(1f)) { removing = false }
                    Pill(stringResource(R.string.cp_pin_remove), removing, Modifier.weight(1f)) { removing = true }
                }
            }
            WaPinPad(
                title = stringResource(when (step) { "current" -> R.string.cp_pin_current; "new" -> R.string.cp_pin_new; else -> R.string.cp_pin_confirm }),
                sub = stringResource(R.string.cp_pin_sub),
                modifier = Modifier.fillMaxWidth().height(500.dp),
                onComplete = { pin ->
                    when (step) {
                        "current" -> try {
                            if (removing) {
                                ApiClient.more.removeLockPin(chatAuth(), conversationId, JsonObject().apply { addProperty("pin", pin) })
                                host.showToast(removed)
                                onChanged(false)
                                onDismiss()
                                PadResult.Ok
                            } else {
                                ApiClient.more.unlock(chatAuth(), conversationId, JsonObject().apply { addProperty("pin", pin) })
                                current = pin
                                step = "new"
                                PadResult.Reset
                            }
                        } catch (e: Exception) {
                            lockFailure(e)
                        }
                        "new" -> { first = pin; step = "confirm"; PadResult.Reset }
                        else -> if (pin != first) {
                            step = "new"
                            PadResult.Error(mismatch)
                        } else try {
                            ApiClient.more.setLockPin(chatAuth(), conversationId, JsonObject().apply { addProperty("pin", pin); current?.let { addProperty("current_pin", it) } })
                            ChatPinLock.unlocked += conversationId
                            host.showToast(saved)
                            onChanged(true)
                            onDismiss()
                            PadResult.Ok
                        } catch (e: Exception) {
                            lockFailure(e)
                        }
                    }
                },
            )
        }
    }
}

// =============================================================================== 81 @username

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun UsernameSheet(onDismiss: () -> Unit, onSaved: (String?) -> Unit = {}) {
    val scope = rememberCoroutineScope()
    var value by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { value = runCatching { ApiClient.more.username(chatAuth()).data?.username }.getOrNull().orEmpty() }

    fun save(name: String?) {
        busy = true
        error = null
        scope.launch {
            runCatching { ApiClient.more.setUsername(chatAuth(), JsonObject().apply { if (name == null) add("username", JsonNull.INSTANCE) else addProperty("username", name) }).data }
                .onSuccess { onSaved(it?.username); onDismiss() }
                .onFailure { error = it.apiFailure().message }
            busy = false
        }
    }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(R.string.cp_username), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.cp_username_sub), color = Ch.Mut, fontSize = 13.sp)
            Spacer(Modifier.height(12.dp))
            ChField(value, { value = it.lowercase().filter { c -> c.isLetterOrDigit() || c == '_' }.take(32) }, stringResource(R.string.cp_username_hint), icon = Icons.Rounded.AlternateEmail)
            error?.let { Text(it, color = Ch.Danger, fontSize = 12.5.sp, modifier = Modifier.padding(top = 6.dp)) }
            Spacer(Modifier.height(14.dp))
            ChPrimaryButton(stringResource(R.string.ch_save), modifier = Modifier.fillMaxWidth(), enabled = value.length >= 3 && !busy) { save(value) }
            Spacer(Modifier.height(6.dp))
            TextButton({ save(null) }, modifier = Modifier.fillMaxWidth()) { Text(stringResource(R.string.cp_username_remove), color = Ch.Mut) }
        }
    }
}

// =============================================================================== 123 what I missed

/** Since a time (24 h by default): mentions, replies to me, replies I owe, missed calls, reminders, decisions, busiest chats. */
@Composable
fun CatchUpPage() {
    val host = LocalChat.current
    var range by remember { mutableStateOf(24) } // hours
    var data by remember { mutableStateOf<CatchUpDto?>(null) }
    LaunchedEffect(range) {
        data = null
        data = runCatching { ApiClient.more.catchUp(chatAuth(), Instant.now().minusSeconds(range * 3600L).toString()).data }.getOrNull() ?: CatchUpDto()
    }
    val open: (String?, String?) -> Unit = { c, m -> if (c != null) { if (m != null) host.focusRequest = c to m; host.push(ChRoute.Conversation(c)) } }

    ChPage(stringResource(R.string.cp_catch_up), onBack = { host.pop() }) {
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf(8 to R.string.cp_8h, 24 to R.string.cp_24h, 72 to R.string.cp_3d, 168 to R.string.cp_week).forEach { (h, label) ->
                    Pill(stringResource(label), range == h, Modifier.weight(1f)) { range = h }
                }
            }
            val d = data
            if (d == null) {
                Box(Modifier.fillMaxWidth().padding(40.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ch.Red) }
                return@Column
            }
            val empty = d.mentions.isEmpty() && d.replies.isEmpty() && d.urgent.isEmpty() && d.owed.isEmpty() && d.missedCalls.isEmpty() && d.reminders.isEmpty() && d.decisions.isEmpty() && d.unreadChats.isEmpty()
            if (empty) {
                Spacer(Modifier.height(40.dp))
                Text(stringResource(R.string.cp_caught_up), color = Ch.Mut, fontSize = 15.sp, fontWeight = FontWeight.Bold, modifier = Modifier.align(Alignment.CenterHorizontally))
                return@Column
            }
            RefSection(stringResource(R.string.cp_sec_urgent), Icons.Rounded.NotificationsActive, Ch.Danger, d.urgent, open)
            RefSection(stringResource(R.string.cp_sec_mentions), Icons.Rounded.AlternateEmail, Ch.Red, d.mentions, open)
            RefSection(stringResource(R.string.cp_sec_replies), Icons.Rounded.Reply, Color(0xFF2563EB), d.replies, open)
            RefSection(stringResource(R.string.cp_sec_owed), Icons.Rounded.Psychology, Color(0xFFD97706), d.owed, open)
            if (d.missedCalls.isNotEmpty()) {
                SectionTitle(stringResource(R.string.cp_sec_calls), Icons.Rounded.CallMissed, Ch.Danger)
                d.missedCalls.forEach { c -> RefCard(listOfNotNull(c.caller, c.chat.takeIf { it != c.caller }).joinToString(" · "), stringResource(if (c.type == "video") R.string.cp_video_call else R.string.cp_voice_call), c.createdAt) { open(c.conversationId, null) } }
            }
            RefSection(stringResource(R.string.cp_sec_reminders), Icons.Rounded.NotificationsActive, Color(0xFF7C3AED), d.reminders, open)
            if (d.decisions.isNotEmpty()) {
                SectionTitle(stringResource(R.string.cp_sec_decisions), Icons.Rounded.Gavel, Color(0xFF16A34A))
                d.decisions.forEach { x -> RefCard(x.chat.orEmpty(), x.title, x.deadlineAt) { host.push(ChRoute.DecisionRoom(x.id)) } }
            }
            if (d.unreadChats.isNotEmpty()) {
                SectionTitle(stringResource(R.string.cp_sec_unread), Icons.Rounded.Visibility, Ch.Mut)
                d.unreadChats.forEach { u -> RefCard(u.chat.orEmpty(), stringResource(R.string.cp_unread_n, u.unread) + if (u.mention) "  ·  @" else "", null) { open(u.conversationId, null) } }
            }
        }
    }
}

@Composable
private fun SectionTitle(title: String, icon: ImageVector, tint: Color) {
    Row(Modifier.padding(top = 18.dp, bottom = 8.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(28.dp).clip(CircleShape).background(tint.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) { Icon(icon, null, tint = tint, modifier = Modifier.size(16.dp)) }
        Spacer(Modifier.width(8.dp))
        Text(title, color = Ch.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
    }
}

@Composable
private fun RefSection(title: String, icon: ImageVector, tint: Color, refs: List<MsgRefDto>, open: (String?, String?) -> Unit) {
    if (refs.isEmpty()) return
    SectionTitle("$title (${refs.size})", icon, tint)
    refs.forEach { r -> RefCard(listOfNotNull(r.chat, r.sender.takeIf { it != r.chat }).joinToString(" · "), r.note?.let { "📝 $it · ${r.excerpt.orEmpty()}" } ?: r.excerpt.orEmpty(), r.createdAt) { open(r.conversationId, r.messageId) } }
}

@Composable
private fun RefCard(top: String, text: String, at: String?, onClick: () -> Unit) {
    Column(Modifier.fillMaxWidth().padding(bottom = 6.dp).clip(RoundedCornerShape(16.dp)).background(Ch.Surface).clickable(onClick = onClick).padding(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(top, color = Ch.Red, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
            at?.let { Text(listTime(it), color = Ch.Soft, fontSize = 11.sp) }
        }
        Text(text, color = Ch.Ink, fontSize = 14.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
    }
}

// =============================================================================== 66 money in a chat

/** My wallet balance and the money in this chat: transfers, requests, splits, and what's owed. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChatMoneySheet(conversationId: String, onDismiss: () -> Unit, onJump: (String) -> Unit) {
    var data by remember { mutableStateOf<ChatMoneyDto?>(null) }
    var balance by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(conversationId) {
        data = runCatching { ApiClient.more.money(chatAuth(), conversationId).data }.getOrNull() ?: ChatMoneyDto()
        balance = runCatching { ApiClient.wallet.balance(chatAuth()) }.getOrNull()?.let { env ->
            val w = env.data ?: return@let null
            formatMinor(w.totalMinor, w.currencyCode)
        }
    }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 24.dp)) {
            Text(stringResource(R.string.cp_money), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(10.dp))
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(Ch.HeaderBrush).padding(16.dp)) {
                Text(stringResource(R.string.cp_my_balance), color = Color.White.copy(alpha = 0.8f), fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                Text(balance ?: "—", color = Color.White, fontSize = 24.sp, fontWeight = FontWeight.ExtraBold)
            }
            val d = data
            if (d == null) {
                Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ch.Red) }
                return@Column
            }
            val cur = d.totals.currency
            Row(Modifier.padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Total(stringResource(R.string.cp_sent), formatMinor(d.totals.sentMinor, cur), Ch.Red, Modifier.weight(1f))
                Total(stringResource(R.string.cp_received), formatMinor(d.totals.receivedMinor, cur), Color(0xFF16A34A), Modifier.weight(1f))
            }
            if (d.totals.iOweMinor > 0 || d.totals.owedToMeMinor > 0) Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Total(stringResource(R.string.cp_i_owe), formatMinor(d.totals.iOweMinor, cur), Color(0xFFD97706), Modifier.weight(1f))
                Total(stringResource(R.string.cp_owed_to_me), formatMinor(d.totals.owedToMeMinor, cur), Color(0xFF2563EB), Modifier.weight(1f))
            }
            Text(stringResource(R.string.cp_history), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 14.dp, bottom = 6.dp))
            if (d.items.isEmpty()) Text(stringResource(R.string.cp_no_money), color = Ch.Soft, fontSize = 13.5.sp)
            d.items.forEach { m ->
                Row(Modifier.fillMaxWidth().padding(bottom = 6.dp).clip(RoundedCornerShape(14.dp)).background(Ch.SurfaceMuted).clickable { onDismiss(); onJump(m.messageId) }.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.Payments, null, tint = if (m.isMine) Ch.Red else Color(0xFF16A34A), modifier = Modifier.size(20.dp))
                    Spacer(Modifier.width(10.dp))
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(when (m.type) { "money_request" -> R.string.cp_t_request; "bill_split" -> R.string.cp_t_split; else -> if (m.isMine) R.string.cp_t_sent else R.string.cp_t_received }), color = Ch.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold)
                        Text(listOfNotNull(m.note, m.status, listTime(m.createdAt)).joinToString(" · "), color = Ch.Mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                    Text(formatMinor(m.amountMinor, m.currency), color = Ch.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                }
            }
        }
    }
}

@Composable
private fun Total(label: String, value: String, tint: Color, modifier: Modifier = Modifier) {
    Column(modifier.clip(RoundedCornerShape(16.dp)).background(tint.copy(alpha = 0.1f)).padding(12.dp)) {
        Text(label, color = tint, fontSize = 12.sp, fontWeight = FontWeight.Bold)
        Text(value, color = Ch.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
    }
}

// =============================================================================== 153 the decision room

/** The question, its options and votes, everyone's arguments for / against, and the admin's decision. */
@Composable
fun DecisionRoomPage(id: String) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var room by remember { mutableStateOf<DecisionRoomDto?>(null) }
    var text by remember { mutableStateOf("") }
    var stance by remember { mutableStateOf("pro") }
    var option by remember { mutableStateOf<Int?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(id) { room = runCatching { ApiClient.more.decisionRoom(chatAuth(), id).data }.getOrNull() }

    ChPage(stringResource(R.string.cp_room), onBack = { host.pop() }) {
        val r = room
        if (r == null) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ch.Red) }
            return@ChPage
        }
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).imePadding().padding(16.dp)) {
            Text(r.title, color = Ch.Ink, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold)
            r.description?.let { Text(it, color = Ch.Mut, fontSize = 14.sp, modifier = Modifier.padding(top = 4.dp)) }
            Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                val (tint, label) = when {
                    r.status == "approved" -> Color(0xFF16A34A) to stringResource(R.string.ch_decision_approved)
                    r.status == "rejected" -> Ch.Danger to stringResource(R.string.ch_decision_rejected)
                    r.isClosed -> Ch.Mut to stringResource(R.string.cp_room_closed)
                    else -> Color(0xFFF59E0B) to stringResource(R.string.ch_decision_open)
                }
                Text(label, color = tint, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.clip(CircleShape).background(tint.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp))
                r.deadlineAt?.let { Text(stringResource(R.string.cp_deadline, listTime(it)), color = Ch.Mut, fontSize = 12.sp, modifier = Modifier.align(Alignment.CenterVertically)) }
            }
            r.outcome?.let { Text("→ $it", color = Color(0xFF16A34A), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 8.dp)) }

            // Options with votes and their arguments.
            Spacer(Modifier.height(10.dp))
            val total = r.options.sumOf { it.votes }.coerceAtLeast(1)
            r.options.forEach { o ->
                Column(Modifier.fillMaxWidth().padding(bottom = 8.dp).clip(RoundedCornerShape(16.dp)).background(Ch.Surface).padding(12.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(o.text, color = Ch.Ink, fontSize = 15.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                        Text("${o.votes}", color = Ch.Red, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                    }
                    Box(Modifier.padding(top = 6.dp).fillMaxWidth().height(6.dp).clip(CircleShape).background(Ch.SurfaceMuted)) {
                        Box(Modifier.fillMaxWidth(o.votes.toFloat() / total).height(6.dp).clip(CircleShape).background(Ch.Red))
                    }
                    r.arguments.filter { it.optionId == o.id.toString() }.forEach { a -> ArgumentLine(a.stance, a.text, a.by?.name) }
                }
            }
            val general = r.arguments.filter { it.optionId == null }
            if (general.isNotEmpty()) {
                Text(stringResource(R.string.cp_room_general), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 6.dp))
                general.forEach { a -> ArgumentLine(a.stance, a.text, a.by?.name) }
            }

            // Add mine.
            if (!r.isClosed) {
                Text(stringResource(R.string.cp_room_add), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 16.dp, bottom = 6.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    listOf("pro" to R.string.cp_pro, "con" to R.string.cp_con, "note" to R.string.cp_note).forEach { (k, l) -> Pill(stringResource(l), stance == k, Modifier.weight(1f)) { stance = k } }
                }
                Row(Modifier.padding(top = 8.dp).horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    FolderChip(stringResource(R.string.cp_room_whole), option == null, null) { option = null }
                    r.options.forEach { o -> FolderChip(o.text, option == o.id, null) { option = o.id } }
                }
                Spacer(Modifier.height(8.dp))
                ChField(text, { text = it.take(500) }, stringResource(R.string.cp_room_hint), singleLine = false, maxLines = 4)
                error?.let { Text(it, color = Ch.Danger, fontSize = 12.5.sp) }
                Spacer(Modifier.height(8.dp))
                ChPrimaryButton(stringResource(R.string.cp_room_post), modifier = Modifier.fillMaxWidth(), enabled = text.isNotBlank()) {
                    scope.launch {
                        runCatching { ApiClient.more.argue(chatAuth(), id, JsonObject().apply { addProperty("stance", stance); addProperty("text", text.trim()); option?.let { addProperty("option_id", it.toString()) } }).data }
                            .onSuccess { room = it; text = "" }.onFailure { error = it.apiFailure().message }
                    }
                }
            }
            if (r.canDecide && r.status == "open") {
                Spacer(Modifier.height(14.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    ChPrimaryButton(stringResource(R.string.ch_decision_approve), modifier = Modifier.weight(1f)) {
                        scope.launch { runCatching { ApiClient.organize.decide(chatAuth(), id, JsonObject().apply { addProperty("approve", true) }) }; room = runCatching { ApiClient.more.decisionRoom(chatAuth(), id).data }.getOrNull() }
                    }
                    Text(
                        stringResource(R.string.ch_decision_reject), color = Ch.Danger, fontWeight = FontWeight.Bold, textAlign = androidx.compose.ui.text.style.TextAlign.Center,
                        modifier = Modifier.weight(1f).clip(RoundedCornerShape(16.dp)).background(Ch.Danger.copy(alpha = 0.1f)).clickable {
                            scope.launch { runCatching { ApiClient.organize.decide(chatAuth(), id, JsonObject().apply { addProperty("approve", false) }) }; room = runCatching { ApiClient.more.decisionRoom(chatAuth(), id).data }.getOrNull() }
                        }.padding(vertical = 14.dp),
                    )
                }
            }
        }
    }
}

@Composable
private fun ArgumentLine(stance: String, text: String, by: String?) {
    val (icon, tint) = when (stance) {
        "pro" -> Icons.Rounded.ThumbUp to Color(0xFF16A34A)
        "con" -> Icons.Rounded.ThumbDown to Ch.Danger
        else -> Icons.Rounded.Notes to Ch.Mut
    }
    Row(Modifier.padding(top = 6.dp), verticalAlignment = Alignment.Top) {
        Icon(icon, null, tint = tint, modifier = Modifier.size(16.dp).padding(top = 2.dp))
        Spacer(Modifier.width(6.dp))
        Column {
            by?.let { Text(it, color = tint, fontSize = 11.5.sp, fontWeight = FontWeight.Bold) }
            Text(text, color = Ch.Ink, fontSize = 13.5.sp)
        }
    }
}

// =============================================================================== 127 the privacy center

@Composable
private fun audience(value: Any?): String = stringResource(
    when (value?.toString()) { "nobody" -> R.string.cp_aud_nobody; "contacts" -> R.string.cp_aud_contacts; else -> R.string.cp_aud_everyone },
)

/** Every privacy choice in one place, each a tap away from where it's changed. */
@Composable
fun PrivacyCenterPage() {
    val host = LocalChat.current
    var data by remember { mutableStateOf<PrivacyCenterDto?>(null) }
    var username by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { data = runCatching { ApiClient.more.privacyCenter(chatAuth()).data }.getOrNull() }

    ChPage(stringResource(R.string.cp_privacy_center), onBack = { host.pop() }) {
        val d = data
        if (d == null) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ch.Red) }
            return@ChPage
        }
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
            CenterCard(Icons.Rounded.Visibility, stringResource(R.string.cp_pc_who_sees), listOf(
                stringResource(R.string.cp_pc_last_seen) to audience(d.whoSees["last_seen"]),
                stringResource(R.string.cp_pc_photo) to audience(d.whoSees["profile_photo"]),
                stringResource(R.string.cp_pc_status) to audience(d.whoSees["status"]),
                stringResource(R.string.cp_pc_receipts) to stringResource(if (d.whoSees["read_receipts"] == false) R.string.ch_off else R.string.cp_on),
            )) { host.push(ChRoute.Privacy) }
            CenterCard(Icons.Rounded.Shield, stringResource(R.string.cp_pc_who_can), listOf(
                stringResource(R.string.cp_pc_message) to audience(d.whoCan["message"]),
                stringResource(R.string.cp_pc_groups) to audience(d.whoCan["add_to_groups"]),
                stringResource(R.string.cp_pc_call) to audience(d.whoCan["call"]),
                stringResource(R.string.cp_pc_urgent) to audience(d.whoCan["urgent"]),
            )) { host.push(ChRoute.Privacy) }
            CenterCard(Icons.Rounded.NotificationsActive, stringResource(R.string.cp_pc_notifications), listOf(
                stringResource(R.string.cp_pc_preview) to d.notifications,
                stringResource(R.string.cp_pc_privacy_mode) to stringResource(if (d.privacyMode["on"] == true) R.string.cp_on else if (d.privacyMode["scheduled"] == true) R.string.cp_scheduled else R.string.ch_off),
                stringResource(R.string.cp_pc_quiet) to stringResource(if (d.quiet["on"] == true) R.string.cp_on else if (d.quiet["scheduled"] == true) R.string.cp_scheduled else R.string.ch_off),
                stringResource(R.string.cp_pc_screenshots) to stringResource(if (d.blockScreenshots) R.string.cp_blocked_word else R.string.cp_allowed),
            )) { host.push(ChRoute.Privacy) }
            CenterCard(Icons.Rounded.Lock, stringResource(R.string.cp_pc_chats), listOf(
                stringResource(R.string.cp_pc_circles) to "${d.circles["count"] ?: 0} (${stringResource(R.string.cp_pc_locked_n, d.circles["locked"] ?: 0)})",
                stringResource(R.string.cp_pc_locked_chats) to "${d.lockedChats}",
                stringResource(R.string.cp_pc_pin_chats) to "${d.pinLockedChats}",
                stringResource(R.string.cp_pc_blocked) to "${d.blocked}",
            )) { host.filter = "locked"; host.pop() }
            CenterCard(Icons.Rounded.AlternateEmail, stringResource(R.string.cp_username), listOf(
                stringResource(R.string.cp_username) to (d.username?.let { "@$it" } ?: stringResource(R.string.cp_none)),
            )) { username = true }
            CenterCard(Icons.Rounded.Psychology, stringResource(R.string.cp_pc_ai), listOf(
                stringResource(R.string.cp_pc_ai_on) to stringResource(if (d.ai["enabled"] == true) R.string.cp_on else R.string.ch_off),
            ), note = d.ai["reads"]?.toString()) {}
            Text(stringResource(R.string.cp_pc_devices), color = Ch.Soft, fontSize = 12.sp, modifier = Modifier.padding(top = 8.dp))
        }
    }
    if (username) UsernameSheet(onDismiss = { username = false }) { u -> data = data?.copy(username = u) }
}

@Composable
private fun CenterCard(icon: ImageVector, title: String, rows: List<Pair<String, String>>, note: String? = null, onClick: () -> Unit) {
    Column(Modifier.fillMaxWidth().padding(bottom = 10.dp).clip(RoundedCornerShape(20.dp)).background(Ch.Surface).clickable(onClick = onClick).padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(34.dp).clip(RoundedCornerShape(11.dp)).background(Ch.Red.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) { Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(19.dp)) }
            Spacer(Modifier.width(10.dp))
            Text(title, color = Ch.Ink, fontSize = 15.5.sp, fontWeight = FontWeight.ExtraBold)
        }
        Spacer(Modifier.height(6.dp))
        rows.forEach { (k, v) ->
            Row(Modifier.fillMaxWidth().padding(vertical = 3.dp)) {
                Text(k, color = Ch.Mut, fontSize = 13.5.sp, modifier = Modifier.weight(1f))
                Text(v, color = Ch.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold)
            }
        }
        note?.let { Text(it, color = Ch.Soft, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp)) }
    }
}
