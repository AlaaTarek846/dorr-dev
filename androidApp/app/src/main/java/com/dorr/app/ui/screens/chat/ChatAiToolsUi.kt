package com.dorr.app.ui.screens.chat

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
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
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Alarm
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.CheckBox
import androidx.compose.material.icons.rounded.CheckBoxOutlineBlank
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.FormatQuote
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.HealthAndSafety
import androidx.compose.material.icons.rounded.Code
import androidx.compose.material.icons.rounded.Engineering
import androidx.compose.material.icons.rounded.EventAvailable
import androidx.compose.material.icons.rounded.MenuBook
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material.icons.rounded.Spellcheck
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
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
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AskReferenceDto
import com.dorr.app.network.AssistantAnswerDto
import com.dorr.app.network.DateFoundDto
import com.dorr.app.network.ImportantDto
import com.dorr.app.network.MessageDto
import com.dorr.app.network.RelatedFileDto
import com.dorr.app.network.SafetyDto
import com.dorr.app.network.SimplifyDto
import com.dorr.app.network.TaskDto
import com.dorr.app.network.TaskSuggestionDto
import com.dorr.app.network.TodayDto
import com.dorr.app.network.UnderstandDto
import com.dorr.app.network.apiFailure
import com.google.gson.Gson
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.ZoneId

/*
 * DORR AI tools in the chat (spec 31, 36–42, 46, 48, 49) and my tasks (38). One tap each; the
 * sheet says what goes to DORR AI, and nothing is sent, saved or scheduled until I confirm.
 * Answers that touch religion, law, medicine, structural design or production code carry the
 * approved alert card (spec 350–362) — the same card everywhere.
 */

private fun zone(): String = ZoneId.systemDefault().id

// =============================================================================== the safety card

/**
 * The alert card inside a reply (spec 350–362): the approved referral sentence and the opening
 * disclaimer, exactly as the server sent them — the app never words them.
 */
@Composable
fun SafetyCard(safety: SafetyDto?, modifier: Modifier = Modifier) {
    if (safety == null || (safety.notice == null && safety.disclaimer == null)) return
    val icon = when (safety.domain) {
        "medicine" -> Icons.Rounded.HealthAndSafety
        "law" -> Icons.Rounded.Gavel
        "religion" -> Icons.Rounded.MenuBook
        "engineering" -> Icons.Rounded.Engineering
        else -> Icons.Rounded.Code
    }
    val tint = Color(0xFFB45309)
    Row(
        modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Color(0xFFF59E0B).copy(alpha = 0.12f))
            .border(1.dp, Color(0xFFF59E0B).copy(alpha = 0.45f), RoundedCornerShape(16.dp)).padding(12.dp),
        verticalAlignment = Alignment.Top,
    ) {
        Icon(icon, null, tint = tint, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(8.dp))
        Column(Modifier.weight(1f)) {
            safety.notice?.let { Text(it, color = Ch.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, lineHeight = 19.sp) }
            safety.disclaimer?.let { Text(it, color = Ch.Mut, fontSize = 11.5.sp, lineHeight = 16.sp, modifier = Modifier.padding(top = if (safety.notice != null) 4.dp else 0.dp)) }
        }
    }
}

@Composable
private fun Loading() {
    Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) { AiShimmer(Ch.Ink) }
}

@Composable
private fun ErrorText(text: String?) {
    text?.let { Text(it, color = Ch.Danger, fontSize = 13.5.sp, modifier = Modifier.padding(vertical = 8.dp)) }
}

@Composable
private fun Privacy() {
    Text(stringResource(R.string.ch_ai_privacy_note), color = Ch.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 10.dp))
}

@Composable
private fun RefRow(ref: AskReferenceDto, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().padding(top = 6.dp).clip(RoundedCornerShape(12.dp)).background(Ch.Surface).clickable(onClick = onClick).padding(10.dp), verticalAlignment = Alignment.Top) {
        Icon(Icons.Rounded.FormatQuote, null, tint = Ch.Red, modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(6.dp))
        Column(Modifier.weight(1f)) {
            Text(ref.sender.orEmpty() + "  ·  " + listTime(ref.createdAt), color = Ch.Red, fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
            Text(ref.excerpt, color = Ch.Ink, fontSize = 13.sp)
        }
    }
}

