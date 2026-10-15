package com.dorr.app.ui.screens.chat

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateContentSize
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Alarm
import androidx.compose.material.icons.rounded.AssignmentInd
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.EventAvailable
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.Handshake
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.PushPin
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material.icons.rounded.TransferWithinAStation
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AgreementDto
import com.dorr.app.network.AgreementTermDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.LifeCommitmentDto
import com.dorr.app.network.HandoffDto
import com.dorr.app.network.LifePersonDto
import com.dorr.app.network.MessageDto
import com.dorr.app.network.apiFailure
import com.google.gson.Gson
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.ZoneId
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter

/*
 * Daily intelligence in the chat, batch A (spec 148, 149, 152, 154): "turn it into…" on any
 * message, and the three cards it makes — a commitment (who does what, by when), a handoff (taken
 * on by the one it's handed to), an agreement (terms every party approves; a record only). Nothing
 * happens before the right person taps (AT-LIFE-01); the card follows every answer live.
 */

private val gson = Gson()

private val LifeAmber = Color(0xFFF59E0B)
private val LifeBlue = Color(0xFF2563EB)
private val LifeGreen = Color(0xFF16A34A)
private val LifePurple = Color(0xFF7C3AED)

internal fun lifeWhen(iso: String?): String? = iso?.let {
    runCatching { Instant.parse(it).atZone(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("EEE d MMM · HH:mm", com.dorr.app.network.AppLocale.java())) }.getOrNull()
}

@Composable
private fun statusText(status: String): Pair<String, Color> = when (status) {
    "proposed", "pending" -> stringResource(R.string.lf_st_waiting) to LifeAmber
    "accepted" -> stringResource(R.string.lf_st_accepted) to LifeBlue
    "agreed" -> stringResource(R.string.lf_st_agreed) to LifeGreen
    "done" -> stringResource(R.string.lf_st_done) to LifeGreen
    "declined" -> stringResource(R.string.lf_st_declined) to Ch.Danger
    else -> stringResource(R.string.lf_st_cancelled) to Ch.Soft
}

@Composable
private fun StatusPill(status: String) {
    val (label, color) = statusText(status)
    Text(label, color = color, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.clip(CircleShape).background(color.copy(alpha = 0.12f)).padding(horizontal = 9.dp, vertical = 3.dp))
}

@Composable
private fun CardHeader(emoji: String, title: String, color: Color, status: String) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(30.dp).clip(RoundedCornerShape(10.dp)).background(color.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) { Text(emoji, fontSize = 15.sp) }
        Spacer(Modifier.width(8.dp))
        Text(title, color = color, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
        StatusPill(status)
    }
}

