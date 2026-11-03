@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.dorr.app.ui.screens.chat

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
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AttachFile
import androidx.compose.material.icons.rounded.ChatBubbleOutline
import androidx.compose.material.icons.rounded.FileDownload
import androidx.compose.material.icons.rounded.Insights
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
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
import com.dorr.app.network.GroupRolesDto
import com.dorr.app.network.GroupTaskDto
import com.dorr.app.network.MemberDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsSwitchColors
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.time.LocalDate
import java.time.LocalTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/*
 * A work group's tasks (docs/groups_tasks.md GR-033..036): the list (open, mine, waiting for
 * review, done), a task's sheet (checklist, proof, start / finish, approve / send back), and a new
 * task (assignee, priority, due, approval, proof, repeat, checklist). What each button does is
 * checked again on the server; the page reloads on `chat.group.task` — never on a timer.
 */

private val Filters = listOf("open" to R.string.gt_filter_open, "mine" to R.string.gt_filter_mine, "review" to R.string.gt_filter_review, "done" to R.string.gt_filter_done)

@Composable
fun GroupTasksPage(groupId: String, spaceId: Int? = null, spaceName: String? = null) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    val listState = rememberLazyListState()
    var filter by remember { mutableStateOf("open") }
    var tasks by remember { mutableStateOf<List<GroupTaskDto>?>(null) }
    var page by remember { mutableIntStateOf(1) }
    var more by remember { mutableStateOf(false) }
    var loadingMore by remember { mutableStateOf(false) }
    var openTask by remember { mutableStateOf<GroupTaskDto?>(null) }
    var creating by remember { mutableStateOf(false) }
    var members by remember { mutableStateOf<List<MemberDto>>(emptyList()) }
    var reportOpen by remember { mutableStateOf(false) }
    // Who may see the team report (reports.view) — from the group's own data.
    var canReport by remember { mutableStateOf(false) }
    LaunchedEffect(groupId) {
        canReport = runCatching { ApiClient.chat.conversation(chatAuth(), groupId).data?.group?.myPermissions?.contains("reports.view") }.getOrNull() == true
    }

    suspend fun load(reset: Boolean) {
        if (!reset && (loadingMore || !more)) return
        if (!reset) loadingMore = true
        val next = if (reset) 1 else page + 1
        val result = runCatching {
            ApiClient.chat.groupTasks(chatAuth(), groupId, if (filter == "mine") "open" else filter, if (filter == "mine") 1 else null, next, spaceId).data
        }.getOrNull()
        tasks = if (reset) result?.tasks.orEmpty() else tasks.orEmpty() + result?.tasks.orEmpty().filter { t -> tasks.orEmpty().none { it.id == t.id } }
        more = result?.hasMore == true
        page = next
        loadingMore = false
        openTask = openTask?.let { o -> tasks.orEmpty().firstOrNull { it.id == o.id } ?: o }
    }
    LaunchedEffect(filter) { tasks = null; load(reset = true) }
    LaunchedEffect(groupId) { members = runCatching { ApiClient.chat.members(chatAuth(), groupId).data }.getOrNull().orEmpty() }
    // Live: a task here changed → one reload.
    LaunchedEffect(groupId) {
        ChatRealtime.events.collect { e ->
            if (e.name == "chat.group.task" && e.data.get("conversation_id")?.asString == groupId) load(reset = true)
        }
    }
    LaunchedEffect(listState, tasks?.size, more) {
        snapshotFlow { listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index to listState.layoutInfo.totalItemsCount }
            .collect { (last, total) -> if (last != null && total > 0 && last >= total - 3 && more) scope.launch { load(reset = false) } }
    }

    ChPage(
        spaceName?.let { stringResource(R.string.gt_project_tasks, it) } ?: stringResource(R.string.gt_title),
        onBack = { host.pop() },
        actions = { if (canReport && spaceId == null) GlassIcon(Icons.Rounded.Insights, size = 38.dp) { reportOpen = true } },
        settingsHeader = true,
    ) {
        Box(Modifier.fillMaxSize()) {
            Column(Modifier.fillMaxSize()) {
                LazyRow(contentPadding = PaddingValues(horizontal = 14.dp, vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(Filters, key = { it.first }) { (key, label) ->
                        Pill(stringResource(label), selected = filter == key) { filter = key }
                    }
                }
                val list = tasks
                when {
                    list == null -> Box(Modifier.fillMaxWidth().height(200.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(28.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
                    list.isEmpty() -> Column(Modifier.fillMaxWidth().padding(40.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        Icon(Icons.Rounded.TaskAlt, null, tint = Ch.Red.copy(alpha = 0.5f), modifier = Modifier.size(56.dp))
                        Spacer(Modifier.height(10.dp))
                        Text(stringResource(R.string.gt_empty), color = Ch.Mut, textAlign = TextAlign.Center)
                    }
                    else -> LazyColumn(state = listState, contentPadding = PaddingValues(start = 14.dp, end = 14.dp, top = 4.dp, bottom = 96.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        itemsIndexed(list, key = { _, t -> t.id }) { i, t -> TaskCard(t, i) { openTask = t } }
                    }
                }
            }
            Box(
                Modifier.align(Alignment.BottomEnd).padding(18.dp).size(56.dp).shadow(12.dp, CircleShape, spotColor = Ch.Red.copy(alpha = 0.5f))
                    .clip(CircleShape).background(Ch.HeaderBrush).clickable { creating = true },
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.Add, stringResource(R.string.gt_new), tint = Color.White, modifier = Modifier.size(28.dp)) }
        }
    }

    openTask?.let { t -> TaskSheet(groupId, t, onChanged = { fresh -> openTask = fresh; scope.launch { load(reset = true) } }, onDismiss = { openTask = null }) }
    if (creating) NewTaskSheet(groupId, members, spaceId = spaceId, onDone = { creating = false; scope.launch { load(reset = true) } }, onDismiss = { creating = false })
    if (reportOpen) TaskReportSheet(groupId) { reportOpen = false }
}

@Composable
private fun Pill(text: String, selected: Boolean, onClick: () -> Unit) {
    Text(
        text, color = if (selected) Color.White else Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 13.sp,
        modifier = Modifier.clip(RoundedCornerShape(50))
            .then(if (selected) Modifier.background(Ch.HeaderBrush) else Modifier.background(Ch.Surface).border(1.dp, Ch.Line, RoundedCornerShape(50)))
            .clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 8.dp),
    )
}

private fun priorityColor(p: String) = when (p) {
    "urgent" -> Color(0xFFDC2626)
    "high" -> Color(0xFFF59E0B)
    "low" -> Color(0xFF9CA3AF)
    else -> Color(0xFF3B82F6)
}

@Composable
private fun statusLabel(s: String): String = stringResource(
    when (s) {
        "in_progress" -> R.string.gt_status_in_progress
        "in_review" -> R.string.gt_status_in_review
        "done" -> R.string.gt_status_done
        "cancelled" -> R.string.gt_status_cancelled
        else -> R.string.gt_status_new
    },
)

internal fun dueText(iso: String?): String? = iso?.let {
    runCatching {
        OffsetDateTime.parse(it).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("d MMM · HH:mm", Locale.getDefault()))
    }.getOrNull()
}

@Composable
private fun TaskCard(t: GroupTaskDto, index: Int, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().chStagger(index).clip(RoundedCornerShape(18.dp)).background(Ch.Surface).clickable(onClick = onClick).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(10.dp).clip(CircleShape).background(priorityColor(t.priority)))
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(t.title, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
            Text(
                listOfNotNull(
                    t.assignee?.profile?.name,
                    t.space?.name,
                    dueText(t.dueAt),
                    if (t.items.isNotEmpty()) "☑ ${t.itemsDone}/${t.items.size}" else null,
                ).joinToString(" · "),
                color = if (t.isLate) Ch.Danger else Ch.Mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
            )
        }
        Spacer(Modifier.width(8.dp))
        Text(
            statusLabel(t.status), color = Ch.Red, fontSize = 11.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.clip(RoundedCornerShape(50)).background(Ch.Red.copy(alpha = 0.1f)).padding(horizontal = 8.dp, vertical = 3.dp),
        )
    }
}

