package com.dorr.app.ui.screens.chat

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.EmojiEvents
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.Lightbulb
import androidx.compose.material.icons.rounded.MilitaryTech
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.network.ApiClient
import com.dorr.app.network.BadgeDto
import com.dorr.app.network.GoalDto
import com.dorr.app.network.IdeaDto
import com.dorr.app.network.MeetingDto
import com.dorr.app.network.MemberDto
import com.dorr.app.network.ShiftDto
import com.dorr.app.network.apiFailure
import kotlinx.coroutines.launch
import java.time.ZonedDateTime

/*
 * Work group extras (docs/groups_tasks.md GR-040, 042, 043, 044, 046 without AI) and anonymous
 * polls in both kinds (GR-045). Each sheet loads once and again on `chat.group.work` for this
 * group — never on a timer. What a button may do is checked again on the server.
 */

/** One reload whenever this group's shifts / competitions / badges / ideas / meetings change. */
@Composable
private fun OnWorkChange(groupId: String, reload: suspend () -> Unit) {
    LaunchedEffect(groupId) {
        ChatRealtime.events.collect { e ->
            if (e.name == "chat.group.work" && e.data.get("conversation_id")?.asString == groupId) reload()
        }
    }
}

// ============================================================================ GR-040 shifts

/** The week's shifts (or mine): hand over to whoever is next, ask a swap, answer one. */
@Composable
internal fun WorkShiftsSheet(groupId: String, members: List<MemberDto>, canManage: Boolean, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val context = LocalContext.current
    var mine by remember { mutableStateOf(false) }
    var shifts by remember { mutableStateOf<List<ShiftDto>?>(null) }
    var who by remember { mutableStateOf<Int?>(null) }
    var start by remember { mutableStateOf<ZonedDateTime?>(null) }
    var hours by remember { mutableStateOf(8) }
    val notes = remember { mutableStateMapOf<String, String>() }
    var swapFor by remember { mutableStateOf<String?>(null) }
    suspend fun load() { shifts = runCatching { ApiClient.classroom.shifts(chatAuth(), groupId, null, if (mine) 1 else null).data }.getOrNull().orEmpty() }
    OnWorkChange(groupId) { load() }
    LaunchedEffect(mine) { load() }
    fun act(call: suspend () -> Unit) {
        host.scope.launch { runCatching { call() }.onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }; load() }
    }

    ClSheet("🕒 " + stringResource(R.string.wk_shifts), onDismiss) {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            ClPill(stringResource(R.string.wk_week), !mine) { mine = false }
            ClPill(stringResource(R.string.gt_filter_mine), mine) { mine = true }
        }
        Spacer(Modifier.height(6.dp))
        val list = shifts ?: return@ClSheet WkLoading()
        if (list.isEmpty()) Text(stringResource(R.string.wk_no_shifts), color = Ch.Mut, modifier = Modifier.padding(vertical = 10.dp))
        list.forEach { s ->
            WkBox {
                Text(listOfNotNull(dueText(s.startsAt), dueText(s.endsAt)).joinToString(" → "), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                Text(listOfNotNull(s.member?.profile?.name, s.title).joinToString(" · "), color = Ch.Mut, fontSize = 12.5.sp)
                s.handoverNote?.let { Text("📝 $it", color = Ch.Ink, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp)) }
                s.swapWith?.let { Text(stringResource(R.string.wk_swap_pending, it.profile?.name.orEmpty()), color = Ch.Red, fontSize = 12.5.sp, modifier = Modifier.padding(top = 4.dp)) }
                if (s.can.handover) {
                    ChField(notes[s.id].orEmpty(), { notes[s.id] = it.take(3000) }, stringResource(R.string.wk_handover_hint), modifier = Modifier.padding(top = 6.dp))
                    WkActions {
                        ClPill(stringResource(R.string.wk_handover), false) {
                            val n = notes[s.id]?.trim().orEmpty()
                            if (n.isNotEmpty()) act { ApiClient.classroom.handover(chatAuth(), groupId, s.id, mapOf("note" to n)); notes[s.id] = "" }
                        }
                        if (s.can.askSwap) ClPill(stringResource(if (s.swapWith != null) R.string.wk_swap_cancel else R.string.wk_swap_ask), swapFor == s.id) {
                            if (s.swapWith != null) act { ApiClient.classroom.askSwap(chatAuth(), groupId, s.id, mapOf("with_participant_id" to null)) }
                            else swapFor = if (swapFor == s.id) null else s.id
                        }
                    }
                    if (swapFor == s.id) {
                        WkMemberPicker(members.filter { it.profile?.isMe != true }, null) { pid ->
                            swapFor = null
                            act { ApiClient.classroom.askSwap(chatAuth(), groupId, s.id, mapOf("with_participant_id" to pid)) }
                        }
                    }
                }
                if (s.can.answerSwap) WkActions {
                    ClPill("✓ " + stringResource(R.string.wk_accept), false) { act { ApiClient.classroom.answerSwap(chatAuth(), groupId, s.id, mapOf("accept" to true)) } }
                    ClPill(stringResource(R.string.wk_decline), false) { act { ApiClient.classroom.answerSwap(chatAuth(), groupId, s.id, mapOf("accept" to false)) } }
                }
                if (s.can.manage) Text(
                    stringResource(R.string.wk_delete), color = Ch.Danger, fontSize = 12.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.padding(top = 6.dp).clip(RoundedCornerShape(8.dp)).clickable { act { ApiClient.classroom.deleteShift(chatAuth(), groupId, s.id) } }.padding(4.dp),
                )
            }
        }
        if (canManage) {
            ClTitle(stringResource(R.string.wk_new_shift))
            WkMemberPicker(members, who) { who = it }
            Spacer(Modifier.height(8.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                ClPill(start?.let { dueText(it.toOffsetDateTime().toString()) } ?: stringResource(R.string.wk_pick_start), start != null) { pickDateTime(context) { start = it } }
                listOf(4, 8, 12).forEach { h -> ClPill(stringResource(R.string.wk_hours, h), hours == h) { hours = h } }
            }
            Spacer(Modifier.height(10.dp))
            ChPrimaryButton(stringResource(R.string.wk_new_shift), icon = Icons.Rounded.Schedule, modifier = Modifier.fillMaxWidth(), enabled = who != null && start != null) {
                val from = start ?: return@ChPrimaryButton
                act {
                    ApiClient.classroom.createShift(chatAuth(), groupId, mapOf(
                        "participant_id" to who,
                        "starts_at" to from.toOffsetDateTime().toString(),
                        "ends_at" to from.plusHours(hours.toLong()).toOffsetDateTime().toString(),
                    ))
                    who = null; start = null
                }
            }
        }
    }
}

