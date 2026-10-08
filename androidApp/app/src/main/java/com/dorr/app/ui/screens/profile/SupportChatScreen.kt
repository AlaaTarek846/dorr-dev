package com.dorr.app.ui.screens.profile

import android.content.Intent
import android.net.Uri
import android.widget.Toast
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.slideInVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
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
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.AddPhotoAlternate
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.LockOpen
import androidx.compose.material.icons.rounded.SupportAgent
import androidx.compose.material3.CircularProgressIndicator
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.SupportAutoReplyFeedbackRequest
import com.dorr.app.network.SupportMessageDto
import com.dorr.app.network.SupportStatusRequest
import com.dorr.app.network.SupportTicketDto
import com.dorr.app.network.apiFailure
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.screens.ConfirmDialog
import com.google.gson.Gson
import java.time.Instant
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.toRequestBody

/** True while a support conversation is on screen: the main bottom bar steps aside so the chat gets the whole screen. */
object SupportChatState {
    var open by androidx.compose.runtime.mutableStateOf(false)
}

private sealed interface ConversationRow {
    data class Day(val label: String) : ConversationRow
    data class Msg(val message: SupportMessageDto) : ConversationRow
}

/**
 * One support ticket as a live conversation: the customer's messages on one side, the support
 * team's on the other, the status (opened, resolved, closed, reopened) at the top, and the actions
 * that fit it: close it, or reopen it when it is finished. New messages and status changes made by
 * support arrive on their own (Pusher), no refreshing.
 */
