package com.dorr.app.ui.screens.chat

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.animateColorAsState
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
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AddPhotoAlternate
import androidx.compose.material.icons.rounded.Campaign
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Forum
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.HowToVote
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.People
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.VisibilityOff
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
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.network.ApiClient
import com.dorr.app.network.BroadcastDto
import com.dorr.app.network.CircleDto
import com.dorr.app.network.ContactDto
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.DecisionDto
import com.dorr.app.network.MessageDto
import com.dorr.app.network.apiFailure
import com.google.gson.Gson
import com.google.gson.JsonNull
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody

/*
 * Organising chats (docs/remaining_chat.md): threads (spec 122), group decisions (119–120),
 * privacy circles (98–103) and broadcast lists (like WhatsApp's).
 */

// =============================================================================== threads

/** "💬 3 replies" under a message that has a thread. */
@Composable
internal fun ThreadChip(count: Int, mine: Boolean, onClick: () -> Unit) {
    Row(
        Modifier.padding(top = 2.dp, start = 8.dp, end = 8.dp).clip(RoundedCornerShape(14.dp)).background(Ch.Surface)
            .border(1.dp, Ch.Red.copy(alpha = 0.25f), RoundedCornerShape(14.dp)).clickable(onClick = onClick).padding(horizontal = 10.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.Forum, null, tint = Ch.Red, modifier = Modifier.size(14.dp))
        Spacer(Modifier.width(5.dp))
        Text(stringResource(R.string.ch_thread_replies, count), color = Ch.Red, fontSize = 12.sp, fontWeight = FontWeight.Bold)
    }
}

/** A thread: the message it hangs from, its replies, and a box to answer in it. */
@Composable
fun ThreadPage(conversationId: String, rootId: String) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var root by remember { mutableStateOf<MessageDto?>(null) }
    val replies = remember { mutableStateListOf<MessageDto>() }
    var text by remember { mutableStateOf("") }
    var sending by remember { mutableStateOf(false) }
    val list = rememberLazyListState()
    val gson = remember { Gson() }

    LaunchedEffect(Unit) {
        runCatching { ApiClient.organize.thread(chatAuth(), conversationId, rootId).data }.getOrNull()?.let { page ->
            root = page.root
            replies.clear()
            replies.addAll(page.messages)
        }
    }
    // New replies arrive live.
    LaunchedEffect(Unit) {
        ChatRealtime.events.collect { e ->
            if (e.name != "chat.message.sent" || e.data.get("conversation_id")?.asString != conversationId) return@collect
            val dto = runCatching { gson.fromJson(e.data.getAsJsonObject("message"), MessageDto::class.java) }.getOrNull() ?: return@collect
            if (dto.threadRoot == rootId && replies.none { it.id == dto.id }) replies.add(dto)
        }
    }
    LaunchedEffect(replies.size) { if (replies.isNotEmpty()) list.animateScrollToItem(replies.size) }

    ChPage(stringResource(R.string.ch_thread), onBack = { host.pop() }) {
        Column(Modifier.fillMaxSize().imePadding()) {
            LazyColumn(Modifier.weight(1f), state = list, contentPadding = PaddingValues(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                root?.let { r ->
                    item(key = "root") {
                        Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(Ch.Red.copy(alpha = 0.07f)).padding(14.dp)) {
                            Text(r.sender?.name.orEmpty(), color = Ch.Red, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                            Text(r.body ?: stringResource(R.string.ch_attachment), color = Ch.Ink, fontSize = 15.sp)
                            Text(clockTime(r.createdAt), color = Ch.Soft, fontSize = 11.sp, modifier = Modifier.align(Alignment.End))
                        }
                    }
                    item(key = "count") {
                        Text(stringResource(R.string.ch_thread_replies, replies.size), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(vertical = 4.dp))
                    }
                }
                items(replies, key = { it.id }) { m -> ThreadReply(m) }
            }
            Row(Modifier.fillMaxWidth().background(Ch.Surface).padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.weight(1f)) { ChField(text, { text = it }, stringResource(R.string.ch_thread_reply_hint), singleLine = false, maxLines = 4) }
                Spacer(Modifier.width(8.dp))
                Box(
                    Modifier.size(46.dp).clip(CircleShape).background(if (text.isBlank() || sending) Ch.Soft else Ch.Red)
                        .clickable(enabled = text.isNotBlank() && !sending) {
                            val body = text.trim()
                            sending = true
                            scope.launch {
                                runCatching { ApiClient.chat.send(chatAuth(), conversationId, mapOf("type" to "text", "body" to body, "thread" to rootId)).data }
                                    .onSuccess { sent -> text = ""; sent?.let { if (replies.none { r -> r.id == it.id }) replies.add(it) } }
                                    .onFailure { host.showToast(it.apiFailure().message ?: "") }
                                sending = false
                            }
                        },
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.AutoMirrored.Rounded.Send, null, tint = Color.White, modifier = Modifier.size(22.dp)) }
            }
        }
    }
}