private fun copy(context: Context, text: String) {
    (context.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager)?.setPrimaryClip(ClipData.newPlainText("dorr", text))
}

// =============================================================================== 31 the assistant

private data class Turn(val question: String, val answer: AssistantAnswerDto? = null, val error: String? = null)

/**
 * DORR AI inside the chat — only I see it. A general question sends nothing of the chat; with
 * picked messages ([messageIds]) only those go. A little conversation, kept on the phone.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AiAssistantSheet(conversationId: String, messageIds: List<String>?, onDismiss: () -> Unit, onJump: (String) -> Unit, onUse: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val turns = remember { mutableStateListOf<Turn>() }
    var question by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    val list = rememberLazyListState()
    val networkError = stringResource(R.string.ch_error_network)

    fun ask() {
        val q = question.trim().takeIf { it.isNotEmpty() } ?: return
        question = ""
        busy = true
        val history = turns.filter { it.answer != null }.takeLast(5)
        turns.add(Turn(q))
        scope.launch {
            val index = turns.lastIndex
            try {
                val body = JsonObject().apply {
                    addProperty("question", q)
                    messageIds?.takeIf { it.isNotEmpty() }?.let { add("messages", Gson().toJsonTree(it)) }
                    add("history", JsonArray().apply { history.forEach { t -> add(JsonObject().apply { addProperty("q", t.question); addProperty("a", t.answer!!.answer) }) } })
                }
                turns[index] = turns[index].copy(answer = ApiClient.aiTools.assistant(chatAuth(), conversationId, body).data)
            } catch (e: Exception) {
                turns[index] = turns[index].copy(error = e.apiFailure().message ?: networkError)
            }
            busy = false
            list.animateScrollToItem(turns.lastIndex)
        }
    }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().padding(horizontal = 20.dp).padding(bottom = 20.dp)) {
            AiHeader(
                stringResource(R.string.ct_assistant),
                if (messageIds.isNullOrEmpty()) stringResource(R.string.ct_assistant_sub) else stringResource(R.string.ch_ai_ask_scope_picked, messageIds.size),
            )
            Spacer(Modifier.height(10.dp))
            LazyColumn(Modifier.fillMaxWidth().heightIn(min = 120.dp, max = 460.dp), state = list) {
                if (turns.isEmpty()) item { Text(stringResource(R.string.ct_assistant_empty), color = Ch.Soft, fontSize = 13.sp, modifier = Modifier.padding(vertical = 18.dp)) }
                itemsIndexed(turns) { i, t ->
                    Column(Modifier.fillMaxWidth().padding(bottom = 12.dp)) {
                        Text(
                            t.question, color = Color.White, fontSize = 14.sp,
                            modifier = Modifier.align(Alignment.End).clip(RoundedCornerShape(18.dp)).background(Ch.Red).padding(horizontal = 14.dp, vertical = 9.dp),
                        )
                        Spacer(Modifier.height(6.dp))
                        Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Ch.SurfaceMuted).padding(12.dp)) {
                            val a = t.answer
                            when {
                                t.error != null -> ErrorText(t.error)
                                a == null -> AiShimmer(Ch.Ink)
                                else -> {
                                    Text(a.answer, color = Ch.Ink, fontSize = 14.5.sp, lineHeight = 21.sp)
                                    SafetyCard(a.safety, Modifier.padding(top = 10.dp))
                                    a.references.forEach { ref -> RefRow(ref) { onDismiss(); onJump(ref.id) } }
                                    Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                                        SmallAction(Icons.Rounded.ContentCopy, stringResource(R.string.ch_copy)) { copy(context, a.answer) }
                                        SmallAction(Icons.Rounded.Send, stringResource(R.string.ct_use_in_chat)) { onUse(a.answer); onDismiss() }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.weight(1f)) { ChField(question, { question = it.take(1000) }, stringResource(R.string.ct_assistant_hint), singleLine = false, maxLines = 4) }
                Spacer(Modifier.width(8.dp))
                ChPrimaryButton(stringResource(R.string.ch_ai_ask_go), enabled = question.isNotBlank() && !busy) { ask() }
            }
            Privacy()
        }
    }
}

@Composable
private fun SmallAction(icon: ImageVector, label: String, onClick: () -> Unit) {
    Row(Modifier.clip(RoundedCornerShape(10.dp)).clickable(onClick = onClick).padding(4.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(15.dp))
        Spacer(Modifier.width(4.dp))
        Text(label, color = Ch.Red, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
    }
}

// =============================================================================== 36 proofread

/**
 * "✓ Fix spelling" above the composer once I've written something: one tap sends only that text,
 * and puts the corrected one in its place — with an undo.
 */
