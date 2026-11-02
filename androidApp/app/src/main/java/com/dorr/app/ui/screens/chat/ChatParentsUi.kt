package com.dorr.app.ui.screens.chat

import android.content.Intent
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.FamilyRestroom
import androidx.compose.material.icons.rounded.Link
import androidx.compose.material.icons.rounded.Share
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ChildLinkDto
import com.dorr.app.network.ChildPageDto
import com.dorr.app.network.GroupParentsDto
import com.dorr.app.network.MyParentDto
import com.dorr.app.network.ParentCodeDto
import com.dorr.app.network.ParentMeetingDto
import com.dorr.app.network.apiFailure
import kotlinx.coroutines.launch
import java.time.ZonedDateTime

/*
 * Parents in educational groups (docs/groups_tasks.md GR-018, 019, 027 — decision D8). A parent is
 * an ordinary account and never a member of the class chat: the student makes a code, the parent
 * enters it, a teacher approves. The parent then sees that child only. Every screen loads once and
 * again on `chat.group.parent` — never on a timer; every rule is checked on the server.
 */

/** One reload whenever a link, a meeting or a booking changes for me. */
@Composable
private fun OnParentChange(reload: suspend () -> Unit) {
    LaunchedEffect(Unit) {
        ChatRealtime.events.collect { e -> if (e.name == "chat.group.parent") reload() }
    }
}

// ============================================================================ the parent

/** «أولادي»: enter my child's code; my children, approved and waiting. */
@Composable
fun ParentChildrenPage() {
    val host = LocalChat.current
    var children by remember { mutableStateOf<List<ChildLinkDto>?>(null) }
    var code by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    val sent = stringResource(R.string.pa_code_sent)
    suspend fun load() { children = runCatching { ApiClient.classroom.children(chatAuth()).data }.getOrNull().orEmpty() }
    LaunchedEffect(Unit) { load() }
    OnParentChange { load() }

    ChPage(stringResource(R.string.pa_children), onBack = { host.pop() }, settingsHeader = true) {
        LazyColumn(contentPadding = PaddingValues(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxSize()) {
            item(key = "code") {
                Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Ch.Surface).padding(14.dp)) {
                    Text(stringResource(R.string.pa_link_child), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
                    Text(stringResource(R.string.pa_link_child_sub), color = Ch.Mut, fontSize = 12.5.sp, lineHeight = 17.sp, modifier = Modifier.padding(top = 2.dp, bottom = 8.dp))
                    ChField(code, { code = it.uppercase().filter { c -> c.isLetterOrDigit() }.take(12) }, stringResource(R.string.pa_code_hint), icon = Icons.Rounded.Link)
                    Spacer(Modifier.height(8.dp))
                    ChPrimaryButton(stringResource(R.string.pa_send_code), icon = Icons.Rounded.FamilyRestroom, modifier = Modifier.fillMaxWidth(), enabled = code.length >= 6 && !busy) {
                        busy = true
                        host.scope.launch {
                            runCatching { ApiClient.classroom.redeemParentCode(chatAuth(), mapOf("code" to code)) }
                                .onSuccess { code = ""; host.showToast(sent); load() }
                                .onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
                            busy = false
                        }
                    }
                }
            }
            val list = children
            if (list == null) item(key = "loading") { PaLoading() }
            else if (list.isEmpty()) item(key = "empty") { Text(stringResource(R.string.pa_no_children), color = Ch.Mut, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(24.dp)) }
            else itemsIndexed(list, key = { _, c -> c.id }) { i, c ->
                val waiting = c.status != "approved"
                ClCard(
                    Icons.Rounded.FamilyRestroom, c.student?.name.orEmpty(), c.group?.name,
                    badge = if (waiting) stringResource(R.string.pa_waiting) else null, badgeColor = Ch.Mut, index = i,
                ) { if (!waiting) host.push(ChRoute.ParentChild(c.id, c.student?.name.orEmpty())) }
            }
        }
    }
}