@Composable
fun SupportChatScreen(
    initial: SupportTicketDto,
    onBack: () -> Unit,
    onTicketChanged: (SupportTicketDto) -> Unit = {},
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val gson = remember { Gson() }
    val accent = settingsAccent()
    var ticket by remember { mutableStateOf(initial) }
    var messages by remember { mutableStateOf<List<SupportMessageDto>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var nextPage by remember { mutableStateOf(2) }
    var hasEarlier by remember { mutableStateOf(false) }
    var loadingEarlier by remember { mutableStateOf(false) }
    var draft by remember { mutableStateOf("") }
    var imageUri by remember { mutableStateOf<Uri?>(null) }
    var sending by remember { mutableStateOf(false) }
    var statusBusy by remember { mutableStateOf(false) }
    var confirmClose by remember { mutableStateOf(false) }
    val listState = rememberLazyListState()
    androidx.compose.runtime.DisposableEffect(Unit) {
        SupportChatState.open = true
        onDispose { SupportChatState.open = false }
    }
    val reconnectTick = collectReconnectTick()
    val failed = stringResource(R.string.support_chat_failed)
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri -> if (uri != null) imageUri = uri }

    fun update(fresh: SupportTicketDto) {
        ticket = fresh
        onTicketChanged(fresh)
    }

    fun add(message: SupportMessageDto) {
        if (messages.none { it.id == message.id }) messages = messages + message
    }

    suspend fun load() {
        runCatching { ApiClient.support.messages("Bearer ${AuthSession.token.orEmpty()}", ticket.id, page = 1) }
            .onSuccess { envelope ->
                messages = envelope.data.orEmpty().reversed()
                hasEarlier = envelope.pagination?.hasMorePages == true
                nextPage = 2
            }
        loading = false
    }

    fun loadEarlier() {
        if (loadingEarlier) return
        loadingEarlier = true
        scope.launch {
            runCatching { ApiClient.support.messages("Bearer ${AuthSession.token.orEmpty()}", ticket.id, page = nextPage) }
                .onSuccess { envelope ->
                    val older = envelope.data.orEmpty().reversed()
                    messages = older.filter { o -> messages.none { it.id == o.id } } + messages
                    hasEarlier = envelope.pagination?.hasMorePages == true
                    nextPage += 1
                }
            loadingEarlier = false
        }
    }

    fun send() {
        val text = draft.trim()
        if ((text.isEmpty() && imageUri == null) || sending || !ticket.acceptsReplies) return
        sending = true
        scope.launch {
            try {
                val part = imageUri?.let { cacheImagePart(context, it, "support-message") }
                val created = ApiClient.support.sendMessage(
                    "Bearer ${AuthSession.token.orEmpty()}",
                    ticket.id,
                    text.takeIf { it.isNotEmpty() }?.toRequestBody("text/plain".toMediaType()),
                    part,
                ).data
                draft = ""
                imageUri = null
                if (created != null) add(created) else load()
            } catch (e: Exception) {
                Toast.makeText(context, e.apiFailure().message ?: failed, Toast.LENGTH_LONG).show()
            } finally {
                sending = false
            }
        }
    }

    fun autoFeedback(solved: Boolean) {
        if (statusBusy) return
        statusBusy = true
        scope.launch {
            try {
                ApiClient.support.autoReplyFeedback("Bearer ${AuthSession.token.orEmpty()}", ticket.id, SupportAutoReplyFeedbackRequest(solved)).data?.let { update(it) }
            } catch (e: Exception) {
                Toast.makeText(context, e.apiFailure().message ?: failed, Toast.LENGTH_LONG).show()
            } finally {
                statusBusy = false
            }
        }
    }

    fun setStatus(status: String) {
        if (statusBusy) return
        statusBusy = true
        scope.launch {
            try {
                ApiClient.support.setStatus("Bearer ${AuthSession.token.orEmpty()}", ticket.id, SupportStatusRequest(status)).data?.let { update(it) }
            } catch (e: Exception) {
                Toast.makeText(context, e.apiFailure().message ?: failed, Toast.LENGTH_LONG).show()
            } finally {
                statusBusy = false
                confirmClose = false
            }
        }
    }

    LaunchedEffect(reconnectTick, ticket.id) { load() }

    // Live: support's replies and status changes land here the moment they happen.
    LaunchedEffect(ticket.id) {
        ChatRealtime.events.collect { event ->
            if (!event.name.startsWith("support.")) return@collect
            val fresh = runCatching { gson.fromJson(event.data.get("ticket"), SupportTicketDto::class.java) }.getOrNull()
            if (fresh == null || fresh.id != ticket.id) return@collect
            update(fresh)
            if (event.name == "support.message") {
                runCatching { gson.fromJson(event.data.get("message"), SupportMessageDto::class.java) }.getOrNull()?.let { add(it) }
            }
        }
    }

    val rows = remember(messages) { conversationRows(messages) }
    // The feedback buttons belong to the last message only when it is an automatic FAQ answer.
    val lastFaqId = messages.lastOrNull()?.takeIf { it.isAuto && it.autoKind == "faq" }?.id
    LaunchedEffect(rows.size) {
        if (rows.isNotEmpty()) listState.animateScrollToItem(rows.lastIndex)
        // Everything on screen counts as read.
        messages.lastOrNull()?.createdAt?.let { SupportSeen.mark(context, ticket.id, it) }
    }

    Box(Modifier.fillMaxSize().imePadding()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            TicketStatusBar(
                onBack = onBack,
                ticket = ticket,
                busy = statusBusy,
                onClose = { confirmClose = true },
                onReopen = { setStatus("reopened") },
            )
            when {
                loading -> Box(Modifier.weight(1f).fillMaxWidth(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(color = accent, strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
                }
                rows.isEmpty() -> Column(
                    Modifier.weight(1f).fillMaxWidth().padding(horizontal = 28.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.Center,
                ) {
                    PinkIcon(Icons.Rounded.SupportAgent, 56.dp)
                    Spacer(Modifier.height(14.dp))
                    Text(stringResource(R.string.support_conv_empty), color = settingsMut(), fontSize = 14.sp, lineHeight = 20.sp, textAlign = TextAlign.Center)
                }
                else -> LazyColumn(
                    state = listState,
                    modifier = Modifier.weight(1f).fillMaxWidth(),
                    contentPadding = PaddingValues(horizontal = 14.dp, vertical = 8.dp),
                    verticalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    if (hasEarlier) item("earlier") {
                        Box(Modifier.fillMaxWidth().padding(bottom = 6.dp), contentAlignment = Alignment.Center) {
                            if (loadingEarlier) CircularProgressIndicator(color = accent, strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
                            else Text(
                                stringResource(R.string.support_load_earlier),
                                color = accent, fontSize = 13.sp, fontWeight = FontWeight.Bold,
                                modifier = Modifier.clip(RoundedCornerShape(12.dp)).clickable { loadEarlier() }.padding(horizontal = 12.dp, vertical = 6.dp),
                            )
                        }
                    }
                    items(rows, key = { if (it is ConversationRow.Day) "d-${it.label}" else "m-${(it as ConversationRow.Msg).message.id}" }) { row ->
                        when (row) {
                            is ConversationRow.Day -> DayChip(row.label, Modifier.animateItem())
                            is ConversationRow.Msg -> {
                                SupportBubble(row.message, Modifier.animateItem())
                                // Under the newest FAQ answer: did it solve the problem?
                                if (row.message.id == lastFaqId && ticket.acceptsReplies && !ticket.autoReplyStopped) {
                                    AutoReplyFeedback(busy = statusBusy, onSolved = { autoFeedback(true) }, onNeedAgent = { autoFeedback(false) })
                                }
                            }
                        }
                    }
                }
            }
            Composer(
                ticket = ticket,
                draft = draft,
                onDraft = { if (it.length <= 4000) draft = it },
                imageUri = imageUri,
                onPick = { picker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) },
                onClearImage = { imageUri = null },
                sending = sending,
                reopenBusy = statusBusy,
                onSend = ::send,
                onReopen = { setStatus("reopened") },
            )
        }
    }

    if (confirmClose) {
        ConfirmDialog(
            icon = Icons.Rounded.Lock,
            title = stringResource(R.string.support_close_title),
            message = stringResource(R.string.support_close_message),
            loading = statusBusy,
            onConfirm = { setStatus("closed") },
            onDismiss = { if (!statusBusy) confirmClose = false },
        )
    }
}

/** The status under the header: a coloured chip, the ticket's title, and the one action that fits. */
@Composable
private fun TicketStatusBar(ticket: SupportTicketDto, busy: Boolean, onBack: () -> Unit, onClose: () -> Unit, onReopen: () -> Unit) {
    val accent = settingsAccent()
    val card = if (settingsNight()) AccountDark.card else settingsCard()
    Row(
        Modifier
            .fillMaxWidth()
            .padding(horizontal = 10.dp, vertical = 8.dp)
            .clip(RoundedCornerShape(20.dp))
            .background(card)
            .border(1.dp, settingsMut().copy(alpha = 0.15f), RoundedCornerShape(20.dp))
            .padding(horizontal = 6.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            Modifier.size(38.dp).clip(CircleShape).clickable(onClick = onBack),
            contentAlignment = Alignment.Center,
        ) { Icon(Icons.AutoMirrored.Rounded.ArrowBack, null, tint = settingsInk(), modifier = Modifier.size(22.dp)) }
        Box(
            Modifier.size(42.dp).clip(CircleShape).background(accent.copy(alpha = 0.14f)),
            contentAlignment = Alignment.Center,
        ) { Icon(Icons.Rounded.SupportAgent, null, tint = accent, modifier = Modifier.size(24.dp)) }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(
                ticket.title.orEmpty().ifBlank { stringResource(R.string.support_agent_default) },
                color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold,
                maxLines = 1, overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis,
            )
            Spacer(Modifier.height(4.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("#${ticket.id}", color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
                Spacer(Modifier.width(8.dp))
                SupportStatusChip(ticket.status)
            }
        }
        Spacer(Modifier.width(8.dp))
        if (busy) {
            CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(20.dp))
        } else if (ticket.acceptsReplies) {
            TextAction(stringResource(R.string.support_close), Icons.Rounded.Lock, onClose)
        } else {
            TextAction(stringResource(R.string.support_reopen), Icons.Rounded.LockOpen, onReopen)
        }
        Spacer(Modifier.width(4.dp))
    }
}

@Composable
private fun TextAction(label: String, icon: androidx.compose.ui.graphics.vector.ImageVector, onClick: () -> Unit) {
    Row(
        Modifier.clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.12f)).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 7.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(15.dp))
        Spacer(Modifier.width(6.dp))
        Text(label, color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
    }
}