@Composable
private fun ThreadReply(m: MessageDto) {
    val mine = m.sender?.key == myKey()
    Row(Modifier.fillMaxWidth(), horizontalArrangement = if (mine) Arrangement.End else Arrangement.Start) {
        if (!mine) {
            ChAvatar(m.sender?.avatar, m.sender?.name, m.sender?.key, size = 30.dp)
            Spacer(Modifier.width(6.dp))
        }
        Column(
            Modifier.widthIn(max = 290.dp).clip(RoundedCornerShape(18.dp)).background(if (mine) Ch.Red else Ch.Surface).padding(horizontal = 12.dp, vertical = 8.dp),
        ) {
            if (!mine) Text(m.sender?.name.orEmpty(), color = Ch.Red, fontSize = 12.sp, fontWeight = FontWeight.Bold)
            Text(m.body ?: stringResource(R.string.ch_attachment), color = if (mine) Color.White else Ch.Ink, fontSize = 14.5.sp)
            Text(clockTime(m.createdAt), color = if (mine) Color.White.copy(alpha = 0.7f) else Ch.Soft, fontSize = 10.5.sp, modifier = Modifier.align(Alignment.End))
        }
    }
}

// =============================================================================== decisions

/** "Make it a decision": a vote answering this message (agree / disagree), in a group. */
internal fun ChatHost.makeDecision(message: MessageDto, done: String) {
    scope.launch {
        runCatching { ApiClient.organize.makeDecision(chatAuth(), message.id, JsonObject()) }
            .onSuccess { showToast(done) }
            .onFailure { showToast(it.apiFailure().message ?: "") }
    }
}

/** The group's decisions log: approved ones with their outcome and date, open ones with their votes. */
@Composable
fun DecisionsPage(conversationId: String, isAdmin: Boolean) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var rows by remember { mutableStateOf<List<DecisionDto>?>(null) }
    suspend fun load() { rows = runCatching { ApiClient.organize.decisions(chatAuth(), conversationId).data }.getOrNull().orEmpty() }
    LaunchedEffect(Unit) { load() }

    ChPage(stringResource(R.string.ch_decisions), onBack = { host.pop() }) {
        val list = rows
        when {
            list == null -> Column(Modifier.padding(14.dp)) { repeat(3) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(110.dp).padding(bottom = 10.dp), RoundedCornerShape(20.dp)) } }
            list.isEmpty() -> ChEmptyState(Icons.Rounded.Gavel, stringResource(R.string.ch_decisions_empty), stringResource(R.string.ch_decisions_empty_text))
            else -> LazyColumn(contentPadding = PaddingValues(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                itemsIndexed(list, key = { _, d -> d.id }) { i, d ->
                    // A tap opens its room: arguments for and against (spec 153).
                    Box(Modifier.clickable { host.push(ChRoute.DecisionRoom(d.id)) }) { DecisionCard(d, i, isAdmin) { approve ->
                        scope.launch {
                            runCatching { ApiClient.organize.decide(chatAuth(), d.id, JsonObject().apply { addProperty("approve", approve) }) }
                                .onFailure { host.showToast(it.apiFailure().message ?: "") }
                            load()
                        }
                    } }
                }
            }
        }
    }
}