@Composable
internal fun ProofreadBar(text: String, enabled: Boolean, onReplace: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val host = LocalChat.current
    var busy by remember { mutableStateOf(false) }
    var undo by remember { mutableStateOf<String?>(null) }
    val noMistakes = stringResource(R.string.ct_proof_clean)
    val visible = enabled && ChatAi.proofread && (text.trim().length >= 6 || undo != null)
    LaunchedEffect(undo) { if (undo != null) { delay(6000); undo = null } }

    AnimatedVisibility(visible, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
        Row(Modifier.fillMaxWidth().padding(bottom = 6.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            val before = undo
            if (before != null) {
                Chip(Icons.Rounded.Undo, stringResource(R.string.ct_proof_undo)) { onReplace(before); undo = null }
            } else {
                Chip(Icons.Rounded.Spellcheck, stringResource(if (busy) R.string.ct_proof_busy else R.string.ct_proof)) {
                    if (busy) return@Chip
                    busy = true
                    scope.launch {
                        runCatching { ApiClient.aiTools.proofread(chatAuth(), JsonObject().apply { addProperty("text", text) }).data }
                            .onSuccess { r ->
                                if (r != null && r.changed) { undo = text; onReplace(r.text) } else host.showToast(noMistakes)
                            }
                            .onFailure { host.showToast(it.apiFailure().message ?: "") }
                        busy = false
                    }
                }
            }
        }
    }
}

@Composable
private fun Chip(icon: ImageVector, label: String, onClick: () -> Unit) {
    Row(
        Modifier.clip(CircleShape).background(Ch.Surface).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 7.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(15.dp))
        Spacer(Modifier.width(5.dp))
        Text(label, color = Ch.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold)
    }
}

// =============================================================================== 37 + 42 understand