/** One child: homework, grades, tests, attendance, coming classes, announcements, meetings, the teacher. */
@Composable
fun ParentChildPage(linkId: String, title: String) {
    val host = LocalChat.current
    var page by remember { mutableStateOf<ChildPageDto?>(null) }
    var meetings by remember { mutableStateOf<List<ParentMeetingDto>>(emptyList()) }
    var gone by remember { mutableStateOf(false) }
    var confirmUnlink by remember { mutableStateOf(false) }
    suspend fun load() {
        val result = runCatching { ApiClient.classroom.child(chatAuth(), linkId).data }
        page = result.getOrNull()
        gone = result.isFailure
        meetings = runCatching { ApiClient.classroom.childMeetings(chatAuth(), linkId).data }.getOrNull().orEmpty()
    }
    LaunchedEffect(linkId) { load() }
    OnParentChange { load() }
    fun act(call: suspend () -> Unit) {
        host.scope.launch { runCatching { call() }.onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }; load() }
    }

    ChPage(title, onBack = { host.pop() }, settingsHeader = true) {
        val p = page
        when {
            p == null && gone -> Text(stringResource(R.string.pa_link_gone), color = Ch.Mut, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(40.dp))
            p == null -> PaLoading()
            else -> LazyColumn(contentPadding = PaddingValues(start = 14.dp, end = 14.dp, top = 6.dp, bottom = 40.dp), verticalArrangement = Arrangement.spacedBy(10.dp), modifier = Modifier.fillMaxSize()) {
                item(key = "head") {
                    PaBox {
                        Text(p.group?.name.orEmpty(), color = Ch.Mut, fontSize = 12.5.sp)
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(p.student?.name.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, modifier = Modifier.weight(1f))
                            if (p.stars > 0) Text("⭐ ${p.stars}", color = Ch.Red, fontWeight = FontWeight.Bold)
                        }
                        val c = p.attendance.counts
                        Text(
                            stringResource(R.string.pa_attendance_line, c["present"] ?: 0, c["late"] ?: 0, c["absent"] ?: 0, c["excused"] ?: 0),
                            color = Ch.Mut, fontSize = 12.5.sp, modifier = Modifier.padding(top = 4.dp),
                        )
                    }
                }
                if (p.messaging && p.teachers.isNotEmpty()) item(key = "teachers") {
                    PaBox {
                        PaTitle(stringResource(R.string.pa_message_teacher))
                        p.teachers.forEach { t ->
                            PaLine("💬 " + t.profile?.name.orEmpty(), null, Ch.Mut) {
                                host.scope.launch {
                                    runCatching { ApiClient.classroom.messageTeacher(chatAuth(), linkId, mapOf("teacher_participant_id" to t.participantId)).data }
                                        .onSuccess { r -> r?.conversationId?.let { host.push(ChRoute.Conversation(it)) } }
                                        .onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
                                }
                            }
                        }
                    }
                }
                if (meetings.isNotEmpty()) item(key = "meetings") {
                    PaBox {
                        PaTitle(stringResource(R.string.pa_meetings))
                        meetings.forEach { m -> ParentMeetingCard(m, teacher = false, onBook = { at -> act { ApiClient.classroom.bookSlot(chatAuth(), linkId, m.id, mapOf("slot_at" to at)) } },
                            onCancel = { act { ApiClient.classroom.cancelSlot(chatAuth(), linkId, m.id) } }) }
                    }
                }
                item(key = "homework") {
                    PaBox {
                        PaTitle(stringResource(R.string.cx_to_hand_in))
                        if (p.homework.isEmpty()) Text(stringResource(R.string.pa_nothing), color = Ch.Mut, fontSize = 13.sp)
                        p.homework.forEach { PaLine(it.title, dueText(it.dueAt), if (it.isPastDue) Ch.Danger else Ch.Mut) }
                    }
                }
                item(key = "grades") {
                    PaBox {
                        PaTitle(stringResource(R.string.pa_grades))
                        if (p.grades.isEmpty()) Text(stringResource(R.string.pa_nothing), color = Ch.Mut, fontSize = 13.sp)
                        p.grades.forEach { g -> PaLine(g.title, (g.score?.let { fmt(it) } ?: "—") + (g.max?.let { " / $it" } ?: ""), Ch.Success) }
                    }
                }
                if (p.tests.isNotEmpty() || p.sessions.isNotEmpty()) item(key = "coming") {
                    PaBox {
                        PaTitle(stringResource(R.string.pa_coming))
                        p.sessions.forEach { PaLine("📘 " + it.title, dueText(it.startsAt), Ch.Mut) }
                        p.tests.forEach { PaLine("📝 " + it.title, if (it.isOpen) stringResource(R.string.cl_open) else dueText(it.opensAt), Ch.Mut) }
                    }
                }
                if (p.attendance.sessions.isNotEmpty()) item(key = "attendance") {
                    PaBox {
                        PaTitle(stringResource(R.string.pa_attendance))
                        p.attendance.sessions.take(15).forEach { s -> PaLine(s.title, attendanceLabel(s.myStatus), if (s.myStatus == "absent") Ch.Danger else Ch.Mut) }
                    }
                }
                if (p.announcements.isNotEmpty()) item(key = "announcements") {
                    PaBox {
                        PaTitle("📢 " + stringResource(R.string.ch_announcements))
                        p.announcements.forEach { a ->
                            Text(a.body.orEmpty(), color = Ch.Ink, fontSize = 13.5.sp, modifier = Modifier.padding(vertical = 4.dp))
                        }
                    }
                }
                item(key = "unlink") {
                    Text(
                        stringResource(if (confirmUnlink) R.string.pa_unlink_confirm else R.string.pa_unlink), color = Ch.Danger, fontWeight = FontWeight.Bold, fontSize = 13.sp, textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable {
                            if (!confirmUnlink) confirmUnlink = true
                            else host.scope.launch {
                                runCatching { ApiClient.classroom.unlinkChild(chatAuth(), linkId) }.onSuccess { host.pop() }
                                    .onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
                            }
                        }.padding(12.dp),
                    )
                }
            }
        }
    }
}