@Composable
private fun DecisionCard(d: DecisionDto, index: Int, isAdmin: Boolean, onDecide: (Boolean) -> Unit) {
    val (tint, label) = when (d.status) {
        "approved" -> Color(0xFF16A34A) to stringResource(R.string.ch_decision_approved)
        "rejected" -> Ch.Danger to stringResource(R.string.ch_decision_rejected)
        else -> Color(0xFFF59E0B) to stringResource(R.string.ch_decision_open)
    }
    Column(Modifier.fillMaxWidth().chStagger(index).clip(RoundedCornerShape(20.dp)).background(Ch.Surface).padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(if (d.status == "open") Icons.Rounded.HowToVote else Icons.Rounded.Gavel, null, tint = tint, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(8.dp))
            Text(label, color = tint, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
            Text(listTime(d.decidedAt ?: d.createdAt), color = Ch.Soft, fontSize = 11.5.sp)
        }
        Spacer(Modifier.height(6.dp))
        Text(d.title, color = Ch.Ink, fontSize = 15.sp, fontWeight = FontWeight.Bold)
        d.outcome?.let { Text("→ $it", color = tint, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 4.dp)) }
        if (d.options.isNotEmpty()) {
            Spacer(Modifier.height(8.dp))
            val total = d.options.sumOf { it.votes }.coerceAtLeast(1)
            d.options.forEach { o ->
                Row(Modifier.fillMaxWidth().padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(o.text, color = Ch.Ink, fontSize = 13.sp, modifier = Modifier.width(110.dp), maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Box(Modifier.weight(1f).height(8.dp).clip(RoundedCornerShape(4.dp)).background(Ch.SurfaceMuted)) {
                        Box(Modifier.fillMaxWidth(o.votes / total.toFloat()).height(8.dp).clip(RoundedCornerShape(4.dp)).background(tint))
                    }
                    Text(" ${o.votes}", color = Ch.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
        val who = d.decidedBy?.name ?: d.createdBy?.name
        who?.let { Text(stringResource(if (d.decidedBy != null) R.string.ch_decision_by else R.string.ch_decision_proposed_by, it), color = Ch.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 6.dp)) }
        if (isAdmin && d.status == "open") {
            Spacer(Modifier.height(10.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                ChPrimaryButton(stringResource(R.string.ch_decision_approve), modifier = Modifier.weight(1f), icon = Icons.Rounded.Check) { onDecide(true) }
                Box(
                    Modifier.weight(1f).height(46.dp).clip(RoundedCornerShape(16.dp)).background(Ch.Danger.copy(alpha = 0.1f)).clickable { onDecide(false) },
                    contentAlignment = Alignment.Center,
                ) { Text(stringResource(R.string.ch_decision_reject), color = Ch.Danger, fontWeight = FontWeight.ExtraBold) }
            }
        }
    }
}

// =============================================================================== circles

/** Which circle a chat sits in (or none) — and a new circle from here. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CirclePickerSheet(conversation: ConversationDto, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var creating by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { host.refreshCircles() }

    fun assign(id: String?) {
        onDismiss()
        host.scope.launch {
            runCatching { ApiClient.organize.setCircle(chatAuth(), conversation.id, JsonObject().apply { if (id == null) add("circle_id", JsonNull.INSTANCE) else addProperty("circle_id", id) }).data }
                .onSuccess { updated ->
                    updated?.let { host.upsert(it) }
                    // A circle that hides its chats takes this one out of the main list.
                    if (updated?.circle?.hideFromList == true && !host.filter.startsWith("circle:")) host.remove(conversation.id)
                    host.refreshCircles()
                }
                .onFailure { host.showToast(it.apiFailure().message ?: "") }
        }
    }

    if (creating) {
        CircleEditorSheet(null, onDismiss = { creating = false; onDismiss() }) { created -> created?.let { assign(it.id) } }
        return
    }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(R.string.ch_circle_pick), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.ch_circle_pick_sub), color = Ch.Mut, fontSize = 13.sp)
            Spacer(Modifier.height(12.dp))
            host.circles.forEach { c ->
                CircleRow(c, selected = conversation.circle?.id == c.id) { assign(c.id) }
            }
            if (conversation.circle != null) CircleRowPlain(Icons.Rounded.Close, stringResource(R.string.ch_circle_none)) { assign(null) }
            CircleRowPlain(Icons.Rounded.Add, stringResource(R.string.ch_circle_new)) { creating = true }
        }
    }
}

@Composable
private fun CircleRow(c: CircleDto, selected: Boolean, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).clickable(onClick = onClick).padding(vertical = 10.dp, horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(40.dp).clip(CircleShape).background(Ch.Red.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) { Text(c.emoji ?: "⭕", fontSize = 19.sp) }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(c.name, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
                if (c.locked) { Spacer(Modifier.width(4.dp)); Icon(Icons.Rounded.Lock, null, tint = Ch.Mut, modifier = Modifier.size(14.dp)) }
                if (c.hideFromList) { Spacer(Modifier.width(4.dp)); Icon(Icons.Rounded.VisibilityOff, null, tint = Ch.Mut, modifier = Modifier.size(14.dp)) }
            }
            Text(disclosureLabel(c.disclosure), color = Ch.Mut, fontSize = 12.sp)
        }
        if (selected) Icon(Icons.Rounded.CheckCircle, null, tint = Ch.Red)
    }
}

@Composable
private fun CircleRowPlain(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).clickable(onClick = onClick).padding(vertical = 12.dp, horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(40.dp).clip(CircleShape).background(Ch.SurfaceMuted), contentAlignment = Alignment.Center) { Icon(icon, null, tint = Ch.Mut) }
        Spacer(Modifier.width(12.dp))
        Text(label, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
    }
}

/** P0 … P4, in words. */
@Composable
internal fun disclosureLabel(level: String): String = stringResource(
    when (level) {
        "all" -> R.string.ch_disclosure_all
        "name" -> R.string.ch_disclosure_name
        "none" -> R.string.ch_disclosure_none
        "hidden" -> R.string.ch_disclosure_hidden
        else -> R.string.ch_disclosure_circle
    },
)

/** A circle's settings: name, stand-in name and emoji, what its notifications show, hide, lock. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CircleEditorSheet(circle: CircleDto?, onDismiss: () -> Unit, onSaved: (CircleDto?) -> Unit) {
    val host = LocalChat.current
    var name by remember { mutableStateOf(circle?.name.orEmpty()) }
    var masked by remember { mutableStateOf(circle?.maskedName.orEmpty()) }
    var emoji by remember { mutableStateOf(circle?.emoji ?: "⭐") }
    var disclosure by remember { mutableStateOf(circle?.disclosure ?: "circle") }
    var hide by remember { mutableStateOf(circle?.hideFromList ?: true) }
    var locked by remember { mutableStateOf(circle?.locked ?: false) }
    var busy by remember { mutableStateOf(false) }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(if (circle == null) R.string.ch_circle_new else R.string.ch_circle_edit), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.ch_circle_sub), color = Ch.Mut, fontSize = 13.sp)
            Spacer(Modifier.height(12.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.width(70.dp)) { ChField(emoji, { emoji = it.take(4) }, "⭐") }
                Spacer(Modifier.width(8.dp))
                Box(Modifier.weight(1f)) { ChField(name, { name = it.take(60) }, stringResource(R.string.ch_circle_name)) }
            }
            Spacer(Modifier.height(8.dp))
            ChField(masked, { masked = it.take(60) }, stringResource(R.string.ch_circle_masked))
            Text(stringResource(R.string.ch_circle_masked_sub), color = Ch.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(start = 6.dp, top = 2.dp))
            Spacer(Modifier.height(12.dp))
            Text(stringResource(R.string.ch_circle_notifications), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
            listOf("all", "name", "circle", "none", "hidden").forEach { level ->
                val on = disclosure == level
                val bg by animateColorAsState(if (on) Ch.Red.copy(alpha = 0.1f) else Color.Transparent, label = "level")
                Row(Modifier.fillMaxWidth().padding(top = 4.dp).clip(RoundedCornerShape(14.dp)).background(bg).clickable { disclosure = level }.padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text("P" + listOf("all", "name", "circle", "none", "hidden").indexOf(level), color = if (on) Ch.Red else Ch.Soft, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp, modifier = Modifier.width(30.dp))
                    Text(disclosureLabel(level), color = Ch.Ink, fontSize = 14.sp, modifier = Modifier.weight(1f))
                    if (on) Icon(Icons.Rounded.CheckCircle, null, tint = Ch.Red, modifier = Modifier.size(20.dp))
                }
            }
            Spacer(Modifier.height(8.dp))
            ToggleRow(Icons.Rounded.VisibilityOff, stringResource(R.string.ch_circle_hide), hide, subtitle = stringResource(R.string.ch_circle_hide_sub)) { hide = it }
            ToggleRow(Icons.Rounded.Lock, stringResource(R.string.ch_circle_lock), locked, subtitle = stringResource(R.string.ch_circle_lock_sub)) { locked = it }
            Spacer(Modifier.height(14.dp))
            ChPrimaryButton(stringResource(R.string.ch_save), modifier = Modifier.fillMaxWidth(), enabled = name.isNotBlank() && !busy) {
                busy = true
                val body = JsonObject().apply {
                    addProperty("name", name.trim())
                    addProperty("masked_name", masked.trim())
                    addProperty("emoji", emoji.trim())
                    addProperty("disclosure", disclosure)
                    addProperty("hide_from_list", hide)
                    addProperty("locked", locked)
                }
                host.scope.launch {
                    runCatching { if (circle == null) ApiClient.organize.createCircle(chatAuth(), body).data else ApiClient.organize.updateCircle(chatAuth(), circle.id, body).data }
                        .onSuccess { saved -> host.refreshCircles(); host.refreshList(); onSaved(saved); onDismiss() }
                        .onFailure { host.showToast(it.apiFailure().message ?: ""); busy = false }
                }
            }
            if (circle != null) {
                Spacer(Modifier.height(6.dp))
                Text(
                    stringResource(R.string.ch_circle_delete), color = Ch.Danger, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable {
                        host.scope.launch {
                            runCatching { ApiClient.organize.deleteCircle(chatAuth(), circle.id) }
                            if (host.filter == "circle:${circle.id}") host.filter = "all"
                            host.refreshCircles()
                            host.refreshList()
                            onSaved(null)
                            onDismiss()
                        }
                    }.padding(12.dp),
                )
            }
        }
    }
}

// =============================================================================== broadcast lists

/** My broadcast lists (like WhatsApp's) and a new one. */
@Composable
fun BroadcastsPage() {
    val host = LocalChat.current
    var lists by remember { mutableStateOf<List<BroadcastDto>?>(null) }
    var creating by remember { mutableStateOf(false) }
    suspend fun load() { lists = runCatching { ApiClient.organize.broadcasts(chatAuth()).data }.getOrNull().orEmpty() }
    LaunchedEffect(Unit) { load() }

    ChPage(stringResource(R.string.ch_broadcasts), onBack = { host.pop() }) {
        LazyColumn(contentPadding = PaddingValues(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxSize()) {
            item {
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(24.dp)).background(Ch.HeaderBrush).clickable { creating = true }.padding(16.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Box(Modifier.size(52.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.18f)), contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.Campaign, null, tint = Color.White, modifier = Modifier.size(28.dp))
                    }
                    Spacer(Modifier.width(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(R.string.ch_broadcast_new), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
                        Text(stringResource(R.string.ch_broadcast_hint), color = Color.White.copy(alpha = 0.85f), fontSize = 12.5.sp)
                    }
                    Icon(Icons.Rounded.Add, null, tint = Color.White)
                }
            }
            val items = lists
            when {
                items == null -> items(3) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(70.dp), RoundedCornerShape(20.dp)) }
                items.isEmpty() -> item { Text(stringResource(R.string.ch_broadcast_none), color = Ch.Mut, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(30.dp)) }
                else -> itemsIndexed(items, key = { _, b -> b.id }) { i, b ->
                    Row(
                        Modifier.fillMaxWidth().chStagger(i).clip(RoundedCornerShape(20.dp)).background(Ch.Surface).clickable { host.push(ChRoute.Broadcast(b.id)) }.padding(12.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Box(Modifier.size(48.dp).clip(CircleShape).background(Ch.Red.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.Campaign, null, tint = Ch.Red)
                        }
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text(b.title, color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text(b.lastSent?.body ?: stringResource(R.string.ch_broadcast_people, b.membersCount), color = Ch.Mut, fontSize = 12.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                        Text(listTime(b.lastSent?.at), color = Ch.Soft, fontSize = 11.5.sp)
                    }
                }
            }
        }
    }

    if (creating) PeoplePickerSheet(emptySet(), onDismiss = { creating = false }) { ids ->
        host.scope.launch {
            runCatching { ApiClient.organize.createBroadcast(chatAuth(), JsonObject().apply { add("members", Gson().toJsonTree(ids)) }).data }
                .onSuccess { created -> created?.let { host.push(ChRoute.Broadcast(it.id)) } }
                .onFailure { host.showToast(it.apiFailure().message ?: "") }
        }
    }
}

/** One broadcast list: what I sent to it, and a box to send the next one (text or a photo). */
@Composable
fun BroadcastPage(id: String) {
    val host = LocalChat.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var list by remember { mutableStateOf<BroadcastDto?>(null) }
    var text by remember { mutableStateOf("") }
    var photo by remember { mutableStateOf<Uri?>(null) }
    var sending by remember { mutableStateOf(false) }
    var editingPeople by remember { mutableStateOf(false) }
    var renaming by remember { mutableStateOf(false) }
    var confirmDelete by remember { mutableStateOf(false) }
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri -> if (uri != null) photo = uri }
    suspend fun load() { list = runCatching { ApiClient.organize.broadcast(chatAuth(), id).data }.getOrNull() }
    LaunchedEffect(Unit) { load() }
    val skippedText = stringResource(R.string.ch_broadcast_skipped)

    ChPage(list?.title ?: stringResource(R.string.ch_broadcasts), onBack = { host.pop() }, actions = {
        GlassIcon(Icons.Rounded.People) { editingPeople = true }
        Spacer(Modifier.width(6.dp))
        GlassIcon(Icons.Rounded.Edit) { renaming = true }
        Spacer(Modifier.width(6.dp))
        GlassIcon(Icons.Rounded.Delete) { confirmDelete = true }
    }) {
        Column(Modifier.fillMaxSize().imePadding()) {
            LazyColumn(Modifier.weight(1f), contentPadding = PaddingValues(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                item {
                    Text(
                        stringResource(R.string.ch_broadcast_rule, list?.membersCount ?: 0), color = Ch.Mut, fontSize = 12.5.sp, textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Ch.SurfaceMuted).padding(10.dp),
                    )
                }
                items(list?.sent.orEmpty(), key = { it.id }) { s ->
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
                        Column(Modifier.widthIn(max = 290.dp).clip(RoundedCornerShape(18.dp)).background(Ch.Red).padding(10.dp)) {
                            s.thumbnail?.let { AsyncImage(it, null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxWidth().height(160.dp).clip(RoundedCornerShape(12.dp))) }
                            s.body?.takeIf { it.isNotBlank() }?.let { Text(it, color = Color.White, fontSize = 14.5.sp, modifier = Modifier.padding(top = 4.dp)) }
                            Text(
                                stringResource(R.string.ch_broadcast_sent_to, s.sentCount) + (if (s.skippedCount > 0) " · " + stringResource(R.string.ch_broadcast_not_reached, s.skippedCount) else "") + "  " + clockTime(s.createdAt),
                                color = Color.White.copy(alpha = 0.75f), fontSize = 10.5.sp, modifier = Modifier.align(Alignment.End).padding(top = 4.dp),
                            )
                        }
                    }
                }
            }
            photo?.let { p ->
                Row(Modifier.fillMaxWidth().background(Ch.Surface).padding(horizontal = 12.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    AsyncImage(p, null, contentScale = ContentScale.Crop, modifier = Modifier.size(54.dp).clip(RoundedCornerShape(10.dp)))
                    Spacer(Modifier.weight(1f))
                    GlassIcon(Icons.Rounded.Close, size = 32.dp) { photo = null }
                }
            }
            Row(Modifier.fillMaxWidth().background(Ch.Surface).padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(42.dp).clip(CircleShape).background(Ch.SurfaceMuted).clickable { picker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }, contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.AddPhotoAlternate, null, tint = Ch.Mut)
                }
                Spacer(Modifier.width(8.dp))
                Box(Modifier.weight(1f)) { ChField(text, { text = it }, stringResource(R.string.ch_broadcast_type), singleLine = false, maxLines = 5) }
                Spacer(Modifier.width(8.dp))
                val canSend = (text.isNotBlank() || photo != null) && !sending
                Box(
                    Modifier.size(46.dp).clip(CircleShape).background(if (canSend) Ch.Red else Ch.Soft).clickable(enabled = canSend) {
                        sending = true
                        scope.launch {
                            try {
                                val plain = "text/plain".toMediaTypeOrNull()
                                val image = photo
                                val fields = mutableMapOf<String, RequestBody>(
                                    "type" to (if (image != null) "image" else "text").toRequestBody(plain),
                                    "body" to text.trim().toRequestBody(plain),
                                )
                                val parts = image?.let { copyToCache(context, it, "photo.jpg") }?.let { compressImage(context, it) }?.let { f ->
                                    listOf(MultipartBody.Part.createFormData("files[]", f.name, f.file.asRequestBody(f.mime.toMediaTypeOrNull())))
                                }.orEmpty()
                                val result = ApiClient.organize.sendBroadcast(chatAuth(), id, fields, parts).data
                                text = ""
                                photo = null
                                load()
                                result?.let { r ->
                                    host.showToast(context.getString(R.string.ch_broadcast_sent_to, r.sent) + if (r.skipped.isNotEmpty()) " · " + skippedText.format(r.skipped.joinToString("، ") { it.name.orEmpty() }) else "")
                                }
                            } catch (e: Exception) {
                                host.showToast(e.apiFailure().message ?: "")
                            }
                            sending = false
                        }
                    },
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.AutoMirrored.Rounded.Send, null, tint = Color.White, modifier = Modifier.size(22.dp)) }
            }
        }
    }

    if (editingPeople) PeoplePickerSheet(list?.members.orEmpty().map { it.id }.toSet(), onDismiss = { editingPeople = false }) { ids ->
        scope.launch {
            runCatching { ApiClient.organize.updateBroadcast(chatAuth(), id, JsonObject().apply { add("members", Gson().toJsonTree(ids)) }).data }
                .onSuccess { list = it }.onFailure { host.showToast(it.apiFailure().message ?: "") }
        }
    }
    if (renaming) TextInputSheet(stringResource(R.string.ch_broadcast_name), initial = list?.name.orEmpty(), action = stringResource(R.string.ch_save), onDismiss = { renaming = false }) { value ->
        scope.launch {
            runCatching { ApiClient.organize.updateBroadcast(chatAuth(), id, JsonObject().apply { addProperty("name", value.trim()) }).data }
                .onSuccess { list = it }.onFailure { host.showToast(it.apiFailure().message ?: "") }
        }
    }
    if (confirmDelete) ChoiceSheet(stringResource(R.string.ch_broadcast_delete), listOf(stringResource(R.string.ch_broadcast_delete) to {
        scope.launch { runCatching { ApiClient.organize.deleteBroadcast(chatAuth(), id) }; host.pop() }
        Unit
    }), danger = true, onDismiss = { confirmDelete = false })
}