/** One task: details, checklist, proof, and what I may do with it. */
@Composable
private fun TaskSheet(groupId: String, t: GroupTaskDto, onChanged: (GroupTaskDto) -> Unit, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val context = LocalContext.current
    var busy by remember { mutableStateOf(false) }
    var note by remember(t.id) { mutableStateOf(t.proof?.note.orEmpty()) }
    var reviewNote by remember(t.id) { mutableStateOf("") }

    fun act(call: suspend () -> GroupTaskDto?) {
        busy = true
        host.scope.launch {
            try {
                call()?.let(onChanged)
            } catch (e: Exception) {
                e.apiFailure().message?.let { host.showToast(it) }
            }
            busy = false
        }
    }
    val pick = rememberLauncherForActivityResult(ActivityResultContracts.GetMultipleContents()) { uris ->
        if (uris.isEmpty()) return@rememberLauncherForActivityResult
        act {
            val parts = uris.take(5).mapNotNull { copyToCache(context, it, "proof") }.map { f ->
                MultipartBody.Part.createFormData("files[]", f.name, f.file.asRequestBody(f.mime.toMediaTypeOrNull()))
            }
            ApiClient.chat.addGroupTaskProof(chatAuth(), groupId, t.id, parts, note.takeIf { it.isNotBlank() }?.toRequestBody("text/plain".toMediaTypeOrNull())).data
        }
    }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 20.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(12.dp).clip(CircleShape).background(priorityColor(t.priority)))
                Spacer(Modifier.width(8.dp))
                Text(t.title, color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp, modifier = Modifier.weight(1f))
            }
            Text(
                listOfNotNull(statusLabel(t.status), t.assignee?.profile?.name, dueText(t.dueAt), t.space?.name).joinToString(" · "),
                color = if (t.isLate) Ch.Danger else Ch.Mut, fontSize = 12.5.sp, modifier = Modifier.padding(top = 4.dp),
            )
            if (t.needsApproval || t.requiresProof || t.repeat != null) {
                Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    if (t.needsApproval) Tag(stringResource(R.string.gt_needs_approval))
                    if (t.requiresProof) Tag(stringResource(R.string.gt_requires_proof))
                    t.repeat?.let { Tag("↻ " + repeatLabel(it)) }
                }
            }
            t.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = Ch.Ink, fontSize = 14.sp, lineHeight = 20.sp, modifier = Modifier.padding(top = 12.dp)) }
            // Made from a message (GR-038): its words only while I may still read it; a tap opens it in its chat.
            t.source?.let { src ->
                Row(
                    Modifier.padding(top = 12.dp).fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Ch.SurfaceMuted)
                        .clickable(enabled = src.available && src.conversationId != null) {
                            val c = src.conversationId ?: return@clickable
                            src.messageId?.let { host.focusRequest = c to it }
                            onDismiss()
                            host.push(ChRoute.Conversation(c))
                        }.padding(10.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.ChatBubbleOutline, null, tint = Ch.Mut, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.width(8.dp))
                    Text(
                        if (src.available) "“${src.excerpt.orEmpty()}”" else stringResource(R.string.gt_source_gone),
                        color = if (src.available) Ch.Ink else Ch.Soft, fontSize = 13.sp, maxLines = 3, overflow = TextOverflow.Ellipsis,
                    )
                }
            }
            t.reviewNote?.takeIf { it.isNotBlank() && t.status == "in_progress" }?.let {
                Row(
                    Modifier.padding(top = 12.dp).fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Color(0xFFF59E0B).copy(alpha = 0.12f)).padding(10.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.Undo, null, tint = Color(0xFFD97706), modifier = Modifier.size(18.dp))
                    Spacer(Modifier.width(8.dp))
                    Text(stringResource(R.string.gt_returned_note, it), color = Ch.Ink, fontSize = 13.sp)
                }
            }

            if (t.items.isNotEmpty()) {
                SectionTitle(stringResource(R.string.gt_checklist, t.itemsDone, t.items.size))
                t.items.forEach { item ->
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp))
                            .clickable(enabled = t.can.work && !busy) { act { ApiClient.chat.tickGroupTaskItem(chatAuth(), groupId, t.id, item.id, mapOf("done" to !item.done)).data } }
                            .padding(vertical = 7.dp, horizontal = 4.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(if (item.done) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (item.done) Ch.Success else Ch.Soft, modifier = Modifier.size(21.dp))
                        Spacer(Modifier.width(10.dp))
                        Text(item.text, color = if (item.done) Ch.Mut else Ch.Ink, fontSize = 14.sp)
                    }
                }
            }

            // Proof of the work (GR-035): files open from their signed links.
            if (t.requiresProof || t.proof?.files?.isNotEmpty() == true || !t.proof?.note.isNullOrBlank() || t.can.work) {
                SectionTitle(stringResource(R.string.gt_proof))
                t.proof?.files?.forEach { f ->
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable {
                            runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(f.url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
                        }.padding(vertical = 7.dp, horizontal = 4.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Icons.Rounded.AttachFile, null, tint = Ch.Red, modifier = Modifier.size(19.dp))
                        Spacer(Modifier.width(8.dp))
                        Text(f.name ?: f.id, color = Ch.Ink, fontSize = 13.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
                if (t.can.work) {
                    ChField(note, { note = it.take(2000) }, stringResource(R.string.gt_proof_note), singleLine = false, minLines = 2)
                    Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        SmallAction(Icons.Rounded.AttachFile, stringResource(R.string.gt_proof_add_files), enabled = !busy) { pick.launch("*/*") }
                        if (note.trim() != t.proof?.note.orEmpty()) {
                            SmallAction(Icons.Rounded.CheckCircle, stringResource(R.string.gt_proof_save_note), enabled = !busy) {
                                act { ApiClient.chat.addGroupTaskProof(chatAuth(), groupId, t.id, emptyList(), note.trim().toRequestBody("text/plain".toMediaTypeOrNull())).data }
                            }
                        }
                    }
                } else if (!t.proof?.note.isNullOrBlank()) {
                    Text(t.proof?.note.orEmpty(), color = Ch.Ink, fontSize = 13.5.sp)
                }
            }

            Spacer(Modifier.height(16.dp))
            if (t.can.work) {
                if (t.status == "new") {
                    ChPrimaryButton(stringResource(R.string.gt_start), icon = Icons.Rounded.PlayArrow, modifier = Modifier.fillMaxWidth(), enabled = !busy) {
                        act { ApiClient.chat.setGroupTaskStatus(chatAuth(), groupId, t.id, mapOf("status" to "in_progress")).data }
                    }
                    Spacer(Modifier.height(8.dp))
                }
                ChPrimaryButton(
                    stringResource(if (t.needsApproval) R.string.gt_submit else R.string.gt_finish), icon = Icons.Rounded.TaskAlt,
                    modifier = Modifier.fillMaxWidth(), enabled = !busy,
                ) { act { ApiClient.chat.setGroupTaskStatus(chatAuth(), groupId, t.id, mapOf("status" to "done")).data } }
            }
            if (t.can.review) {
                ChField(reviewNote, { reviewNote = it.take(1000) }, stringResource(R.string.gt_review_note), singleLine = false, minLines = 2)
                Spacer(Modifier.height(8.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    ChPrimaryButton(stringResource(R.string.gt_approve), icon = Icons.Rounded.CheckCircle, modifier = Modifier.weight(1f), enabled = !busy) {
                        act { ApiClient.chat.reviewGroupTask(chatAuth(), groupId, t.id, mapOf("approve" to true, "note" to reviewNote.trim().ifBlank { null })).data }
                    }
                    SmallAction(Icons.Rounded.Undo, stringResource(R.string.gt_send_back), enabled = !busy && reviewNote.isNotBlank(), modifier = Modifier.weight(1f)) {
                        act { ApiClient.chat.reviewGroupTask(chatAuth(), groupId, t.id, mapOf("approve" to false, "note" to reviewNote.trim())).data }
                    }
                }
            }
            if (t.can.edit) {
                Text(
                    stringResource(R.string.gt_cancel_task), color = Ch.Danger, fontWeight = FontWeight.Bold, fontSize = 13.5.sp,
                    modifier = Modifier.align(Alignment.CenterHorizontally).padding(top = 12.dp).clip(RoundedCornerShape(10.dp)).clickable(enabled = !busy) {
                        busy = true
                        host.scope.launch {
                            runCatching { ApiClient.chat.cancelGroupTask(chatAuth(), groupId, t.id) }.onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
                            busy = false
                            onDismiss()
                        }
                    }.padding(horizontal = 12.dp, vertical = 6.dp),
                )
            }
        }
    }
}