// ============================================================================ the student

/** «ربط ولي أمري»: a code for my parent (7 days), and who is linked to me. */
@Composable
internal fun MyParentsSheet(groupId: String, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val context = LocalContext.current
    var parents by remember { mutableStateOf<List<MyParentDto>?>(null) }
    var code by remember { mutableStateOf<ParentCodeDto?>(null) }
    val shareText = stringResource(R.string.pa_share_text)
    suspend fun load() { parents = runCatching { ApiClient.classroom.myParents(chatAuth(), groupId).data }.getOrNull().orEmpty() }
    LaunchedEffect(groupId) { load() }
    OnParentChange { load() }

    ClSheet(stringResource(R.string.pa_link_my_parent), onDismiss) {
        Text(stringResource(R.string.pa_link_my_parent_sub), color = Ch.Mut, fontSize = 12.5.sp, lineHeight = 17.sp)
        Spacer(Modifier.height(10.dp))
        code?.let { c ->
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Ch.SurfaceMuted).padding(14.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Text(c.code, color = Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 28.sp, letterSpacing = 4.sp)
                dueText(c.expiresAt)?.let { Text(stringResource(R.string.pa_code_until, it), color = Ch.Mut, fontSize = 12.sp) }
            }
            Spacer(Modifier.height(8.dp))
            ChPrimaryButton(stringResource(R.string.pa_share_code), icon = Icons.Rounded.Share, modifier = Modifier.fillMaxWidth()) {
                val send = Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, shareText.format(c.code))
                runCatching { context.startActivity(Intent.createChooser(send, null).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
            }
        } ?: ChPrimaryButton(stringResource(R.string.pa_make_code), icon = Icons.Rounded.Link, modifier = Modifier.fillMaxWidth()) {
            host.scope.launch {
                runCatching { ApiClient.classroom.parentCode(chatAuth(), groupId).data }
                    .onSuccess { code = it }.onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
            }
        }
        val list = parents ?: return@ClSheet PaLoading()
        if (list.isNotEmpty()) {
            ClTitle(stringResource(R.string.pa_my_parents))
            list.forEach { PaLine(it.parent?.name.orEmpty(), if (it.status == "approved") "✓" else stringResource(R.string.pa_waiting), Ch.Mut) }
        }
    }
}

// ============================================================================ the teacher (`parents.link`)