@Composable
private fun DayChip(label: String, modifier: Modifier = Modifier) {
    Box(modifier.fillMaxWidth().padding(vertical = 6.dp), contentAlignment = Alignment.Center) {
        Text(
            label, color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold,
            modifier = Modifier.clip(RoundedCornerShape(10.dp)).background(settingsMut().copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp),
        )
    }
}

@Composable
private fun SupportBubble(message: SupportMessageDto, modifier: Modifier = Modifier) {
    val context = LocalContext.current
    val mine = message.sender == "user"
    val shape = RoundedCornerShape(
        topStart = 20.dp, topEnd = 20.dp,
        bottomStart = if (mine) 20.dp else 5.dp,
        bottomEnd = if (mine) 5.dp else 20.dp,
    )
    val textColor = if (mine) Color.White else settingsInk()
    val visible = remember(message.id) { androidx.compose.animation.core.MutableTransitionState(false).apply { targetState = true } }
    AnimatedVisibility(
        visibleState = visible,
        enter = fadeIn(tween(220)) + scaleIn(tween(220), initialScale = 0.92f) + slideInVertically(tween(220)) { it / 6 },
        modifier = modifier,
    ) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = if (mine) Arrangement.End else Arrangement.Start) {
            Column(
                Modifier
                    .widthIn(max = 290.dp)
                    .clip(shape)
                    .background(
                        when {
                            mine -> settingsAccent()
                            message.isAuto -> settingsAccent().copy(alpha = 0.09f)
                            settingsNight() -> AccountDark.card
                            else -> settingsCard()
                        },
                    )
                    .then(
                        when {
                            mine -> Modifier
                            message.isAuto -> Modifier.border(1.dp, settingsAccent().copy(alpha = 0.35f), shape)
                            else -> Modifier.border(1.dp, settingsMut().copy(alpha = 0.18f), shape)
                        },
                    )
                    .padding(horizontal = 14.dp, vertical = 10.dp),
            ) {
                if (message.isAuto) {
                    Text(
                        stringResource(R.string.support_auto_reply) + " · " + stringResource(
                            when (message.autoKind) {
                                "away" -> R.string.support_auto_away
                                "faq" -> R.string.support_auto_faq
                                else -> R.string.support_auto_ack
                            },
                        ),
                        color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.ExtraBold,
                        modifier = Modifier.padding(bottom = 3.dp),
                    )
                } else if (!mine) {
                    Text(
                        message.agentName?.takeIf { it.isNotBlank() } ?: stringResource(R.string.support_agent_default),
                        color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.ExtraBold,
                        modifier = Modifier.padding(bottom = 3.dp),
                    )
                }
                message.imageUrl?.let { url ->
                    AsyncImage(
                        model = ApiClient.mediaUrl(url),
                        contentDescription = stringResource(R.string.support_photo),
                        contentScale = ContentScale.Crop,
                        modifier = Modifier
                            .padding(bottom = if (message.body.isNullOrBlank()) 0.dp else 6.dp)
                            .widthIn(max = 240.dp).heightIn(max = 220.dp).fillMaxWidth()
                            .clip(RoundedCornerShape(12.dp))
                            .clickable { runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(ApiClient.mediaUrl(url))).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) } },
                    )
                }
                if (!message.body.isNullOrBlank()) {
                    Text(message.body, color = textColor, fontSize = 14.5.sp, lineHeight = 21.sp)
                }
                Text(
                    timeOf(message.createdAt).orEmpty(),
                    color = textColor.copy(alpha = 0.6f), fontSize = 10.sp,
                    modifier = Modifier.align(Alignment.End).padding(top = 3.dp),
                )
            }
        }
    }
}