@Composable
private fun PersonLine(label: String, p: LifePersonDto?) {
    if (p == null) return
    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(top = 6.dp)) {
        ChAvatar(p.avatar, p.name, p.key, size = 22.dp)
        Spacer(Modifier.width(6.dp))
        Text(label, color = Ch.Mut, fontSize = 12.sp)
        Spacer(Modifier.width(4.dp))
        Text(if (p.key == myKey()) stringResource(R.string.lf_you) else p.name.orEmpty(), color = Ch.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

@Composable
private fun CardButton(text: String, color: Color, filled: Boolean, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Text(
        text, color = if (filled) Color.White else color, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center, maxLines = 1,
        modifier = modifier.clip(RoundedCornerShape(12.dp)).background(if (filled) color else color.copy(alpha = 0.1f)).clickable(onClick = onClick).padding(vertical = 9.dp, horizontal = 10.dp),
    )
}

@Composable
private fun SourceLink(preview: String?, id: String?, onJump: (String) -> Unit) {
    if (id == null) return
    Row(
        Modifier.fillMaxWidth().padding(top = 8.dp).clip(RoundedCornerShape(10.dp)).background(Ch.SurfaceMuted).clickable { onJump(id) }.padding(horizontal = 9.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.width(3.dp).height(16.dp).clip(CircleShape).background(Ch.Red))
        Spacer(Modifier.width(7.dp))
        Text(preview ?: stringResource(R.string.lf_source), color = Ch.Mut, fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
        Text("↗", color = Ch.Red, fontSize = 12.sp, fontWeight = FontWeight.Bold)
    }
}

/** The card of a commitment, a handoff or an agreement, in the chat. */
@Composable
internal fun LifeCardBubble(dto: MessageDto, onJump: (String) -> Unit, footer: @Composable () -> Unit) {
    val meta = dto.meta ?: return
    val kind = meta.get("kind")?.takeIf { !it.isJsonNull }?.asString ?: dto.type
    Column(Modifier.widthIn(min = 250.dp).padding(12.dp)) {
        when (kind) {
            "commitment" -> CommitmentCard(gson.fromJson(meta, LifeCommitmentDto::class.java), onJump)
            "handoff" -> HandoffCard(gson.fromJson(meta, HandoffDto::class.java), onJump)
            "agreement" -> AgreementCard(gson.fromJson(meta, AgreementDto::class.java), dto.conversationId, onJump)
        }
        footer()
    }
}

@Composable
private fun CommitmentCard(initial: LifeCommitmentDto, onJump: (String) -> Unit) {
    val host = LocalChat.current
    var c by remember(initial) { mutableStateOf(initial) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val me = myKey()
    fun act(call: suspend () -> LifeCommitmentDto?) {
        if (busy) return
        busy = true
        scope.launch { runCatching { call() }.onSuccess { r -> r?.let { c = it } }.onFailure { host.showToast(it.apiFailure().message ?: "") }; busy = false }
    }
    CardHeader("📌", stringResource(R.string.lf_commitment), LifeAmber, c.status)
    Text(c.title.orEmpty(), color = Ch.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 8.dp))
    PersonLine(stringResource(R.string.lf_on), c.assignee)
    lifeWhen(c.dueAt)?.let { Text("🗓️ $it", color = Ch.Mut, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp)) }
    SourceLink(c.source?.preview, c.source?.id, onJump)
    val assignee = c.assignee?.key == me
    val creator = c.by?.key == me
    Row(Modifier.fillMaxWidth().padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        if (assignee && c.status == "proposed") {
            CardButton(stringResource(R.string.lf_accept), LifeGreen, true, Modifier.weight(1f)) { act { ApiClient.life.respond(chatAuth(), c.id, JsonObject().apply { addProperty("accept", true) }).data } }
            CardButton(stringResource(R.string.lf_decline), Ch.Danger, false, Modifier.weight(1f)) { act { ApiClient.life.respond(chatAuth(), c.id, JsonObject().apply { addProperty("accept", false) }).data } }
        }
        if (assignee && c.status == "accepted") CardButton(stringResource(R.string.lf_mark_done), LifeGreen, true, Modifier.weight(1f)) { act { ApiClient.life.done(chatAuth(), c.id).data } }
        if (creator && c.status in setOf("proposed", "accepted") && !assignee) CardButton(stringResource(R.string.lf_cancel), Ch.Mut, false, Modifier.weight(1f)) { act { ApiClient.life.cancel(chatAuth(), c.id).data } }
    }
}

@Composable
private fun HandoffCard(initial: HandoffDto, onJump: (String) -> Unit) {
    val host = LocalChat.current
    var h by remember(initial) { mutableStateOf(initial) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val me = myKey()
    fun act(action: String) {
        if (busy) return
        busy = true
        scope.launch { runCatching { ApiClient.life.moveHandoff(chatAuth(), h.id, action).data }.onSuccess { r -> r?.let { h = it } }.onFailure { host.showToast(it.apiFailure().message ?: "") }; busy = false }
    }
    CardHeader("🤝", stringResource(R.string.lf_handoff), LifeBlue, h.status)
    PersonLine(stringResource(R.string.lf_from), h.from)
    PersonLine(stringResource(R.string.lf_to), h.to)
    h.note?.let { Text("“$it”", color = Ch.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 8.dp)) }
    SourceLink(h.source?.preview, h.source?.id, onJump)
    val receiver = h.to?.key == me
    Row(Modifier.fillMaxWidth().padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        if (receiver && h.status == "pending") {
            CardButton(stringResource(R.string.lf_take_it), LifeGreen, true, Modifier.weight(1f)) { act("accept") }
            CardButton(stringResource(R.string.lf_hand_back), Ch.Danger, false, Modifier.weight(1f)) { act("decline") }
        }
        if (receiver && h.status == "accepted") CardButton(stringResource(R.string.lf_mark_done), LifeGreen, true, Modifier.weight(1f)) { act("done") }
        if (h.from?.key == me && h.status == "pending") CardButton(stringResource(R.string.lf_take_back), Ch.Mut, false, Modifier.weight(1f)) { act("cancel") }
    }
}

@Composable
private fun AgreementCard(initial: AgreementDto, conversationId: String, onJump: (String) -> Unit) {
    val host = LocalChat.current
    var a by remember(initial) { mutableStateOf(initial) }
    var busy by remember { mutableStateOf(false) }
    var editing by remember { mutableStateOf(false) }
    var history by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val me = myKey()
    fun act(call: suspend () -> AgreementDto?) {
        if (busy) return
        busy = true
        scope.launch { runCatching { call() }.onSuccess { r -> r?.let { a = it } }.onFailure { host.showToast(it.apiFailure().message ?: "") }; busy = false }
    }
    CardHeader("📝", stringResource(R.string.lf_agreement) + if (a.version > 1) " · v${a.version}" else "", LifePurple, a.status)
    Text(a.title.orEmpty(), color = Ch.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 8.dp))
    Column(Modifier.fillMaxWidth().padding(top = 8.dp).clip(RoundedCornerShape(12.dp)).background(Ch.SurfaceMuted).padding(10.dp)) {
        a.terms.forEach { t ->
            Row(Modifier.fillMaxWidth().padding(vertical = 3.dp)) {
                Text(t.label, color = Ch.Mut, fontSize = 12.sp, modifier = Modifier.weight(0.9f))
                Text(t.value, color = Ch.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1.4f))
            }
        }
    }
    // Who agreed to this version.
    FlowRowParties(a)
    Text(stringResource(R.string.lf_record_only), color = Ch.Soft, fontSize = 10.5.sp, modifier = Modifier.padding(top = 6.dp))
    SourceLink(a.source?.preview, a.source?.id, onJump)
    val myParty = a.parties.firstOrNull { it.key == me }
    Row(Modifier.fillMaxWidth().padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        if (myParty != null && a.status == "proposed" && myParty.decision != "accepted") {
            CardButton(stringResource(R.string.lf_agree), LifeGreen, true, Modifier.weight(1f)) { act { ApiClient.life.answer(chatAuth(), a.id, JsonObject().apply { addProperty("accept", true); addProperty("version", a.version) }).data } }
            CardButton(stringResource(R.string.lf_disagree), Ch.Danger, false, Modifier.weight(1f)) { act { ApiClient.life.answer(chatAuth(), a.id, JsonObject().apply { addProperty("accept", false); addProperty("version", a.version) }).data } }
        }
    }
    Row(Modifier.fillMaxWidth().padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        if (myParty != null && a.status in setOf("proposed", "declined")) CardButton(stringResource(R.string.lf_change_terms), LifePurple, false, Modifier.weight(1f)) { editing = true }
        CardButton(stringResource(R.string.lf_history), Ch.Mut, false, Modifier.weight(1f)) { history = true }
        if (a.by?.key == me && a.status != "cancelled") CardButton(stringResource(R.string.lf_cancel), Ch.Mut, false, Modifier.weight(1f)) { act { ApiClient.life.cancelAgreement(chatAuth(), a.id).data } }
    }
    if (editing) AgreementSheet(conversationId, members = emptyList(), existing = a, source = null, onDismiss = { editing = false }) { a = it }
    if (history) AgreementHistorySheet(a.id) { history = false }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun FlowRowParties(a: AgreementDto) {
    FlowRow(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        a.parties.forEach { p ->
            val (mark, color) = when (p.decision) { "accepted" -> "✓" to LifeGreen; "declined" -> "✕" to Ch.Danger; else -> "…" to LifeAmber }
            Row(Modifier.clip(CircleShape).background(color.copy(alpha = 0.1f)).padding(start = 3.dp, end = 9.dp, top = 3.dp, bottom = 3.dp), verticalAlignment = Alignment.CenterVertically) {
                ChAvatar(p.avatar, p.name, p.key, size = 20.dp)
                Spacer(Modifier.width(5.dp))
                Text((if (p.key == myKey()) stringResource(R.string.lf_you) else p.name.orEmpty()) + " $mark", color = color, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
            }
        }
    }
}

// ------------------------------------------------------------------ "turn it into…" (148)

private data class ActionTile(val icon: ImageVector, val label: Int, val color: Color, val key: String)

/** Every way a message becomes something to do — each keeps a link back to it. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ToActionSheet(state: ConversationState, message: MessageDto, onDismiss: () -> Unit, onReminder: () -> Unit) {
    val host = LocalChat.current
    val isGroup = state.conversation?.type == "group"
    var open by remember { mutableStateOf<String?>(null) }
    val decisionMade = stringResource(R.string.ch_decision_made)
    val taskAdded = stringResource(R.string.lf_task_added)
    val calendarAdded = stringResource(R.string.lf_calendar_added)
    val scope = rememberCoroutineScope()
    LaunchedEffect(isGroup) { if (isGroup) state.loadMembers() }
    val tiles = listOfNotNull(
        ActionTile(Icons.Rounded.PushPin, R.string.lf_commitment, LifeAmber, "commitment"),
        ActionTile(Icons.Rounded.TransferWithinAStation, R.string.lf_handoff, LifeBlue, "handoff"),
        ActionTile(Icons.Rounded.Handshake, R.string.lf_agreement, LifePurple, "agreement"),
        ActionTile(Icons.Rounded.TaskAlt, R.string.lf_task, LifeGreen, "task"),
        ActionTile(Icons.Rounded.EventAvailable, R.string.lf_appointment, Color(0xFF0891B2), "calendar"),
        ActionTile(Icons.Rounded.Alarm, R.string.ch_reminder, Color(0xFFEA580C), "reminder"),
        if (isGroup && message.type !in setOf("poll", "system")) ActionTile(Icons.Rounded.Gavel, R.string.ch_make_decision, Color(0xFF475569), "decision") else null,
    )
    when (open) {
        "commitment" -> { CommitmentSheet(state, message, onDismiss); return }
        "handoff" -> { HandoffSheet(state, message, onDismiss); return }
        "agreement" -> { AgreementSheet(state.id, state.members.map { LifePersonDto(it.participantId, it.profile?.key, it.profile?.name, it.profile?.avatar) }, null, message, onDismiss) {}; return }
        "calendar" -> {
            ScheduleSheet(onDismiss = onDismiss) { at ->
                scope.launch {
                    runCatching {
                        ApiClient.calendar.create(chatAuth(), JsonObject().apply {
                            addProperty("title", message.body?.take(160)?.ifBlank { null } ?: "📅")
                            addProperty("starts_at", at.toOffsetDateTime().toString())
                            addProperty("timezone", ZoneId.systemDefault().id)
                            addProperty("message_id", message.id)
                        })
                    }.onSuccess { host.showToast(calendarAdded) }.onFailure { host.showToast(it.apiFailure().message ?: "") }
                }
            }
            return
        }
    }
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(R.string.lf_turn_into), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.lf_turn_into_sub), color = Ch.Mut, fontSize = 12.5.sp)
            message.body?.takeIf { it.isNotBlank() }?.let { Text("“${it.take(120)}”", color = Ch.Ink, fontSize = 13.sp, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 8.dp)) }
            Spacer(Modifier.height(14.dp))
            tiles.chunked(3).forEach { row ->
                Row(Modifier.fillMaxWidth().padding(bottom = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    row.forEach { t ->
                        Column(
                            Modifier.weight(1f).clip(RoundedCornerShape(18.dp)).background(t.color.copy(alpha = 0.08f)).clickable {
                                when (t.key) {
                                    "reminder" -> { onDismiss(); onReminder() }
                                    "decision" -> { onDismiss(); host.makeDecision(message, decisionMade) }
                                    "task" -> {
                                        onDismiss()
                                        scope.launch {
                                            runCatching {
                                                ApiClient.aiTools.createTasks(chatAuth(), JsonObject().apply {
                                                    add("tasks", JsonArray().apply { add(JsonObject().apply { addProperty("text", message.body?.take(300)?.ifBlank { null } ?: "✅") }) })
                                                    addProperty("message_id", message.id)
                                                })
                                            }.onSuccess { host.showToast(taskAdded) }.onFailure { host.showToast(it.apiFailure().message ?: "") }
                                        }
                                    }
                                    else -> open = t.key
                                }
                            }.padding(vertical = 14.dp),
                            horizontalAlignment = Alignment.CenterHorizontally,
                        ) {
                            Icon(t.icon, null, tint = t.color, modifier = Modifier.size(24.dp))
                            Text(stringResource(t.label), color = Ch.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 6.dp))
                        }
                    }
                    repeat(3 - row.size) { Spacer(Modifier.weight(1f)) }
                }
            }
        }
    }
}

// ------------------------------------------------------------------ the three sheets

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
internal fun CommitmentSheet(state: ConversationState, message: MessageDto?, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    val isGroup = state.conversation?.type == "group"
    var title by remember { mutableStateOf(message?.body?.take(300).orEmpty()) }
    // null = me; a participant id otherwise. In a direct chat the other one is the default.
    var who by remember { mutableStateOf<Int?>(if (isGroup) null else -1) }
    var due by remember { mutableStateOf<ZonedDateTime?>(null) }
    var picking by remember { mutableStateOf(false) }
    var remind by remember { mutableIntStateOf(60) }
    var busy by remember { mutableStateOf(false) }
    val sent = stringResource(R.string.lf_commitment_sent)
    LaunchedEffect(isGroup) { if (isGroup) state.loadMembers() }
    if (picking) { ScheduleSheet(onDismiss = { picking = false }) { due = it }; return }

    ChSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text("📌 " + stringResource(R.string.lf_commitment), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.lf_commitment_sub), color = Ch.Mut, fontSize = 12.5.sp)
            Spacer(Modifier.height(12.dp))
            ChField(title, { title = it.take(300) }, stringResource(R.string.lf_what), singleLine = false, minLines = 2)
            Text(stringResource(R.string.lf_who), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 14.dp, bottom = 6.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Chip(stringResource(R.string.lf_me), who == null) { who = null }
                if (!isGroup) Chip(state.conversation?.peer?.name ?: stringResource(R.string.lf_them), who == -1) { who = -1 }
                state.members.filter { it.profile?.key != myKey() }.forEach { m -> Chip(m.profile?.name.orEmpty(), who == m.participantId) { who = m.participantId } }
            }
            Text(stringResource(R.string.lf_by_when), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 14.dp, bottom = 6.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Chip(due?.let { lifeWhen(it.toInstant().toString()) } ?: stringResource(R.string.lf_pick_time), due != null) { picking = true }
                if (due != null) { Spacer(Modifier.width(6.dp)); Chip("✕", false) { due = null } }
            }
            AnimatedVisibility(due != null) {
                Column {
                    Text(stringResource(R.string.lf_remind_before), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 14.dp, bottom = 6.dp))
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        listOf(0 to R.string.lf_no_reminder, 15 to R.string.lf_15m, 60 to R.string.lf_1h, 1440 to R.string.lf_1d).forEach { (m, l) -> Chip(stringResource(l), remind == m) { remind = m } }
                    }
                }
            }
            Text(stringResource(R.string.lf_commitment_note), color = Ch.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 12.dp))
            ChPrimaryButton(stringResource(R.string.lf_send), Modifier.fillMaxWidth().padding(top = 14.dp), enabled = title.isNotBlank() && !busy) {
                busy = true
                scope.launch {
                    val body = JsonObject().apply {
                        addProperty("title", title.trim())
                        when (val w = who) { null -> state.members.firstOrNull { it.profile?.key == myKey() }?.let { addProperty("assignee", it.participantId) }; -1 -> {}; else -> addProperty("assignee", w) }
                        due?.let { addProperty("due_at", it.toOffsetDateTime().toString()); if (remind > 0) addProperty("remind_minutes", remind) }
                    }
                    // In a direct chat, "me" means me: the server's default is the other one.
                    if (who == null && !isGroup) body.addProperty("assignee", myParticipantId(state) ?: 0)
                    runCatching { if (message != null) ApiClient.life.commitFromMessage(chatAuth(), message.id, body) else ApiClient.life.commit(chatAuth(), state.id, body) }
                        .onSuccess { host.showToast(sent); onDismiss() }
                        .onFailure { host.showToast(it.apiFailure().message ?: "") }
                    busy = false
                }
            }
        }
    }
}

/** My own participant id in this chat (members list or the conversation's own field). */
private suspend fun myParticipantId(state: ConversationState): Int? {
    state.members.firstOrNull { it.profile?.key == myKey() }?.let { return it.participantId }
    return runCatching { ApiClient.chat.members(chatAuth(), state.id).data }.getOrNull()?.firstOrNull { it.profile?.key == myKey() }?.participantId
}

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
internal fun HandoffSheet(state: ConversationState, message: MessageDto, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    val isGroup = state.conversation?.type == "group"
    var to by remember { mutableStateOf<Int?>(null) }
    var outside by remember { mutableStateOf<Int?>(null) }
    var outsideName by remember { mutableStateOf<String?>(null) }
    var picking by remember { mutableStateOf(false) }
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    val sent = stringResource(R.string.lf_handoff_sent)
    LaunchedEffect(isGroup) { if (isGroup) state.loadMembers() }
    if (picking) {
        PeoplePickerSheet(emptySet(), onDismiss = { picking = false }) { ids ->
            picking = false
            ids.firstOrNull()?.let { outside = it; to = null; outsideName = null }
        }
        return
    }
    ChSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text("🤝 " + stringResource(R.string.lf_handoff), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.lf_handoff_sub), color = Ch.Mut, fontSize = 12.5.sp)
            Text(stringResource(R.string.lf_to_whom), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 14.dp, bottom = 6.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                state.members.filter { it.profile?.key != myKey() }.forEach { m -> Chip(m.profile?.name.orEmpty(), to == m.participantId) { to = m.participantId; outside = null } }
                Chip(if (outside != null) "👤 " + (outsideName ?: stringResource(R.string.lf_someone_picked)) else "＋ " + stringResource(R.string.lf_someone_else), outside != null) { picking = true }
            }
            if (outside != null) Text(stringResource(R.string.lf_forward_note), color = Ch.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 6.dp))
            Spacer(Modifier.height(12.dp))
            ChField(note, { note = it.take(500) }, stringResource(R.string.lf_handoff_note), singleLine = false, minLines = 2)
            ChPrimaryButton(stringResource(R.string.lf_hand_it), Modifier.fillMaxWidth().padding(top = 14.dp), enabled = (to != null || outside != null) && !busy) {
                busy = true
                scope.launch {
                    val body = JsonObject().apply {
                        to?.let { addProperty("to_participant_id", it) } ?: outside?.let { addProperty("to_user_id", it) }
                        if (note.isNotBlank()) addProperty("note", note.trim())
                    }
                    runCatching { ApiClient.life.handOff(chatAuth(), message.id, body) }
                        .onSuccess { host.showToast(sent); onDismiss() }
                        .onFailure { host.showToast(it.apiFailure().message ?: "") }
                    busy = false
                }
            }
        }
    }
}