@Composable
private fun SectionTitle(text: String) {
    Text(text, color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(top = 16.dp, bottom = 4.dp))
}

@Composable
private fun Tag(text: String) {
    Text(text, color = Ch.Red, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(RoundedCornerShape(50)).background(Ch.Red.copy(alpha = 0.1f)).padding(horizontal = 8.dp, vertical = 3.dp))
}

@Composable
private fun SmallAction(icon: androidx.compose.ui.graphics.vector.ImageVector, text: String, enabled: Boolean = true, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Row(
        modifier.clip(RoundedCornerShape(16.dp)).border(1.5.dp, Ch.Red.copy(alpha = if (enabled) 0.45f else 0.15f), RoundedCornerShape(16.dp))
            .clickable(enabled = enabled, onClick = onClick).padding(horizontal = 12.dp, vertical = 11.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.Center,
    ) {
        Icon(icon, null, tint = Ch.Red.copy(alpha = if (enabled) 1f else 0.4f), modifier = Modifier.size(17.dp))
        Spacer(Modifier.width(6.dp))
        Text(text, color = Ch.Red.copy(alpha = if (enabled) 1f else 0.4f), fontWeight = FontWeight.Bold, fontSize = 13.sp)
    }
}

@Composable
private fun repeatLabel(r: String): String = stringResource(
    when (r) {
        "daily" -> R.string.gt_repeat_daily
        "weekly" -> R.string.gt_repeat_weekly
        "monthly" -> R.string.gt_repeat_monthly
        else -> R.string.gt_repeat_none
    },
)

/** A new task: title, notes, who, priority, when, approval / proof, repeat, checklist (a line each). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun NewTaskSheet(
    groupId: String,
    members: List<MemberDto>,
    onDone: () -> Unit,
    onDismiss: () -> Unit,
    spaceId: Int? = null,
    // GR-038: a draft from a message — confirmed here before anything is saved.
    sourceMessageId: String? = null,
    prefillTitle: String = "",
    prefillDescription: String = "",
) {
    val host = LocalChat.current
    var title by remember { mutableStateOf(prefillTitle) }
    var description by remember { mutableStateOf(prefillDescription) }
    // A due time suggested by DORR AI from the message (shown as its own chip).
    var suggestedDue by remember { mutableStateOf<String?>(null) }
    var suggesting by remember { mutableStateOf(false) }
    var assignee by remember { mutableStateOf<Int?>(null) }
    var priority by remember { mutableStateOf("normal") }
    // none | today | tomorrow | week
    var due by remember { mutableStateOf("none") }
    var approval by remember { mutableStateOf(false) }
    var proof by remember { mutableStateOf(false) }
    var repeat by remember { mutableStateOf<String?>(null) }
    var items by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }

    fun dueIso(): String? {
        if (due == "suggested") return suggestedDue
        val day = when (due) {
            "today" -> LocalDate.now()
            "tomorrow" -> LocalDate.now().plusDays(1)
            "week" -> LocalDate.now().plusWeeks(1)
            else -> return null
        }
        return day.atTime(LocalTime.of(17, 0)).atZone(ZoneId.systemDefault()).toOffsetDateTime().toString()
    }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 20.dp)) {
            Text(stringResource(if (sourceMessageId != null) R.string.gt_from_message else R.string.gt_new), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            Spacer(Modifier.height(12.dp))
            ChField(title, { title = it.take(200) }, stringResource(R.string.gt_title_hint), icon = Icons.Rounded.TaskAlt)
            // DORR AI reads the message and suggests a short title and its due time (a suggestion; I confirm).
            if (sourceMessageId != null && ChatAi.tasks) {
                Text(
                    "✨ " + stringResource(R.string.gt_ai_suggest), color = Ch.Red, fontWeight = FontWeight.Bold, fontSize = 13.sp,
                    modifier = Modifier.padding(top = 6.dp).clip(RoundedCornerShape(10.dp)).clickable(enabled = !suggesting) {
                        suggesting = true
                        host.scope.launch {
                            val tip = runCatching {
                                ApiClient.aiTools.tasksFrom(chatAuth(), sourceMessageId, com.google.gson.JsonObject().apply { addProperty("timezone", ZoneId.systemDefault().id) }).data?.tasks?.firstOrNull()
                            }.getOrNull()
                            if (tip != null) {
                                title = tip.text.take(200)
                                tip.dueAt?.let { suggestedDue = it; due = "suggested" }
                            }
                            suggesting = false
                        }
                    }.padding(horizontal = 6.dp, vertical = 4.dp),
                )
            }
            Spacer(Modifier.height(8.dp))
            ChField(description, { description = it.take(5000) }, stringResource(R.string.gt_description_hint), singleLine = false, minLines = 2)

            SectionTitle(stringResource(R.string.gt_assignee))
            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                item(key = "none") { Pill(stringResource(R.string.gt_assignee_none), selected = assignee == null) { assignee = null } }
                items(members, key = { it.participantId }) { m ->
                    Pill(if (m.profile?.isMe == true) stringResource(R.string.ch_you) else m.profile?.name.orEmpty(), selected = assignee == m.participantId) { assignee = m.participantId }
                }
            }

            SectionTitle(stringResource(R.string.gt_priority))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("low" to R.string.gt_priority_low, "normal" to R.string.gt_priority_normal, "high" to R.string.gt_priority_high, "urgent" to R.string.gt_priority_urgent).forEach { (k, l) ->
                    Pill(stringResource(l), selected = priority == k) { priority = k }
                }
            }

            SectionTitle(stringResource(R.string.gt_due))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                suggestedDue?.let { iso -> Pill("✨ " + (dueText(iso) ?: iso), selected = due == "suggested") { due = "suggested" } }
                listOf("none" to R.string.gt_due_none, "today" to R.string.gt_due_today, "tomorrow" to R.string.gt_due_tomorrow, "week" to R.string.gt_due_week).forEach { (k, l) ->
                    Pill(stringResource(l), selected = due == k) { due = k; if (k == "none") repeat = null }
                }
            }

            if (due != "none") {
                SectionTitle(stringResource(R.string.gt_repeat))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    listOf(null, "daily", "weekly", "monthly").forEach { k -> Pill(repeatLabel(k ?: "none"), selected = repeat == k) { repeat = k } }
                }
            }

            Spacer(Modifier.height(8.dp))
            ToggleLine(stringResource(R.string.gt_needs_approval), approval) { approval = it }
            ToggleLine(stringResource(R.string.gt_requires_proof), proof) { proof = it }

            SectionTitle(stringResource(R.string.gt_checklist_hint))
            ChField(items, { items = it.take(3000) }, stringResource(R.string.gt_checklist_lines), singleLine = false, minLines = 3)

            Spacer(Modifier.height(14.dp))
            ChPrimaryButton(stringResource(R.string.gt_create), icon = Icons.Rounded.Add, modifier = Modifier.fillMaxWidth(), enabled = title.isNotBlank() && !busy) {
                busy = true
                host.scope.launch {
                    try {
                        ApiClient.chat.createGroupTask(chatAuth(), groupId, mapOf(
                            "title" to title.trim(),
                            "description" to description.trim().ifBlank { null },
                            "assignee_participant_id" to assignee,
                            "priority" to priority,
                            "due_at" to dueIso(),
                            "needs_approval" to approval,
                            "requires_proof" to proof,
                            "repeat" to repeat,
                            "items" to items.lines().map { it.trim() }.filter { it.isNotEmpty() }.take(50),
                            "space_id" to spaceId,
                            "source_message_id" to sourceMessageId,
                        ))
                        onDone()
                    } catch (e: Exception) {
                        e.apiFailure().message?.let { host.showToast(it) }
                    }
                    busy = false
                }
            }
        }
    }
}

@Composable
private fun ToggleLine(text: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { onChange(!checked) }.padding(vertical = 6.dp, horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(text, color = Ch.Ink, fontSize = 14.5.sp, modifier = Modifier.weight(1f))
        Switch(checked, onChange, colors = settingsSwitchColors())
    }
}

/**
 * GR-038: "Make it a task" on a message in a work group or one of its projects — the new-task
 * sheet filled from the message (its main group's members to pick from), saved only on confirm.
 */
@Composable
internal fun TaskFromMessageSheet(mainGroupId: String, spaceId: Int?, message: com.dorr.app.network.MessageDto, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var members by remember { mutableStateOf<List<MemberDto>?>(null) }
    val saved = stringResource(R.string.gt_saved_from_message)
    LaunchedEffect(mainGroupId) { members = runCatching { ApiClient.chat.members(chatAuth(), mainGroupId).data }.getOrNull().orEmpty() }
    val list = members ?: return
    val body = message.body.orEmpty()
    NewTaskSheet(
        mainGroupId, list,
        onDone = { host.showToast(saved); onDismiss() },
        onDismiss = onDismiss,
        spaceId = spaceId,
        sourceMessageId = message.id,
        prefillTitle = body.lineSequence().firstOrNull().orEmpty().take(200),
        prefillDescription = body.take(5000),
    )
}

/** GR-039: the team's picture — totals, each member's load, the late ones — and the export. */
@Composable
private fun TaskReportSheet(groupId: String, onDismiss: () -> Unit) {
    val host = LocalChat.current
    val context = LocalContext.current
    var report by remember { mutableStateOf<com.dorr.app.network.GroupTaskReportDto?>(null) }
    var exporting by remember { mutableStateOf(false) }
    val failed = stringResource(R.string.gt_export_failed)
    LaunchedEffect(groupId) {
        report = runCatching { ApiClient.chat.groupTaskReport(chatAuth(), groupId).data }
            .onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }.getOrNull()
    }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 20.dp)) {
            Text(stringResource(R.string.gt_report), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            Spacer(Modifier.height(12.dp))
            val r = report
            if (r == null) {
                Box(Modifier.fillMaxWidth().height(140.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
            } else {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    StatTile(r.totals.open.toString(), stringResource(R.string.gt_filter_open), Ch.Red, Modifier.weight(1f))
                    StatTile(r.totals.inReview.toString(), stringResource(R.string.gt_filter_review), Color(0xFFF59E0B), Modifier.weight(1f))
                }
                Spacer(Modifier.height(8.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    StatTile(r.totals.late.toString(), stringResource(R.string.gt_late), Ch.Danger, Modifier.weight(1f))
                    StatTile(r.totals.doneWeek.toString(), stringResource(R.string.gt_done_week), Ch.Success, Modifier.weight(1f))
                }
                if (r.members.isNotEmpty()) {
                    SectionTitle(stringResource(R.string.gt_load))
                    r.members.forEach { m ->
                        Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                            ChAvatar(m.profile?.avatar, m.profile?.name, m.profile?.key, size = 34.dp)
                            Spacer(Modifier.width(10.dp))
                            Text(m.profile?.name.orEmpty(), color = Ch.Ink, fontSize = 14.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text(stringResource(R.string.gt_load_line, m.open, m.late, m.doneWeek), color = if (m.late > 0) Ch.Danger else Ch.Mut, fontSize = 12.sp)
                        }
                    }
                }
                if (r.late.isNotEmpty()) {
                    SectionTitle(stringResource(R.string.gt_late))
                    r.late.forEach { t ->
                        Row(Modifier.fillMaxWidth().padding(vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
                            Box(Modifier.size(9.dp).clip(CircleShape).background(priorityColor(t.priority)))
                            Spacer(Modifier.width(8.dp))
                            Text(t.title, color = Ch.Ink, fontSize = 13.5.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text(dueText(t.dueAt).orEmpty(), color = Ch.Danger, fontSize = 12.sp)
                        }
                    }
                }
                Spacer(Modifier.height(16.dp))
                ChPrimaryButton(stringResource(R.string.gt_export), icon = Icons.Rounded.FileDownload, modifier = Modifier.fillMaxWidth(), enabled = !exporting) {
                    exporting = true
                    host.scope.launch {
                        val file = runCatching {
                            kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
                                val response = ApiClient.chat.exportGroupTasks(chatAuth(), groupId)
                                val body = response.body()
                                if (!response.isSuccessful || body == null) null else {
                                    val dir = java.io.File(context.cacheDir, "exports").apply { mkdirs() }
                                    java.io.File(dir, "tasks-${LocalDate.now()}.csv").also { out -> body.byteStream().use { input -> out.outputStream().use { input.copyTo(it) } } }
                                }
                            }
                        }.getOrNull()
                        if (file != null) shareFile(context, file, "text/csv") else host.showToast(failed)
                        exporting = false
                    }
                }
            }
        }
    }
}

@Composable
private fun StatTile(value: String, label: String, tint: Color, modifier: Modifier) {
    Column(modifier.clip(RoundedCornerShape(16.dp)).background(tint.copy(alpha = 0.1f)).padding(12.dp)) {
        Text(value, color = tint, fontWeight = FontWeight.ExtraBold, fontSize = 22.sp)
        Text(label, color = Ch.Mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/** GR-032: the team by role — who leads, who supervises, who works (a member under each role they hold). */
@Composable
internal fun TeamSheet(groupId: String, members: List<MemberDto>, onDismiss: () -> Unit) {
    var roles by remember { mutableStateOf<GroupRolesDto?>(null) }
    LaunchedEffect(groupId) { roles = runCatching { ApiClient.chat.groupRoles(chatAuth(), groupId).data }.getOrNull() }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 18.dp).padding(bottom = 20.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Groups, null, tint = Ch.Red, modifier = Modifier.size(24.dp))
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.gt_team), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            }
            Spacer(Modifier.height(10.dp))
            val r = roles
            if (r == null) {
                Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
            } else {
                val owner = members.firstOrNull { it.role == "owner" }
                LazyColumn(Modifier.heightIn(max = 480.dp)) {
                    owner?.let { o -> item(key = "owner") { TeamSection(stringResource(R.string.gt_team_owner), listOf(o)) } }
                    items(r.roles, key = { it.id }) { role ->
                        val holders = members.filter { role.id in it.roles.orEmpty() }
                        if (holders.isNotEmpty()) TeamSection(role.name, holders)
                    }
                }
            }
        }
    }
}

@Composable
private fun TeamSection(title: String, people: List<MemberDto>) {
    Column(Modifier.fillMaxWidth().padding(bottom = 10.dp)) {
        Text("$title · ${people.size}", color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(vertical = 4.dp))
        people.forEach { m ->
            Row(Modifier.fillMaxWidth().padding(vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
                ChAvatar(m.profile?.avatar, m.profile?.name, m.profile?.key, size = 34.dp)
                Spacer(Modifier.width(10.dp))
                Text(m.profile?.name.orEmpty(), color = Ch.Ink, fontSize = 14.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
    }
}
