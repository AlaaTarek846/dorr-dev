package com.dorr.freelance.ui.screens.workroom

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
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.automirrored.rounded.OpenInNew
import androidx.compose.material.icons.rounded.CloudUpload
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.AgreementDto
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.AppLocale
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.WorkCommentBodyRequest
import com.dorr.freelance.net.WorkCommentDto
import com.dorr.freelance.net.WorkCommentRequest
import com.dorr.freelance.net.WorkFileDto
import com.dorr.freelance.net.WorkUploadRulesDto
import com.dorr.freelance.net.WorkVersionDto
import com.dorr.freelance.net.apiDone
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.MessageToast
import com.dorr.freelance.ui.components.ShellState
import com.dorr.freelance.ui.components.SkeletonList
import com.dorr.freelance.ui.kit.ConfirmDialog
import com.dorr.freelance.ui.kit.Corners
import com.dorr.freelance.ui.kit.PinkBackdrop
import com.dorr.freelance.ui.kit.SectionHeader
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.StatusPill
import com.dorr.freelance.ui.kit.SubHeader
import com.dorr.freelance.ui.kit.Tints
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * One file of the Workroom: its details, every version (newest first, each opens in a viewer with my own sign-in), and the notes on it.
 * It reads once when it opens, once on a live event for this file (`freelance.workroom` on the project's channel — a new version, a note, an
 * edit), once on a notice and once when the connection returns; nothing on a timer.
 */
