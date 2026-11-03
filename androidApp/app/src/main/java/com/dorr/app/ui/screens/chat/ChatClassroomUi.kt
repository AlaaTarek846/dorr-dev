@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.dorr.app.ui.screens.chat

import android.app.DatePickerDialog
import android.app.TimePickerDialog
import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AttachFile
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.EventAvailable
import androidx.compose.material.icons.rounded.MenuBook
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.Quiz
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material.icons.rounded.Assignment
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
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
import com.dorr.app.network.AssignmentDto
import com.dorr.app.network.EduFileDto
import com.dorr.app.network.GroupSpaceDto
import com.dorr.app.network.LessonDto
import com.dorr.app.network.QuizDto
import com.dorr.app.network.SessionDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsSwitchColors
import com.google.gson.JsonPrimitive
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.time.LocalDate
import java.time.LocalTime
import java.time.ZoneId
import java.time.ZonedDateTime

/*
 * An educational group's classroom (docs/groups_tasks.md GR-011..017): lessons, homework, tests and
 * class sessions, each for the whole group or one term / subgroup. Teachers see what they manage;
 * a student sees their own hand-ins, grades and attendance only. The open page reloads on
 * `chat.group.education` — never on a timer. Every rule is checked again on the server.
 */

private val Tabs = listOf(
    "lessons" to R.string.cl_lessons,
    "assignments" to R.string.cl_assignments,
    "quizzes" to R.string.cl_quizzes,
    "sessions" to R.string.cl_sessions,
    "more" to R.string.cx_more,
)

@Composable
fun ClassroomPage(groupId: String) {
    val host = LocalChat.current
    var tab by remember { mutableStateOf("lessons") }
    // Bumped on a live event: the open tab reloads once.
    var version by remember { mutableIntStateOf(0) }
    var perms by remember { mutableStateOf<List<String>>(emptyList()) }
    var spaces by remember { mutableStateOf<List<GroupSpaceDto>>(emptyList()) }
    LaunchedEffect(groupId) {
        perms = runCatching { ApiClient.chat.conversation(chatAuth(), groupId).data?.group?.myPermissions }.getOrNull().orEmpty()
        spaces = runCatching { ApiClient.chat.groupSpaces(chatAuth(), groupId).data }.getOrNull().orEmpty()
    }
    LaunchedEffect(groupId) {
        ChatRealtime.events.collect { e ->
            if (e.name == "chat.group.education" && e.data.get("conversation_id")?.asString == groupId) version++
        }
    }

    ChPage(stringResource(R.string.cl_title), onBack = { host.pop() }, settingsHeader = true) {
        Column(Modifier.fillMaxSize()) {
            // GR-011: a new class — terms and subgroups are optional; a hint until there's one.
            if (spaces.isEmpty() && "spaces.manage" in perms) SetupHint()
            LazyRow(contentPadding = PaddingValues(horizontal = 14.dp, vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                items(Tabs, key = { it.first }) { (key, label) -> ClPill(stringResource(label), tab == key) { tab = key } }
            }
            Box(Modifier.weight(1f).fillMaxWidth()) {
                when (tab) {
                    "lessons" -> LessonsTab(groupId, "lessons.manage" in perms, spaces, version)
                    "assignments" -> AssignmentsTab(groupId, "assignments.manage" in perms, spaces, version)
                    "quizzes" -> QuizzesTab(groupId, "quizzes.manage" in perms, spaces, version)
                    "sessions" -> SessionsTab(groupId, "attendance.manage" in perms, spaces, version)
                    else -> ClassroomMoreTab(groupId, perms)
                }
            }
        }
    }
}

@Composable
private fun SetupHint() {
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 6.dp).clip(RoundedCornerShape(16.dp)).background(Ch.Red.copy(alpha = 0.07f)).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text("🎓", fontSize = 20.sp)
        Spacer(Modifier.width(10.dp))
        Text(stringResource(R.string.cl_setup_hint), color = Ch.Ink, fontSize = 12.5.sp, lineHeight = 17.sp)
    }
}

// ============================================================================ shared bits

@Composable
internal fun ClPill(text: String, selected: Boolean, onClick: () -> Unit) {
    Text(
        text, color = if (selected) Color.White else Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 13.sp,
        modifier = Modifier.clip(RoundedCornerShape(50))
            .then(if (selected) Modifier.background(Ch.HeaderBrush) else Modifier.background(Ch.Surface).border(1.dp, Ch.Line, RoundedCornerShape(50)))
            .clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 8.dp),
    )
}

@Composable
internal fun ClTitle(text: String) {
    Text(text, color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(top = 14.dp, bottom = 4.dp))
}