/** «أولياء الأمور»: requests to approve, the linked parents, private messages on/off, meetings. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun GroupParentsSheet(groupId: String, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val context = LocalContext.current
    var data by remember { mutableStateOf<GroupParentsDto?>(null) }
    var meetings by remember { mutableStateOf<List<ParentMeetingDto>>(emptyList()) }
    var creating by remember { mutableStateOf(false) }
    var title by remember { mutableStateOf("") }
    var at by remember { mutableStateOf<ZonedDateTime?>(null) }
    var slots by remember { mutableStateOf(false) }
    var slotMinutes by remember { mutableStateOf(10) }
    var slotCount by remember { mutableStateOf("6") }
    suspend fun load() {
        data = runCatching { ApiClient.classroom.groupParents(chatAuth(), groupId, null).data }.getOrNull() ?: GroupParentsDto()
        meetings = runCatching { ApiClient.classroom.parentMeetings(chatAuth(), groupId).data }.getOrNull().orEmpty()
    }
    LaunchedEffect(groupId) { load() }
    OnParentChange { load() }
    fun act(call: suspend () -> Unit) {
        host.scope.launch { runCatching { call() }.onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }; load() }
    }

    ClSheet(stringResource(R.string.pa_parents), onDismiss) {
        val d = data ?: return@ClSheet PaLoading()
        // Private messages from linked parents (GR-019).
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { act { ApiClient.classroom.parentSettings(chatAuth(), groupId, mapOf("messaging" to !d.messaging)) } }.padding(vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.pa_messaging), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                Text(stringResource(R.string.pa_messaging_sub), color = Ch.Mut, fontSize = 12.sp)
            }
            ClPill(if (d.messaging) "✓" else "—", d.messaging) { act { ApiClient.classroom.parentSettings(chatAuth(), groupId, mapOf("messaging" to !d.messaging)) } }
        }

        val pending = d.links.filter { it.status == "pending" }
        if (pending.isNotEmpty()) {
            ClTitle(stringResource(R.string.pa_requests))
            pending.forEach { l ->
                PaBox {
                    Text(stringResource(R.string.pa_parent_of, l.parent?.name.orEmpty(), l.student?.profile?.name.orEmpty()), color = Ch.Ink, fontSize = 14.sp)
                    Row(Modifier.padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        ClPill("✓ " + stringResource(R.string.wk_accept), false) { act { ApiClient.classroom.decideParent(chatAuth(), groupId, l.id, mapOf("approve" to true)) } }
                        ClPill(stringResource(R.string.wk_decline), false) { act { ApiClient.classroom.decideParent(chatAuth(), groupId, l.id, mapOf("approve" to false)) } }
                    }
                }
            }
        }
        val linked = d.links.filter { it.status == "approved" }
        ClTitle(stringResource(R.string.pa_linked, linked.size))
        linked.forEach { l ->
            Row(Modifier.fillMaxWidth().padding(vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(stringResource(R.string.pa_parent_of, l.parent?.name.orEmpty(), l.student?.profile?.name.orEmpty()), color = Ch.Ink, fontSize = 13.5.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(stringResource(R.string.wk_delete), color = Ch.Danger, fontSize = 12.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable { act { ApiClient.classroom.removeParent(chatAuth(), groupId, l.id) } }.padding(4.dp))
            }
        }

        // GR-027: parents' meetings.
        ClTitle(stringResource(R.string.pa_meetings))
        meetings.forEach { m -> ParentMeetingCard(m, teacher = true, onDelete = { act { ApiClient.classroom.deleteParentMeeting(chatAuth(), groupId, m.id) } }) }
        if (!creating) {
            ClPill("+ " + stringResource(R.string.pa_new_meeting), false) { creating = true }
        } else {
            ChField(title, { title = it.take(200) }, stringResource(R.string.cl_title_hint))
            Spacer(Modifier.height(8.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                ClPill(at?.let { dueText(it.toOffsetDateTime().toString()) } ?: stringResource(R.string.cl_pick_time), at != null) { pickDateTime(context) { at = it } }
                ClPill(stringResource(R.string.pa_mode_group), !slots) { slots = false }
                ClPill(stringResource(R.string.pa_mode_slots), slots) { slots = true }
            }
            if (slots) {
                Spacer(Modifier.height(8.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    listOf(5, 10, 15, 20).forEach { mins -> ClPill(stringResource(R.string.pa_minutes, mins), slotMinutes == mins) { slotMinutes = mins } }
                }
                ChField(slotCount, { v -> slotCount = v.filter { it.isDigit() }.take(2) }, stringResource(R.string.pa_slot_count), modifier = Modifier.padding(top = 8.dp))
            }
            Spacer(Modifier.height(10.dp))
            ChPrimaryButton(stringResource(R.string.pa_new_meeting), icon = Icons.Rounded.Event, modifier = Modifier.fillMaxWidth(), enabled = title.isNotBlank() && at != null && (!slots || (slotCount.toIntOrNull() ?: 0) in 1..60)) {
                val time = at ?: return@ChPrimaryButton
                act {
                    ApiClient.classroom.createParentMeeting(chatAuth(), groupId, mapOf(
                        "title" to title.trim(), "starts_at" to time.toOffsetDateTime().toString(), "mode" to if (slots) "slots" else "group",
                        "slot_minutes" to if (slots) slotMinutes else null, "slots" to if (slots) slotCount.toIntOrNull() else null,
                    ))
                    title = ""; at = null; creating = false
                }
            }
        }
    }
}

// ============================================================================ shared bits

/** A parents' meeting: its time, the slots (book / mine / taken, or who booked for the teacher), the call. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ParentMeetingCard(m: ParentMeetingDto, teacher: Boolean, onBook: (String) -> Unit = {}, onCancel: () -> Unit = {}, onDelete: () -> Unit = {}) {
    val host = LocalChat.current
    Column(Modifier.fillMaxWidth().padding(vertical = 6.dp).clip(RoundedCornerShape(14.dp)).background(Ch.SurfaceMuted).padding(10.dp)) {
        Text(m.title, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp)
        Text(
            listOfNotNull(dueText(m.startsAt), stringResource(if (m.mode == "slots") R.string.pa_mode_slots else R.string.pa_mode_group)).joinToString(" · "),
            color = Ch.Mut, fontSize = 12.sp,
        )
        m.note?.let { Text(it, color = Ch.Ink, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp)) }
        if (m.slots.isNotEmpty()) {
            Spacer(Modifier.height(6.dp))
            if (teacher) {
                m.slots.forEach { s ->
                    PaLine(dueText(s.at).orEmpty(), s.parent?.let { stringResource(R.string.pa_parent_of, it.name.orEmpty(), s.student?.name.orEmpty()) } ?: stringResource(R.string.pa_free), Ch.Mut)
                }
            } else {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    m.slots.forEach { s ->
                        val label = (dueText(s.at) ?: s.at).substringAfter("· ")
                        when {
                            s.mine -> ClPill("✓ $label", true) { onCancel() }
                            s.free -> ClPill(label, false) { onBook(s.at) }
                            else -> Text(label, color = Ch.Mut.copy(alpha = 0.5f), fontSize = 13.sp, modifier = Modifier.padding(horizontal = 14.dp, vertical = 8.dp))
                        }
                    }
                }
                if (m.mySlot != null) Text(stringResource(R.string.pa_tap_to_cancel), color = Ch.Mut, fontSize = 11.5.sp, modifier = Modifier.padding(top = 4.dp))
            }
        }
        Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            m.call?.let { c -> ClPill("🎥 " + stringResource(R.string.pa_join_call), false) { host.callLinkToken = c.token } }
            if (teacher) ClPill(stringResource(R.string.wk_delete), false) { onDelete() }
        }
    }
}

@Composable
private fun attendanceLabel(status: String?): String = when (status) {
    "present" -> stringResource(R.string.pa_present)
    "late" -> stringResource(R.string.pa_late)
    "absent" -> stringResource(R.string.pa_absent)
    "excused" -> stringResource(R.string.pa_excused)
    else -> "—"
}

@Composable
private fun PaBox(content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Ch.Surface).padding(14.dp)) { content() }
}

@Composable
private fun PaTitle(text: String) {
    Text(text, color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(bottom = 4.dp))
}

@Composable
private fun PaLine(title: String, sub: String?, subColor: Color, onClick: (() -> Unit)? = null) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier).padding(vertical = 5.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(title, color = Ch.Ink, fontSize = 14.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
        sub?.let { Spacer(Modifier.width(8.dp)); Text(it, color = subColor, fontSize = 12.sp) }
    }
}

@Composable
private fun PaLoading() {
    Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
}
