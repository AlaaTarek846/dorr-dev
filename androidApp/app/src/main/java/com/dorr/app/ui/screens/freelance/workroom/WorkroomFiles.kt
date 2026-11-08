package com.dorr.app.ui.screens.freelance.workroom

import android.net.Uri
import android.provider.OpenableColumns
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AttachFile
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.CloudUpload
import androidx.compose.material.icons.rounded.Comment
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.FolderOpen
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Storage
import androidx.compose.material.icons.rounded.UploadFile
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.WorkFileDto
import com.dorr.app.network.WorkFilesDto
import com.dorr.app.network.WorkPartDto
import com.dorr.app.network.WorkUploadRulesDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.components.PullToRefreshHost
import com.dorr.app.ui.screens.freelance.Chip
import com.dorr.app.ui.screens.freelance.FlAction
import com.dorr.app.ui.screens.freelance.FlCorners
import com.dorr.app.ui.screens.freelance.FlNotice
import com.dorr.app.ui.screens.freelance.FlRetry
import com.dorr.app.ui.screens.freelance.FlSheet
import com.dorr.app.ui.screens.freelance.FlSkeletonList
import com.dorr.app.ui.screens.freelance.FlStat
import com.dorr.app.ui.screens.freelance.FlTone
import com.dorr.app.ui.screens.freelance.NearEnd
import com.dorr.app.ui.screens.freelance.PagedSpinner
import com.dorr.app.ui.screens.freelance.auth
import com.dorr.app.ui.screens.freelance.filePart
import com.dorr.app.ui.screens.freelance.message
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody

/**
 * The Workroom's File Hub: every file of the project, each with its versions and notes. A card per file (what it is, its newest version, who
 * added it, how many notes), a search, «Mine», and «Upload» for a new one. A file opens on its own page. Read once when it opens, on a live
 * event, on a pull and when the connection returns; the search waits for a pause in typing.
 */
@Composable
internal fun FilesTab(
    project: String,
    parts: List<WorkPartDto>,
    freelancer: String,
    live: WorkroomLive,
    onOpenFile: (String) -> Unit,
) {
    val generic = stringResource(R.string.fl_error_generic)
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    var typed by rememberSaveable { mutableStateOf("") }
    var search by rememberSaveable { mutableStateOf("") }
    var mineOnly by rememberSaveable { mutableStateOf(false) }
    var version by remember { mutableIntStateOf(0) }
    var uploading by remember { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }
    val uploaded = stringResource(R.string.wr_file_uploaded)

    // The search is asked 400 ms after the last key — a pause in typing, not a timer.
    LaunchedEffect(typed) {
        if (typed.trim() != search) {
            delay(400)
            search = typed.trim()
        }
    }

    val meta = remember(project) { mutableStateOf<WorkFilesDto?>(null) }
    val pager = remember(project, search, mineOnly) {
        val q = search.ifBlank { null }
        val m = if (mineOnly) 1 else null
        workPager(meta, { it.files }, { it.uuid }) { page ->
            ApiClient.freelanceWorkroom.files(auth(), project, mine = m, search = q, perPage = WORK_PAGE, page = page)
        }
    }
    LaunchedEffect(pager, reconnect, live.files, version) { pager.reload(generic) }
    val listState = rememberLazyListState()
    listState.NearEnd(pager.rows.size) { scope.launch { pager.loadMore(generic) } }

    val answer = meta.value
    val canUpload = answer != null && answer.can.fileUpload && !answer.readOnly

    PullToRefreshHost(Modifier.fillMaxSize()) {
        LazyColumn(
            Modifier.fillMaxSize(), state = listState,
            contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 4.dp, bottom = 28.dp), verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            if (answer != null) item(key = "summary") { FilesSummary(answer) }
            if (canUpload) item(key = "upload") { FlAction(stringResource(R.string.wr_file_upload), Icons.Rounded.CloudUpload, FlTone.Primary) { uploading = true } }
            notice?.let { item(key = "notice") { FlNotice(it, Icons.Rounded.CheckCircle, WrGreen) } }
            if (answer?.readOnly == true) item(key = "readonly") { FlNotice(stringResource(R.string.wr_read_only), Icons.Rounded.Lock, WrAmber) }
            if (answer != null && (answer.summary.files > 0 || search.isNotBlank() || mineOnly)) item(key = "filters") {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    DorrTextField(
                        typed, { typed = it.take(80) }, placeholder = stringResource(R.string.wr_file_search), icon = Icons.Rounded.Search, modifier = Modifier.fillMaxWidth(),
                        trailing = {
                            if (typed.isNotEmpty()) Icon(
                                Icons.Rounded.Close, stringResource(R.string.fl_remove), tint = settingsMut(),
                                modifier = Modifier.size(28.dp).clip(RoundedCornerShape(50)).clickable { typed = ""; search = "" }.padding(5.dp),
                            )
                        },
                    )
                    WrChipRow { Chip(stringResource(R.string.wr_file_mine), mineOnly, Icons.Rounded.Person) { mineOnly = !mineOnly } }
                }
            }
            when {
                !pager.loaded && pager.error == null -> item(key = "loading") { FlSkeletonList(3, 92.dp) }
                pager.rows.isEmpty() && pager.error != null -> item(key = "failed") { FlRetry(pager.error ?: generic) }
                pager.rows.isEmpty() -> item(key = "empty") {
                    val filtered = search.isNotBlank() || mineOnly
                    WrEmpty(
                        Icons.Rounded.FolderOpen,
                        stringResource(if (filtered) R.string.wr_file_none_filtered else R.string.wr_file_none),
                        stringResource(if (filtered) R.string.wr_file_none_filtered_body else R.string.wr_file_none_body),
                    )
                }
            }
            items(pager.rows, key = { it.uuid }) { file -> FileCard(file, freelancer) { onOpenFile(file.uuid) } }
            item(key = "more") { PagedSpinner(pager) }
        }
    }

    if (uploading) WorkUploadSheet(
        project = project, file = null, parts = parts, rules = answer?.upload ?: WorkUploadRulesDto(), onDismiss = { uploading = false },
        onDone = { uploading = false; notice = uploaded; version++ },
    )
}

