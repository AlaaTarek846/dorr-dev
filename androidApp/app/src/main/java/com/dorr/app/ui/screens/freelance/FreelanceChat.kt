package com.dorr.app.ui.screens.freelance

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
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
import com.dorr.app.R
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelanceChatMessageDto
import com.dorr.app.network.FreelanceChatRoomDto
import com.dorr.app.network.FreelanceChatSend
import com.dorr.app.network.FreelanceInterviewDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.UUID

private val ChatRed = Color(0xFFDC2626)

/**
 * The talk with a freelancer about their offer (FL-308), on the chat of FL-006. Until the agreement the server refuses phone numbers,
 * emails and links and says so. New messages arrive on the room's private channel (one reload each) — nothing is asked on a timer;
 * the page also reads once when it opens and when the connection comes back.
 */
@Composable
internal fun ChatPage(roomUuid: String, onBack: () -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var room by remember { mutableStateOf<FreelanceChatRoomDto?>(null) }
    // Newest first, as the server gives them (the list is drawn from the bottom).
    val messages = remember { mutableStateListOf<FreelanceChatMessageDto>() }
    var hasMore by remember { mutableStateOf(false) }
    var input by remember { mutableStateOf("") }
    var sending by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var loaded by remember { mutableStateOf(false) }
    var interviews by remember { mutableStateOf<List<FreelanceInterviewDto>>(emptyList()) }

    suspend fun load() {
        try {
            room = ApiClient.freelance.chatRoom(auth(), roomUuid).data
            runCatching { ApiClient.freelance.interviews(auth(), roomUuid).data }.getOrNull()?.let { interviews = it }
            val list = ApiClient.freelance.chatMessages(auth(), roomUuid).data.orEmpty()
            messages.clear()
            messages.addAll(list)
            hasMore = list.size >= 40
            error = null
            if (list.any { it.mine == false && it.readAt == null }) runCatching { ApiClient.freelance.chatRead(auth(), roomUuid) }
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            if (!loaded) error = e.message(generic)
        }
        loaded = true
    }
    LaunchedEffect(roomUuid, reconnect) { load() }
    // The room's own channel while the page is open.
    DisposableEffect(roomUuid) {
        ChatRealtime.watchFreelanceRoom(roomUuid)
        onDispose { ChatRealtime.unwatchFreelanceRoom(roomUuid) }
    }
    LaunchedEffect(roomUuid) {
        ChatRealtime.events.collect { if (it.name == "freelance.message") load() }
    }

    fun send() {
        val text = input.trim()
        if (text.isEmpty() || sending) return
        sending = true
        error = null
        scope.launch {
            try {
                val sent = ApiClient.freelance.chatSend(auth(), roomUuid, FreelanceChatSend(text, UUID.randomUUID().toString())).data
                if (sent != null && messages.none { it.id == sent.id }) messages.add(0, sent)
                input = ""
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.message(generic)
            }
            sending = false
        }
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.matchParentSize())
        Column(Modifier.fillMaxSize().navigationBarsPadding().imePadding()) {
            SubHeader(room?.peer?.name ?: stringResource(R.string.fl_chat_title), onBack)
            room?.project?.title?.let { Text(it, color = settingsMut(), fontSize = 12.sp, modifier = Modifier.padding(horizontal = 18.dp)) }
            if (room?.guarded == true) Text(stringResource(R.string.fl_chat_guard_hint), color = settingsMut(), fontSize = 11.sp, modifier = Modifier.padding(horizontal = 18.dp, vertical = 4.dp))
            Box(Modifier.weight(1f).fillMaxWidth()) {
                if (!loaded && error == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.align(Alignment.Center).size(26.dp))
                else LazyColumn(Modifier.fillMaxSize(), reverseLayout = true, contentPadding = PaddingValues(horizontal = 14.dp, vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    items(messages, key = { it.id }) { m -> ChatBubble(m) }
                    if (hasMore) item {
                        Text(
                            stringResource(R.string.fl_chat_earlier), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.fillMaxWidth().clickable {
                                scope.launch {
                                    val before = messages.lastOrNull()?.id ?: return@launch
                                    runCatching { ApiClient.freelance.chatMessages(auth(), roomUuid, before = before).data.orEmpty() }.getOrNull()?.let { more ->
                                        messages.addAll(more.filter { n -> messages.none { it.id == n.id } })
                                        hasMore = more.size >= 40
                                    }
                                }
                            }.padding(12.dp),
                        )
                    }
                    if (loaded && messages.isEmpty()) item { Text(stringResource(R.string.fl_chat_empty), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(24.dp)) }
                }
            }
            error?.let { Text(it, color = ChatRed, fontSize = 12.sp, modifier = Modifier.padding(horizontal = 18.dp, vertical = 2.dp)) }
            if (room != null) InterviewPanel(roomUuid, room?.canWrite == true, interviews, onChanged = { scope.launch { load() } }, onError = { error = it })
            if (room?.canWrite == true) Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                DorrTextField(value = input, onValueChange = { if (it.length <= 2000) input = it }, modifier = Modifier.weight(1f), placeholder = stringResource(R.string.fl_chat_hint), singleLine = false)
                Box(
                    Modifier.size(44.dp).clip(CircleShape).background(settingsAccent().copy(alpha = if (input.isBlank() || sending) 0.4f else 1f)).clickable(enabled = input.isNotBlank() && !sending, onClick = ::send),
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.AutoMirrored.Rounded.Send, stringResource(R.string.fl_chat_send), tint = Color.White, modifier = Modifier.size(20.dp)) }
            } else if (room != null) Text(stringResource(R.string.fl_chat_closed), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.fillMaxWidth().padding(16.dp))
        }
    }
}

@Composable
private fun ChatBubble(m: FreelanceChatMessageDto) {
    if (m.kind == "system") {
        InterviewSystemLine(m.body.orEmpty())
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
                m.kind == "deleted" -> stringResource(R.string.fl_chat_deleted)
                m.kind != "text" -> stringResource(R.string.fl_chat_file)
                else -> m.body.orEmpty()
            }
            Text(text, color = if (mine) Color.White else settingsInk(), fontSize = 14.sp, lineHeight = 20.sp)
            chatTime(m.createdAt)?.let { Text("‎$it", color = if (mine) Color.White.copy(alpha = 0.8f) else settingsMut(), fontSize = 10.sp) }
        }
    }
}

private fun chatTime(iso: String?): String? = runCatching {
    OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("HH:mm"))
}.getOrNull()