@Composable
internal fun ClCard(icon: ImageVector, title: String, sub: String?, badge: String? = null, badgeColor: Color = Ch.Red, index: Int, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().chStagger(index).clip(RoundedCornerShape(18.dp)).background(Ch.Surface).clickable(onClick = onClick).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(Ch.Red.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(20.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(title, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
            sub?.takeIf { it.isNotBlank() }?.let { Text(it, color = Ch.Mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
        }
        badge?.let {
            Spacer(Modifier.width(8.dp))
            Text(it, color = badgeColor, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(RoundedCornerShape(50)).background(badgeColor.copy(alpha = 0.1f)).padding(horizontal = 8.dp, vertical = 3.dp))
        }
    }
}

@Composable
private fun ClList(loading: Boolean, empty: Boolean, emptyText: String, canAdd: Boolean, onAdd: () -> Unit, content: androidx.compose.foundation.lazy.LazyListScope.() -> Unit) {
    Box(Modifier.fillMaxSize()) {
        when {
            loading -> Box(Modifier.fillMaxWidth().height(200.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(28.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
            empty -> Text(emptyText, color = Ch.Mut, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(40.dp))
            else -> LazyColumn(contentPadding = PaddingValues(start = 14.dp, end = 14.dp, top = 4.dp, bottom = 96.dp), verticalArrangement = Arrangement.spacedBy(8.dp), content = content)
        }
        if (canAdd) {
            Box(
                Modifier.align(Alignment.BottomEnd).padding(18.dp).size(56.dp).shadow(12.dp, CircleShape, spotColor = Ch.Red.copy(alpha = 0.5f))
                    .clip(CircleShape).background(Ch.HeaderBrush).clickable(onClick = onAdd),
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.Add, null, tint = Color.White, modifier = Modifier.size(28.dp)) }
        }
    }
}

@Composable
internal fun ClSheet(title: String, onDismiss: () -> Unit, content: @Composable () -> Unit) {
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 20.dp)) {
            Text(title, color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            Spacer(Modifier.height(8.dp))
            content()
        }
    }
}

@Composable
internal fun ClFiles(files: List<EduFileDto>) {
    val context = LocalContext.current
    files.forEach { f ->
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { openUrl(context, f.url) }.padding(vertical = 7.dp, horizontal = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.AttachFile, null, tint = Ch.Red, modifier = Modifier.size(19.dp))
            Spacer(Modifier.width(8.dp))
            Text(f.name ?: f.id, color = Ch.Ink, fontSize = 13.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

/** Whole group, or one of its terms / subgroups. */
@Composable
private fun ScopePicker(spaces: List<GroupSpaceDto>, selected: Int?, onSelect: (Int?) -> Unit) {
    if (spaces.isEmpty()) return
    ClTitle(stringResource(R.string.cl_scope))
    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        item(key = "all") { ClPill(stringResource(R.string.cl_scope_all), selected == null) { onSelect(null) } }
        items(spaces, key = { it.id }) { s -> ClPill(s.name.orEmpty(), selected == s.id) { onSelect(s.id) } }
    }
}

/** A time: none, quick choices, or "pick" (the phone's date and time pickers). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun WhenPicker(label: String, value: ZonedDateTime?, allowNone: Boolean = true, onChange: (ZonedDateTime?) -> Unit) {
    val context = LocalContext.current
    ClTitle(label)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        if (allowNone) ClPill(stringResource(R.string.gt_due_none), value == null) { onChange(null) }
        val today = LocalDate.now().atTime(LocalTime.of(17, 0)).atZone(ZoneId.systemDefault())
        ClPill(stringResource(R.string.gt_due_tomorrow), value == today.plusDays(1)) { onChange(today.plusDays(1)) }
        ClPill(stringResource(R.string.gt_due_week), value == today.plusWeeks(1)) { onChange(today.plusWeeks(1)) }
        ClPill(value?.let { dueText(it.toOffsetDateTime().toString()) } ?: stringResource(R.string.cl_pick_time), value != null && value != today.plusDays(1) && value != today.plusWeeks(1)) {
            pickDateTime(context) { onChange(it) }
        }
    }
}

internal fun pickDateTime(context: Context, onPicked: (ZonedDateTime) -> Unit) {
    val now = ZonedDateTime.now()
    DatePickerDialog(context, { _, y, m, d ->
        TimePickerDialog(context, { _, h, min ->
            onPicked(ZonedDateTime.of(y, m + 1, d, h, min, 0, 0, ZoneId.systemDefault()))
        }, 9, 0, true).show()
    }, now.year, now.monthValue - 1, now.dayOfMonth).show()
}

internal fun pickDate(context: Context, onPicked: (LocalDate) -> Unit) {
    val now = LocalDate.now()
    DatePickerDialog(context, { _, y, m, d -> onPicked(LocalDate.of(y, m + 1, d)) }, now.year, now.monthValue - 1, now.dayOfMonth).show()
}

private fun openUrl(context: Context, url: String) {
    runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
}

private fun text(v: String): RequestBody = v.toRequestBody("text/plain".toMediaTypeOrNull())

private fun fileParts(context: Context, uris: List<Uri>, field: String = "files[]"): List<MultipartBody.Part> =
    uris.take(10).mapNotNull { copyToCache(context, it, "file") }.map { f -> MultipartBody.Part.createFormData(field, f.name, f.file.asRequestBody(f.mime.toMediaTypeOrNull())) }

// ============================================================================ GR-013 lessons

@Composable
private fun LessonsTab(groupId: String, canManage: Boolean, spaces: List<GroupSpaceDto>, version: Int) {
    val host = LocalChat.current
    var rows by remember { mutableStateOf<List<LessonDto>?>(null) }
    var query by remember { mutableStateOf("") }
    var open by remember { mutableStateOf<LessonDto?>(null) }
    var creating by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    LaunchedEffect(groupId, version, query, reload) {
        rows = runCatching { ApiClient.classroom.lessons(chatAuth(), groupId, query.ifBlank { null }, 1).data?.lessons }.getOrNull().orEmpty()
    }
    Column(Modifier.fillMaxSize()) {
        ChField(query, { query = it.take(100) }, stringResource(R.string.cl_search_lessons), modifier = Modifier.padding(horizontal = 14.dp, vertical = 4.dp), clearable = true)
        ClList(rows == null, rows?.isEmpty() == true, stringResource(R.string.cl_lessons_empty), canManage, { creating = true }) {
            itemsIndexed(rows.orEmpty(), key = { _, l -> l.id }) { i, l ->
                ClCard(Icons.Rounded.MenuBook, l.title, listOfNotNull(l.space?.name, if (l.files.isNotEmpty()) "📎 ${l.files.size}" else null).joinToString(" · "), index = i) { open = l }
            }
        }
    }
    open?.let { l ->
        ClSheet(l.title, { open = null }) {
            l.space?.name?.let { Text(it, color = Ch.Mut, fontSize = 12.5.sp) }
            l.body?.takeIf { it.isNotBlank() }?.let { Text(it, color = Ch.Ink, fontSize = 14.sp, lineHeight = 21.sp, modifier = Modifier.padding(top = 8.dp)) }
            if (l.files.isNotEmpty()) { ClTitle(stringResource(R.string.cl_files)); ClFiles(l.files) }
            if (l.canManage) {
                Text(
                    stringResource(R.string.cl_delete), color = Ch.Danger, fontWeight = FontWeight.Bold,
                    modifier = Modifier.padding(top = 14.dp).clip(RoundedCornerShape(10.dp)).clickable {
                        host.scope.launch {
                            runCatching { ApiClient.classroom.deleteLesson(chatAuth(), groupId, l.id) }.onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
                            open = null
                            rows = runCatching { ApiClient.classroom.lessons(chatAuth(), groupId, null, 1).data?.lessons }.getOrNull().orEmpty()
                        }
                    }.padding(8.dp),
                )
            }
        }
    }
    if (creating) NewLessonSheet(groupId, spaces, onDone = { creating = false; reload++ }, onDismiss = { creating = false })
}

@Composable
private fun NewLessonSheet(groupId: String, spaces: List<GroupSpaceDto>, onDone: () -> Unit, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val context = LocalContext.current
    var title by remember { mutableStateOf("") }
    var body by remember { mutableStateOf("") }
    var scope by remember { mutableStateOf<Int?>(null) }
    val files = remember { mutableStateListOf<Uri>() }
    var busy by remember { mutableStateOf(false) }
    val pick = rememberLauncherForActivityResult(ActivityResultContracts.GetMultipleContents()) { files.addAll(it.take(10 - files.size)) }

    ClSheet(stringResource(R.string.cl_new_lesson), onDismiss) {
        ChField(title, { title = it.take(200) }, stringResource(R.string.cl_title_hint), icon = Icons.Rounded.MenuBook)
        Spacer(Modifier.height(8.dp))
        ChField(body, { body = it.take(20000) }, stringResource(R.string.cl_lesson_body), singleLine = false, minLines = 4)
        ScopePicker(spaces.filter { it.type != "project" }, scope) { scope = it }
        ClTitle(stringResource(R.string.cl_files_count, files.size))
        ClPill("📎 " + stringResource(R.string.gt_proof_add_files), false) { pick.launch("*/*") }
        Spacer(Modifier.height(14.dp))
        ChPrimaryButton(stringResource(R.string.cl_publish), icon = Icons.Rounded.Send, modifier = Modifier.fillMaxWidth(), enabled = title.isNotBlank() && !busy) {
            busy = true
            host.scope.launch {
                try {
                    val fields = mutableMapOf("title" to text(title.trim()), "body" to text(body.trim()))
                    scope?.let { fields["space_id"] = text(it.toString()) }
                    ApiClient.classroom.createLesson(chatAuth(), groupId, fields, fileParts(context, files))
                    onDone()
                } catch (e: Exception) {
                    e.apiFailure().message?.let { host.showToast(it) }
                }
                busy = false
            }
        }
    }
}

// ============================================================================ GR-014 homework

@Composable
private fun AssignmentsTab(groupId: String, canManage: Boolean, spaces: List<GroupSpaceDto>, version: Int) {
    var rows by remember { mutableStateOf<List<AssignmentDto>?>(null) }
    var open by remember { mutableStateOf<String?>(null) }
    var creating by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    LaunchedEffect(groupId, version, reload) {
        rows = runCatching { ApiClient.classroom.assignments(chatAuth(), groupId, 1).data?.assignments }.getOrNull().orEmpty()
    }
    ClList(rows == null, rows?.isEmpty() == true, stringResource(R.string.cl_assignments_empty), canManage, { creating = true }) {
        itemsIndexed(rows.orEmpty(), key = { _, a -> a.id }) { i, a ->
            val (badge, color) = when {
                a.myGrade?.score != null -> stringResource(R.string.cl_grade_badge, fmt(a.myGrade.score), a.maxScore?.toString() ?: "—") to Ch.Success
                a.mySubmission != null -> stringResource(R.string.cl_handed_in) to Ch.Success
                a.can.grade -> stringResource(R.string.cl_submissions_count, a.submissionsCount ?: 0) to Ch.Red
                a.isPastDue -> stringResource(R.string.gt_late) to Ch.Danger
                else -> null to Ch.Red
            }
            ClCard(Icons.Rounded.Assignment, a.title, listOfNotNull(a.space?.name, dueText(a.dueAt)).joinToString(" · "), badge, color, i) { open = a.id }
        }
    }
    open?.let { id -> AssignmentSheet(groupId, id, onChanged = { reload++ }) { open = null } }
    if (creating) NewAssignmentSheet(groupId, spaces, onDone = { creating = false; reload++ }) { creating = false }
}

internal fun fmt(v: Double): String = if (v % 1.0 == 0.0) v.toInt().toString() else String.format(java.util.Locale.US, "%.1f", v)

@Composable
private fun AssignmentSheet(groupId: String, id: String, onChanged: () -> Unit, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val context = LocalContext.current
    var a by remember { mutableStateOf<AssignmentDto?>(null) }
    var body by remember { mutableStateOf("") }
    val files = remember { mutableStateListOf<Uri>() }
    var busy by remember { mutableStateOf(false) }
    var grading by remember { mutableStateOf(false) }
    LaunchedEffect(id) { a = runCatching { ApiClient.classroom.assignment(chatAuth(), groupId, id).data }.getOrNull(); body = a?.mySubmission?.body.orEmpty() }
    val pick = rememberLauncherForActivityResult(ActivityResultContracts.GetMultipleContents()) { files.addAll(it.take(10 - files.size)) }
    val x = a ?: return

    if (grading) {
        SubmissionsSheet(groupId, x) { grading = false }
        return
    }
    ClSheet(x.title, onDismiss) {
        Text(listOfNotNull(x.space?.name, dueText(x.dueAt)?.let { stringResource(R.string.cl_due, it) }, if (x.mode == "team") stringResource(R.string.cl_team_mode) else null).joinToString(" · "), color = if (x.isPastDue) Ch.Danger else Ch.Mut, fontSize = 12.5.sp)
        x.instructions?.takeIf { it.isNotBlank() }?.let { Text(it, color = Ch.Ink, fontSize = 14.sp, lineHeight = 21.sp, modifier = Modifier.padding(top = 8.dp)) }
        if (x.files.isNotEmpty()) { ClTitle(stringResource(R.string.cl_files)); ClFiles(x.files) }
        // Mine only: my grade and my hand-in.
        x.myGrade?.let { g ->
            ClTitle(stringResource(R.string.cl_my_grade))
            Text(stringResource(R.string.cl_grade_badge, fmt(g.score ?: 0.0), x.maxScore?.toString() ?: "—"), color = Ch.Success, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
            g.feedback?.takeIf { it.isNotBlank() }?.let { Text(it, color = Ch.Ink, fontSize = 13.5.sp) }
        }
        x.mySubmission?.let { s ->
            ClTitle(stringResource(R.string.cl_my_submission, s.version))
            if (s.isLate) Text(stringResource(R.string.gt_late), color = Ch.Danger, fontSize = 12.sp)
            ClFiles(s.files)
        }
        if (x.can.submit) {
            ClTitle(stringResource(if (x.mySubmission == null) R.string.cl_hand_in else R.string.cl_hand_in_again))
            ChField(body, { body = it.take(20000) }, stringResource(R.string.cl_answer_hint), singleLine = false, minLines = 3)
            Spacer(Modifier.height(6.dp))
            ClPill("📎 " + stringResource(R.string.cl_files_count, files.size), false) { pick.launch("*/*") }
            Spacer(Modifier.height(10.dp))
            ChPrimaryButton(stringResource(R.string.cl_hand_in), icon = Icons.Rounded.Send, modifier = Modifier.fillMaxWidth(), enabled = !busy && (body.isNotBlank() || files.isNotEmpty())) {
                busy = true
                host.scope.launch {
                    try {
                        ApiClient.classroom.submit(chatAuth(), groupId, x.id, mapOf("body" to text(body.trim())), fileParts(context, files))
                        files.clear()
                        a = ApiClient.classroom.assignment(chatAuth(), groupId, x.id).data
                        host.showToast(context.getString(R.string.cl_handed_in))
                        onChanged()
                    } catch (e: Exception) {
                        e.apiFailure().message?.let { host.showToast(it) }
                    }
                    busy = false
                }
            }
        }
        if (x.can.grade) {
            Spacer(Modifier.height(10.dp))
            ChPrimaryButton(stringResource(R.string.cl_submissions_count, x.submissionsCount ?: 0), icon = Icons.Rounded.CheckCircle, modifier = Modifier.fillMaxWidth()) { grading = true }
        }
    }
}

/** Every hand-in and the missing ones; a grade for each student (each their own). */
@Composable
private fun SubmissionsSheet(groupId: String, a: AssignmentDto, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var data by remember { mutableStateOf<com.dorr.app.network.SubmissionsDto?>(null) }
    val scores = remember { mutableStateMapOf<Int, String>() }
    suspend fun load() {
        data = runCatching { ApiClient.classroom.submissions(chatAuth(), groupId, a.id).data }.getOrNull()
        data?.submissions?.forEach { s -> s.grades?.forEach { g -> g.score?.let { scores[g.participantId] = fmt(it) } } }
    }
    LaunchedEffect(a.id) { load() }
    val saved = stringResource(R.string.cl_grade_saved)

    @Composable
    fun GradeRow(pid: Int, name: String) {
        Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
            Text(name, color = Ch.Ink, fontSize = 14.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
            ChField(scores[pid].orEmpty(), { scores[pid] = it.filter { c -> c.isDigit() || c == '.' }.take(6) }, "/${a.maxScore ?: "—"}", modifier = Modifier.width(96.dp),
                keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(keyboardType = androidx.compose.ui.text.input.KeyboardType.Decimal))
            Spacer(Modifier.width(6.dp))
            Icon(Icons.Rounded.CheckCircle, null, tint = Ch.Red, modifier = Modifier.size(28.dp).clip(CircleShape).clickable {
                host.scope.launch {
                    runCatching { ApiClient.classroom.grade(chatAuth(), groupId, a.id, mapOf("participant_id" to pid, "score" to scores[pid]?.toDoubleOrNull())) }
                        .onSuccess { host.showToast(saved) }.onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
                }
            })
        }
    }

    ClSheet(stringResource(R.string.cl_submissions_count, data?.submissions?.size ?: 0), onDismiss) {
        val d = data
        if (d == null) {
            Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
            return@ClSheet
        }
        d.submissions.forEach { s ->
            Column(Modifier.fillMaxWidth().padding(vertical = 6.dp).clip(RoundedCornerShape(14.dp)).background(Ch.SurfaceMuted).padding(10.dp)) {
                Text(
                    listOfNotNull(s.by?.profile?.name, if (s.isLate) stringResource(R.string.gt_late) else null, "v${s.version}").joinToString(" · "),
                    color = if (s.isLate) Ch.Danger else Ch.Mut, fontSize = 12.sp,
                )
                s.body?.takeIf { it.isNotBlank() }?.let { Text(it, color = Ch.Ink, fontSize = 13.5.sp, maxLines = 6, overflow = TextOverflow.Ellipsis) }
                ClFiles(s.files)
                (s.team ?: listOfNotNull(s.by)).forEach { p -> GradeRow(p.participantId, p.profile?.name.orEmpty()) }
            }
        }
        if (d.missing.isNotEmpty()) {
            ClTitle(stringResource(R.string.cl_missing, d.missing.size))
            d.missing.forEach { m -> GradeRow(m.participantId, m.profile?.name.orEmpty()) }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun NewAssignmentSheet(groupId: String, spaces: List<GroupSpaceDto>, onDone: () -> Unit, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val context = LocalContext.current
    var title by remember { mutableStateOf("") }
    var instructions by remember { mutableStateOf("") }
    var due by remember { mutableStateOf<ZonedDateTime?>(null) }
    var team by remember { mutableStateOf(false) }
    var maxScore by remember { mutableStateOf("10") }
    var scope by remember { mutableStateOf<Int?>(null) }
    val files = remember { mutableStateListOf<Uri>() }
    var busy by remember { mutableStateOf(false) }
    val pick = rememberLauncherForActivityResult(ActivityResultContracts.GetMultipleContents()) { files.addAll(it.take(10 - files.size)) }

    ClSheet(stringResource(R.string.cl_new_assignment), onDismiss) {
        ChField(title, { title = it.take(200) }, stringResource(R.string.cl_title_hint), icon = Icons.Rounded.Assignment)
        Spacer(Modifier.height(8.dp))
        ChField(instructions, { instructions = it.take(10000) }, stringResource(R.string.cl_instructions), singleLine = false, minLines = 3)
        WhenPicker(stringResource(R.string.gt_due), due) { due = it }
        ClTitle(stringResource(R.string.cl_max_score))
        ChField(maxScore, { maxScore = it.filter(Char::isDigit).take(4) }, "10", modifier = Modifier.width(120.dp))
        Row(Modifier.fillMaxWidth().padding(top = 8.dp).clickable { team = !team }, verticalAlignment = Alignment.CenterVertically) {
            Text(stringResource(R.string.cl_team_mode), color = Ch.Ink, fontSize = 14.5.sp, modifier = Modifier.weight(1f))
            Switch(team, { team = it }, colors = settingsSwitchColors())
        }
        ScopePicker(spaces.filter { it.type != "project" }, scope) { scope = it }
        ClTitle(stringResource(R.string.cl_files_count, files.size))
        ClPill("📎 " + stringResource(R.string.gt_proof_add_files), false) { pick.launch("*/*") }
        Spacer(Modifier.height(14.dp))
        ChPrimaryButton(stringResource(R.string.cl_publish), icon = Icons.Rounded.Send, modifier = Modifier.fillMaxWidth(), enabled = title.isNotBlank() && !busy) {
            busy = true
            host.scope.launch {
                try {
                    val fields = mutableMapOf("title" to text(title.trim()), "instructions" to text(instructions.trim()), "mode" to text(if (team) "team" else "individual"))
                    due?.let { fields["due_at"] = text(it.toOffsetDateTime().toString()) }
                    maxScore.toIntOrNull()?.let { fields["max_score"] = text(it.toString()) }
                    scope?.let { fields["space_id"] = text(it.toString()) }
                    ApiClient.classroom.createAssignment(chatAuth(), groupId, fields, fileParts(context, files))
                    onDone()
                } catch (e: Exception) {
                    e.apiFailure().message?.let { host.showToast(it) }
                }
                busy = false
            }
        }
    }
}

// ============================================================================ GR-015 / GR-016 tests

@Composable
private fun QuizzesTab(groupId: String, canManage: Boolean, spaces: List<GroupSpaceDto>, version: Int) {
    var rows by remember { mutableStateOf<List<QuizDto>?>(null) }
    var open by remember { mutableStateOf<String?>(null) }
    var creating by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    LaunchedEffect(groupId, version, reload) {
        rows = runCatching { ApiClient.classroom.quizzes(chatAuth(), groupId, 1).data?.quizzes }.getOrNull().orEmpty()
    }
    ClList(rows == null, rows?.isEmpty() == true, stringResource(R.string.cl_quizzes_empty), canManage, { creating = true }) {
        itemsIndexed(rows.orEmpty(), key = { _, q -> q.id }) { i, q ->
            val badge = when {
                q.myAttempt?.total != null -> fmt(q.myAttempt.total) + " / " + fmt(q.totalPoints)
                q.myAttempt?.waitingForMarking == true -> stringResource(R.string.cl_waiting_marking)
                q.myAttempt?.submittedAt != null -> stringResource(R.string.cl_handed_in)
                q.can.manage && !q.isPublished -> stringResource(R.string.cl_draft)
                q.isOpen -> stringResource(R.string.cl_open)
                else -> null
            }
            ClCard(Icons.Rounded.Quiz, q.title, stringResource(R.string.cl_quiz_sub, q.questionsCount, q.durationMinutes ?: 0), badge, index = i) { open = q.id }
        }
    }
    open?.let { id -> QuizSheet(groupId, id, onChanged = { reload++ }) { open = null } }
    if (creating) QuizBuilderSheet(groupId, spaces, onDone = { creating = false; reload++ }) { creating = false }
}

/** A student takes the test (one go); a teacher publishes it and marks written answers. */
@Composable
private fun QuizSheet(groupId: String, id: String, onChanged: () -> Unit, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var q by remember { mutableStateOf<QuizDto?>(null) }
    val answers = remember { mutableStateMapOf<Int, Any>() }
    var busy by remember { mutableStateOf(false) }
    var marking by remember { mutableStateOf(false) }
    var board by remember { mutableStateOf(false) }
    LaunchedEffect(id) { q = runCatching { ApiClient.classroom.quiz(chatAuth(), groupId, id).data }.getOrNull() }
    val x = q ?: return

    fun act(call: suspend () -> QuizDto?) {
        busy = true
        host.scope.launch {
            try { call()?.let { q = it; onChanged() } } catch (e: Exception) { e.apiFailure().message?.let { host.showToast(it) } }
            busy = false
        }
    }

    if (marking) {
        MarkingSheet(groupId, x) { marking = false }
        return
    }
    if (board) {
        LeaderboardSheet(groupId, x.id) { board = false }
        return
    }
    ClSheet(x.title, onDismiss) {
        Text(stringResource(R.string.cl_quiz_sub, x.questionsCount, x.durationMinutes ?: 0), color = Ch.Mut, fontSize = 12.5.sp)
        if (x.isCompetition) ClPill("🏆 " + stringResource(R.string.cx_leaderboard), false) { board = true }
        x.instructions?.takeIf { it.isNotBlank() }?.let { Text(it, color = Ch.Ink, fontSize = 14.sp, modifier = Modifier.padding(top = 6.dp)) }
        val attempt = x.myAttempt
        when {
            x.can.manage -> {
                Row(Modifier.fillMaxWidth().padding(top = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(stringResource(R.string.cl_published), color = Ch.Ink, fontSize = 14.5.sp, modifier = Modifier.weight(1f))
                    Switch(x.isPublished, { on -> act { ApiClient.classroom.updateQuiz(chatAuth(), groupId, x.id, mapOf("is_published" to on)).data } }, colors = settingsSwitchColors())
                }
                if (x.can.grade) {
                    Spacer(Modifier.height(8.dp))
                    ChPrimaryButton(stringResource(R.string.cl_attempts, x.attemptsCount ?: 0), icon = Icons.Rounded.CheckCircle, modifier = Modifier.fillMaxWidth()) { marking = true }
                }
                x.questions?.forEachIndexed { i, qu -> Text("${i + 1}. ${qu.text}  (${fmt(qu.points)})", color = Ch.Ink, fontSize = 13.5.sp, modifier = Modifier.padding(top = 8.dp)) }
            }
            attempt?.submittedAt != null -> {
                ClTitle(stringResource(R.string.cl_my_result))
                Text(
                    if (attempt.total != null) fmt(attempt.total) + " / " + fmt(x.totalPoints) else stringResource(R.string.cl_waiting_marking),
                    color = Ch.Success, fontWeight = FontWeight.ExtraBold, fontSize = 20.sp,
                )
            }
            attempt == null -> if (x.can.take && x.isOpen) {
                Spacer(Modifier.height(12.dp))
                ChPrimaryButton(stringResource(R.string.cl_start_quiz), icon = Icons.Rounded.PlayArrow, modifier = Modifier.fillMaxWidth(), enabled = !busy) {
                    act { ApiClient.classroom.startQuiz(chatAuth(), groupId, x.id).data }
                }
            } else Text(stringResource(R.string.cl_not_open), color = Ch.Mut, modifier = Modifier.padding(top = 10.dp))
            else -> {
                // Taking it: the questions, without the answers.
                x.questions.orEmpty().forEachIndexed { i, qu ->
                    ClTitle("${i + 1}. ${qu.text}  (${fmt(qu.points)})")
                    when (qu.type) {
                        "choice" -> qu.options.orEmpty().forEachIndexed { oi, opt ->
                            val on = answers[qu.id] == oi
                            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { answers[qu.id] = oi }.padding(6.dp), verticalAlignment = Alignment.CenterVertically) {
                                Icon(if (on) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (on) Ch.Red else Ch.Soft, modifier = Modifier.size(21.dp))
                                Spacer(Modifier.width(8.dp))
                                Text(opt, color = Ch.Ink, fontSize = 14.sp)
                            }
                        }
                        "true_false" -> Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            ClPill(stringResource(R.string.cl_true), answers[qu.id] == true) { answers[qu.id] = true }
                            ClPill(stringResource(R.string.cl_false), answers[qu.id] == false) { answers[qu.id] = false }
                        }
                        else -> ChField((answers[qu.id] as? String).orEmpty(), { answers[qu.id] = it.take(5000) }, stringResource(R.string.cl_answer_hint), singleLine = false, minLines = 3)
                    }
                }
                Spacer(Modifier.height(14.dp))
                ChPrimaryButton(stringResource(R.string.cl_submit_quiz), icon = Icons.Rounded.Send, modifier = Modifier.fillMaxWidth(), enabled = !busy) {
                    val body = answers.entries.associate { (k, v) -> k.toString() to (if (v is Int) listOf(v) else v) }
                    act { ApiClient.classroom.submitQuiz(chatAuth(), groupId, x.id, mapOf("answers" to body)).data }
                }
            }
        }
    }
}

/** Written answers waiting for points; the rest are already marked. */
@Composable
private fun MarkingSheet(groupId: String, quiz: QuizDto, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var attempts by remember { mutableStateOf<List<com.dorr.app.network.AttemptDto>?>(null) }
    val points = remember { mutableStateMapOf<String, String>() }
    suspend fun load() { attempts = runCatching { ApiClient.classroom.attempts(chatAuth(), groupId, quiz.id).data }.getOrNull().orEmpty() }
    LaunchedEffect(quiz.id) { load() }
    val essays = quiz.questions.orEmpty().filter { it.type == "essay" }
    val saved = stringResource(R.string.cl_grade_saved)

    ClSheet(stringResource(R.string.cl_attempts, attempts?.size ?: 0), onDismiss) {
        attempts.orEmpty().forEach { at ->
            Column(Modifier.fillMaxWidth().padding(vertical = 6.dp).clip(RoundedCornerShape(14.dp)).background(Ch.SurfaceMuted).padding(10.dp)) {
                Text(
                    "${at.student?.profile?.name.orEmpty()} · ${fmt(at.total)}" + if (at.needsMarking) " · " + stringResource(R.string.cl_waiting_marking) else "",
                    color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 13.5.sp,
                )
                essays.forEach { qu ->
                    val answer = at.answers?.get(qu.id.toString())?.takeIf { it is JsonPrimitive }?.asString.orEmpty()
                    Text("${qu.text}: $answer", color = Ch.Mut, fontSize = 12.5.sp, modifier = Modifier.padding(top = 4.dp))
                    val key = "${at.id}:${qu.id}"
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        ChField(points[key] ?: at.marks?.get(qu.id.toString())?.let { fmt(it) }.orEmpty(), { points[key] = it.filter { c -> c.isDigit() || c == '.' }.take(5) }, "/${fmt(qu.points)}", modifier = Modifier.width(100.dp),
                            keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(keyboardType = androidx.compose.ui.text.input.KeyboardType.Decimal))
                        Spacer(Modifier.width(6.dp))
                        Icon(Icons.Rounded.CheckCircle, null, tint = Ch.Red, modifier = Modifier.size(28.dp).clip(CircleShape).clickable {
                            val v = points[key]?.toDoubleOrNull() ?: return@clickable
                            host.scope.launch {
                                runCatching { ApiClient.classroom.mark(chatAuth(), groupId, quiz.id, at.id, mapOf("marks" to mapOf(qu.id.toString() to v))) }
                                    .onSuccess { host.showToast(saved); load() }.onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
                            }
                        })
                    }
                }
            }
        }
    }
}

/** A new test: its questions (choice / true-false / written), time limit, and where it's for. Saved as a draft. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun QuizBuilderSheet(groupId: String, spaces: List<GroupSpaceDto>, onDone: () -> Unit, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var title by remember { mutableStateOf("") }
    var duration by remember { mutableStateOf("") }
    var scope by remember { mutableStateOf<Int?>(null) }
    var publish by remember { mutableStateOf(false) }
    var competition by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    val questions = remember { mutableStateListOf(Draft()) }

    ClSheet(stringResource(R.string.cl_new_quiz), onDismiss) {
        ChField(title, { title = it.take(200) }, stringResource(R.string.cl_title_hint), icon = Icons.Rounded.Quiz)
        ClTitle(stringResource(R.string.cl_duration))
        ChField(duration, { duration = it.filter(Char::isDigit).take(3) }, "20", modifier = Modifier.width(120.dp))
        ScopePicker(spaces.filter { it.type != "project" }, scope) { scope = it }
        questions.forEachIndexed { i, d ->
            Column(Modifier.fillMaxWidth().padding(top = 12.dp).clip(RoundedCornerShape(16.dp)).background(Ch.SurfaceMuted).padding(10.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("${i + 1}", color = Ch.Red, fontWeight = FontWeight.ExtraBold)
                    Spacer(Modifier.width(8.dp))
                    FlowRow(Modifier.weight(1f), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        listOf("choice" to R.string.cl_q_choice, "true_false" to R.string.cl_q_tf, "essay" to R.string.cl_q_essay).forEach { (k, l) ->
                            ClPill(stringResource(l), d.type == k) { questions[i] = d.copy(type = k) }
                        }
                    }
                    if (questions.size > 1) Icon(Icons.Rounded.Close, null, tint = Ch.Soft, modifier = Modifier.size(22.dp).clickable { questions.removeAt(i) })
                }
                Spacer(Modifier.height(6.dp))
                ChField(d.text, { questions[i] = d.copy(text = it.take(5000)) }, stringResource(R.string.cl_q_text), singleLine = false, minLines = 2)
                when (d.type) {
                    "choice" -> {
                        ChField(d.options, { questions[i] = d.copy(options = it.take(2000)) }, stringResource(R.string.cl_q_options), singleLine = false, minLines = 3, modifier = Modifier.padding(top = 6.dp))
                        val opts = d.options.lines().map { it.trim() }.filter { it.isNotEmpty() }
                        if (opts.isNotEmpty()) {
                            Text(stringResource(R.string.cl_q_correct), color = Ch.Mut, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp))
                            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                opts.forEachIndexed { oi, o -> ClPill(o, d.correct == oi) { questions[i] = d.copy(correct = oi) } }
                            }
                        }
                    }
                    "true_false" -> Row(Modifier.padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        ClPill(stringResource(R.string.cl_true), d.truth) { questions[i] = d.copy(truth = true) }
                        ClPill(stringResource(R.string.cl_false), !d.truth) { questions[i] = d.copy(truth = false) }
                    }
                }
                ChField(d.points, { questions[i] = d.copy(points = it.filter { c -> c.isDigit() || c == '.' }.take(4)) }, stringResource(R.string.cl_q_points), modifier = Modifier.width(120.dp).padding(top = 6.dp))
            }
        }
        Spacer(Modifier.height(8.dp))
        ClPill("＋ " + stringResource(R.string.cl_add_question), false) { questions.add(Draft()) }
        Row(Modifier.fillMaxWidth().padding(top = 8.dp).clickable { competition = !competition }, verticalAlignment = Alignment.CenterVertically) {
            Text("🏆 " + stringResource(R.string.cx_competition), color = Ch.Ink, fontSize = 14.5.sp, modifier = Modifier.weight(1f))
            Switch(competition, { competition = it }, colors = settingsSwitchColors())
        }
        Row(Modifier.fillMaxWidth().padding(top = 8.dp).clickable { publish = !publish }, verticalAlignment = Alignment.CenterVertically) {
            Text(stringResource(R.string.cl_publish_now), color = Ch.Ink, fontSize = 14.5.sp, modifier = Modifier.weight(1f))
            Switch(publish, { publish = it }, colors = settingsSwitchColors())
        }
        Spacer(Modifier.height(12.dp))
        ChPrimaryButton(stringResource(R.string.cl_save_quiz), icon = Icons.Rounded.CheckCircle, modifier = Modifier.fillMaxWidth(), enabled = title.isNotBlank() && !busy) {
            busy = true
            host.scope.launch {
                try {
                    val qs = questions.filter { it.text.isNotBlank() }.map { d ->
                        mapOf(
                            "type" to d.type, "text" to d.text.trim(), "points" to (d.points.toDoubleOrNull() ?: 1.0),
                            "options" to if (d.type == "choice") d.options.lines().map { it.trim() }.filter { it.isNotEmpty() } else null,
                            "correct" to when (d.type) { "choice" -> listOf(d.correct); "true_false" -> d.truth; else -> null },
                        )
                    }
                    val created = ApiClient.classroom.createQuiz(chatAuth(), groupId, mapOf(
                        "title" to title.trim(), "duration_minutes" to duration.toIntOrNull(), "space_id" to scope, "questions" to qs, "is_competition" to competition,
                    )).data
                    if (publish && created != null) ApiClient.classroom.updateQuiz(chatAuth(), groupId, created.id, mapOf("is_published" to true))
                    onDone()
                } catch (e: Exception) {
                    e.apiFailure().message?.let { host.showToast(it) }
                }
                busy = false
            }
        }
    }
}

private data class Draft(val type: String = "choice", val text: String = "", val options: String = "", val correct: Int = 0, val truth: Boolean = true, val points: String = "1")

// ============================================================================ GR-017 sessions and attendance

@Composable
private fun SessionsTab(groupId: String, canManage: Boolean, spaces: List<GroupSpaceDto>, version: Int) {
    var whenFilter by remember { mutableStateOf("upcoming") }
    var rows by remember { mutableStateOf<List<SessionDto>?>(null) }
    var open by remember { mutableStateOf<String?>(null) }
    var creating by remember { mutableStateOf(false) }
    var mine by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    LaunchedEffect(groupId, version, whenFilter, reload) {
        rows = runCatching { ApiClient.classroom.sessions(chatAuth(), groupId, whenFilter, 1).data?.sessions }.getOrNull().orEmpty()
    }
    Column(Modifier.fillMaxSize()) {
        Row(Modifier.padding(horizontal = 14.dp, vertical = 4.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            ClPill(stringResource(R.string.cl_upcoming), whenFilter == "upcoming") { whenFilter = "upcoming" }
            ClPill(stringResource(R.string.cl_past), whenFilter == "past") { whenFilter = "past" }
            if (!canManage) ClPill("📋 " + stringResource(R.string.cl_my_attendance), false) { mine = true }
        }
        ClList(rows == null, rows?.isEmpty() == true, stringResource(R.string.cl_sessions_empty), canManage, { creating = true }) {
            itemsIndexed(rows.orEmpty(), key = { _, s -> s.id }) { i, s ->
                ClCard(Icons.Rounded.EventAvailable, s.title, listOfNotNull(dueText(s.startsAt), s.space?.name).joinToString(" · "), s.myStatus?.let { statusLabel(it) }, statusColor(s.myStatus), i) { open = s.id }
            }
        }
    }
    open?.let { id -> SessionSheet(groupId, id) { open = null } }
    if (creating) NewSessionSheet(groupId, spaces, onDone = { creating = false; reload++ }) { creating = false }
    if (mine) MyAttendanceSheet(groupId) { mine = false }
}

@Composable
private fun statusLabel(s: String): String = stringResource(
    when (s) {
        "present" -> R.string.cl_present
        "absent" -> R.string.cl_absent
        "late" -> R.string.cl_late
        else -> R.string.cl_excused
    },
)

private fun statusColor(s: String?): Color = when (s) {
    "present" -> Color(0xFF10B981)
    "absent" -> Color(0xFFDC2626)
    "late" -> Color(0xFFF59E0B)
    "excused" -> Color(0xFF3B82F6)
    else -> Color(0xFF9CA3AF)
}

/** The register (teacher): each student's status, saved together. A student sees only their own. */
@Composable
private fun SessionSheet(groupId: String, id: String, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var s by remember { mutableStateOf<SessionDto?>(null) }
    val statuses = remember { mutableStateMapOf<Int, String>() }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(id) {
        s = runCatching { ApiClient.classroom.session(chatAuth(), groupId, id).data }.getOrNull()
        s?.register?.forEach { r -> r.status?.let { statuses[r.participantId] = it } }
    }
    val x = s ?: return
    val saved = stringResource(R.string.cl_attendance_saved)

    ClSheet(x.title, onDismiss) {
        Text(listOfNotNull(dueText(x.startsAt), x.space?.name).joinToString(" · "), color = Ch.Mut, fontSize = 12.5.sp)
        val register = x.register
        if (register == null) {
            ClTitle(stringResource(R.string.cl_my_status))
            Text(x.myStatus?.let { statusLabel(it) } ?: stringResource(R.string.cl_not_recorded), color = statusColor(x.myStatus), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
            return@ClSheet
        }
        register.forEach { r ->
            Column(Modifier.fillMaxWidth().padding(vertical = 6.dp)) {
                Text(r.profile?.name.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                Row(Modifier.padding(top = 4.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    listOf("present", "absent", "late", "excused").forEach { st ->
                        val on = statuses[r.participantId] == st
                        Text(
                            statusLabel(st), color = if (on) Color.White else statusColor(st), fontSize = 12.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.clip(RoundedCornerShape(50)).background(if (on) statusColor(st) else statusColor(st).copy(alpha = 0.1f))
                                .clickable { statuses[r.participantId] = st }.padding(horizontal = 10.dp, vertical = 6.dp),
                        )
                    }
                }
            }
        }
        Spacer(Modifier.height(12.dp))
        ChPrimaryButton(stringResource(R.string.cl_save_attendance), icon = Icons.Rounded.CheckCircle, modifier = Modifier.fillMaxWidth(), enabled = statuses.isNotEmpty() && !busy) {
            busy = true
            host.scope.launch {
                try {
                    s = ApiClient.classroom.recordAttendance(chatAuth(), groupId, x.id, mapOf("entries" to statuses.map { (pid, st) -> mapOf("participant_id" to pid, "status" to st) })).data ?: s
                    host.showToast(saved)
                } catch (e: Exception) {
                    e.apiFailure().message?.let { host.showToast(it) }
                }
                busy = false
            }
        }
    }
}

@Composable
private fun NewSessionSheet(groupId: String, spaces: List<GroupSpaceDto>, onDone: () -> Unit, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var title by remember { mutableStateOf("") }
    var at by remember { mutableStateOf<ZonedDateTime?>(null) }
    var scope by remember { mutableStateOf<Int?>(null) }
    var busy by remember { mutableStateOf(false) }

    ClSheet(stringResource(R.string.cl_new_session), onDismiss) {
        ChField(title, { title = it.take(200) }, stringResource(R.string.cl_title_hint), icon = Icons.Rounded.EventAvailable)
        WhenPicker(stringResource(R.string.cl_session_time), at, allowNone = false) { at = it }
        ScopePicker(spaces.filter { it.type != "project" }, scope) { scope = it }
        Spacer(Modifier.height(14.dp))
        ChPrimaryButton(stringResource(R.string.cl_publish), icon = Icons.Rounded.Send, modifier = Modifier.fillMaxWidth(), enabled = title.isNotBlank() && at != null && !busy) {
            busy = true
            host.scope.launch {
                try {
                    ApiClient.classroom.createSession(chatAuth(), groupId, mapOf("title" to title.trim(), "starts_at" to at?.toOffsetDateTime()?.toString(), "space_id" to scope))
                    onDone()
                } catch (e: Exception) {
                    e.apiFailure().message?.let { host.showToast(it) }
                }
                busy = false
            }
        }
    }
}

@Composable
private fun MyAttendanceSheet(groupId: String, onDismiss: () -> Unit) {
    var report by remember { mutableStateOf<com.dorr.app.network.AttendanceReportDto?>(null) }
    LaunchedEffect(groupId) { report = runCatching { ApiClient.classroom.myAttendance(chatAuth(), groupId).data }.getOrNull() }

    ClSheet(stringResource(R.string.cl_my_attendance), onDismiss) {
        val r = report ?: return@ClSheet
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf("present", "absent", "late", "excused").forEach { st ->
                Column(Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(statusColor(st).copy(alpha = 0.1f)).padding(10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    Text((r.counts[st] ?: 0).toString(), color = statusColor(st), fontWeight = FontWeight.ExtraBold, fontSize = 20.sp)
                    Text(statusLabel(st), color = Ch.Mut, fontSize = 11.sp, maxLines = 1)
                }
            }
        }
        r.sessions.forEach { s ->
            Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(s.title, color = Ch.Ink, fontSize = 14.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(s.myStatus?.let { statusLabel(it) }.orEmpty(), color = statusColor(s.myStatus), fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}

// ============================================================================ GR-012 the next term

/** The next term: its name and dates, and which of this term's members move on (all by default). */
@Composable
internal fun NextTermSheet(groupId: String, term: GroupSpaceDto, members: List<com.dorr.app.network.MemberDto>, onDone: () -> Unit, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val context = LocalContext.current
    var name by remember { mutableStateOf("") }
    var starts by remember { mutableStateOf<LocalDate?>(null) }
    var ends by remember { mutableStateOf<LocalDate?>(null) }
    val picked = remember { mutableStateListOf<Int>() }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(term.id) {
        val inTerm = runCatching { ApiClient.classroom.spaceMembers(chatAuth(), groupId, term.id).data?.participantIds }.getOrNull().orEmpty()
        picked.clear(); picked.addAll(inTerm.filter { id -> members.any { it.participantId == id && it.role != "owner" } })
    }

    ClSheet(stringResource(R.string.cl_next_term), onDismiss) {
        Text(stringResource(R.string.cl_next_term_sub, term.name.orEmpty()), color = Ch.Mut, fontSize = 12.5.sp, lineHeight = 17.sp)
        Spacer(Modifier.height(10.dp))
        ChField(name, { name = it.take(100) }, stringResource(R.string.ch_space_name))
        Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            ClPill("📅 " + (starts?.toString() ?: stringResource(R.string.cl_starts)), starts != null) { pickDate(context) { starts = it } }
            ClPill("📅 " + (ends?.toString() ?: stringResource(R.string.cl_ends)), ends != null) { pickDate(context) { ends = it } }
        }
        ClTitle(stringResource(R.string.ch_space_members_pick, picked.size))
        members.filter { it.role != "owner" }.forEach { m ->
            val on = m.participantId in picked
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { if (on) picked.remove(m.participantId) else picked.add(m.participantId) }.padding(vertical = 7.dp, horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(m.profile?.name.orEmpty(), color = Ch.Ink, fontSize = 14.5.sp, modifier = Modifier.weight(1f))
                Icon(if (on) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (on) Ch.Red else Ch.Soft, modifier = Modifier.size(22.dp))
            }
        }
        Spacer(Modifier.height(12.dp))
        ChPrimaryButton(stringResource(R.string.cl_start_next_term), icon = Icons.Rounded.PlayArrow, modifier = Modifier.fillMaxWidth(), enabled = name.isNotBlank() && !busy) {
            busy = true
            host.scope.launch {
                try {
                    ApiClient.classroom.nextTerm(chatAuth(), groupId, term.id, mapOf("name" to name.trim(), "starts_on" to starts?.toString(), "ends_on" to ends?.toString(), "members" to picked.toList()))
                    onDone()
                } catch (e: Exception) {
                    e.apiFailure().message?.let { host.showToast(it) }
                }
                busy = false
            }
        }
    }
}