/** Propose an agreement — or, with `existing`, change its terms (a new version). */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
internal fun AgreementSheet(conversationId: String, members: List<LifePersonDto>, existing: AgreementDto?, source: MessageDto?, onDismiss: () -> Unit, onSaved: (AgreementDto) -> Unit) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var title by remember { mutableStateOf(existing?.title ?: source?.body?.take(120).orEmpty()) }
    val terms = remember { mutableStateListOf<Pair<String, String>>().apply { existing?.terms?.forEach { add(it.label to it.value) } } }
    val parties = remember { mutableStateListOf<Int>() }
    var busy by remember { mutableStateOf(false) }
    val presets = listOf(R.string.lf_t_scope, R.string.lf_t_date, R.string.lf_t_price, R.string.lf_t_place, R.string.lf_t_notes).map { stringResource(it) }
    LaunchedEffect(Unit) { if (terms.isEmpty()) { terms.add(presets[0] to ""); terms.add(presets[1] to "") } }
    val sent = stringResource(R.string.lf_agreement_sent)
    ChSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text("📝 " + stringResource(if (existing == null) R.string.lf_agreement else R.string.lf_change_terms), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(if (existing == null) R.string.lf_agreement_sub else R.string.lf_change_sub), color = Ch.Mut, fontSize = 12.5.sp)
            Spacer(Modifier.height(12.dp))
            ChField(title, { title = it.take(200) }, stringResource(R.string.lf_agreement_title))
            Text(stringResource(R.string.lf_terms), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 14.dp, bottom = 6.dp))
            terms.forEachIndexed { i, (label, value) ->
                Row(Modifier.fillMaxWidth().padding(bottom = 6.dp).animateContentSize(), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.weight(0.8f)) { ChField(label, { terms[i] = it.take(60) to value }, stringResource(R.string.lf_term_label)) }
                    Spacer(Modifier.width(6.dp))
                    Box(Modifier.weight(1.3f)) { ChField(value, { terms[i] = label to it.take(500) }, stringResource(R.string.lf_term_value)) }
                    Icon(Icons.Rounded.Close, null, tint = Ch.Soft, modifier = Modifier.padding(start = 4.dp).size(20.dp).clickable { terms.removeAt(i) })
                }
            }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                presets.filter { p -> terms.none { it.first == p } }.forEach { p -> Chip("＋ $p", false) { if (terms.size < 12) terms.add(p to "") } }
                Chip("＋ " + stringResource(R.string.lf_term_other), false) { if (terms.size < 12) terms.add("" to "") }
            }
            if (existing == null && members.isNotEmpty()) {
                Text(stringResource(R.string.lf_who_agrees), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 14.dp, bottom = 6.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    members.filter { it.key != myKey() }.forEach { m -> Chip(m.name.orEmpty(), m.participantId in parties) { if (m.participantId in parties) parties.remove(m.participantId) else parties.add(m.participantId) } }
                }
            }
            Text(stringResource(R.string.lf_record_only), color = Ch.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 12.dp))
            val ready = title.isNotBlank() && terms.any { it.first.isNotBlank() && it.second.isNotBlank() } && (existing != null || members.isEmpty() || parties.isNotEmpty())
            ChPrimaryButton(stringResource(if (existing == null) R.string.lf_send else R.string.lf_save_version), Modifier.fillMaxWidth().padding(top = 14.dp), enabled = ready && !busy) {
                busy = true
                scope.launch {
                    val body = JsonObject().apply {
                        addProperty("title", title.trim())
                        add("terms", JsonArray().apply { terms.filter { it.first.isNotBlank() && it.second.isNotBlank() }.forEach { (l, v) -> add(JsonObject().apply { addProperty("label", l.trim()); addProperty("value", v.trim()) }) } })
                        if (existing == null && parties.isNotEmpty()) add("parties", JsonArray().apply { parties.forEach { add(it) } })
                        source?.let { addProperty("source", it.id) }
                    }
                    runCatching { if (existing == null) ApiClient.life.propose(chatAuth(), conversationId, body).data else ApiClient.life.amend(chatAuth(), existing.id, body).data }
                        .onSuccess { r -> r?.let(onSaved); host.showToast(sent); onDismiss() }
                        .onFailure { host.showToast(it.apiFailure().message ?: "") }
                    busy = false
                }
            }
        }
    }
}

