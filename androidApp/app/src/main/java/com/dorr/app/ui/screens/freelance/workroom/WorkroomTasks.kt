package com.dorr.app.ui.screens.freelance.workroom

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
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AutoMode
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.DeleteForever
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.WorkPartDto
import com.dorr.app.network.WorkStatusRequest
import com.dorr.app.network.WorkTaskDto
import com.dorr.app.network.WorkTaskSummaryDto
import com.dorr.app.network.WorkTasksDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.components.PullToRefreshHost
import com.dorr.app.ui.screens.ConfirmDialog
import com.dorr.app.ui.screens.freelance.Chip
import com.dorr.app.ui.screens.freelance.FlAction
import com.dorr.app.ui.screens.freelance.FlCorners
import com.dorr.app.ui.screens.freelance.FlNotice
import com.dorr.app.ui.screens.freelance.FlRetry
import com.dorr.app.ui.screens.freelance.FlSheet
import com.dorr.app.ui.screens.freelance.FlSkeletonList
import com.dorr.app.ui.screens.freelance.FlStat
import com.dorr.app.ui.screens.freelance.FlTone
import com.dorr.app.ui.screens.freelance.FlDotPill
import com.dorr.app.ui.screens.freelance.NearEnd
import com.dorr.app.ui.screens.freelance.PagedSpinner
import com.dorr.app.ui.screens.freelance.auth
import com.dorr.app.ui.screens.freelance.message
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

private val STATUSES = listOf("todo", "doing", "blocked", "done")

/**
 * The Workroom's tasks: what each side has to do on the project. A summary on top (how far it is), filters, and a card for each task with
 * its part, its day and who it is for; whoever the task is on (or wrote it) changes its status with one tap. Read once when it opens, on a
 * live event, on a pull and when the connection returns.
 */