/** What the message wants, its tone, and what I could do — reply, remind, task, note. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AiUnderstandSheet(message: MessageDto, onDismiss: () -> Unit, onReply: (String) -> Unit, onRemind: () -> Unit, onTasks: () -> Unit, onNote: () -> Unit) {
    var result by remember { mutableStateOf<UnderstandDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val networkError = stringResource(R.string.ch_error_network)
    LaunchedEffect(message.id) {
        runCatching { ApiClient.aiTools.understand(chatAuth(), message.id).data }.onSuccess { result = it }.onFailure { error = it.apiFailure().message ?: networkError }
    }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 24.dp)) {
            AiHeader(stringResource(R.string.ct_understand), message.sender?.name)
            Spacer(Modifier.height(12.dp))
            val r = result
            when {
                error != null -> ErrorText(error)
                r == null -> Loading()
                else -> {
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        Tag(intentLabel(r.intent), Ch.Red)
                        Tag(toneLabel(r.tone), toneColor(r.tone))
                    }
                    if (r.summary.isNotBlank()) Text(r.summary, color = Ch.Ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 10.dp))
                    r.toneNote?.let { Text(it, color = Ch.Mut, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp)) }
                    SafetyCard(r.safety, Modifier.padding(top = 10.dp))
                    if (r.replies.isNotEmpty()) {
                        Text(stringResource(R.string.ct_suggested_replies), color = Ch.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 14.dp, bottom = 4.dp))
                        r.replies.forEach { reply ->
                            Text(reply, color = Ch.Ink, fontSize = 14.sp, modifier = Modifier.fillMaxWidth().padding(top = 6.dp).clip(RoundedCornerShape(14.dp)).background(Ch.SurfaceMuted).clickable { onReply(reply); onDismiss() }.padding(12.dp))
                        }
                    }
                    Row(Modifier.padding(top = 14.dp).horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        if ("remind" in r.actions || r.intent in setOf("appointment", "request", "payment")) Chip(Icons.Rounded.Alarm, stringResource(R.string.ch_reminder)) { onDismiss(); onRemind() }
                        if (ChatAi.tasks) Chip(Icons.Rounded.TaskAlt, stringResource(R.string.ct_make_tasks)) { onDismiss(); onTasks() }
                        if (ChatAi.notes) Chip(Icons.Rounded.Description, stringResource(R.string.ct_make_note)) { onDismiss(); onNote() }
                    }
                }
            }
            Privacy()
        }
    }
}

@Composable
private fun Tag(text: String, color: Color) {
    Text(text, color = color, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).background(color.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp))
}

@Composable
private fun intentLabel(intent: String): String = stringResource(
    when (intent) {
        "question" -> R.string.ct_intent_question
        "request" -> R.string.ct_intent_request
        "invitation" -> R.string.ct_intent_invitation
        "appointment" -> R.string.ct_intent_appointment
        "payment" -> R.string.ct_intent_payment
        "information" -> R.string.ct_intent_information
        "complaint" -> R.string.ct_intent_complaint
        "greeting" -> R.string.ct_intent_greeting
        else -> R.string.ct_intent_other
    },
)

@Composable
private fun toneLabel(tone: String): String = stringResource(
    when (tone) {
        "positive" -> R.string.ct_tone_positive
        "negative" -> R.string.ct_tone_negative
        "urgent" -> R.string.ct_tone_urgent
        "worried" -> R.string.ct_tone_worried
        "angry" -> R.string.ct_tone_angry
        "joking" -> R.string.ct_tone_joking
        else -> R.string.ct_tone_neutral
    },
)

private fun toneColor(tone: String): Color = when (tone) {
    "positive", "joking" -> Color(0xFF059669)
    "negative", "angry" -> Color(0xFFDC2626)
    "urgent", "worried" -> Color(0xFFD97706)
    else -> Color(0xFF6B7280)
}

// =============================================================================== 46 simplify

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AiSimplifySheet(message: MessageDto, onDismiss: () -> Unit) {
    var result by remember { mutableStateOf<SimplifyDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val networkError = stringResource(R.string.ch_error_network)
    LaunchedEffect(message.id) {
        runCatching { ApiClient.aiTools.simplify(chatAuth(), message.id).data }.onSuccess { result = it }.onFailure { error = it.apiFailure().message ?: networkError }
    }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 24.dp)) {
            AiHeader(stringResource(R.string.ct_simplify), message.sender?.name)
            Spacer(Modifier.height(12.dp))
            val r = result
            when {
                error != null -> ErrorText(error)
                r == null -> Loading()
                else -> {
                    Text(r.short, color = Ch.Ink, fontSize = 15.5.sp, fontWeight = FontWeight.SemiBold, lineHeight = 22.sp)
                    r.points.forEach { p ->
                        Row(Modifier.padding(top = 8.dp), verticalAlignment = Alignment.Top) {
                            Box(Modifier.padding(top = 7.dp).size(6.dp).clip(CircleShape).background(Ch.Red))
                            Spacer(Modifier.width(8.dp))
                            Text(p, color = Ch.Ink, fontSize = 14.sp)
                        }
                    }
                }
            }
            Privacy()
        }
    }
}

// =============================================================================== 38 tasks from a message

/** Suggested tasks from a message — tick the ones to keep, then save to "My tasks". */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AiTasksFromSheet(message: MessageDto, onDismiss: () -> Unit) {
    val scope = rememberCoroutineScope()
    val host = LocalChat.current
    var items by remember { mutableStateOf<List<TaskSuggestionDto>?>(null) }
    val picked = remember { mutableStateListOf<Int>() }
    var error by remember { mutableStateOf<String?>(null) }
    val networkError = stringResource(R.string.ch_error_network)
    val saved = stringResource(R.string.ct_tasks_saved)
    LaunchedEffect(message.id) {
        runCatching { ApiClient.aiTools.tasksFrom(chatAuth(), message.id, JsonObject().apply { addProperty("timezone", zone()) }).data?.tasks.orEmpty() }
            .onSuccess { items = it; picked.addAll(it.indices) }
            .onFailure { error = it.apiFailure().message ?: networkError }
    }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 24.dp)) {
            AiHeader(stringResource(R.string.ct_make_tasks), null)
            Spacer(Modifier.height(12.dp))
            val list = items
            when {
                error != null -> ErrorText(error)
                list == null -> Loading()
                list.isEmpty() -> Text(stringResource(R.string.ct_tasks_none), color = Ch.Soft, fontSize = 13.5.sp)
                else -> {
                    list.forEachIndexed { i, t ->
                        val on = i in picked
                        Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable { if (on) picked.remove(i) else picked.add(i) }.padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                            Icon(if (on) Icons.Rounded.CheckBox else Icons.Rounded.CheckBoxOutlineBlank, null, tint = if (on) Ch.Red else Ch.Soft, modifier = Modifier.size(22.dp))
                            Spacer(Modifier.width(10.dp))
                            Column(Modifier.weight(1f)) {
                                Text(t.text, color = Ch.Ink, fontSize = 14.5.sp)
                                t.dueAt?.let { Text(listTime(it) + " " + clockTime(it), color = Ch.Red, fontSize = 12.sp, fontWeight = FontWeight.Bold) }
                            }
                        }
                    }
                    Spacer(Modifier.height(12.dp))
                    ChPrimaryButton(stringResource(R.string.ct_tasks_save, picked.size), icon = Icons.Rounded.TaskAlt, modifier = Modifier.fillMaxWidth(), enabled = picked.isNotEmpty()) {
                        scope.launch {
                            runCatching {
                                ApiClient.aiTools.createTasks(chatAuth(), JsonObject().apply {
                                    addProperty("message_id", message.id)
                                    add("tasks", JsonArray().apply { picked.sorted().forEach { i -> add(JsonObject().apply { addProperty("text", list[i].text); list[i].dueAt?.let { addProperty("due_at", it) } }) } })
                                })
                            }.onSuccess { host.showToast(saved); onDismiss() }.onFailure { error = it.apiFailure().message ?: networkError }
                        }
                    }
                }
            }
            Privacy()
        }
    }
}

