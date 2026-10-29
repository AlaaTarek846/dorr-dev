package com.dorr.freelance.ui.screens

import com.dorr.freelance.ui.components.SkeletonList
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.ui.components.MessageToast
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.ChatMessageDto
import com.dorr.freelance.net.ChatRoomDto
import com.dorr.freelance.net.ChatSendRequest
import com.dorr.freelance.net.InterviewDto
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.AppShell
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.ShellState
import com.dorr.freelance.ui.kit.PinkBackdrop
import com.dorr.freelance.ui.kit.SubHeader
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsSurface
import kotlinx.coroutines.launch
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.UUID

private val Red = Color(0xFFDC2626)

/**
 * The talk with a client about one of my offers (FL-308). The client opens the room; here I read and answer. Until the agreement the
 * server refuses phone numbers, emails and links, and says so. The page reads once when it opens, once when a notice arrives and
 * once when the connection comes back — never on a timer.
 */
@Composable
fun ChatScreen(conversationUuid: String, onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var room by remember { mutableStateOf<ChatRoomDto?>(null) }
    // Newest first, as the server gives them (the list is drawn from the bottom).
    val messages = remember { mutableStateListOf<ChatMessageDto>() }
    var hasMore by remember { mutableStateOf(false) }
    var loadingOlder by remember { mutableStateOf(false) }
    var olderFailed by remember { mutableStateOf(false) }
    val listState = rememberLazyListState()
    var input by remember { mutableStateOf("") }
    var sending by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var loaded by remember { mutableStateOf(false) }
    var interviews by remember { mutableStateOf<List<InterviewDto>>(emptyList()) }
    // FL-709: the message being reported (long press on the other person's).
    var reporting by remember { mutableStateOf<Int?>(null) }
    var reportNotice by remember { mutableStateOf<String?>(null) }

    // The page is a chat: it takes the whole screen (no tab bar over the keyboard).
    DisposableEffect(Unit) {
        ShellState.immersive = true
        onDispose { ShellState.immersive = false }
    }

    suspend fun load() {
        (apiCall { ApiClient.chat.room(conversationUuid) } as? ApiResult.Ok)?.data?.let { room = it }
        (apiCall { ApiClient.chat.interviews(conversationUuid) } as? ApiResult.Ok)?.data?.let { interviews = it }
        val result = apiCall { ApiClient.chat.messages(conversationUuid) }
        if (result is ApiResult.Ok) {
            // The newest page replaces what is shown; the older messages I scrolled up to stay under it.
            val oldest = result.data.lastOrNull()?.id
            val older = if (oldest == null) emptyList() else messages.filter { it.id < oldest }
            messages.clear()
            messages.addAll(result.data)
            messages.addAll(older)
            if (older.isEmpty()) hasMore = result.data.size >= 40
            error = null
            if (result.data.any { it.mine == false && it.readAt == null }) apiCall { ApiClient.chat.read(conversationUuid) }
        } else if (!loaded) {
            error = result.errorText(offlineText) ?: genericError
        }
        loaded = true
    }
    LaunchedEffect(conversationUuid, reconnect) { load() }
    LaunchedEffect(conversationUuid) { Realtime.changed.collect { load() } }

    // Older messages come by themselves when I scroll up to the oldest one loaded (nothing on a timer).
    suspend fun loadOlder() {
        if (loadingOlder || !hasMore) return
        val before = messages.lastOrNull()?.id ?: return
        loadingOlder = true
        olderFailed = false
        val more = apiCall { ApiClient.chat.messages(conversationUuid, before = before) }
        if (more is ApiResult.Ok) {
            messages.addAll(more.data.filter { n -> messages.none { it.id == n.id } })
            hasMore = more.data.size >= 40
        } else olderFailed = true
        loadingOlder = false
    }
    val nearOldest by remember {
        derivedStateOf {
            val info = listState.layoutInfo
            val last = info.visibleItemsInfo.lastOrNull()?.index ?: -1
            info.totalItemsCount > 0 && last >= info.totalItemsCount - 4
        }
    }
    LaunchedEffect(nearOldest, messages.size, hasMore, olderFailed, loaded) {
        if (loaded && nearOldest && hasMore && !loadingOlder && !olderFailed) loadOlder()
    }

    fun send() {
        val text = input.trim()
        if (text.isEmpty() || sending) return
        sending = true
        error = null
        scope.launch {
            val result = apiCall { ApiClient.chat.send(conversationUuid, ChatSendRequest(text, UUID.randomUUID().toString())) }
            if (result is ApiResult.Ok) {
                if (messages.none { it.id == result.data.id }) messages.add(0, result.data)
                input = ""
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
            sending = false
        }
    }

    reporting?.let { id -> ReportMessageDialog(id, onDone = { reportNotice = it; reporting = null }, onDismiss = { reporting = null }) }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding().imePadding()) {
            SubHeader(room?.peer?.name ?: stringResource(R.string.chat_title), onBack)
            room?.project?.title?.let { Text(it, color = settingsMut(), fontSize = 12.sp, modifier = Modifier.padding(horizontal = 18.dp)) }
            if (room?.guarded == true) Text(stringResource(R.string.chat_guard_hint), color = settingsMut(), fontSize = 11.sp, modifier = Modifier.padding(horizontal = 18.dp, vertical = 4.dp))
            Box(Modifier.weight(1f).fillMaxWidth()) {
                if (!loaded && error == null) SkeletonList(count = 3, modifier = Modifier.padding(14.dp))
                else LazyColumn(
                    Modifier.fillMaxSize(), state = listState, reverseLayout = true,
                    contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 14.dp, vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    items(messages, key = { it.id }) { m -> Bubble(m) { reporting = m.id } }
                    if (hasMore) item {
                        if (olderFailed) Text(
                            stringResource(R.string.common_retry), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.fillMaxWidth().clickable { olderFailed = false }.padding(12.dp),
                        ) else Box(Modifier.fillMaxWidth().padding(12.dp), contentAlignment = Alignment.Center) {
                            CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(20.dp))
                        }
                    }
                    if (loaded && messages.isEmpty()) item { Text(stringResource(R.string.chat_empty), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(24.dp)) }
                }
            }
            error?.let { Text(it, color = Red, fontSize = 12.sp, modifier = Modifier.padding(horizontal = 18.dp, vertical = 2.dp)) }
            MessageToast(reportNotice)
            if (room != null) InterviewPanel(conversationUuid, room?.canWrite == true, interviews, onChanged = { scope.launch { load() } }, onError = { error = it })
            if (room?.canWrite == true) Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                DorrTextField(value = input, onValueChange = { if (it.length <= 2000) input = it }, modifier = Modifier.weight(1f), placeholder = stringResource(R.string.chat_hint), singleLine = false)
                Box(
                    Modifier.size(44.dp).clip(CircleShape).background(settingsAccent().copy(alpha = if (input.isBlank() || sending) 0.4f else 1f)).clickable(enabled = input.isNotBlank() && !sending, onClick = ::send),
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.AutoMirrored.Rounded.Send, stringResource(R.string.chat_send), tint = Color.White, modifier = Modifier.size(20.dp)) }
            } else if (room != null) Text(stringResource(R.string.chat_closed), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.fillMaxWidth().padding(16.dp))
        }
    }
}

