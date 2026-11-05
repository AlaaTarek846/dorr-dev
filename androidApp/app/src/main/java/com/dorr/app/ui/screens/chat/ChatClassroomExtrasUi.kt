package com.dorr.app.ui.screens.chat

import android.content.Intent
import android.net.Uri
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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AutoStories
import androidx.compose.material.icons.rounded.Backpack
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.HelpOutline
import androidx.compose.material.icons.rounded.Poll
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.WorkspacePremium
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.BagDto
import com.dorr.app.network.CertificateDto
import com.dorr.app.network.HelpDto
import com.dorr.app.network.MemberDto
import com.dorr.app.network.ReadingDto
import com.dorr.app.network.StarDto
import com.dorr.app.network.apiFailure
import kotlinx.coroutines.launch

/*
 * Educational group extras (docs/groups_tasks.md GR-021, 022, 024, 025, 026, 028, 030) and help
 * requests in both kinds of group (GR-031, GR-041). A student sees only their own marks; the
 * leaderboard of a class competition is the one exception, by design.
 */

/** The classroom's "More" tab: one card per extra. */
@Composable
internal fun ClassroomMoreTab(groupId: String, perms: List<String>) {
    var open by remember { mutableStateOf<String?>(null) }
    var members by remember { mutableStateOf<List<MemberDto>>(emptyList()) }
    LaunchedEffect(groupId) { members = runCatching { ApiClient.chat.members(chatAuth(), groupId).data }.getOrNull().orEmpty() }
    val teacher = "lessons.manage" in perms
    val cards = buildList {
        if ("assignments.submit" in perms && !teacher) add(Triple("bag", Icons.Rounded.Backpack, R.string.cx_bag))
        add(Triple("schedule", Icons.Rounded.CalendarMonth, R.string.cx_schedule))
        add(Triple("stars", Icons.Rounded.Star, R.string.cx_stars))
        add(Triple("reading", Icons.Rounded.AutoStories, R.string.cx_reading))
        add(Triple("certificates", Icons.Rounded.WorkspacePremium, R.string.cx_certificates))
        if ("announcements.post" in perms) add(Triple("survey", Icons.Rounded.Poll, R.string.cx_survey))
        add(Triple("help", Icons.Rounded.HelpOutline, R.string.cx_help))
    }
    LazyColumn(contentPadding = PaddingValues(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxSize()) {
        itemsIndexed(cards, key = { _, c -> c.first }) { i, (key, icon, label) -> ClCard(icon, stringResource(label), null, index = i) { open = key } }
    }
    when (open) {
        "bag" -> BagSheet(groupId) { open = null }
        "schedule" -> ScheduleSheet(groupId) { open = null }
        "stars" -> StarsSheet(groupId, teacher, members) { open = null }
        "reading" -> ReadingSheet(groupId, teacher) { open = null }
        "certificates" -> CertificatesSheet(groupId, teacher, members) { open = null }
        "survey" -> SurveySheet(groupId) { open = null }
        "help" -> HelpRequestsSheet(groupId, members) { open = null }
    }
}

/** GR-021: homework to hand in, my grades, coming tests, my reading, stars and certificates. */
@Composable
private fun BagSheet(groupId: String, onDismiss: () -> Unit) {
    var bag by remember { mutableStateOf<BagDto?>(null) }
    LaunchedEffect(groupId) { bag = runCatching { ApiClient.classroom.bag(chatAuth(), groupId).data }.getOrNull() }
    ClSheet(stringResource(R.string.cx_bag), onDismiss) {
        val b = bag ?: return@ClSheet Loading()
        if (b.toHandIn.isNotEmpty()) {
            ClTitle(stringResource(R.string.cx_to_hand_in))
            b.toHandIn.forEach { Line(it.title, dueText(it.dueAt), if (it.isPastDue) Ch.Danger else Ch.Mut) }
        }
        if (b.grades.isNotEmpty()) {
            ClTitle(stringResource(R.string.cl_my_grade))
            b.grades.forEach { g -> Line(g.title, (g.score?.let { fmt(it) } ?: "—") + (g.max?.let { " / $it" } ?: ""), Ch.Success) }
        }
        if (b.tests.isNotEmpty()) {
            ClTitle(stringResource(R.string.cx_schedule))
            b.tests.forEach { Line(it.title, dueText(it.opensAt), Ch.Mut) }
        }
        if (b.reading.isNotEmpty()) {
            ClTitle(stringResource(R.string.cx_reading))
            b.reading.forEach { Line(it.title, null, Ch.Mut) }
        }
        ClTitle(stringResource(R.string.cx_stars_count, b.stars))
        if (b.certificates.isNotEmpty()) CertificateList(b.certificates)
    }
}

/** GR-022: the coming tests, soonest first. */
@Composable
private fun ScheduleSheet(groupId: String, onDismiss: () -> Unit) {
    var tests by remember { mutableStateOf<List<com.dorr.app.network.ScheduledTestDto>?>(null) }
    LaunchedEffect(groupId) { tests = runCatching { ApiClient.classroom.testSchedule(chatAuth(), groupId).data?.tests }.getOrNull().orEmpty() }
    ClSheet(stringResource(R.string.cx_schedule), onDismiss) {
        val list = tests ?: return@ClSheet Loading()
        if (list.isEmpty()) Text(stringResource(R.string.cl_quizzes_empty), color = Ch.Mut)
        list.forEach { t ->
            Line(
                (if (t.isCompetition) "🏆 " else "") + t.title,
                if (t.isOpen) stringResource(R.string.cl_open) else dueText(t.opensAt),
                if (t.isOpen) Ch.Success else Ch.Mut,
            )
        }
    }
}

/** GR-024: a class competition's leaderboard. */
@Composable
internal fun LeaderboardSheet(groupId: String, quizId: String, onDismiss: () -> Unit) {
    var rows by remember { mutableStateOf<List<com.dorr.app.network.LeaderRowDto>?>(null) }
    LaunchedEffect(quizId) { rows = runCatching { ApiClient.classroom.leaderboard(chatAuth(), groupId, quizId).data }.getOrNull().orEmpty() }
    ClSheet("🏆 " + stringResource(R.string.cx_leaderboard), onDismiss) {
        val list = rows ?: return@ClSheet Loading()
        list.forEach { r ->
            Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(
                    when (r.rank) { 1 -> "🥇"; 2 -> "🥈"; 3 -> "🥉"; else -> "${r.rank}" },
                    fontSize = 18.sp, modifier = Modifier.width(36.dp),
                )
                Text(r.student?.profile?.name.orEmpty(), color = Ch.Ink, fontSize = 14.5.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(fmt(r.total), color = Ch.Red, fontWeight = FontWeight.ExtraBold)
            }
        }
    }
}

/** GR-025: this week's stars; the teacher names one (a line in the chat for everyone). */
@Composable
private fun StarsSheet(groupId: String, teacher: Boolean, members: List<MemberDto>, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var stars by remember { mutableStateOf<List<StarDto>?>(null) }
    var pick by remember { mutableStateOf<Int?>(null) }
    var reason by remember { mutableStateOf("") }
    suspend fun load() { stars = runCatching { ApiClient.classroom.stars(chatAuth(), groupId).data }.getOrNull().orEmpty() }
    LaunchedEffect(groupId) { load() }
    ClSheet("⭐ " + stringResource(R.string.cx_stars), onDismiss) {
        val list = stars ?: return@ClSheet Loading()
        if (list.isEmpty()) Text(stringResource(R.string.cx_no_stars), color = Ch.Mut)
        list.forEach { Line("⭐ " + it.student?.profile?.name.orEmpty(), it.reason, Ch.Mut) }
        if (teacher) {
            ClTitle(stringResource(R.string.cx_name_star))
            StudentPicker(members, pick) { pick = it }
            ChField(reason, { reason = it.take(300) }, stringResource(R.string.cx_reason), modifier = Modifier.padding(top = 8.dp))
            Spacer(Modifier.height(10.dp))
            ChPrimaryButton(stringResource(R.string.cx_name_star), icon = Icons.Rounded.Star, modifier = Modifier.fillMaxWidth(), enabled = pick != null) {
                host.scope.launch {
                    runCatching { ApiClient.classroom.awardStar(chatAuth(), groupId, mapOf("participant_id" to pick, "reason" to reason.trim().ifBlank { null })) }
                        .onSuccess { reason = ""; pick = null; load() }
                        .onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
                }
            }
        }
    }
}

/** GR-026: the reading list — the teacher adds and sees the class's progress; a student marks theirs. */
@Composable
private fun ReadingSheet(groupId: String, teacher: Boolean, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val context = LocalContext.current
    var items by remember { mutableStateOf<List<ReadingDto>?>(null) }
    var title by remember { mutableStateOf("") }
    suspend fun load() { items = runCatching { ApiClient.classroom.reading(chatAuth(), groupId).data }.getOrNull().orEmpty() }
    LaunchedEffect(groupId) { load() }
    ClSheet("📚 " + stringResource(R.string.cx_reading), onDismiss) {
        val list = items ?: return@ClSheet Loading()
        if (list.isEmpty()) Text(stringResource(R.string.cx_no_reading), color = Ch.Mut)
        list.forEach { r ->
            Column(Modifier.fillMaxWidth().padding(vertical = 6.dp).clip(RoundedCornerShape(14.dp)).background(Ch.SurfaceMuted).padding(10.dp)) {
                Text(r.title, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp)
                listOfNotNull(r.author, r.pages?.let { stringResource(R.string.cx_pages, it) }).joinToString(" · ").takeIf { it.isNotBlank() }?.let { Text(it, color = Ch.Mut, fontSize = 12.sp) }
                r.link?.let { link -> Text(link, color = Ch.Red, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.clickable { runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(link)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) } }) }
                r.classProgress?.let { c -> Text(stringResource(R.string.cx_class_progress, c.reading, c.done, c.students), color = Ch.Mut, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp)) }
                if (r.classProgress == null) {
                    Row(Modifier.padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        listOf("reading" to R.string.cx_reading_now, "done" to R.string.cx_read_done).forEach { (st, label) ->
                            ClPill(stringResource(label), r.myStatus == st) {
                                host.scope.launch {
                                    runCatching { ApiClient.classroom.readingProgress(chatAuth(), groupId, r.id, mapOf("status" to st)) }
                                    load()
                                }
                            }
                        }
                    }
                }
            }
        }
        if (teacher) {
            ClTitle(stringResource(R.string.cx_add_reading))
            ChField(title, { title = it.take(200) }, stringResource(R.string.cl_title_hint))
            Spacer(Modifier.height(8.dp))
            ChPrimaryButton(stringResource(R.string.cx_add_reading), icon = Icons.Rounded.AutoStories, modifier = Modifier.fillMaxWidth(), enabled = title.isNotBlank()) {
                host.scope.launch {
                    runCatching { ApiClient.classroom.addReading(chatAuth(), groupId, mapOf("title" to title.trim())) }
                        .onSuccess { title = ""; load() }.onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
                }
            }
        }
    }
}