// =============================================================================== 40 dates in the chat

/** Dates mentioned in the chat — "Remind me" sets a reminder on that message, at that time. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AiDatesSheet(conversationId: String, onDismiss: () -> Unit, onJump: (String) -> Unit) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var items by remember { mutableStateOf<List<DateFoundDto>?>(null) }
    val done = remember { mutableStateListOf<Int>() }
    var error by remember { mutableStateOf<String?>(null) }
    val networkError = stringResource(R.string.ch_error_network)
    val reminded = stringResource(R.string.ch_ai_commitment_reminded)
    val addedToCalendar = stringResource(R.string.cal_saved)
    LaunchedEffect(conversationId) {
        runCatching { ApiClient.aiTools.dates(chatAuth(), conversationId, JsonObject().apply { addProperty("timezone", zone()) }).data?.dates.orEmpty() }
            .onSuccess { items = it }.onFailure { error = it.apiFailure().message ?: networkError }
    }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 24.dp)) {
            AiHeader(stringResource(R.string.ct_dates), stringResource(R.string.ct_dates_sub))
            Spacer(Modifier.height(12.dp))
            val list = items
            when {
                error != null -> ErrorText(error)
                list == null -> Loading()
                list.isEmpty() -> Text(stringResource(R.string.ct_dates_none), color = Ch.Soft, fontSize = 13.5.sp)
                else -> list.forEachIndexed { i, d ->
                    Row(Modifier.fillMaxWidth().padding(bottom = 8.dp).clip(RoundedCornerShape(16.dp)).background(Ch.SurfaceMuted).clickable { d.messageId?.let { onDismiss(); onJump(it) } }.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.Schedule, null, tint = Ch.Red, modifier = Modifier.size(22.dp))
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(d.title, color = Ch.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.SemiBold)
                            Text(listTime(d.at) + if (d.allDay) "" else " " + clockTime(d.at), color = Ch.Mut, fontSize = 12.5.sp)
                        }
                        val isDone = i in done
                        // Into my calendar (spec 201) — the same event twice stays one.
                        Box(
                            Modifier.padding(end = 6.dp).size(32.dp).clip(CircleShape).background(Ch.Red.copy(alpha = 0.1f)).clickable {
                                scope.launch {
                                    runCatching {
                                        ApiClient.calendar.create(chatAuth(), JsonObject().apply {
                                            addProperty("title", d.title)
                                            if (d.allDay) { addProperty("all_day", true); addProperty("date", d.at.take(10)) } else addProperty("starts_at", d.at)
                                            addProperty("timezone", zone())
                                            d.messageId?.let { addProperty("message_id", it) }
                                        })
                                    }.onSuccess { host.showToast(addedToCalendar) }.onFailure { host.showToast(it.apiFailure().message ?: networkError) }
                                }
                            },
                            contentAlignment = Alignment.Center,
                        ) { Icon(Icons.Rounded.EventAvailable, null, tint = Ch.Red, modifier = Modifier.size(18.dp)) }
                        if (d.messageId != null) Text(
                            stringResource(if (isDone) R.string.ch_ai_commitment_done else R.string.ch_ai_commitment_remind), color = Color.White, fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.clip(CircleShape).background(if (isDone) Color(0xFF10B981) else Ch.Red).clickable(enabled = !isDone) {
                                scope.launch {
                                    runCatching { ApiClient.chat.setReminder(chatAuth(), d.messageId, mapOf("remind_at" to d.at, "note" to d.title.take(200))) }
                                        .onSuccess { done.add(i); host.showToast(reminded) }.onFailure { host.showToast(it.apiFailure().message ?: networkError) }
                                }
                            }.padding(horizontal = 12.dp, vertical = 7.dp),
                        )
                    }
                }
            }
            Privacy()
        }
    }
}

// =============================================================================== 48 related files

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AiRelatedFilesSheet(conversationId: String, onDismiss: () -> Unit, onJump: (String) -> Unit) {
    var items by remember { mutableStateOf<List<RelatedFileDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val networkError = stringResource(R.string.ch_error_network)
    LaunchedEffect(conversationId) {
        runCatching { ApiClient.aiTools.relatedFiles(chatAuth(), conversationId).data?.files.orEmpty() }.onSuccess { items = it }.onFailure { error = it.apiFailure().message ?: networkError }
    }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 24.dp)) {
            AiHeader(stringResource(R.string.ct_files), stringResource(R.string.ct_files_sub))
            Spacer(Modifier.height(12.dp))
            val list = items
            when {
                error != null -> ErrorText(error)
                list == null -> Loading()
                list.isEmpty() -> Text(stringResource(R.string.ct_files_none), color = Ch.Soft, fontSize = 13.5.sp)
                else -> list.forEach { f ->
                    Row(Modifier.fillMaxWidth().padding(bottom = 8.dp).clip(RoundedCornerShape(16.dp)).background(Ch.SurfaceMuted).clickable { onDismiss(); onJump(f.messageId) }.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.Description, null, tint = Ch.Red, modifier = Modifier.size(24.dp))
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(f.name ?: f.type, color = Ch.Ink, fontSize = 14.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text(listOfNotNull(f.why?.takeIf { it.isNotBlank() }, listTime(f.createdAt)).joinToString("  ·  "), color = Ch.Mut, fontSize = 12.sp, maxLines = 2)
                        }
                    }
                }
            }
            Privacy()
        }
    }
}

// =============================================================================== 41 important · 49 today

/** What deserves attention — in this chat ([conversationId]) or across my unread chats. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AiImportantSheet(conversationId: String?, onDismiss: () -> Unit, onOpen: (conversationId: String, messageId: String) -> Unit) {
    var result by remember { mutableStateOf<ImportantDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val networkError = stringResource(R.string.ch_error_network)
    LaunchedEffect(conversationId) {
        runCatching { ApiClient.aiTools.important(chatAuth(), JsonObject().apply { conversationId?.let { addProperty("conversation_id", it) } }).data }
            .onSuccess { result = it }.onFailure { error = it.apiFailure().message ?: networkError }
    }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 24.dp)) {
            AiHeader(stringResource(R.string.ct_important), stringResource(if (conversationId == null) R.string.ct_important_sub_all else R.string.ct_important_sub_chat))
            Spacer(Modifier.height(12.dp))
            val r = result
            when {
                error != null -> ErrorText(error)
                r == null -> Loading()
                r.items.isEmpty() -> Text(stringResource(R.string.ct_important_none), color = Ch.Soft, fontSize = 13.5.sp)
                else -> r.items.forEach { item ->
                    Row(
                        Modifier.fillMaxWidth().padding(bottom = 8.dp).clip(RoundedCornerShape(16.dp)).background(Ch.SurfaceMuted)
                            .clickable { (item.conversationId ?: conversationId)?.let { c -> onDismiss(); onOpen(c, item.messageId) } }.padding(12.dp),
                        verticalAlignment = Alignment.Top,
                    ) {
                        Box(Modifier.padding(top = 5.dp).size(9.dp).clip(CircleShape).background(if (item.level == "high") Ch.Danger else Color(0xFFF59E0B)))
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(listOfNotNull(item.chat.takeIf { conversationId == null }, item.sender).joinToString(" · "), color = Ch.Red, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                            Text(item.excerpt.orEmpty(), color = Ch.Ink, fontSize = 14.sp, maxLines = 3, overflow = TextOverflow.Ellipsis)
                            item.why?.takeIf { it.isNotBlank() }?.let { Text(it, color = Ch.Mut, fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp)) }
                        }
                    }
                }
            }
            Privacy()
        }
    }
}

/** Today across my chats: a few lines, and the messages that matter. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AiTodaySheet(onDismiss: () -> Unit, onOpen: (conversationId: String, messageId: String) -> Unit) {
    var result by remember { mutableStateOf<TodayDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val networkError = stringResource(R.string.ch_error_network)
    LaunchedEffect(Unit) {
        runCatching { ApiClient.aiTools.today(chatAuth(), JsonObject().apply { addProperty("timezone", zone()) }).data }.onSuccess { result = it }.onFailure { error = it.apiFailure().message ?: networkError }
    }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 24.dp)) {
            AiHeader(stringResource(R.string.ct_today), result?.let { stringResource(R.string.ct_today_sub, it.messages, it.chats) })
            Spacer(Modifier.height(12.dp))
            val r = result
            when {
                error != null -> ErrorText(error)
                r == null -> Loading()
                else -> {
                    r.summary.forEach { p ->
                        Row(Modifier.padding(bottom = 8.dp), verticalAlignment = Alignment.Top) {
                            Box(Modifier.padding(top = 7.dp).size(6.dp).clip(CircleShape).background(Ch.Red))
                            Spacer(Modifier.width(8.dp))
                            Text(p, color = Ch.Ink, fontSize = 14.5.sp)
                        }
                    }
                    if (r.highlights.isNotEmpty()) Text(stringResource(R.string.ct_today_highlights), color = Ch.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 8.dp, bottom = 4.dp))
                    r.highlights.forEach { h ->
                        Column(
                            Modifier.fillMaxWidth().padding(bottom = 8.dp).clip(RoundedCornerShape(16.dp)).background(Ch.SurfaceMuted)
                                .clickable { h.conversationId?.let { c -> onDismiss(); onOpen(c, h.messageId) } }.padding(12.dp),
                        ) {
                            Text(listOfNotNull(h.chat, h.sender).joinToString(" · "), color = Ch.Red, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                            Text(h.text?.takeIf { it.isNotBlank() } ?: h.excerpt.orEmpty(), color = Ch.Ink, fontSize = 14.sp)
                        }
                    }
                }
            }
            Privacy()
        }
    }
}

// =============================================================================== 38 my tasks

/** My tasks: open ones (due first), add one by hand, tick off, and the done ones below. */
@Composable
fun TasksPage() {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var open by remember { mutableStateOf<List<TaskDto>?>(null) }
    var done by remember { mutableStateOf<List<TaskDto>>(emptyList()) }
    var adding by remember { mutableStateOf("") }
    var dueFor by remember { mutableStateOf<TaskDto?>(null) }
    val networkError = stringResource(R.string.ch_error_network)

    suspend fun load() {
        open = runCatching { ApiClient.aiTools.tasks(chatAuth()).data.orEmpty() }.getOrElse { open ?: emptyList() }
        done = runCatching { ApiClient.aiTools.tasks(chatAuth(), "done").data.orEmpty() }.getOrDefault(done)
    }
    fun update(task: TaskDto, body: JsonObject) {
        scope.launch { runCatching { ApiClient.aiTools.updateTask(chatAuth(), task.id, body) }.onFailure { host.showToast(it.apiFailure().message ?: networkError) }; load() }
    }
    LaunchedEffect(Unit) { load() }

    ChPage(stringResource(R.string.ct_tasks), onBack = { host.pop() }) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.weight(1f)) { ChField(adding, { adding = it.take(300) }, stringResource(R.string.ct_tasks_add_hint), icon = Icons.Rounded.Add) }
                Spacer(Modifier.width(8.dp))
                ChPrimaryButton(stringResource(R.string.ct_tasks_add), enabled = adding.isNotBlank()) {
                    val text = adding.trim()
                    adding = ""
                    scope.launch {
                        runCatching { ApiClient.aiTools.createTasks(chatAuth(), JsonObject().apply { add("tasks", JsonArray().apply { add(JsonObject().apply { addProperty("text", text) }) }) }) }
                            .onFailure { host.showToast(it.apiFailure().message ?: networkError) }
                        load()
                    }
                }
            }
            Spacer(Modifier.height(14.dp))
            val list = open
            when {
                list == null -> Box(Modifier.fillMaxWidth().padding(30.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ch.Red) }
                list.isEmpty() -> Text(stringResource(R.string.ct_tasks_empty), color = Ch.Soft, fontSize = 14.sp, modifier = Modifier.padding(vertical = 20.dp))
                else -> list.forEachIndexed { i, t -> TaskRow(t, Modifier.chStagger(i), onToggle = { update(t, JsonObject().apply { addProperty("done", true) }) }, onDue = { dueFor = t }, onOpen = {
                    t.conversationId?.let { c -> t.messageId?.let { m -> host.focusRequest = c to m }; host.push(ChRoute.Conversation(c)) }
                }, onDelete = { scope.launch { runCatching { ApiClient.aiTools.deleteTask(chatAuth(), t.id) }; load() } }) }
            }
            if (done.isNotEmpty()) {
                Text(stringResource(R.string.ct_tasks_done), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 18.dp, bottom = 6.dp))
                done.take(50).forEach { t -> TaskRow(t, onToggle = { update(t, JsonObject().apply { addProperty("done", false) }) }, onDue = {}, onOpen = {}, onDelete = {
                    scope.launch { runCatching { ApiClient.aiTools.deleteTask(chatAuth(), t.id) }; load() }
                }) }
            }
        }
    }

    dueFor?.let { t ->
        ScheduleSheet(onDismiss = { dueFor = null }) { at ->
            update(t, JsonObject().apply { addProperty("due_at", at.toOffsetDateTime().toString()) })
            dueFor = null
        }
    }
}