@Composable
fun WorkroomFileScreen(project: String, fileUuid: String, onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val reconnect = collectReconnectTick()
    val locale = AppLocale.java()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val openFailed = stringResource(R.string.wr_file_open_failed)
    var file by remember { mutableStateOf<WorkFileDto?>(null) }
    var failure by remember { mutableStateOf<String?>(null) }
    var readOnly by remember { mutableStateOf(false) }
    var canNote by remember { mutableStateOf(false) }
    var agreement by remember { mutableStateOf<AgreementDto?>(null) }
    var rules by remember { mutableStateOf(WorkUploadRulesDto()) }
    var toast by remember { mutableStateOf<String?>(null) }
    var opening by remember { mutableIntStateOf(0) }
    var input by rememberSaveable { mutableStateOf("") }
    var sending by remember { mutableStateOf(false) }
    var editingFile by remember { mutableStateOf(false) }
    var addingVersion by remember { mutableStateOf(false) }
    var deleting by remember { mutableStateOf(false) }
    var editingNote by remember { mutableStateOf<WorkCommentDto?>(null) }
    var deletingNote by remember { mutableStateOf<WorkCommentDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var dirty by remember { mutableIntStateOf(0) }

    val notes = remember(project, fileUuid) {
        WorkFeed<WorkCommentDto>({ it.uuid }) { page ->
            val r = apiCall { ApiClient.workroom.comments(project, file = fileUuid, perPage = WORK_PAGE, page = page) }
            if (r is ApiResult.Ok) { canNote = r.data.can.commentAdd; readOnly = r.data.readOnly }
            r.rows { it.comments }
        }
    }

    // The page is a thread with a composer: it takes the whole screen (no tab bar over the keyboard).
    DisposableEffect(Unit) {
        ShellState.immersive = true
        onDispose { ShellState.immersive = false }
    }
    DisposableEffect(project) {
        Realtime.listenWorkroom(project)
        onDispose { Realtime.stopListeningWorkroom(project) }
    }
    // FS-209: a team file (and the notes on it) travels on the team's channel.
    val teamUuid = agreement?.team?.uuid
    DisposableEffect(teamUuid) {
        if (teamUuid != null) Realtime.listenTeamWorkroom(teamUuid)
        onDispose { if (teamUuid != null) Realtime.stopListeningTeamWorkroom(teamUuid) }
    }

    suspend fun load() {
        val r = apiCall { ApiClient.workroom.file(project, fileUuid) }
        if (r is ApiResult.Ok) { file = r.data.file; failure = null } else if (file == null) failure = r.errorText(offlineText) ?: genericError
        notes.reload()
    }
    LaunchedEffect(project, fileUuid, reconnect) { load() }
    LaunchedEffect(project, fileUuid) { Realtime.changed.collect { dirty++ } }
    LaunchedEffect(project, fileUuid) {
        // What the project's channel says about this file (or its notes) makes one new read; nothing carries the text.
        Realtime.workroomEvents.collect { e ->
            if (e.data.text("project")?.let { it != project } == true) return@collect
            val kind = e.data.text("kind")
            if ((kind == "file" || kind == "comment") && (e.data.text("uuid") == fileUuid || e.data.text("uuid") == null)) dirty++
        }
    }
    // A burst of changes is one read: wait for it to settle, then read once.
    LaunchedEffect(dirty) {
        if (dirty == 0) return@LaunchedEffect
        delay(350)
        load()
    }
    // The parts and the upload rules a sheet needs (read once; the screen works without them).
    LaunchedEffect(project) {
        (apiCall { ApiClient.agreements.show(project) } as? ApiResult.Ok)?.let { agreement = it.data }
        (apiCall { ApiClient.workroom.files(project, perPage = 1) } as? ApiResult.Ok)?.let { rules = it.data.upload }
    }

    fun openVersionOf(v: WorkVersionDto) {
        if (opening != 0) return
        opening = v.number
        scope.launch {
            val ok = openVersion(context, project, fileUuid, v.number, v.name, v.mimeType)
            opening = 0
            if (!ok) toast = openFailed
        }
    }

    fun send() {
        val text = input.trim()
        if (text.isEmpty() || sending) return
        sending = true
        scope.launch {
            val r = apiCall { ApiClient.workroom.addComment(project, WorkCommentRequest(file = fileUuid, version = file?.current?.number, body = text)) }
            sending = false
            if (r is ApiResult.Ok) { input = ""; notes.reload(); file = file?.copy(commentsCount = (file?.commentsCount ?: 0) + 1) } else toast = r.errorText(offlineText) ?: genericError
        }
    }

    val listState = rememberLazyListState()
    NearEnd(listState) { scope.launch { notes.more() } }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding().imePadding()) {
            SubHeader(file?.title ?: stringResource(R.string.wr_file), onBack)
            Box(Modifier.weight(1f).fillMaxWidth()) {
                val f = file
                when {
                    f == null && failure == null -> SkeletonList(count = 3, modifier = Modifier.padding(16.dp))
                    f == null -> Column(Modifier.fillMaxSize().padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                        Icon(Icons.Rounded.ErrorOutline, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(44.dp))
                        Spacer(Modifier.size(12.dp))
                        Text(failure.orEmpty(), color = settingsMut(), fontSize = 14.sp)
                        Text(stringResource(R.string.common_retry), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable { failure = null; scope.launch { load() } }.padding(16.dp))
                    }
                    else -> LazyColumn(
                        Modifier.fillMaxSize(), state = listState,
                        contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 2.dp, bottom = 20.dp), verticalArrangement = Arrangement.spacedBy(10.dp),
                    ) {
                        item(key = "head") {
                            FileHead(
                                f, readOnly, opening != 0,
                                onOpen = { f.current?.let(::openVersionOf) },
                                onNewVersion = { addingVersion = true }, onEdit = { editingFile = true }, onDelete = { deleting = true },
                            )
                        }
                        item(key = "versions-title") { SectionHeader(stringResource(R.string.wr_versions, f.versions.size.coerceAtLeast(f.versionsCount))) }
                        items(f.versions, key = { "v${it.number}" }) { v -> VersionRow(v, opening == v.number, locale) { openVersionOf(v) } }
                        item(key = "notes-title") { SectionHeader(stringResource(R.string.wr_notes)) }
                        if (notes.paged.loaded && notes.paged.rows.isEmpty()) item(key = "notes-empty") {
                            Text(stringResource(R.string.wr_notes_empty), color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp, modifier = Modifier.padding(vertical = 6.dp))
                        }
                        items(notes.paged.rows, key = { "n${it.uuid}" }) { c -> NoteCard(c, locale, onEdit = { editingNote = c }, onDelete = { deletingNote = c }) }
                    }
                }
            }
            MessageToast(toast)
            if (file != null && canNote && !readOnly) Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                DorrTextField(value = input, onValueChange = { if (it.length <= 2000) input = it }, modifier = Modifier.weight(1f), placeholder = stringResource(R.string.wr_note_hint), singleLine = false)
                Box(
                    Modifier.size(44.dp).clip(CircleShape).background(settingsAccent().copy(alpha = if (input.isBlank() || sending) 0.4f else 1f)).clickable(enabled = input.isNotBlank() && !sending, onClick = ::send),
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.AutoMirrored.Rounded.Send, stringResource(R.string.wr_note_send), tint = Color.White, modifier = Modifier.size(20.dp)) }
            }
        }
    }

    val current = file
    if (editingFile && current != null) EditFileSheet(
        project, current, agreement?.milestones?.asParts().orEmpty(), onDismiss = { editingFile = false },
        onSaved = { editingFile = false; scope.launch { load() } },
    )
    if (addingVersion && current != null) UploadSheet(
        project = project, existing = current, rules = rules, parts = emptyList(), team = null, onDismiss = { addingVersion = false },
        onDone = { addingVersion = false; scope.launch { load() } },
    )
    if (deleting && current != null) ConfirmDialog(
        icon = Icons.Rounded.Delete, title = stringResource(R.string.wr_file_delete), message = stringResource(R.string.wr_file_delete_ask), confirmLabel = stringResource(R.string.wr_delete), loading = busy,
        onConfirm = {
            busy = true
            scope.launch {
                val r = apiCall { ApiClient.workroom.deleteFile(project, current.uuid) }
                busy = false
                deleting = false
                if (r is ApiResult.Ok) onBack() else toast = r.errorText(offlineText) ?: genericError
            }
        },
        onDismiss = { deleting = false },
    )
    editingNote?.let { c -> EditNoteSheet(project, c, onDismiss = { editingNote = null }, onSaved = { editingNote = null; scope.launch { notes.reload() } }) }
    deletingNote?.let { c ->
        ConfirmDialog(
            icon = Icons.Rounded.Delete, title = stringResource(R.string.wr_note_delete), message = stringResource(R.string.wr_note_delete_ask), confirmLabel = stringResource(R.string.wr_delete), loading = busy,
            onConfirm = {
                busy = true
                scope.launch {
                    val problem = apiDone(offlineText, genericError) { ApiClient.workroom.deleteComment(project, c.uuid) }
                    busy = false
                    deletingNote = null
                    if (problem == null) { notes.reload(); file = file?.copy(commentsCount = ((file?.commentsCount ?: 1) - 1).coerceAtLeast(0)) } else toast = problem
                }
            },
            onDismiss = { deletingNote = null },
        )
    }
}

