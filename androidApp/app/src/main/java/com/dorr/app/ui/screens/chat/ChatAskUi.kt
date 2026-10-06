package com.dorr.app.ui.screens.chat

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Alarm
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.FormatQuote
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
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
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AskAnswerDto
import com.dorr.app.network.CommitmentDto
import com.dorr.app.network.apiFailure
import com.google.gson.Gson
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.ZoneId
import java.time.temporal.ChronoUnit

/*
 * AI about a chat, on a tap (spec 125–126): ask a question about it, and find what someone
 * promised to do. Only the chosen messages go to the AI; nothing is done until I confirm it.
 */

@Composable
internal fun AiHeader(title: String, sub: String?) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(Ch.HeaderBrush), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.AutoAwesome, null, tint = Color.White, modifier = Modifier.size(20.dp))
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(title, color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            sub?.let { Text(it, color = Ch.Mut, fontSize = 12.sp) }
        }
    }
}

/**
 * "Ask about this chat" — about the messages I picked (`messageIds`) or the latest ones. The answer
 * shows the messages it rests on; a tap jumps to one.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AiAskSheet(conversationId: String, messageIds: List<String>?, onDismiss: () -> Unit, onJump: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    var question by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var answer by remember { mutableStateOf<AskAnswerDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val networkError = stringResource(R.string.ch_error_network)

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            AiHeader(
                stringResource(R.string.ch_ai_ask),
                if (messageIds.isNullOrEmpty()) stringResource(R.string.ch_ai_ask_scope_latest) else stringResource(R.string.ch_ai_ask_scope_picked, messageIds.size),
            )
            Spacer(Modifier.height(12.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.weight(1f)) { ChField(question, { question = it.take(500) }, stringResource(R.string.ch_ai_ask_hint), singleLine = false, maxLines = 3) }
                Spacer(Modifier.width(8.dp))
                ChPrimaryButton(stringResource(R.string.ch_ai_ask_go), enabled = question.isNotBlank() && !busy) {
                    busy = true
                    error = null
                    scope.launch {
                        try {
                            val body = JsonObject().apply {
                                addProperty("question", question.trim())
                                messageIds?.takeIf { it.isNotEmpty() }?.let { add("messages", Gson().toJsonTree(it)) }
                            }
                            answer = ApiClient.organize.ask(chatAuth(), conversationId, body).data
                        } catch (e: Exception) {
                            error = e.apiFailure().message ?: networkError
                        }
                        busy = false
                    }
                }
            }
            Spacer(Modifier.height(12.dp))
            Box(Modifier.fillMaxWidth().heightIn(min = 70.dp, max = 420.dp).clip(RoundedCornerShape(18.dp)).background(Ch.SurfaceMuted).padding(14.dp)) {
                val a = answer
                when {
                    error != null -> Text(error.orEmpty(), color = Ch.Danger, fontSize = 13.5.sp)
                    busy -> AiShimmer(Ch.Ink)
                    a == null -> Text(stringResource(R.string.ch_ai_ask_empty), color = Ch.Soft, fontSize = 13.sp)
                    else -> Column(Modifier.verticalScroll(rememberScrollState())) {
                        Text(a.answer, color = Ch.Ink, fontSize = 15.sp, lineHeight = 22.sp)
                        // DORR AI safety (spec 350–362): the approved card, when the question needs it.
                        SafetyCard(a.safety, Modifier.padding(top = 10.dp))
                        if (a.references.isNotEmpty()) {
                            Spacer(Modifier.height(10.dp))
                            Text(stringResource(R.string.ch_ai_ask_sources), color = Ch.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                            a.references.forEach { ref ->
                                Row(
                                    Modifier.fillMaxWidth().padding(top = 6.dp).clip(RoundedCornerShape(12.dp)).background(Ch.Surface)
                                        .clickable { onDismiss(); onJump(ref.id) }.padding(10.dp),
                                    verticalAlignment = Alignment.Top,
                                ) {
                                    Icon(Icons.Rounded.FormatQuote, null, tint = Ch.Red, modifier = Modifier.size(18.dp))
                                    Spacer(Modifier.width(6.dp))
                                    Column(Modifier.weight(1f)) {
                                        Text(ref.sender.orEmpty() + "  ·  " + listTime(ref.createdAt), color = Ch.Red, fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
                                        Text(ref.excerpt, color = Ch.Ink, fontSize = 13.sp)
                                    }
                                }
                            }
                        }
                    }
                }
            }
            Spacer(Modifier.height(10.dp))
            Text(stringResource(R.string.ch_ai_privacy_note), color = Ch.Soft, fontSize = 11.5.sp)
        }
    }
}

/**
 * Promises and tasks found in the chat — suggestions only. "Remind me" sets a reminder on the
 * message they came from (at their time, or tomorrow morning), and only when I tap it.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AiCommitmentsSheet(conversationId: String, onDismiss: () -> Unit, onJump: (String) -> Unit) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var items by remember { mutableStateOf<List<CommitmentDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val done = remember { mutableStateListOf<Int>() }
    val networkError = stringResource(R.string.ch_error_network)
    val reminderSet = stringResource(R.string.ch_ai_commitment_reminded)

    LaunchedEffect(Unit) {
        try {
            items = ApiClient.organize.commitments(chatAuth(), conversationId, JsonObject().apply { addProperty("timezone", ZoneId.systemDefault().id) }).data?.commitments.orEmpty()
        } catch (e: Exception) {
            error = e.apiFailure().message ?: networkError
        }
    }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            AiHeader(stringResource(R.string.ch_ai_commitments), stringResource(R.string.ch_ai_commitments_sub))
            Spacer(Modifier.height(12.dp))
            val list = items
            Column(Modifier.fillMaxWidth().heightIn(max = 460.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                when {
                    error != null -> Text(error.orEmpty(), color = Ch.Danger, fontSize = 13.5.sp)
                    list == null -> Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Ch.SurfaceMuted).padding(14.dp)) { AiShimmer(Ch.Ink) }
                    list.isEmpty() -> Text(stringResource(R.string.ch_ai_commitments_none), color = Ch.Mut, fontSize = 14.sp, modifier = Modifier.padding(vertical = 18.dp))
                    else -> list.forEachIndexed { i, c ->
                        Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Ch.SurfaceMuted).padding(12.dp)) {
                            Row(verticalAlignment = Alignment.Top) {
                                Icon(Icons.Rounded.TaskAlt, null, tint = if (c.isMine) Ch.Red else Ch.Mut, modifier = Modifier.size(20.dp))
                                Spacer(Modifier.width(8.dp))
                                Column(Modifier.weight(1f)) {
                                    Text(c.text, color = Ch.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold)
                                    Text(
                                        listOfNotNull(
                                            if (c.isMine) stringResource(R.string.ch_ai_commitment_me) else c.owner,
                                            c.dueAt?.let { listTime(it) + " " + clockTime(it) },
                                        ).joinToString("  ·  "),
                                        color = Ch.Mut, fontSize = 12.sp,
                                    )
                                }
                            }
                            Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                c.messageId?.let { id ->
                                    Text(
                                        stringResource(R.string.ch_ai_commitment_show), color = Ch.Red, fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
                                        modifier = Modifier.clip(RoundedCornerShape(12.dp)).background(Ch.Surface).clickable { onDismiss(); onJump(id) }.padding(horizontal = 12.dp, vertical = 7.dp),
                                    )
                                }
                                if (c.messageId != null) {
                                    val isDone = i in done
                                    Row(
                                        Modifier.clip(RoundedCornerShape(12.dp)).background(if (isDone) Color(0xFF16A34A) else Ch.Red)
                                            .clickable(enabled = !isDone) {
                                                // Confirmed by me: a reminder on that message, at its time or tomorrow 09:00.
                                                val at = c.dueAt ?: Instant.now().atZone(ZoneId.systemDefault()).plusDays(1).withHour(9).withMinute(0).truncatedTo(ChronoUnit.MINUTES).toOffsetDateTime().toString()
                                                scope.launch {
                                                    runCatching { ApiClient.chat.setReminder(chatAuth(), c.messageId, mapOf("remind_at" to at, "note" to c.text.take(200))) }
                                                        .onSuccess { done.add(i); host.showToast(reminderSet) }
                                                        .onFailure { host.showToast(it.apiFailure().message ?: networkError) }
                                                }
                                            }.padding(horizontal = 12.dp, vertical = 7.dp),
                                        verticalAlignment = Alignment.CenterVertically,
                                    ) {
                                        Icon(if (isDone) Icons.Rounded.CheckCircle else Icons.Rounded.Alarm, null, tint = Color.White, modifier = Modifier.size(15.dp))
                                        Spacer(Modifier.width(5.dp))
                                        Text(stringResource(if (isDone) R.string.ch_ai_commitment_done else R.string.ch_ai_commitment_remind), color = Color.White, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                                    }
                                }
                            }
                        }
                    }
                }
            }
            Spacer(Modifier.height(10.dp))
            Text(stringResource(R.string.ch_ai_privacy_note), color = Ch.Soft, fontSize = 11.5.sp)
        }
    }
}