@Composable
private fun TaskRow(t: TaskDto, modifier: Modifier = Modifier, onToggle: () -> Unit, onDue: () -> Unit, onOpen: () -> Unit, onDelete: () -> Unit) {
    Row(
        modifier.fillMaxWidth().padding(bottom = 8.dp).clip(RoundedCornerShape(16.dp)).background(Ch.Surface).clickable(enabled = t.conversationId != null, onClick = onOpen).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(
            if (t.done) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (t.done) Color(0xFF10B981) else Ch.Soft,
            modifier = Modifier.size(24.dp).clip(CircleShape).clickable(onClick = onToggle),
        )
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(t.text, color = if (t.done) Ch.Soft else Ch.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.SemiBold)
            t.dueAt?.let { Text("⏰ " + listTime(it) + " " + clockTime(it), color = Ch.Red, fontSize = 12.sp, fontWeight = FontWeight.Bold) }
        }
        if (!t.done) Icon(Icons.Rounded.Alarm, null, tint = Ch.Mut, modifier = Modifier.size(20.dp).clip(CircleShape).clickable(onClick = onDue))
        Spacer(Modifier.width(10.dp))
        Icon(Icons.Rounded.Delete, null, tint = Ch.Mut, modifier = Modifier.size(20.dp).clip(CircleShape).clickable(onClick = onDelete))
    }
}