@Composable
private fun Bubble(m: ChatMessageDto, onReport: () -> Unit) {
    if (m.kind == "system") {
        SystemLine(m.body.orEmpty())
        return
    }
    val mine = m.mine == true
    Row(Modifier.fillMaxWidth(), horizontalArrangement = if (mine) Arrangement.End else Arrangement.Start) {
        Column(
            Modifier.widthIn(max = 280.dp).clip(RoundedCornerShape(16.dp))
                .then(if (mine) Modifier.background(settingsAccent()) else Modifier.settingsSurface(RoundedCornerShape(16.dp)))
                .padding(horizontal = 12.dp, vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(2.dp),
        ) {
            val text = when {
                m.kind == "deleted" -> stringResource(R.string.chat_deleted)
                m.kind != "text" -> stringResource(R.string.chat_file)
                else -> m.body.orEmpty()
            }
            Text(text, color = if (mine) Color.White else settingsInk(), fontSize = 14.sp, lineHeight = 20.sp)
            timeOf(m.createdAt)?.let { Text("‎$it", color = if (mine) Color.White.copy(alpha = 0.8f) else settingsMut(), fontSize = 10.sp) }
        }
        // FL-709: the three dots beside the other person's message open a small menu (report).
        if (!mine && m.kind != "deleted") {
            var menuOpen by androidx.compose.runtime.remember { androidx.compose.runtime.mutableStateOf(false) }
            Box {
                androidx.compose.material3.Icon(
                    androidx.compose.material.icons.Icons.Default.MoreVert, contentDescription = null, tint = settingsMut(),
                    modifier = Modifier.size(32.dp).clip(androidx.compose.foundation.shape.CircleShape).clickable { menuOpen = true }.padding(6.dp),
                )
                androidx.compose.material3.DropdownMenu(expanded = menuOpen, onDismissRequest = { menuOpen = false }) {
                    androidx.compose.material3.DropdownMenuItem(text = { Text(stringResource(R.string.rm_title)) }, onClick = { menuOpen = false; onReport() })
                }
            }
        }
    }
}

private fun timeOf(iso: String?): String? = runCatching {
    OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("HH:mm"))
}.getOrNull()