// ============================================================================ GR-042 competitions

@Composable
internal fun WorkGoalsSheet(groupId: String, members: List<MemberDto>, canGive: Boolean, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var goals by remember { mutableStateOf<List<GoalDto>?>(null) }
    var title by remember { mutableStateOf("") }
    var pointsFor by remember { mutableStateOf<String?>(null) }
    var who by remember { mutableStateOf<Int?>(null) }
    var points by remember { mutableStateOf("") }
    suspend fun load() { goals = runCatching { ApiClient.classroom.goals(chatAuth(), groupId).data }.getOrNull().orEmpty() }
    LaunchedEffect(groupId) { load() }
    OnWorkChange(groupId) { load() }
    fun act(call: suspend () -> Unit) {
        host.scope.launch { runCatching { call() }.onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }; load() }
    }

    ClSheet("🏆 " + stringResource(R.string.wk_goals), onDismiss) {
        val list = goals ?: return@ClSheet WkLoading()
        if (list.isEmpty()) Text(stringResource(R.string.wk_no_goals), color = Ch.Mut, modifier = Modifier.padding(vertical = 10.dp))
        list.forEach { g ->
            WkBox {
                Text(g.title, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
                listOfNotNull(g.description, dueText(g.endsAt)?.let { stringResource(R.string.wk_ends, it) }).forEach { Text(it, color = Ch.Mut, fontSize = 12.5.sp) }
                g.leaderboard.forEach { r ->
                    Row(Modifier.fillMaxWidth().padding(vertical = 3.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(when (r.rank) { 1 -> "🥇"; 2 -> "🥈"; 3 -> "🥉"; else -> "${r.rank}" }, fontSize = 16.sp, modifier = Modifier.width(32.dp))
                        Text(r.member?.profile?.name.orEmpty(), color = Ch.Ink, fontSize = 14.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Text("${r.points}", color = Ch.Red, fontWeight = FontWeight.ExtraBold)
                    }
                }
                if (canGive) {
                    ClPill("+ " + stringResource(R.string.wk_give_points), pointsFor == g.id) { pointsFor = if (pointsFor == g.id) null else g.id; who = null; points = "" }
                    if (pointsFor == g.id) {
                        Spacer(Modifier.height(6.dp))
                        WkMemberPicker(members, who) { who = it }
                        ChField(points, { v -> points = v.filter { it.isDigit() || it == '-' }.take(5) }, stringResource(R.string.wk_points), modifier = Modifier.padding(top = 6.dp))
                        WkActions {
                            ClPill("✓ " + stringResource(R.string.wk_save), false) {
                                val p = points.toIntOrNull()
                                if (who != null && p != null && p != 0) act { ApiClient.classroom.givePoints(chatAuth(), groupId, g.id, mapOf("participant_id" to who, "points" to p)); pointsFor = null }
                            }
                        }
                    }
                }
            }
        }
        if (canGive) {
            ClTitle(stringResource(R.string.wk_new_goal))
            ChField(title, { title = it.take(200) }, stringResource(R.string.cl_title_hint))
            Spacer(Modifier.height(8.dp))
            ChPrimaryButton(stringResource(R.string.wk_new_goal), icon = Icons.Rounded.EmojiEvents, modifier = Modifier.fillMaxWidth(), enabled = title.isNotBlank()) {
                val t = title.trim()
                title = ""
                act { ApiClient.classroom.createGoal(chatAuth(), groupId, mapOf("title" to t)) }
            }
        }
    }
}

// ============================================================================ GR-043 badges

@Composable
internal fun WorkBadgesSheet(groupId: String, members: List<MemberDto>, canGive: Boolean, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var badges by remember { mutableStateOf<List<BadgeDto>?>(null) }
    var who by remember { mutableStateOf<Int?>(null) }
    var emoji by remember { mutableStateOf("🏅") }
    var title by remember { mutableStateOf("") }
    var reason by remember { mutableStateOf("") }
    suspend fun load() { badges = runCatching { ApiClient.classroom.badges(chatAuth(), groupId, null).data }.getOrNull().orEmpty() }
    LaunchedEffect(groupId) { load() }
    OnWorkChange(groupId) { load() }

    ClSheet("🏅 " + stringResource(R.string.wk_badges), onDismiss) {
        val list = badges ?: return@ClSheet WkLoading()
        if (list.isEmpty()) Text(stringResource(R.string.wk_no_badges), color = Ch.Mut, modifier = Modifier.padding(vertical = 10.dp))
        list.forEach { b ->
            Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(b.emoji ?: "🏅", fontSize = 22.sp)
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(b.title, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                    Text(listOfNotNull(b.member?.profile?.name, b.reason).joinToString(" · "), color = Ch.Mut, fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
                }
            }
        }
        if (canGive) {
            ClTitle(stringResource(R.string.wk_give_badge))
            WkMemberPicker(members.filter { it.profile?.isMe != true }, who) { who = it }
            Spacer(Modifier.height(8.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                listOf("🏅", "⭐", "🚀", "💡", "🤝", "⚡").forEach { e -> ClPill(e, emoji == e) { emoji = e } }
            }
            ChField(title, { title = it.take(100) }, stringResource(R.string.cl_title_hint), modifier = Modifier.padding(top = 8.dp))
            ChField(reason, { reason = it.take(300) }, stringResource(R.string.cx_reason), modifier = Modifier.padding(top = 8.dp))
            Spacer(Modifier.height(10.dp))
            ChPrimaryButton(stringResource(R.string.wk_give_badge), icon = Icons.Rounded.MilitaryTech, modifier = Modifier.fillMaxWidth(), enabled = who != null && title.isNotBlank()) {
                host.scope.launch {
                    runCatching { ApiClient.classroom.giveBadge(chatAuth(), groupId, mapOf("participant_id" to who, "title" to title.trim(), "emoji" to emoji, "reason" to reason.trim().ifBlank { null })) }
                        .onSuccess { who = null; title = ""; reason = ""; load() }
                        .onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
                }
            }
        }
    }
}

// ============================================================================ GR-044 ideas

private val IdeaStatuses = listOf("new", "under_review", "accepted", "rejected", "done")

@Composable
private fun ideaStatusLabel(s: String): String = stringResource(
    when (s) {
        "under_review" -> R.string.wk_idea_under_review
        "accepted" -> R.string.wk_idea_accepted
        "rejected" -> R.string.wk_idea_rejected
        "done" -> R.string.wk_idea_done
        else -> R.string.wk_idea_new
    },
)

@Composable
internal fun WorkIdeasSheet(groupId: String, canDecide: Boolean, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var filter by remember { mutableStateOf<String?>(null) }
    var ideas by remember { mutableStateOf<List<IdeaDto>?>(null) }
    var title by remember { mutableStateOf("") }
    var body by remember { mutableStateOf("") }
    var deciding by remember { mutableStateOf<String?>(null) }
    suspend fun load() { ideas = runCatching { ApiClient.classroom.ideas(chatAuth(), groupId, filter).data }.getOrNull().orEmpty() }
    OnWorkChange(groupId) { load() }
    LaunchedEffect(filter) { load() }
    fun act(call: suspend () -> Unit) {
        host.scope.launch { runCatching { call() }.onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }; load() }
    }

    ClSheet("💡 " + stringResource(R.string.wk_ideas), onDismiss) {
        ChField(title, { title = it.take(200) }, stringResource(R.string.wk_idea_title))
        ChField(body, { body = it.take(5000) }, stringResource(R.string.wk_idea_body), singleLine = false, minLines = 2, modifier = Modifier.padding(top = 8.dp))
        Spacer(Modifier.height(8.dp))
        ChPrimaryButton(stringResource(R.string.wk_propose), icon = Icons.Rounded.Lightbulb, modifier = Modifier.fillMaxWidth(), enabled = title.isNotBlank()) {
            val t = title.trim()
            val b = body.trim().ifBlank { null }
            title = ""; body = ""
            act { ApiClient.classroom.propose(chatAuth(), groupId, mapOf("title" to t, "body" to b)) }
        }
        Spacer(Modifier.height(10.dp))
        LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            item(key = "all") { ClPill(stringResource(R.string.wk_all), filter == null) { filter = null } }
            items(IdeaStatuses, key = { it }) { s -> ClPill(ideaStatusLabel(s), filter == s) { filter = s } }
        }
        val list = ideas ?: return@ClSheet WkLoading()
        if (list.isEmpty()) Text(stringResource(R.string.wk_no_ideas), color = Ch.Mut, modifier = Modifier.padding(vertical = 10.dp))
        list.forEach { i ->
            WkBox {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(i.title, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp)
                        Text(listOfNotNull(i.by?.profile?.name, ideaStatusLabel(i.status)).joinToString(" · "), color = Ch.Mut, fontSize = 12.sp)
                    }
                    Text(
                        "▲ ${i.votes}", color = if (i.voted) androidx.compose.ui.graphics.Color.White else Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp,
                        modifier = Modifier.clip(RoundedCornerShape(50)).background(if (i.voted) Ch.Red else Ch.Red.copy(alpha = 0.1f))
                            .clickable { act { ApiClient.classroom.voteIdea(chatAuth(), groupId, i.id) } }.padding(horizontal = 12.dp, vertical = 6.dp),
                    )
                }
                i.body?.let { Text(it, color = Ch.Ink, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp)) }
                i.statusNote?.let { Text("↳ $it", color = Ch.Success, fontSize = 12.5.sp, modifier = Modifier.padding(top = 4.dp)) }
                if (canDecide) {
                    ClPill(stringResource(R.string.wk_set_status), deciding == i.id) { deciding = if (deciding == i.id) null else i.id }
                    if (deciding == i.id) LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp), modifier = Modifier.padding(top = 6.dp)) {
                        items(IdeaStatuses, key = { it }) { s ->
                            ClPill(ideaStatusLabel(s), i.status == s) {
                                deciding = null
                                act { ApiClient.classroom.ideaStatus(chatAuth(), groupId, i.id, mapOf("status" to s)) }
                            }
                        }
                    }
                }
            }
        }
    }
}