/** An agreement's whole history: who proposed, changed (before → after), agreed, declined. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AgreementHistorySheet(id: String, onDismiss: () -> Unit) {
    var a by remember { mutableStateOf<AgreementDto?>(null) }
    LaunchedEffect(id) { a = runCatching { ApiClient.life.agreement(chatAuth(), id).data }.getOrNull() }
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(R.string.lf_history), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(10.dp))
            a?.log.orEmpty().forEach { l ->
                Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.Top) {
                    Box(Modifier.padding(top = 4.dp).size(10.dp).clip(CircleShape).background(if (l.action == "accepted") LifeGreen else if (l.action == "declined") Ch.Danger else LifePurple))
                    Spacer(Modifier.width(10.dp))
                    Column(Modifier.weight(1f)) {
                        Text(listOfNotNull(l.by?.name, logLabel(l.action)).joinToString(" · "), color = Ch.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold)
                        val version = runCatching { l.data?.asJsonObject?.get("version")?.asInt }.getOrNull()
                        version?.let { Text("v$it", color = Ch.Mut, fontSize = 11.5.sp) }
                        lifeWhen(l.at)?.let { Text(it, color = Ch.Soft, fontSize = 11.sp) }
                    }
                }
            }
        }
    }
}

@Composable
private fun logLabel(action: String): String = when (action) {
    "proposed", "created" -> stringResource(R.string.lf_log_proposed)
    "amended" -> stringResource(R.string.lf_log_amended)
    "accepted" -> stringResource(R.string.lf_st_accepted)
    "declined" -> stringResource(R.string.lf_st_declined)
    "done" -> stringResource(R.string.lf_st_done)
    else -> stringResource(R.string.lf_st_cancelled)
}

@Composable
private fun Chip(text: String, on: Boolean, onClick: () -> Unit) {
    Text(
        text, color = if (on) Color.White else Ch.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1,
        modifier = Modifier.clip(CircleShape).background(if (on) Ch.Red else Ch.SurfaceMuted).border(1.dp, if (on) Ch.Red else Ch.Line, CircleShape).clickable(onClick = onClick).padding(horizontal = 13.dp, vertical = 8.dp),
    )
}

// ------------------------------------------------------------------ my commitments page

/** My commitments and handoffs: on me, and what I'm waiting for from others. */
@Composable
fun CommitmentsPage() {
    val host = LocalChat.current
    var tab by remember { mutableIntStateOf(0) }
    var commitments by remember { mutableStateOf<List<LifeCommitmentDto>?>(null) }
    var handoffs by remember { mutableStateOf<List<HandoffDto>?>(null) }
    val scope = rememberCoroutineScope()
    val scopes = listOf("mine", "theirs")
    suspend fun load() {
        commitments = null
        handoffs = null
        commitments = runCatching { ApiClient.life.commitments(chatAuth(), scopes[tab]).data }.getOrNull().orEmpty()
        handoffs = runCatching { ApiClient.life.handoffs(chatAuth(), if (tab == 0) "received" else "sent").data }.getOrNull().orEmpty()
    }
    LaunchedEffect(tab) { load() }
    ChPage(stringResource(R.string.lf_my_commitments), onBack = { host.pop() }) {
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Ch.Surface).padding(4.dp)) {
                listOf(R.string.lf_tab_on_me, R.string.lf_tab_waiting).forEachIndexed { i, l ->
                    Text(
                        stringResource(l), color = if (tab == i) Color.White else Ch.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                        modifier = Modifier.weight(1f).clip(RoundedCornerShape(12.dp)).background(if (tab == i) Ch.Red else Color.Transparent).clickable { tab = i }.padding(vertical = 10.dp),
                    )
                }
            }
            Spacer(Modifier.height(12.dp))
            val cs = commitments
            val hs = handoffs
            if (cs == null || hs == null) {
                repeat(3) { Box(Modifier.fillMaxWidth().height(70.dp).padding(bottom = 8.dp).clip(RoundedCornerShape(18.dp)).background(Ch.SurfaceMuted)) }
            } else if (cs.isEmpty() && hs.isEmpty()) {
                ChEmptyState(Icons.Rounded.AssignmentInd, stringResource(R.string.lf_empty_title), stringResource(if (tab == 0) R.string.lf_empty_on_me else R.string.lf_empty_waiting), animated = true)
            } else {
                cs.forEach { c ->
                    LifeRow("📌", c.title.orEmpty(), listOfNotNull(if (tab == 0) c.by?.name else c.assignee?.name, lifeWhen(c.dueAt)).joinToString(" · "), c.status, c.conversation?.id) { id -> host.push(ChRoute.Conversation(id)) }
                }
                hs.forEach { h ->
                    LifeRow("🤝", h.note ?: h.source?.preview ?: stringResource(R.string.lf_handoff), (if (tab == 0) h.from?.name else h.to?.name).orEmpty(), h.status, h.conversation?.id) { id -> host.push(ChRoute.Conversation(id)) }
                }
            }
        }
    }
}

@Composable
private fun LifeRow(emoji: String, title: String, sub: String, status: String, conversationId: String?, onOpen: (String) -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(bottom = 8.dp).clip(RoundedCornerShape(18.dp)).background(Ch.Surface).clickable(enabled = conversationId != null) { conversationId?.let(onOpen) }.padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(40.dp).clip(RoundedCornerShape(13.dp)).background(Brush.linearGradient(listOf(LifeAmber.copy(alpha = 0.2f), LifePurple.copy(alpha = 0.15f)))), contentAlignment = Alignment.Center) { Text(emoji, fontSize = 18.sp) }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(title, color = Ch.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis)
            if (sub.isNotBlank()) Text(sub, color = Ch.Mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        Spacer(Modifier.width(8.dp))
        StatusPill(status)
    }
}