@Composable
internal fun TasksTab(
    project: String,
    parts: List<WorkPartDto>,
    freelancer: String,
    live: WorkroomLive,
    onSummary: (WorkTaskSummaryDto, Boolean) -> Unit,
) {
    val generic = stringResource(R.string.fl_error_generic)
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    var status by rememberSaveable { mutableStateOf<String?>(null) }
    var mineOnly by rememberSaveable { mutableStateOf(false) }
    var overdueOnly by rememberSaveable { mutableStateOf(false) }
    var version by remember { mutableIntStateOf(0) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var editing by remember { mutableStateOf<WorkTaskDto?>(null) }
    var creating by remember { mutableStateOf(false) }
    var statusFor by remember { mutableStateOf<WorkTaskDto?>(null) }
    var deleteFor by remember { mutableStateOf<WorkTaskDto?>(null) }

    val meta = remember(project) { mutableStateOf<WorkTasksDto?>(null) }
    val pager = remember(project, status, mineOnly, overdueOnly) {
        val s = status
        val m = if (mineOnly) 1 else null
        val o = if (overdueOnly) 1 else null
        workPager(meta, { it.tasks }, { it.uuid }) { page ->
            ApiClient.freelanceWorkroom.tasks(auth(), project, status = s, mine = m, overdue = o, perPage = WORK_PAGE, page = page)
        }
    }
    LaunchedEffect(pager, reconnect, live.tasks, version) { pager.reload(generic) }
    val answer = meta.value
    LaunchedEffect(answer) { answer?.let { onSummary(it.summary, it.readOnly) } }
    val listState = rememberLazyListState()
    listState.NearEnd(pager.rows.size) { scope.launch { pager.loadMore(generic) } }

    fun act(block: suspend () -> Unit) {
        busy = true
        error = null
        scope.launch {
            try {
                block()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.message(generic)
            }
            busy = false
        }
    }

    fun changeStatus(task: WorkTaskDto, next: String) = act {
        val result = ApiClient.freelanceWorkroom.setTaskStatus(auth(), project, task.uuid, WorkStatusRequest(next)).data
        if (result != null) {
            pager.update({ it.uuid == task.uuid }) { result.task }
            meta.value = meta.value?.copy(summary = result.summary)
        }
        statusFor = null
        version++
    }

    val summary = answer?.summary
    val canCreate = answer != null && answer.can.taskCreate && !answer.readOnly

    PullToRefreshHost(Modifier.fillMaxSize()) {
        LazyColumn(
            Modifier.fillMaxSize(), state = listState,
            contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 4.dp, bottom = 28.dp), verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            if (summary != null) item(key = "summary") { TasksSummary(summary) }
            if (canCreate) item(key = "add") { FlAction(stringResource(R.string.wr_task_add), Icons.Rounded.Add, FlTone.Primary) { creating = true } }
            if (summary != null && summary.total > 0) item(key = "filters") {
                WrChipRow {
                    Chip(stringResource(R.string.wr_all), status == null && !mineOnly && !overdueOnly, Icons.Rounded.TaskAlt) { status = null; mineOnly = false; overdueOnly = false }
                    STATUSES.forEach { s ->
                        val n = when (s) { "todo" -> summary.todo; "doing" -> summary.doing; "blocked" -> summary.blocked; else -> summary.done }
                        Chip(taskLabel(s) + "  $n", status == s, statusIcon(s)) { status = if (status == s) null else s }
                    }
                    Chip(stringResource(R.string.wr_task_mine), mineOnly, Icons.Rounded.Person) { mineOnly = !mineOnly }
                    if (summary.overdue > 0) Chip(stringResource(R.string.wr_task_overdue) + "  ${summary.overdue}", overdueOnly, Icons.Rounded.Schedule) { overdueOnly = !overdueOnly }
                }
            }
            error?.let { item(key = "error") { FlNotice(it, Icons.Rounded.ErrorOutline, WrRed) } }
            if (answer?.readOnly == true) item(key = "readonly") { FlNotice(stringResource(R.string.wr_read_only), Icons.Rounded.Lock, WrAmber) }
            when {
                !pager.loaded && pager.error == null -> item(key = "loading") { FlSkeletonList(3, 96.dp) }
                pager.rows.isEmpty() && pager.error != null -> item(key = "failed") { FlRetry(pager.error ?: generic) }
                pager.rows.isEmpty() -> item(key = "empty") {
                    val filtered = status != null || mineOnly || overdueOnly
                    WrEmpty(
                        Icons.Rounded.TaskAlt,
                        stringResource(if (filtered) R.string.wr_task_none_filtered else R.string.wr_task_none),
                        stringResource(if (filtered) R.string.wr_task_none_filtered_body else R.string.wr_task_none_body),
                    )
                }
            }
            items(pager.rows, key = { it.uuid }) { task ->
                TaskCard(
                    task, freelancer, busy,
                    onToggle = { changeStatus(task, if (task.status == "done") "todo" else "done") },
                    onStatus = { statusFor = task },
                    onEdit = { editing = task },
                    onDelete = { deleteFor = task },
                )
            }
            item(key = "more") { PagedSpinner(pager) }
        }
    }

    if (creating) TaskSheet(null, parts, freelancer, busy, onDismiss = { if (!busy) creating = false }) { body ->
        act {
            ApiClient.freelanceWorkroom.createTask(auth(), project, body)
            creating = false
            version++
        }
    }
    editing?.let { task ->
        TaskSheet(task, parts, freelancer, busy, onDismiss = { if (!busy) editing = null }) { body ->
            act {
                ApiClient.freelanceWorkroom.updateTask(auth(), project, task.uuid, body)
                editing = null
                version++
            }
        }
    }
    statusFor?.let { task ->
        FlSheet(Icons.Rounded.AutoMode, stringResource(R.string.wr_task_status_title), task.title, onDismiss = { if (!busy) statusFor = null }) {
            STATUSES.forEach { s ->
                FlAction(taskLabel(s), if (s == task.status) Icons.Rounded.Check else statusIcon(s), if (s == task.status) FlTone.Primary else FlTone.Soft, enabled = !busy) {
                    if (s == task.status) statusFor = null else changeStatus(task, s)
                }
            }
        }
    }
    deleteFor?.let { task ->
        ConfirmDialog(
            Icons.Rounded.DeleteForever, stringResource(R.string.wr_task_delete_title), stringResource(R.string.wr_task_delete_body, task.title),
            onConfirm = { act { ApiClient.freelanceWorkroom.deleteTask(auth(), project, task.uuid); deleteFor = null; version++ } },
            onDismiss = { if (!busy) deleteFor = null }, loading = busy, confirmLabel = stringResource(R.string.wr_delete),
        )
    }
}