/** GR-028: certificates — mine (a PDF), or, for a teacher, everyone's and a new one. */
@Composable
private fun CertificatesSheet(groupId: String, teacher: Boolean, members: List<MemberDto>, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var list by remember { mutableStateOf<List<CertificateDto>?>(null) }
    var pick by remember { mutableStateOf<Int?>(null) }
    var title by remember { mutableStateOf("") }
    var reason by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    suspend fun load() { list = runCatching { ApiClient.classroom.certificates(chatAuth(), groupId).data }.getOrNull().orEmpty() }
    LaunchedEffect(groupId) { load() }
    ClSheet("🎓 " + stringResource(R.string.cx_certificates), onDismiss) {
        val l = list ?: return@ClSheet Loading()
        if (l.isEmpty()) Text(stringResource(R.string.cx_no_certificates), color = Ch.Mut) else CertificateList(l)
        if (teacher) {
            ClTitle(stringResource(R.string.cx_give_certificate))
            StudentPicker(members, pick) { pick = it }
            ChField(title, { title = it.take(200) }, stringResource(R.string.cl_title_hint), modifier = Modifier.padding(top = 8.dp))
            ChField(reason, { reason = it.take(500) }, stringResource(R.string.cx_reason), modifier = Modifier.padding(top = 8.dp))
            Spacer(Modifier.height(10.dp))
            ChPrimaryButton(stringResource(R.string.cx_give_certificate), icon = Icons.Rounded.WorkspacePremium, modifier = Modifier.fillMaxWidth(), enabled = pick != null && title.isNotBlank() && !busy) {
                busy = true
                host.scope.launch {
                    runCatching { ApiClient.classroom.issueCertificate(chatAuth(), groupId, mapOf("participant_id" to pick, "title" to title.trim(), "reason" to reason.trim().ifBlank { null })) }
                        .onSuccess { title = ""; reason = ""; pick = null; load() }.onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
                    busy = false
                }
            }
        }
    }
}

