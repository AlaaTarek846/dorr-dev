package com.dorr.freelance.ui.screens.workroom

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Checklist
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.AppLocale
import com.dorr.freelance.net.OfferTeamDto
import com.dorr.freelance.net.WorkPartDto
import com.dorr.freelance.net.WorkTaskDto
import com.dorr.freelance.net.WorkTaskSummaryDto
import com.dorr.freelance.net.WorkStatusRequest
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.ChipRow
import com.dorr.freelance.ui.components.DateField
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.kit.ConfirmDialog
import com.dorr.freelance.ui.kit.Corners
import com.dorr.freelance.ui.kit.FilterOption
import com.dorr.freelance.ui.kit.FilterChips
import com.dorr.freelance.ui.kit.RedToggle
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.StatusPill
import com.dorr.freelance.ui.kit.Tints
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import kotlinx.coroutines.launch

/** The tasks of the Workroom: the read, the numbers for the chips and what I may do. */
internal class TasksState(private val project: String) {
    var filter by mutableStateOf("all")
    var summary by mutableStateOf(WorkTaskSummaryDto())
    var readOnly by mutableStateOf(false)
    var canCreate by mutableStateOf(false)
    var max by mutableStateOf<Int?>(null)

    val feed = WorkFeed<WorkTaskDto>({ it.uuid }) { page ->
        val f = filter
        val r = apiCall {
            ApiClient.workroom.tasks(
                project, status = f.takeIf { it in TASK_STATUSES }, assignee = if (f == "client") "client" else null,
                mine = if (f == "mine") 1 else null, overdue = if (f == "overdue") 1 else null, perPage = WORK_PAGE, page = page,
            )
        }
        if (r is ApiResult.Ok) {
            summary = r.data.summary
            readOnly = r.data.readOnly
            canCreate = r.data.can.taskCreate
            max = r.data.limit.max
        }
        r.rows { it.tasks }
    }
}

/** The tab of the tasks: the numbers, the filter, the cards and the button to add. */
@Composable
internal fun TasksTab(project: String, state: TasksState, parts: List<WorkPartDto>, team: OfferTeamDto?, onToast: (String) -> Unit, onChanged: () -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var editing by remember { mutableStateOf<WorkTaskDto?>(null) }
    var adding by remember { mutableStateOf(false) }
    val s = state.summary

    fun setStatus(t: WorkTaskDto, status: String) {
        if (t.status == status) return
        scope.launch {
            val r = apiCall { ApiClient.workroom.setTaskStatus(project, t.uuid, WorkStatusRequest(status)) }
            if (r is ApiResult.Ok) {
                state.summary = r.data.summary
                state.feed.paged.update({ it.uuid == t.uuid }, { r.data.task })
                onChanged()
            } else onToast(r.errorText(offlineText) ?: genericError)
        }
    }

    val filters = listOf(
        FilterOption("all", stringResource(R.string.wr_f_all)),
        FilterOption("mine", stringResource(R.string.wr_f_mine)),
        FilterOption("client", stringResource(R.string.wr_f_client)),
        FilterOption("overdue", stringResource(R.string.wr_f_overdue), dot = s.overdue > 0),
        FilterOption("todo", stringResource(R.string.wr_task_todo)),
        FilterOption("doing", stringResource(R.string.wr_task_doing)),
        FilterOption("blocked", stringResource(R.string.wr_task_blocked)),
        FilterOption("done", stringResource(R.string.wr_task_done)),
    )

    FeedList(
        feed = state.feed, keyOf = { it.uuid },
        empty = Triple(Icons.Rounded.Checklist, stringResource(R.string.wr_tasks_empty_title), stringResource(R.string.wr_tasks_empty_body)),
        fabLabel = if (state.canCreate && !state.readOnly) stringResource(R.string.wr_task_add) else null,
        onFab = if (state.canCreate && !state.readOnly) ({ adding = true }) else null,
        header = {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                if (state.readOnly) ReadOnlyBanner()
                TaskNumbers(s)
                FilterChips(filters, state.filter, { key ->
                    if (key != state.filter) {
                        state.filter = key
                        scope.launch { state.feed.reload() }
                    }
                })
            }
        },
    ) { t ->
        TaskCard(t, onStatus = { setStatus(t, it) }, onOpen = { editing = t })
    }

    if (adding) TaskSheet(project, null, parts, team, state.max, state.summary.total, onDismiss = { adding = false }, onSaved = { adding = false; scope.launch { state.feed.reload() }; onChanged() }, onToast = onToast)
    editing?.let { t ->
        TaskSheet(project, t, parts, team, state.max, state.summary.total, readOnly = state.readOnly || !t.can.taskEdit, onDismiss = { editing = null }, onSaved = { editing = null; scope.launch { state.feed.reload() }; onChanged() }, onToast = onToast)
    }
}

