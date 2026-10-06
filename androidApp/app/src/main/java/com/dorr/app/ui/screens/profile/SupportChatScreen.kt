package com.dorr.app.ui.screens.profile

import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
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
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.SupportAgent
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.SendSupportMessageRequest
import com.dorr.app.network.SupportMessageDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.network.serverMessage
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.AccountDark
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

@Composable
fun SupportChatScreen(
    onBack: () -> Unit,
    ticketId: Int? = null,
    ticketTitle: String? = null,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var messages by remember { mutableStateOf<List<SupportMessageDto>>(emptyList()) }
    var draft by remember { mutableStateOf("") }
    var sending by remember { mutableStateOf(false) }
    val listState = rememberLazyListState()
    val reconnectTick = collectReconnectTick()
    val failed = stringResource(R.string.support_chat_failed)
    val header = if (ticketId != null) {
        stringResource(R.string.support_chat_ticket_title, ticketId)
    } else {
        stringResource(R.string.support_chat_title)
    }

    suspend fun load() {
        runCatching {
            ApiClient.support.listMessages(
                "Bearer ${AuthSession.token.orEmpty()}",
                ticketId = ticketId,
            )
        }.onSuccess { envelope ->
            messages = envelope.data.orEmpty()
        }
    }

    fun send() {
        val text = draft.trim()
        if (text.isEmpty() || sending) return
        sending = true
        scope.launch {
            try {
                val created = ApiClient.support.sendMessage(
                    "Bearer ${AuthSession.token.orEmpty()}",
                    SendSupportMessageRequest(body = text, ticketId = ticketId),
                ).data
                draft = ""
                if (created != null) {
                    messages = messages.filter { it.id != created.id } + created
                } else {
                    load()
                }
            } catch (e: Exception) {
                Toast.makeText(context, e.serverMessage() ?: failed, Toast.LENGTH_LONG).show()
            } finally {
                sending = false
            }
        }
    }

    LaunchedEffect(reconnectTick, ticketId) { load() }
    LaunchedEffect(ticketId) {
        while (true) {
            delay(8000)
            load()
        }
    }
    LaunchedEffect(messages.size) {
        if (messages.isNotEmpty()) {
            listState.animateScrollToItem(messages.lastIndex)
        }
    }

    Box(Modifier.fillMaxSize().imePadding()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(header, onBack)
            if (!ticketTitle.isNullOrBlank()) {
                Text(
                    ticketTitle,
                    color = settingsMut(),
                    fontSize = 13.sp,
                    modifier = Modifier.padding(horizontal = 14.dp).padding(bottom = 6.dp),
                )
            }
            if (messages.isEmpty()) {
                Column(
                    modifier = Modifier
                        .weight(1f)
                        .fillMaxWidth()
                        .padding(horizontal = 28.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.Center,
                ) {
                    PinkIcon(Icons.Rounded.SupportAgent, 56.dp)
                    Spacer(Modifier.height(14.dp))
                    Text(
                        stringResource(R.string.support_chat_empty),
                        color = settingsMut(),
                        fontSize = 14.sp,
                        lineHeight = 20.sp,
                    )
                }
            } else {
                LazyColumn(
                    state = listState,
                    modifier = Modifier.weight(1f).fillMaxWidth(),
                    contentPadding = PaddingValues(horizontal = 14.dp, vertical = 8.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    items(messages, key = { it.id }) { message ->
                        SupportChatBubble(message)
                    }
                }
            }
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 14.dp)
                    .padding(top = 8.dp, bottom = 14.dp),
                verticalAlignment = Alignment.Bottom,
            ) {
                DorrTextField(
                    value = draft,
                    onValueChange = { if (it.length <= 4000) draft = it },
                    placeholder = stringResource(R.string.support_chat_hint),
                    modifier = Modifier.weight(1f),
                    enabled = !sending,
                    keyboardOptions = KeyboardOptions(imeAction = ImeAction.Send),
                )
                Spacer(Modifier.width(8.dp))
                Box(
                    modifier = Modifier
                        .size(46.dp)
                        .clip(CircleShape)
                        .background(settingsAccent().copy(alpha = if (sending || draft.isBlank()) 0.45f else 1f))
                        .clickable(enabled = !sending && draft.isNotBlank()) { send() },
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(
                        Icons.AutoMirrored.Rounded.Send,
                        contentDescription = stringResource(R.string.support_chat_hint),
                        tint = androidx.compose.ui.graphics.Color.White,
                        modifier = Modifier.size(20.dp),
                    )
                }
            }
        }
    }
}

@Composable
private fun SupportChatBubble(message: SupportMessageDto) {
    val mine = message.sender != "support"
    val shape = RoundedCornerShape(
        topStart = 16.dp,
        topEnd = 16.dp,
        bottomStart = if (mine) 16.dp else 4.dp,
        bottomEnd = if (mine) 4.dp else 16.dp,
    )
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = if (mine) Arrangement.End else Arrangement.Start,
    ) {
        Column(
            modifier = Modifier
                .widthIn(max = 280.dp)
                .clip(shape)
                .background(
                    if (mine) settingsAccent()
                    else if (settingsNight()) AccountDark.card
                    else settingsCard(),
                )
                .padding(horizontal = 12.dp, vertical = 8.dp),
        ) {
            Text(
                message.body.orEmpty(),
                color = if (mine) androidx.compose.ui.graphics.Color.White else settingsInk(),
                fontSize = 14.sp,
                lineHeight = 20.sp,
            )
        }
    }
}