@Composable
private fun CertificateList(list: List<CertificateDto>) {
    val context = LocalContext.current
    list.forEach { c ->
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable(enabled = c.pdf != null) {
                runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(c.pdf)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
            }.padding(vertical = 8.dp, horizontal = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text("🎓", fontSize = 18.sp)
            Spacer(Modifier.width(8.dp))
            Column(Modifier.weight(1f)) {
                Text(c.title, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                Text(c.student?.profile?.name.orEmpty(), color = Ch.Mut, fontSize = 12.sp)
            }
        }
    }
}

/** GR-030: a survey in the chat — its results for the teachers only. */
@Composable
private fun SurveySheet(groupId: String, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var question by remember { mutableStateOf("") }
    var options by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    val sent = stringResource(R.string.cx_survey_sent)
    ClSheet(stringResource(R.string.cx_survey), onDismiss) {
        Text(stringResource(R.string.cx_survey_sub), color = Ch.Mut, fontSize = 12.5.sp, lineHeight = 17.sp)
        Spacer(Modifier.height(8.dp))
        ChField(question, { question = it.take(1000) }, stringResource(R.string.cl_q_text), singleLine = false, minLines = 2)
        ChField(options, { options = it.take(1200) }, stringResource(R.string.cl_q_options), singleLine = false, minLines = 3, modifier = Modifier.padding(top = 8.dp))
        Spacer(Modifier.height(10.dp))
        val opts = options.lines().map { it.trim() }.filter { it.isNotEmpty() }
        ChPrimaryButton(stringResource(R.string.cl_publish), icon = Icons.Rounded.Send, modifier = Modifier.fillMaxWidth(), enabled = question.isNotBlank() && opts.size >= 2 && !busy) {
            busy = true
            host.scope.launch {
                runCatching { ApiClient.classroom.survey(chatAuth(), groupId, mapOf("question" to question.trim(), "options" to opts.take(12))) }
                    .onSuccess { host.showToast(sent); onDismiss() }.onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
                busy = false
            }
        }
    }
}

/** GR-031 / GR-041: ask the teachers / the team (or one person), answer, close. */
@Composable
internal fun HelpRequestsSheet(groupId: String, members: List<MemberDto>, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var rows by remember { mutableStateOf<List<HelpDto>?>(null) }
    var question by remember { mutableStateOf("") }
    var to by remember { mutableStateOf<Int?>(null) }
    val answers = remember { mutableStateMapOf<String, String>() }
    suspend fun load() { rows = runCatching { ApiClient.classroom.helpRequests(chatAuth(), groupId, "open").data?.requests }.getOrNull().orEmpty() }
    LaunchedEffect(groupId) { load() }
    fun act(call: suspend () -> Unit) {
        host.scope.launch { runCatching { call() }.onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }; load() }
    }

    ClSheet(stringResource(R.string.cx_help), onDismiss) {
        ChField(question, { question = it.take(2000) }, stringResource(R.string.cx_help_hint), singleLine = false, minLines = 2)
        ClTitle(stringResource(R.string.cx_help_to))
        LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            item(key = "all") { ClPill(stringResource(R.string.cx_help_everyone), to == null) { to = null } }
            items(members.filter { it.profile?.isMe != true }, key = { it.participantId }) { m -> ClPill(m.profile?.name.orEmpty(), to == m.participantId) { to = m.participantId } }
        }
        Spacer(Modifier.height(8.dp))
        ChPrimaryButton(stringResource(R.string.cx_help_ask), icon = Icons.Rounded.Send, modifier = Modifier.fillMaxWidth(), enabled = question.isNotBlank()) {
            val q = question.trim()
            question = ""
            act { ApiClient.classroom.askHelp(chatAuth(), groupId, mapOf("question" to q, "to_participant_id" to to)) }
        }
        val list = rows ?: return@ClSheet Loading()
        list.forEach { h ->
            Column(Modifier.fillMaxWidth().padding(top = 10.dp).clip(RoundedCornerShape(14.dp)).background(Ch.SurfaceMuted).padding(10.dp)) {
                Text(listOfNotNull(h.asker?.profile?.name, h.to?.profile?.name?.let { "→ $it" }).joinToString(" "), color = Ch.Mut, fontSize = 12.sp)
                Text(h.question, color = Ch.Ink, fontSize = 14.sp)
                h.answer?.let { Text("↳ $it", color = Ch.Success, fontSize = 13.5.sp, modifier = Modifier.padding(top = 4.dp)) }
                if (h.can.answer) {
                    ChField(answers[h.id].orEmpty(), { answers[h.id] = it.take(5000) }, stringResource(R.string.cx_help_answer), modifier = Modifier.padding(top = 6.dp))
                    ClPill(stringResource(R.string.cx_help_send_answer), false) {
                        val a = answers[h.id]?.trim().orEmpty()
                        if (a.isNotEmpty()) act { ApiClient.classroom.answerHelp(chatAuth(), groupId, h.id, mapOf("answer" to a)) }
                    }
                }
                if (h.can.close) ClPill("✓ " + stringResource(R.string.cx_help_close), false) { act { ApiClient.classroom.closeHelp(chatAuth(), groupId, h.id) } }
            }
        }
    }
}

@Composable
private fun StudentPicker(members: List<MemberDto>, selected: Int?, onPick: (Int) -> Unit) {
    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        items(members.filter { it.role != "owner" && it.profile?.isMe != true }, key = { it.participantId }) { m ->
            ClPill(m.profile?.name.orEmpty(), selected == m.participantId) { onPick(m.participantId) }
        }
    }
}

@Composable
private fun Line(title: String, sub: String?, subColor: Color) {
    Row(Modifier.fillMaxWidth().padding(vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(title, color = Ch.Ink, fontSize = 14.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
        sub?.let { Text(it, color = subColor, fontSize = 12.sp) }
    }
}

@Composable
private fun Loading() {
    Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
}