/** Four numbers over the list: what is open, in progress, done and late. */
@Composable
private fun TaskNumbers(s: WorkTaskSummaryDto) {
    val total = s.total.coerceAtLeast(1)
    val doneShare = s.done.toFloat() / total
    Column(Modifier.fillMaxWidth().clip(Corners.card).background(settingsMut().copy(alpha = 0.07f)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            NumberCell(s.open.toString(), stringResource(R.string.wr_n_open), settingsInk())
            NumberCell(s.doing.toString(), stringResource(R.string.wr_task_doing), Tints.blue)
            NumberCell(s.done.toString(), stringResource(R.string.wr_task_done), Tints.green)
            NumberCell(s.overdue.toString(), stringResource(R.string.wr_f_overdue), if (s.overdue > 0) WorkRed else settingsMut())
        }
        Box(Modifier.fillMaxWidth().height(6.dp).clip(CircleShape).background(settingsMut().copy(alpha = 0.16f))) {
            Box(Modifier.fillMaxWidth(doneShare.coerceIn(0f, 1f)).height(6.dp).clip(CircleShape).background(Tints.green))
        }
    }
}

@Composable
private fun NumberCell(value: String, label: String, tint: androidx.compose.ui.graphics.Color) {
    Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(1.dp)) {
        Text(value, color = tint, fontSize = 20.sp, fontWeight = FontWeight.Black)
        Text(label, color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
    }
}

/** One task: its status (tap to change), the title, where it belongs, when it is due and who does it. */
@Composable
private fun TaskCard(t: WorkTaskDto, onStatus: (String) -> Unit, onOpen: () -> Unit) {
    val locale = AppLocale.java()
    var menu by remember { mutableStateOf(false) }
    val done = t.status == "done"
    WorkCard(onClick = onOpen, accent = if (t.overdue && !done) WorkRed else null) {
        Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box {
                Box(
                    Modifier.size(36.dp).clip(CircleShape).background(taskTint(t.status).copy(alpha = 0.14f)).clickable(enabled = t.can.taskStatus) { menu = true },
                    contentAlignment = Alignment.Center,
                ) { Icon(taskIcon(t.status), contentDescription = taskLabel(t.status), tint = taskTint(t.status), modifier = Modifier.size(22.dp)) }
                DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                    TASK_STATUSES.forEach { st ->
                        DropdownMenuItem(
                            leadingIcon = { Icon(taskIcon(st), contentDescription = null, tint = taskTint(st), modifier = Modifier.size(18.dp)) },
                            text = { Text(taskLabel(st), fontWeight = if (st == t.status) FontWeight.ExtraBold else FontWeight.Normal) },
                            onClick = { menu = false; onStatus(st) },
                        )
                    }
                }
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(
                    t.title, color = if (done) settingsMut() else settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis,
                    textDecoration = if (done) TextDecoration.LineThrough else null,
                )
                t.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp, maxLines = 2, overflow = TextOverflow.Ellipsis) }
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth()) {
            if (t.scope == "team") TeamOnlyPill()
            t.dueOn?.let { d ->
                val text = workDay(d, locale) ?: d
                StatusPill(if (t.overdue && !done) stringResource(R.string.wr_due_late, text) else stringResource(R.string.wr_due, text), if (t.overdue && !done) WorkRed else settingsMut(), Icons.Rounded.CalendarMonth)
            }
            StatusPill(t.assignee?.name ?: sideName(t.assigneeSide), if (t.assigneeSide == MY_SIDE) Tints.teal else Tints.violet, Icons.Rounded.Person)
        }
        t.milestone?.let { PartTag(it) }
    }
}