private fun statusIcon(status: String): ImageVector = when (status) {
    "doing" -> Icons.Rounded.AutoMode
    "blocked" -> Icons.Rounded.Block
    "done" -> Icons.Rounded.CheckCircle
    else -> Icons.Rounded.RadioButtonUnchecked
}

/** How far the work is: done of all, a bar, and the numbers that ask for something. */
@Composable
private fun TasksSummary(s: WorkTaskSummaryDto) {
    val accent = settingsAccent()
    Column(Modifier.fillMaxWidth().settingsSurface(FlCorners.card).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(stringResource(R.string.wr_task_progress, s.done, s.total), color = settingsInk(), fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
                Text(stringResource(R.string.wr_task_progress_line), color = settingsMut(), fontSize = 12.sp)
            }
            Text(
                if (s.total == 0) "0%" else "${s.done * 100 / s.total}%", color = accent, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold,
            )
        }
        Box(Modifier.fillMaxWidth().height(8.dp).clip(RoundedCornerShape(999.dp)).background(accent.copy(alpha = 0.12f))) {
            if (s.total > 0 && s.done > 0) Box(Modifier.fillMaxHeight().fillMaxWidth(s.done.toFloat() / s.total).clip(RoundedCornerShape(999.dp)).background(WrGreen))
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FlStat(Icons.Rounded.AutoMode, stringResource(R.string.wr_task_open), s.open.toString(), Modifier.weight(1f))
            FlStat(Icons.Rounded.Schedule, stringResource(R.string.wr_task_overdue), s.overdue.toString(), Modifier.weight(1f))
            FlStat(Icons.Rounded.Block, stringResource(R.string.wr_task_blocked), s.blocked.toString(), Modifier.weight(1f))
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun TaskCard(
    task: WorkTaskDto, freelancer: String, busy: Boolean,
    onToggle: () -> Unit, onStatus: () -> Unit, onEdit: () -> Unit, onDelete: () -> Unit,
) {
    val tone = taskTone(task.status)
    val done = task.status == "done"
    Row(
        Modifier.fillMaxWidth().settingsSurface(FlCorners.card).padding(14.dp),
        horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.Top,
    ) {
        // One tap finishes a task (or opens it again) — for whoever may change its status.
        val canToggle = task.can.taskStatus && !busy
        Box(
            Modifier.size(28.dp).clip(CircleShape)
                .background(if (done) WrGreen else Color.Transparent)
                .border(2.dp, if (done) WrGreen else tone.copy(alpha = 0.55f), CircleShape)
                .then(if (canToggle) Modifier.clickable(role = Role.Checkbox, onClick = onToggle) else Modifier),
            contentAlignment = Alignment.Center,
        ) {
            if (done) Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(17.dp))
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(7.dp)) {
            Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(
                    task.title, color = if (done) settingsMut() else settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 21.sp,
                    textDecoration = if (done) TextDecoration.LineThrough else null, modifier = Modifier.weight(1f),
                )
                FlDotPill(taskLabel(task.status), tone, modifier = Modifier.then(if (task.can.taskStatus) Modifier.clickable(role = Role.Button, onClick = onStatus) else Modifier))
            }
            task.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp, maxLines = 3, overflow = TextOverflow.Ellipsis) }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                task.milestone?.let { WrTag("${it.position}. ${it.title}", Icons.Rounded.Flag, settingsAccent()) }
                task.dueOn?.takeIf { it.isNotBlank() }?.let { WrTag(stringResource(R.string.wr_due, wrDay(it)), Icons.Rounded.Event, if (task.overdue) WrRed else WrGray) }
                WrTag(
                    if (task.assigneeSide == "client") stringResource(R.string.wr_for_you) else stringResource(R.string.wr_for_them, sideName("freelancer", freelancer)),
                    Icons.Rounded.Person, if (task.assigneeSide == "client") WrViolet else WrGray,
                )
            }
            Text(
                stringResource(R.string.wr_added_by, sideName(task.createdBySide, freelancer), wrDay(task.createdAt)),
                color = settingsMut(), fontSize = 11.5.sp,
            )
            if (task.can.taskEdit || task.can.taskDelete) Row(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                if (task.can.taskEdit) WrLink(stringResource(R.string.wr_edit), Icons.Rounded.Edit, settingsAccent(), onEdit)
                if (task.can.taskDelete) WrLink(stringResource(R.string.wr_delete), Icons.Rounded.DeleteForever, WrRed, onDelete)
            }
        }
    }
}