/** The file's own card: a tile by its type, the title, the line about it, where it belongs, and what I may do with it. */
@Composable
private fun FileHead(f: WorkFileDto, readOnly: Boolean, opening: Boolean, onOpen: () -> Unit, onNewVersion: () -> Unit, onEdit: () -> Unit, onDelete: () -> Unit) {
    val cur = f.current
    val (icon, tint) = fileIcon(cur?.mimeType.orEmpty(), cur?.name.orEmpty())
    WorkCard {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(Modifier.size(54.dp).clip(Corners.small).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(28.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(f.title, color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, maxLines = 3, overflow = TextOverflow.Ellipsis)
                cur?.let { Text(listOfNotNull(it.name.takeIf { n -> n.isNotBlank() }, fileSize(it.sizeBytes)).joinToString(" · "), color = settingsMut(), fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis) }
            }
        }
        f.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsInk(), fontSize = 13.5.sp, lineHeight = 20.sp) }
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
            if (f.scope == "team") TeamOnlyPill()
            if (cur?.previewOnly == true) PreviewOnlyPill()
            StatusPill(sideName(f.createdBySide), if (f.createdBySide == MY_SIDE) Tints.teal else Tints.violet)
        }
        f.milestone?.let { PartTag(it) }
        if (cur != null) SettingsPrimaryButton(text = stringResource(R.string.wr_file_open), loading = opening, onClick = onOpen)
        if (!readOnly) Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            if (f.can.versionAdd) FileAction(Icons.Rounded.CloudUpload, stringResource(R.string.wr_version_add), Modifier.weight(1f), onNewVersion)
            if (f.can.fileEdit) FileAction(Icons.Rounded.Edit, stringResource(R.string.wr_edit), Modifier.weight(1f), onEdit)
            if (f.can.fileDelete) FileAction(Icons.Rounded.Delete, stringResource(R.string.wr_delete), Modifier.weight(1f), onDelete, danger = true)
        }
    }
}

@Composable
private fun FileAction(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, modifier: Modifier, onClick: () -> Unit, danger: Boolean = false) {
    val tint = if (danger) WorkRed else settingsAccent()
    Column(
        modifier.clip(Corners.small).background(tint.copy(alpha = 0.09f)).clickable(onClick = onClick).padding(vertical = 10.dp),
        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(3.dp),
    ) {
        Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(20.dp))
        Text(label, color = tint, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/** One version: its number, who added it and when, its note, and a way to open it. */
@Composable
private fun VersionRow(v: WorkVersionDto, opening: Boolean, locale: java.util.Locale, onOpen: () -> Unit) {
    WorkCard(onClick = onOpen) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Box(Modifier.clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp)) {
                Text("v${v.number}", color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Black)
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(1.dp)) {
                Text(v.name.ifBlank { stringResource(R.string.wr_file) }, color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(
                    listOfNotNull(sideName(v.bySide), fileSize(v.sizeBytes), workDateTime(v.createdAt, locale)).joinToString(" · "),
                    color = settingsMut(), fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
                )
            }
            if (opening) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(20.dp))
            else Icon(Icons.AutoMirrored.Rounded.OpenInNew, contentDescription = stringResource(R.string.wr_file_open), tint = settingsAccent(), modifier = Modifier.size(20.dp))
        }
        v.note?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp) }
        if (v.previewOnly) PreviewOnlyPill()
    }
}