/** Adding a task, or the task itself: edit it (when I may) or just read it. */
@Composable
private fun TaskSheet(
    project: String, task: WorkTaskDto?, parts: List<WorkPartDto>, team: OfferTeamDto?, max: Int?, count: Int,
    readOnly: Boolean = false, onDismiss: () -> Unit, onSaved: () -> Unit, onToast: (String) -> Unit,
) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val locale = AppLocale.java()
    var title by rememberSaveable { mutableStateOf(task?.title.orEmpty()) }
    var description by rememberSaveable { mutableStateOf(task?.description.orEmpty()) }
    var part by rememberSaveable { mutableStateOf(task?.milestone?.uuid) }
    var due by rememberSaveable { mutableStateOf(task?.dueOn.orEmpty()) }
    var side by rememberSaveable { mutableStateOf(task?.assigneeSide ?: MY_SIDE) }
    var person by rememberSaveable { mutableStateOf(task?.assignee?.uuid) }
    var teamOnly by rememberSaveable { mutableStateOf(task?.scope == "team") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var confirmDelete by remember { mutableStateOf(false) }
    val staff = team?.members?.filter { !it.freelancer.isNullOrBlank() }.orEmpty()
    val atLimit = task == null && max != null && count >= max

    fun save() {
        if (title.isBlank()) { error = null; return }
        busy = true
        error = null
        scope.launch {
            val r = if (task == null) {
                val body = buildMap<String, Any?> {
                    put("title", title.trim())
                    if (description.isNotBlank()) put("description", description.trim())
                    part?.let { put("milestone", it) }
                    if (due.isNotBlank()) put("due_on", due)
                    put("assignee_side", if (teamOnly) MY_SIDE else side)
                    if (side == MY_SIDE && person != null) put("assignee", person)
                    if (teamOnly) put("scope", "team")
                }
                apiCall { ApiClient.workroom.createTask(project, body) }
            } else {
                val body = mapOf<String, Any?>(
                    "title" to title.trim(), "description" to description.trim(), "milestone" to (part ?: ""), "due_on" to due,
                    "assignee_side" to side, "assignee" to (if (side == MY_SIDE) person ?: "" else ""),
                )
                apiCall { ApiClient.workroom.updateTask(project, task.uuid, body) }
            }
            busy = false
            if (r is ApiResult.Ok) onSaved() else error = r.errorText(offlineText) ?: genericError
        }
    }

    WorkSheet(
        title = stringResource(if (task == null) R.string.wr_task_new else if (readOnly) R.string.wr_task_view else R.string.wr_task_edit),
        onDismiss = onDismiss, dismissible = !busy,
    ) {
        if (readOnly && task != null) {
            Text(task.title, color = settingsInk(), fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
            task.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsInk(), fontSize = 14.sp, lineHeight = 21.sp) }
            WorkFact(stringResource(R.string.wr_status), taskLabel(task.status))
            task.milestone?.let { WorkFact(stringResource(R.string.wr_part), stringResource(R.string.wr_part_tag, it.position, it.title)) }
            task.dueOn?.let { WorkFact(stringResource(R.string.wr_due_label), workDay(it, locale) ?: it) }
            WorkFact(stringResource(R.string.wr_assignee), task.assignee?.name ?: sideName(task.assigneeSide))
            if (task.scope == "team") TeamOnlyPill()
            return@WorkSheet
        }
        if (atLimit) Text(stringResource(R.string.wr_tasks_limit, max ?: 0), color = WorkRed, fontSize = 12.5.sp, lineHeight = 18.sp)
        DorrTextField(value = title, onValueChange = { if (it.length <= 160) title = it }, label = stringResource(R.string.wr_task_title), placeholder = stringResource(R.string.wr_task_title_hint), modifier = Modifier.fillMaxWidth())
        DorrTextField(value = description, onValueChange = { if (it.length <= 2000) description = it }, label = stringResource(R.string.wr_description), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
        if (parts.isNotEmpty()) {
            Text(stringResource(R.string.wr_part), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
            PartPicker(parts, part) { part = it }
        }
        DateField(value = due, onChange = { due = it }, label = stringResource(R.string.wr_due_label), modifier = Modifier.fillMaxWidth())
        if (!teamOnly) {
            Text(stringResource(R.string.wr_assignee), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
            ChipRow(
                options = listOf(MY_SIDE to stringResource(R.string.wr_side_me), "client" to stringResource(R.string.wr_side_client)),
                selected = setOf(side), onToggle = { side = it; if (it != MY_SIDE) person = null },
            )
        }
        if (side == MY_SIDE && staff.isNotEmpty()) {
            Text(stringResource(R.string.wr_person), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
            ChipRow(
                options = listOf("" to stringResource(R.string.wr_nobody)) + staff.map { (it.freelancer ?: "") to it.name },
                selected = setOf(person ?: ""), onToggle = { person = it.ifEmpty { null } },
            )
        }
        // FS-207: inside tasks of a team (the client never sees them) are chosen when a task is made.
        if (team != null && task == null) Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(stringResource(R.string.wr_team_only), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                Text(stringResource(R.string.wr_team_only_hint), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
            }
            RedToggle(on = teamOnly, onClick = { teamOnly = !teamOnly; if (teamOnly) side = MY_SIDE })
        }
        error?.let { Text(it, color = WorkRed, fontSize = 12.5.sp) }
        SettingsPrimaryButton(text = stringResource(R.string.wr_save), loading = busy, enabled = title.isNotBlank() && !atLimit, onClick = ::save)
        if (task != null && task.can.taskDelete) Row(
            Modifier.fillMaxWidth().clip(Corners.small).clickable(enabled = !busy) { confirmDelete = true }.padding(12.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.Center,
        ) {
            Icon(Icons.Rounded.Delete, contentDescription = null, tint = WorkRed, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(6.dp))
            Text(stringResource(R.string.wr_task_delete), color = WorkRed, fontSize = 14.sp, fontWeight = FontWeight.Bold)
        }
    }
    if (confirmDelete && task != null) ConfirmDialog(
        icon = Icons.Rounded.Delete, title = stringResource(R.string.wr_task_delete), message = stringResource(R.string.wr_task_delete_ask), confirmLabel = stringResource(R.string.wr_delete), loading = busy,
        onConfirm = {
            busy = true
            scope.launch {
                val r = apiCall { ApiClient.workroom.deleteTask(project, task.uuid) }
                busy = false
                confirmDelete = false
                if (r is ApiResult.Ok) onSaved() else onToast(r.errorText(offlineText) ?: genericError)
            }
        },
        onDismiss = { confirmDelete = false },
    )
}