/** Under an automatic FAQ answer: the customer says whether it was enough. */
@Composable
private fun AutoReplyFeedback(busy: Boolean, onSolved: () -> Unit, onNeedAgent: () -> Unit) {
    Row(Modifier.fillMaxWidth().padding(top = 2.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        TextAction(stringResource(R.string.support_auto_solved), Icons.Rounded.Lock) { if (!busy) onSolved() }
        TextAction(stringResource(R.string.support_auto_need_agent), Icons.Rounded.SupportAgent) { if (!busy) onNeedAgent() }
    }
}

@Composable
private fun Composer(
    ticket: SupportTicketDto,
    draft: String,
    onDraft: (String) -> Unit,
    imageUri: Uri?,
    onPick: () -> Unit,
    onClearImage: () -> Unit,
    sending: Boolean,
    reopenBusy: Boolean,
    onSend: () -> Unit,
    onReopen: () -> Unit,
) {
    val accent = settingsAccent()
    if (!ticket.acceptsReplies) {
        Column(
            Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 14.dp).padding(top = 6.dp, bottom = 14.dp)
                .clip(RoundedCornerShape(16.dp)).background(accent.copy(alpha = 0.10f)).padding(14.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(
                stringResource(if (ticket.status == "resolved") R.string.support_conv_resolved else R.string.support_conv_closed),
                color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp, textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(10.dp))
            SettingsPrimaryButton(text = stringResource(R.string.support_reopen), loading = reopenBusy, enabled = !reopenBusy, onClick = onReopen)
        }
        return
    }
    Column(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(topStart = 24.dp, topEnd = 24.dp))
            .background(if (settingsNight()) AccountDark.card else settingsCard())
            .navigationBarsPadding()
            .padding(horizontal = 12.dp)
            .padding(top = 10.dp, bottom = 10.dp),
    ) {
        if (imageUri != null) {
            Box(Modifier.padding(bottom = 8.dp).size(76.dp).clip(RoundedCornerShape(12.dp))) {
                AsyncImage(model = imageUri, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                Box(
                    Modifier.align(Alignment.TopEnd).padding(4.dp).size(22.dp).clip(CircleShape).background(Color.Black.copy(alpha = 0.55f)).clickable(onClick = onClearImage),
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.Rounded.Close, null, tint = Color.White, modifier = Modifier.size(14.dp)) }
            }
        }
        Row(verticalAlignment = Alignment.Bottom) {
            Box(
                Modifier.size(46.dp).clip(CircleShape).background(accent.copy(alpha = 0.12f)).clickable(enabled = !sending, onClick = onPick),
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.AddPhotoAlternate, stringResource(R.string.support_attach), tint = accent, modifier = Modifier.size(22.dp)) }
            Spacer(Modifier.width(8.dp))
            DorrTextField(
                value = draft,
                onValueChange = onDraft,
                placeholder = stringResource(R.string.support_chat_hint),
                modifier = Modifier.weight(1f),
                enabled = !sending,
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Send),
            )
            Spacer(Modifier.width(8.dp))
            val canSend = !sending && (draft.isNotBlank() || imageUri != null)
            Box(
                Modifier.size(46.dp).clip(CircleShape).background(accent.copy(alpha = if (canSend) 1f else 0.45f)).clickable(enabled = canSend, onClick = onSend),
                contentAlignment = Alignment.Center,
            ) {
                if (sending) CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
                else Icon(Icons.AutoMirrored.Rounded.Send, stringResource(R.string.support_chat_hint), tint = Color.White, modifier = Modifier.size(20.dp))
            }
        }
    }
}

// ----------------------------------------------------------------------------- helpers

private fun parseInstant(iso: String?): Instant? =
    runCatching { Instant.parse(iso) }.getOrNull() ?: runCatching { OffsetDateTime.parse(iso).toInstant() }.getOrNull()

private fun timeOf(iso: String?): String? = parseInstant(iso)?.atZone(ZoneId.systemDefault())
    ?.let { DateTimeFormatter.ofLocalizedTime(FormatStyle.SHORT).format(it) }

private fun conversationRows(messages: List<SupportMessageDto>): List<ConversationRow> {
    val out = mutableListOf<ConversationRow>()
    var lastDay: String? = null
    val formatter = DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM)
    messages.forEach { message ->
        val day = parseInstant(message.createdAt)?.atZone(ZoneId.systemDefault())?.toLocalDate()?.let { formatter.format(it) }
        if (day != null && day != lastDay) {
            out += ConversationRow.Day(day)
            lastDay = day
        }
        out += ConversationRow.Msg(message)
    }
    return out
}