/** One note on the file: who wrote it (me or the client), when, the version it is about, and edit / delete for mine. */
@Composable
private fun NoteCard(c: WorkCommentDto, locale: java.util.Locale, onEdit: () -> Unit, onDelete: () -> Unit) {
    WorkCard(accent = if (c.mine) settingsAccent() else null) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(sideName(c.authorSide), color = if (c.mine) settingsAccent() else settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
            c.version?.let { StatusPill("v$it", Tints.blue) }
            if (c.scope == "team") TeamOnlyPill()
            Spacer(Modifier.weight(1f))
            val whenText = workWhen(c.createdAt, locale)
            if (whenText != null) Text(whenText + if (c.editedAt != null) " · " + stringResource(R.string.wr_edited) else "", color = settingsMut(), fontSize = 11.sp)
        }
        Text(c.body, color = settingsInk(), fontSize = 14.sp, lineHeight = 21.sp)
        if (c.can.commentEdit || c.can.commentDelete) Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
            if (c.can.commentEdit) Text(stringResource(R.string.wr_edit), color = settingsAccent(), fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(onClick = onEdit))
            if (c.can.commentDelete) Text(stringResource(R.string.wr_delete), color = WorkRed, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(onClick = onDelete))
        }
    }
}

/** Change the title, the line about the file and the part it belongs to. */
@Composable
private fun EditFileSheet(project: String, f: WorkFileDto, parts: List<com.dorr.freelance.net.WorkPartDto>, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var title by rememberSaveable { mutableStateOf(f.title) }
    var description by rememberSaveable { mutableStateOf(f.description.orEmpty()) }
    var part by rememberSaveable { mutableStateOf(f.milestone?.uuid) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    WorkSheet(title = stringResource(R.string.wr_file_edit), onDismiss = onDismiss, dismissible = !busy) {
        DorrTextField(value = title, onValueChange = { if (it.length <= 160) title = it }, label = stringResource(R.string.wr_file_title), modifier = Modifier.fillMaxWidth())
        DorrTextField(value = description, onValueChange = { if (it.length <= 1000) description = it }, label = stringResource(R.string.wr_description), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
        if (parts.isNotEmpty()) {
            Text(stringResource(R.string.wr_part), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
            PartPicker(parts, part) { part = it }
        }
        error?.let { Text(it, color = WorkRed, fontSize = 12.5.sp) }
        SettingsPrimaryButton(text = stringResource(R.string.wr_save), loading = busy, enabled = title.isNotBlank(), onClick = {
            busy = true
            error = null
            scope.launch {
                val body = mapOf<String, Any?>("title" to title.trim(), "description" to description.trim(), "milestone" to (part ?: ""))
                val r = apiCall { ApiClient.workroom.updateFile(project, f.uuid, body) }
                busy = false
                if (r is ApiResult.Ok) onSaved() else error = r.errorText(offlineText) ?: genericError
            }
        })
    }
}

/** Change the words of one of my notes. */
@Composable
private fun EditNoteSheet(project: String, c: WorkCommentDto, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var body by rememberSaveable { mutableStateOf(c.body) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    WorkSheet(title = stringResource(R.string.wr_note_edit), onDismiss = onDismiss, dismissible = !busy) {
        DorrTextField(value = body, onValueChange = { if (it.length <= 2000) body = it }, singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
        error?.let { Text(it, color = WorkRed, fontSize = 12.5.sp) }
        SettingsPrimaryButton(text = stringResource(R.string.wr_save), loading = busy, enabled = body.isNotBlank(), onClick = {
            busy = true
            error = null
            scope.launch {
                val r = apiCall { ApiClient.workroom.editComment(project, c.uuid, WorkCommentBodyRequest(body.trim())) }
                busy = false
                if (r is ApiResult.Ok) onSaved() else error = r.errorText(offlineText) ?: genericError
            }
        })
    }
}