/** A small action under a card: an icon and a word in its own colour. */
@Composable
internal fun WrLink(text: String, icon: ImageVector, color: Color, onClick: () -> Unit) {
    Row(
        Modifier.clip(RoundedCornerShape(999.dp)).clickable(role = Role.Button, onClick = onClick).padding(horizontal = 4.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp),
    ) {
        Icon(icon, null, tint = color, modifier = Modifier.size(15.dp))
        Text(text, color = color, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
    }
}

/** The form of a task: a new one, or the one being changed. Saves one map of fields; a part or a day cleared goes as empty text. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun TaskSheet(
    initial: WorkTaskDto?, parts: List<WorkPartDto>, freelancer: String, busy: Boolean,
    onDismiss: () -> Unit, onSave: (Map<String, Any?>) -> Unit,
) {
    var title by rememberSaveable { mutableStateOf(initial?.title.orEmpty()) }
    var description by rememberSaveable { mutableStateOf(initial?.description.orEmpty()) }
    var part by rememberSaveable { mutableStateOf(initial?.milestone?.uuid) }
    var due by rememberSaveable { mutableStateOf(initial?.dueOn?.take(10).orEmpty()) }
    var side by rememberSaveable { mutableStateOf(initial?.assigneeSide ?: "freelancer") }
    FlSheet(
        Icons.Rounded.TaskAlt, stringResource(if (initial == null) R.string.wr_task_new else R.string.wr_task_edit), stringResource(R.string.wr_task_form_body), onDismiss = onDismiss,
        footer = {
            FlAction(stringResource(R.string.common_save), Icons.Rounded.Check, FlTone.Primary, enabled = title.trim().length >= 3, loading = busy) {
                onSave(
                    mapOf(
                        "title" to title.trim(),
                        "description" to description.trim(),
                        "milestone" to (part ?: ""),
                        "due_on" to due,
                        "assignee_side" to side,
                    ),
                )
            }
            FlAction(stringResource(R.string.common_cancel), null, enabled = !busy, onClick = onDismiss)
        },
    ) {
        DorrTextField(title, { title = it.take(160) }, label = stringResource(R.string.wr_task_title), icon = Icons.Rounded.TaskAlt, modifier = Modifier.fillMaxWidth())
        DorrTextField(description, { description = it.take(2000) }, label = stringResource(R.string.wr_task_description), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(stringResource(R.string.wr_for), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Chip(stringResource(R.string.wr_you), side == "client", Icons.Rounded.Person, fit = true) { side = "client" }
                Chip(freelancer.ifBlank { stringResource(R.string.wr_freelancer) }, side == "freelancer", Icons.Rounded.Person, fit = true) { side = "freelancer" }
            }
        }
        WrDateField(stringResource(R.string.wr_task_due), due, { due = it }, min = if (initial?.dueOn.isNullOrBlank()) java.time.LocalDate.now() else null)
        if (parts.isNotEmpty()) Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(stringResource(R.string.wr_part), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            PartPicker(parts, part) { part = it }
        }
    }
}