@Composable
private fun FilesSummary(a: WorkFilesDto) {
    Column(Modifier.fillMaxWidth().settingsSurface(FlCorners.card).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FlStat(Icons.Rounded.AttachFile, stringResource(R.string.wr_file_count), a.summary.files.toString(), Modifier.weight(1f))
            FlStat(Icons.Rounded.History, stringResource(R.string.wr_file_versions), a.summary.versions.toString(), Modifier.weight(1f))
            FlStat(
                Icons.Rounded.Storage, stringResource(R.string.wr_file_storage),
                wrSize(a.summary.storageBytes) + (a.limit.maxStorageMb?.let { " / $it MB" } ?: ""), Modifier.weight(1.4f),
            )
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun FileCard(file: WorkFileDto, freelancer: String, onClick: () -> Unit) {
    val current = file.current
    Row(
        Modifier.fillMaxWidth().settingsSurface(FlCorners.card).clickable(role = Role.Button, onClick = onClick).padding(14.dp),
        horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.Top,
    ) {
        Box(Modifier.size(46.dp).clip(FlCorners.icon).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(fileIcon(current?.mimeType.orEmpty(), current?.name.orEmpty()), null, tint = settingsAccent(), modifier = Modifier.size(24.dp))
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(file.title, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis, lineHeight = 21.sp)
            if (current != null) Text(
                current.name + "  ·  " + wrSize(current.sizeBytes), color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
            )
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                WrTag(if (file.versionsCount > 1) stringResource(R.string.wr_file_v_many, file.versionsCount) else stringResource(R.string.wr_file_v_one), Icons.Rounded.History, WrViolet)
                file.milestone?.let { WrTag("${it.position}. ${it.title}", Icons.Rounded.Flag, settingsAccent()) }
                if (file.commentsCount > 0) WrTag(file.commentsCount.toString(), Icons.Rounded.Comment, WrBlue)
                current?.takeIf { it.previewOnly }?.let { WrTag(stringResource(R.string.wr_file_preview_tag), Icons.Rounded.Lock, WrAmber) }
            }
            Text(
                stringResource(R.string.wr_added_by, sideName(file.createdBySide, freelancer), wrDay(file.createdAt)),
                color = settingsMut(), fontSize = 11.5.sp,
            )
        }
    }
}

/**
 * Adds a file (a title, what it is, the part it belongs to, a note) or — when [file] is given — a new version of it (a note). The file is
 * picked from the phone; what the server accepts (size, kinds) is checked first when the Workroom told it. The server's own words show when it refuses.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun WorkUploadSheet(
    project: String,
    file: WorkFileDto?,
    parts: List<WorkPartDto>,
    rules: WorkUploadRulesDto,
    onDismiss: () -> Unit,
    onDone: () -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.fl_error_generic)
    val tooBig = stringResource(R.string.wr_file_too_big, wrSize(rules.maxKb * 1024L))
    val badType = stringResource(R.string.wr_file_type)
    val unreadable = stringResource(R.string.fl_dl_open_failed)
    var picked by remember { mutableStateOf<Uri?>(null) }
    var pickedName by remember { mutableStateOf("") }
    var pickedSize by remember { mutableLongStateOf(0L) }
    var title by rememberSaveable { mutableStateOf("") }
    var description by rememberSaveable { mutableStateOf("") }
    var part by rememberSaveable { mutableStateOf<String?>(null) }
    var note by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri == null) return@rememberLauncherForActivityResult
        val (name, size) = context.contentResolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE), null, null, null)?.use { c ->
            if (c.moveToFirst()) (c.getString(0) ?: "file") to (if (c.isNull(1)) 0L else c.getLong(1)) else null
        } ?: ("file" to 0L)
        val ext = name.substringAfterLast('.', "").lowercase()
        when {
            rules.maxKb > 0 && size > rules.maxKb * 1024L -> error = tooBig
            rules.mimes.isNotEmpty() && ext !in rules.mimes.map { it.lowercase() } -> error = badType
            else -> {
                error = null
                picked = uri
                pickedName = name
                pickedSize = size
                if (file == null && title.isBlank()) title = name.substringBeforeLast('.').take(160)
            }
        }
    }

    fun send() {
        val uri = picked ?: return
        busy = true
        error = null
        scope.launch {
            try {
                val part0 = withContext(Dispatchers.IO) { filePart(context, uri) }
                if (part0 == null) {
                    error = unreadable
                } else {
                    val fields = buildMap<String, RequestBody> {
                        if (file == null) {
                            if (title.isNotBlank()) put("title", title.trim().toRequestBody(null))
                            if (description.isNotBlank()) put("description", description.trim().toRequestBody(null))
                            part?.let { put("milestone", it.toRequestBody(null)) }
                        }
                        if (note.isNotBlank()) put("note", note.trim().toRequestBody(null))
                    }
                    if (file == null) ApiClient.freelanceWorkroom.uploadFile(auth(), project, fields, part0)
                    else ApiClient.freelanceWorkroom.addVersion(auth(), project, file.uuid, fields, part0)
                    onDone()
                }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.message(generic)
            }
            busy = false
        }
    }

    FlSheet(
        if (file == null) Icons.Rounded.CloudUpload else Icons.Rounded.UploadFile,
        stringResource(if (file == null) R.string.wr_file_new else R.string.wr_file_new_version),
        if (file == null) stringResource(R.string.wr_file_new_body) else stringResource(R.string.wr_file_new_version_body, file.title),
        onDismiss = { if (!busy) onDismiss() },
        footer = {
            FlAction(stringResource(R.string.wr_file_send), Icons.Rounded.CloudUpload, FlTone.Primary, enabled = picked != null && (file != null || title.isNotBlank()), loading = busy) { send() }
            FlAction(stringResource(R.string.common_cancel), null, enabled = !busy, onClick = onDismiss)
        },
    ) {
        // The file itself: nothing chosen yet, or its name and size (tap to choose another).
        Row(
            Modifier.fillMaxWidth().clip(FlCorners.tile).background(settingsAccent().copy(alpha = 0.08f)).clickable(enabled = !busy, role = Role.Button) { picker.launch("*/*") }.padding(14.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(if (picked == null) Icons.Rounded.AttachFile else fileIcon("", pickedName), null, tint = settingsAccent(), modifier = Modifier.size(26.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(
                    if (picked == null) stringResource(R.string.wr_file_choose) else pickedName, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
                    maxLines = 2, overflow = TextOverflow.Ellipsis,
                )
                Text(
                    if (picked == null) wrRulesLine(rules) else stringResource(R.string.wr_file_tap_change) + if (pickedSize > 0) "  ·  " + wrSize(pickedSize) else "",
                    color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp,
                )
            }
            if (picked != null) Icon(Icons.Rounded.Check, null, tint = WrGreen, modifier = Modifier.size(22.dp))
        }
        if (file == null) {
            DorrTextField(title, { title = it.take(160) }, label = stringResource(R.string.wr_file_title), icon = Icons.Rounded.AttachFile, modifier = Modifier.fillMaxWidth())
            DorrTextField(description, { description = it.take(1000) }, label = stringResource(R.string.wr_file_description), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
            if (parts.isNotEmpty()) Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(stringResource(R.string.wr_part), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                PartPicker(parts, part) { part = it }
            }
        }
        DorrTextField(note, { note = it.take(500) }, label = stringResource(R.string.wr_file_note), placeholder = stringResource(R.string.wr_file_note_hint), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
        error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, WrRed) }
    }
}

/** «Up to 20.0 MB · JPG, PNG, PDF…» — what the Workroom takes (empty when it did not say). */
@Composable
private fun wrRulesLine(rules: WorkUploadRulesDto): String {
    val size = if (rules.maxKb > 0) stringResource(R.string.wr_file_rule_size, wrSize(rules.maxKb * 1024L)) else ""
    val kinds = rules.mimes.take(8).joinToString(", ") { it.uppercase() } + if (rules.mimes.size > 8) "…" else ""
    return listOf(size, kinds).filter { it.isNotBlank() }.joinToString("  ·  ").ifBlank { stringResource(R.string.wr_file_choose_hint) }
}