/** Pick people from my contacts on Dorr (search, tick, done). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun PeoplePickerSheet(initial: Set<Int>, onDismiss: () -> Unit, onDone: (List<Int>) -> Unit) {
    var contacts by remember { mutableStateOf<List<ContactDto>?>(null) }
    var query by remember { mutableStateOf("") }
    val picked = remember { mutableStateListOf<Int>().apply { addAll(initial) } }
    LaunchedEffect(Unit) { contacts = runCatching { ApiClient.chat.contacts(chatAuth(), registered = 1).data }.getOrNull().orEmpty() }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 20.dp)) {
            Text(stringResource(R.string.ch_broadcast_pick), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(8.dp))
            ChField(query, { query = it }, stringResource(R.string.ch_search_contacts), icon = Icons.Rounded.Search)
            Spacer(Modifier.height(8.dp))
            val shown = contacts.orEmpty().filter { c -> c.profile != null && (query.isBlank() || c.name.contains(query.trim(), ignoreCase = true) || c.phone.contains(query.trim())) }
            LazyColumn(Modifier.height(380.dp)) {
                items(shown, key = { it.id }) { c ->
                    val uid = c.profile?.id ?: return@items
                    val on = uid in picked
                    Row(Modifier.fillMaxWidth().clickable { if (on) picked.remove(uid) else picked.add(uid) }.padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                        ChAvatar(c.profile.avatar, c.name, c.profile.key, size = 42.dp)
                        Spacer(Modifier.width(10.dp))
                        Text(c.name, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp, modifier = Modifier.weight(1f))
                        Box(Modifier.size(24.dp).clip(CircleShape).background(if (on) Ch.Red else Ch.SurfaceMuted), contentAlignment = Alignment.Center) {
                            if (on) Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(16.dp))
                        }
                    }
                }
            }
            Spacer(Modifier.height(10.dp))
            ChPrimaryButton(stringResource(R.string.ch_broadcast_done, picked.size), modifier = Modifier.fillMaxWidth(), enabled = picked.isNotEmpty()) {
                onDone(picked.toList())
                onDismiss()
            }
        }
    }
}