// ============================================================================ GR-046 meetings (no AI)

@Composable
internal fun WorkMeetingsSheet(groupId: String, members: List<MemberDto>, canCreate: Boolean, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val context = LocalContext.current
    var tab by remember { mutableStateOf("upcoming") }
    var meetings by remember { mutableStateOf<List<MeetingDto>?>(null) }
    var title by remember { mutableStateOf("") }
    var agenda by remember { mutableStateOf("") }
    var at by remember { mutableStateOf<ZonedDateTime?>(null) }
    var open by remember { mutableStateOf<String?>(null) }
    val minutes = remember { mutableStateMapOf<String, String>() }
    val taskLines = remember { mutableStateMapOf<String, String>() }
    val made = stringResource(R.string.wk_tasks_made)
    suspend fun load() { meetings = runCatching { ApiClient.classroom.meetings(chatAuth(), groupId, tab).data }.getOrNull().orEmpty() }
    OnWorkChange(groupId) { load() }
    LaunchedEffect(tab) { load() }
    fun act(done: String? = null, call: suspend () -> Unit) {
        host.scope.launch {
            runCatching { call() }.onSuccess { done?.let { host.showToast(it) } }.onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
            load()
        }
    }

    ClSheet("📅 " + stringResource(R.string.wk_meetings), onDismiss) {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            ClPill(stringResource(R.string.wk_upcoming), tab == "upcoming") { tab = "upcoming" }
            ClPill(stringResource(R.string.wk_past), tab == "past") { tab = "past" }
        }
        val list = meetings ?: return@ClSheet WkLoading()
        if (list.isEmpty()) Text(stringResource(R.string.wk_no_meetings), color = Ch.Mut, modifier = Modifier.padding(vertical = 10.dp))
        list.forEach { m ->
            WkBox(Modifier.clickable { open = if (open == m.id) null else m.id }) {
                Text(m.title, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp)
                dueText(m.startsAt)?.let { Text(it, color = Ch.Mut, fontSize = 12.5.sp) }
                if (open == m.id) {
                    m.agenda?.let { ClTitle(stringResource(R.string.wk_agenda)); Text(it, color = Ch.Ink, fontSize = 13.sp) }
                    if (m.canEdit) {
                        ClTitle(stringResource(R.string.wk_minutes))
                        ChField(minutes[m.id] ?: m.minutes.orEmpty(), { minutes[m.id] = it.take(20000) }, stringResource(R.string.wk_minutes_hint), singleLine = false, minLines = 3)
                        WkActions {
                            ClPill("✓ " + stringResource(R.string.wk_save), false) {
                                val text = minutes[m.id] ?: return@ClPill
                                act { ApiClient.classroom.saveMinutes(chatAuth(), groupId, m.id, mapOf("minutes" to text.trim())) }
                            }
                        }
                        ClTitle(stringResource(R.string.wk_tasks_from_minutes))
                        ChField(taskLines[m.id].orEmpty(), { taskLines[m.id] = it.take(3000) }, stringResource(R.string.gt_checklist_lines), singleLine = false, minLines = 2)
                        WkActions {
                            ClPill("+ " + stringResource(R.string.wk_make_tasks), false) {
                                val items = taskLines[m.id].orEmpty().lines().map { it.trim() }.filter { it.isNotEmpty() }.take(30)
                                if (items.isNotEmpty()) act(made) {
                                    ApiClient.classroom.meetingTasks(chatAuth(), groupId, m.id, mapOf("items" to items.map { mapOf("title" to it) }))
                                    taskLines[m.id] = ""
                                }
                            }
                        }
                    } else {
                        m.minutes?.let { ClTitle(stringResource(R.string.wk_minutes)); Text(it, color = Ch.Ink, fontSize = 13.sp) }
                    }
                }
            }
        }
        if (canCreate) {
            ClTitle(stringResource(R.string.wk_new_meeting))
            ChField(title, { title = it.take(200) }, stringResource(R.string.cl_title_hint))
            Spacer(Modifier.height(8.dp))
            ClPill(at?.let { dueText(it.toOffsetDateTime().toString()) } ?: stringResource(R.string.cl_pick_time), at != null) { pickDateTime(context) { at = it } }
            ChField(agenda, { agenda = it.take(5000) }, stringResource(R.string.wk_agenda), singleLine = false, minLines = 2, modifier = Modifier.padding(top = 8.dp))
            Spacer(Modifier.height(10.dp))
            ChPrimaryButton(stringResource(R.string.wk_new_meeting), icon = Icons.Rounded.Event, modifier = Modifier.fillMaxWidth(), enabled = title.isNotBlank() && at != null) {
                val time = at ?: return@ChPrimaryButton
                act {
                    ApiClient.classroom.createMeeting(chatAuth(), groupId, mapOf("title" to title.trim(), "starts_at" to time.toOffsetDateTime().toString(), "agenda" to agenda.trim().ifBlank { null }))
                    title = ""; agenda = ""; at = null
                }
            }
        }
    }
}

// ============================================================================ GR-045 polls (both kinds)

/** A poll in the chat; anonymous by default — the numbers for everyone, never who voted for what. */
@Composable
internal fun GroupPollSheet(groupId: String, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var question by remember { mutableStateOf("") }
    var options by remember { mutableStateOf("") }
    var anonymous by remember { mutableStateOf(true) }
    var multiple by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    val sent = stringResource(R.string.wk_poll_sent)
    ClSheet(stringResource(R.string.wk_poll), onDismiss) {
        ChField(question, { question = it.take(1000) }, stringResource(R.string.cl_q_text), singleLine = false, minLines = 2)
        ChField(options, { options = it.take(1200) }, stringResource(R.string.cl_q_options), singleLine = false, minLines = 3, modifier = Modifier.padding(top = 8.dp))
        Spacer(Modifier.height(8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            ClPill("🕶 " + stringResource(R.string.wk_poll_anonymous), anonymous) { anonymous = !anonymous }
            ClPill(stringResource(R.string.wk_poll_multiple), multiple) { multiple = !multiple }
        }
        if (anonymous) Text(stringResource(R.string.wk_poll_anonymous_sub), color = Ch.Mut, fontSize = 12.sp, lineHeight = 16.sp, modifier = Modifier.padding(top = 6.dp))
        Spacer(Modifier.height(10.dp))
        val opts = options.lines().map { it.trim() }.filter { it.isNotEmpty() }
        ChPrimaryButton(stringResource(R.string.cl_publish), icon = Icons.Rounded.Send, modifier = Modifier.fillMaxWidth(), enabled = question.isNotBlank() && opts.size >= 2 && !busy) {
            busy = true
            host.scope.launch {
                runCatching { ApiClient.classroom.groupPoll(chatAuth(), groupId, mapOf("question" to question.trim(), "options" to opts.take(12), "anonymous" to anonymous, "multiple" to multiple)) }
                    .onSuccess { host.showToast(sent); onDismiss() }.onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
                busy = false
            }
        }
    }
}

// ============================================================================ shared bits

@Composable
private fun WkMemberPicker(members: List<MemberDto>, selected: Int?, onPick: (Int) -> Unit) {
    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        items(members, key = { it.participantId }) { m ->
            ClPill(if (m.profile?.isMe == true) stringResource(R.string.ch_you) else m.profile?.name.orEmpty(), selected == m.participantId) { onPick(m.participantId) }
        }
    }
}

@Composable
private fun WkBox(modifier: Modifier = Modifier, content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().padding(top = 8.dp).clip(RoundedCornerShape(14.dp)).background(Ch.SurfaceMuted).then(modifier).padding(10.dp)) { content() }
}

@Composable
private fun WkActions(content: @Composable () -> Unit) {
    Row(Modifier.padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) { content() }
}

@Composable
private fun WkLoading() {
    Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
}
