package com.dorr.app.ui.screens.freelance.workroom

import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import androidx.compose.foundation.Image
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
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.AddComment
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.DeleteForever
import androidx.compose.material.icons.rounded.Download
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Save
import androidx.compose.material.icons.rounded.UploadFile
import androidx.compose.material3.CircularProgressIndicator
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
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.WorkCommentBodyRequest
import com.dorr.app.network.WorkCommentDto
import com.dorr.app.network.WorkCommentRequest
import com.dorr.app.network.WorkCommentsDto
import com.dorr.app.network.WorkFileDto
import com.dorr.app.network.WorkVersionDto
import com.dorr.app.network.apiFailure
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.components.PullToRefreshHost
import com.dorr.app.ui.screens.ConfirmDialog
import com.dorr.app.ui.screens.freelance.Chip
import com.dorr.app.ui.screens.freelance.FlAction
import com.dorr.app.ui.screens.freelance.FlAvatar
import com.dorr.app.ui.screens.freelance.FlCorners
import com.dorr.app.ui.screens.freelance.FlDotPill
import com.dorr.app.ui.screens.freelance.FlLoading
import com.dorr.app.ui.screens.freelance.FlNotice
import com.dorr.app.ui.screens.freelance.FlPageHeader
import com.dorr.app.ui.screens.freelance.FlSectionHeader
import com.dorr.app.ui.screens.freelance.FlSheet
import com.dorr.app.ui.screens.freelance.FlTone
import com.dorr.app.ui.screens.freelance.NearEnd
import com.dorr.app.ui.screens.freelance.PagedSpinner
import com.dorr.app.ui.screens.freelance.RightsImageViewer
import com.dorr.app.ui.screens.freelance.auth
import com.dorr.app.ui.screens.freelance.message
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File

/**
 * One file of the Workroom: what it is, every version of it (newest first; a picture shows in place, anything else opens with the phone's own
 * apps), and the notes both sides wrote on it. Its owner adds versions, changes its details or removes it; both sides write notes while the
 * work is running. Read once when it opens, on a live event (a new version, a note), on a pull and when the connection returns.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun WorkroomFilePage(project: String, fileUuid: String, onBack: () -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    val live = rememberWorkroomLive(project)
    val people = rememberWorkroomPeople(project, reconnect)
    var file by remember { mutableStateOf<WorkFileDto?>(null) }
    var failure by remember { mutableStateOf<String?>(null) }
    var version by remember { mutableIntStateOf(0) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var newVersion by remember { mutableStateOf(false) }
    var editing by remember { mutableStateOf(false) }
    var deleting by remember { mutableStateOf(false) }
    var viewing by remember { mutableStateOf<Pair<Bitmap, String>?>(null) }
    var opening by remember { mutableStateOf<Int?>(null) }
    val openFailed = stringResource(R.string.fl_dl_open_failed)
    val versionAdded = stringResource(R.string.wr_file_version_added)

    // The file with its versions: when it opens, on a live event (also a note: its count changes), on a pull and when the connection returns.
    LaunchedEffect(project, fileUuid, reconnect, live.files, version) {
        try {
            file = ApiClient.freelanceWorkroom.file(auth(), project, fileUuid).data?.file
            failure = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            // Read once (an error body can be read only once): the server's words, and whether the file is gone (its owner removed it).
            val refused = e.apiFailure()
            if (refused.httpStatus == 404) file = null
            failure = refused.message ?: generic
        }
    }

    // The notes: all of them, or those on one version; oldest first.
    var versionFilter by rememberSaveable { mutableStateOf<Int?>(null) }
    val commentsMeta = remember(project, fileUuid) { mutableStateOf<WorkCommentsDto?>(null) }
    val comments = remember(project, fileUuid, versionFilter) {
        val v = versionFilter
        workPager(commentsMeta, { it.comments }, { it.uuid }) { page ->
            ApiClient.freelanceWorkroom.comments(auth(), project, file = fileUuid, version = v, perPage = WORK_PAGE, page = page)
        }
    }
    LaunchedEffect(comments, reconnect, live.comments, version) { comments.reload(generic) }
    val listState = rememberLazyListState()
    listState.NearEnd(comments.rows.size) { scope.launch { comments.loadMore(generic) } }

    var draft by rememberSaveable { mutableStateOf("") }
    var sending by remember { mutableStateOf(false) }
    var editingNote by remember { mutableStateOf<WorkCommentDto?>(null) }
    var deletingNote by remember { mutableStateOf<WorkCommentDto?>(null) }
    var scrollToEnd by remember { mutableStateOf(false) }
    LaunchedEffect(comments.rows.size, comments.loading, scrollToEnd) {
        if (scrollToEnd && !comments.loading && !comments.hasMore && comments.rows.isNotEmpty()) {
            listState.animateScrollToItem(listState.layoutInfo.totalItemsCount - 1)
            scrollToEnd = false
        }
    }

    fun act(block: suspend () -> Unit) {
        busy = true
        error = null
        notice = null
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

    fun sendNote() {
        val f = file ?: return
        val text = draft.trim()
        if (text.isEmpty() || sending) return
        sending = true
        error = null
        scope.launch {
            try {
                ApiClient.freelanceWorkroom.addComment(auth(), project, WorkCommentRequest(file = f.uuid, version = versionFilter ?: f.current?.number, body = text))
                draft = ""
                scrollToEnd = true
                version++
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.message(generic)
            }
            sending = false
        }
    }

    Column(Modifier.fillMaxSize()) {
        FlPageHeader(file?.title ?: stringResource(R.string.wr_file_page), onBack)
        val f = file
        if (f == null) {
            FlLoading(failure)
            return@Column
        }
        Box(Modifier.weight(1f).fillMaxWidth()) {
            PullToRefreshHost(Modifier.fillMaxSize()) {
                LazyColumn(
                    Modifier.fillMaxSize(), state = listState,
                    contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 4.dp, bottom = 20.dp), verticalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    item(key = "head") { FileHead(f, people.freelancer) }
                    notice?.let { item(key = "notice") { FlNotice(it, Icons.Rounded.CheckCircle, WrGreen) } }
                    error?.let { item(key = "error") { FlNotice(it, Icons.Rounded.ErrorOutline, WrRed) } }
                    if (f.can.versionAdd) item(key = "add") { FlAction(stringResource(R.string.wr_file_new_version), Icons.Rounded.UploadFile, FlTone.Primary) { newVersion = true } }
                    if (f.can.fileEdit || f.can.fileDelete) item(key = "manage") {
                        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            if (f.can.fileEdit) FlAction(stringResource(R.string.wr_edit), Icons.Rounded.Edit, modifier = Modifier.weight(1f)) { editing = true }
                            if (f.can.fileDelete) FlAction(stringResource(R.string.wr_delete), Icons.Rounded.DeleteForever, FlTone.Danger, modifier = Modifier.weight(1f)) { deleting = true }
                        }
                    }
                    item(key = "versions-head") { FlSectionHeader(stringResource(R.string.wr_file_versions_title, f.versions.size.coerceAtLeast(f.versionsCount)), Modifier.padding(top = 6.dp)) }
                    items(f.versions, key = { "v" + it.number }) { v ->
                        VersionCard(
                            f, v, latest = v.number == f.current?.number, freelancer = people.freelancer, opening = opening == v.number,
                            onView = { viewing = it },
                            onOpen = {
                                opening = v.number
                                scope.launch {
                                    if (!openVersion(context, f, v)) error = openFailed
                                    opening = null
                                }
                            },
                        )
                    }
                    item(key = "notes-head") { FlSectionHeader(stringResource(R.string.wr_notes_title), Modifier.padding(top = 6.dp)) }
                    if (f.versionsCount > 1) item(key = "notes-filter") {
                        WrChipRow {
                            Chip(stringResource(R.string.wr_all), versionFilter == null) { versionFilter = null }
                            f.versions.forEach { v -> Chip("v${v.number}", versionFilter == v.number) { versionFilter = if (versionFilter == v.number) null else v.number } }
                        }
                    }
                    if (comments.loaded && comments.rows.isEmpty()) item(key = "notes-empty") {
                        WrEmpty(Icons.Rounded.AddComment, stringResource(R.string.wr_notes_none), stringResource(R.string.wr_notes_none_body), Modifier.padding(vertical = 0.dp))
                    }
                    items(comments.rows, key = { "c" + it.uuid }) { c ->
                        NoteCard(c, people.freelancer, onEdit = { editingNote = c }, onDelete = { deletingNote = c })
                    }
                    item(key = "more") { PagedSpinner(comments) }
                }
            }
        }
        // Writing a note: while the work runs (the server says so in `can`).
        val canNote = commentsMeta.value?.can?.commentAdd ?: f.can.commentAdd
        if (canNote) Row(
            Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            DorrTextField(
                draft, { draft = it.take(2000) }, modifier = Modifier.weight(1f), singleLine = false,
                placeholder = versionFilter?.let { stringResource(R.string.wr_note_hint_v, it) } ?: stringResource(R.string.wr_note_hint),
            )
            Box(
                Modifier.size(44.dp).clip(CircleShape).background(settingsAccent().copy(alpha = if (draft.isBlank() || sending) 0.4f else 1f))
                    .clickable(enabled = draft.isNotBlank() && !sending, role = Role.Button, onClick = ::sendNote),
                contentAlignment = Alignment.Center,
            ) {
                if (sending) CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(20.dp))
                else Icon(Icons.AutoMirrored.Rounded.Send, stringResource(R.string.wr_note_send), tint = Color.White, modifier = Modifier.size(20.dp))
            }
        } else if (commentsMeta.value?.readOnly == true) FlNotice(stringResource(R.string.wr_read_only), Icons.Rounded.Lock, WrAmber, Modifier.padding(horizontal = 16.dp, vertical = 8.dp))
    }

    val current = file
    if (newVersion && current != null) WorkUploadSheet(
        project = project, file = current, parts = people.parts, rules = com.dorr.app.network.WorkUploadRulesDto(), onDismiss = { newVersion = false },
        onDone = { newVersion = false; notice = versionAdded; version++ },
    )
    if (editing && current != null) FileEditSheet(current, people.parts, busy, onDismiss = { if (!busy) editing = false }) { body ->
        act {
            ApiClient.freelanceWorkroom.updateFile(auth(), project, current.uuid, body)
            editing = false
            version++
        }
    }
    if (deleting && current != null) ConfirmDialog(
        Icons.Rounded.DeleteForever, stringResource(R.string.wr_file_delete_title), stringResource(R.string.wr_file_delete_body, current.title),
        onConfirm = { act { ApiClient.freelanceWorkroom.deleteFile(auth(), project, current.uuid); deleting = false; onBack() } },
        onDismiss = { if (!busy) deleting = false }, loading = busy, confirmLabel = stringResource(R.string.wr_delete),
    )
    editingNote?.let { note ->
        NoteEditSheet(note, busy, onDismiss = { if (!busy) editingNote = null }) { text ->
            act {
                ApiClient.freelanceWorkroom.editComment(auth(), project, note.uuid, WorkCommentBodyRequest(text))
                editingNote = null
                version++
            }
        }
    }
    deletingNote?.let { note ->
        ConfirmDialog(
            Icons.Rounded.DeleteForever, stringResource(R.string.wr_note_delete_title), stringResource(R.string.wr_note_delete_body),
            onConfirm = { act { ApiClient.freelanceWorkroom.deleteComment(auth(), project, note.uuid); deletingNote = null; version++ } },
            onDismiss = { if (!busy) deletingNote = null }, loading = busy, confirmLabel = stringResource(R.string.wr_delete),
        )
    }
    viewing?.let { (bitmap, name) -> RightsImageViewer(bitmap.asImageBitmap(), name) { viewing = null } }
}

/** The file's own card: its icon, its name, what it is, the part it belongs to and who added it. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun FileHead(file: WorkFileDto, freelancer: String) {
    val current = file.current
    Row(
        Modifier.fillMaxWidth().settingsSurface(FlCorners.card).padding(16.dp),
        horizontalArrangement = Arrangement.spacedBy(14.dp), verticalAlignment = Alignment.Top,
    ) {
        Box(Modifier.size(54.dp).clip(FlCorners.icon).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(fileIcon(current?.mimeType.orEmpty(), current?.name.orEmpty()), null, tint = settingsAccent(), modifier = Modifier.size(28.dp))
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(7.dp)) {
            Text(file.title, color = settingsInk(), fontSize = 17.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 23.sp)
            file.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 13.sp, lineHeight = 20.sp) }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                file.milestone?.let { WrTag("${it.position}. ${it.title}", Icons.Rounded.Flag, settingsAccent()) }
                WrTag(if (file.versionsCount > 1) stringResource(R.string.wr_file_v_many, file.versionsCount) else stringResource(R.string.wr_file_v_one), Icons.Rounded.History, WrViolet)
            }
            Text(stringResource(R.string.wr_added_by, sideName(file.createdBySide, freelancer), wrDay(file.createdAt)), color = settingsMut(), fontSize = 11.5.sp)
        }
    }
}

/** One version: its number, its file, who sent it and when, the note that came with it, and «Open» (or the lock when it is only a preview). */
@Composable
private fun VersionCard(
    file: WorkFileDto, v: WorkVersionDto, latest: Boolean, freelancer: String, opening: Boolean,
    onView: (Pair<Bitmap, String>) -> Unit, onOpen: () -> Unit,
) {
    Column(Modifier.fillMaxWidth().settingsSurface(FlCorners.tile).padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(
                "v${v.number}", color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(if (latest) settingsAccent() else WrGray).padding(horizontal = 10.dp, vertical = 4.dp),
            )
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(v.name, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Text(
                    wrSize(v.sizeBytes) + "  ·  " + sideName(v.bySide, freelancer) + "  ·  " + wrLocalDateTime(v.createdAt),
                    color = settingsMut(), fontSize = 11.5.sp, lineHeight = 16.sp,
                )
            }
            if (latest) FlDotPill(stringResource(R.string.wr_file_latest), WrGreen)
        }
        v.note?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp) }
        // The newest picture shows in place (tap: in the app's own viewer).
        if (latest && v.isImage && v.path != null) VersionImage(file.uuid + v.number, v.path, v.name, onView)
        if (v.previewOnly) Text("🔒 " + stringResource(R.string.rights_preview_locked), color = WrAmber, fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold, lineHeight = 17.sp)
        else if (v.path != null) FlAction(stringResource(R.string.wr_file_open), Icons.Rounded.Download, loading = opening, enabled = !opening, onClick = onOpen)
    }
}

/** A picture of a version, read with my sign-in (never a public link) and shown at a size that fits a phone card. */
@Composable
private fun VersionImage(id: String, path: String, name: String, onView: (Pair<Bitmap, String>) -> Unit) {
    var bitmap by remember(id) { mutableStateOf<Bitmap?>(null) }
    LaunchedEffect(id) {
        bitmap = withContext(Dispatchers.IO) {
            runCatching {
                val body = ApiClient.freelance.file(auth(), "mobile/v1/freelance/$path").body() ?: return@runCatching null
                val bytes = body.bytes()
                val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }.also { BitmapFactory.decodeByteArray(bytes, 0, bytes.size, it) }
                val sample = (maxOf(bounds.outWidth, bounds.outHeight) / 1200).coerceAtLeast(1)
                BitmapFactory.decodeByteArray(bytes, 0, bytes.size, BitmapFactory.Options().apply { inSampleSize = sample })
            }.getOrNull()
        }
    }
    val b = bitmap
    if (b == null) Text("🖼 $name", color = settingsMut(), fontSize = 12.sp)
    else Image(
        b.asImageBitmap(), name, contentScale = ContentScale.FillWidth,
        modifier = Modifier.fillMaxWidth().heightIn(max = 320.dp).clip(RoundedCornerShape(12.dp)).clickable { onView(b to name) },
    )
}

/** Downloads one version with the client's own sign-in and opens it with the phone's apps. False when it could not be read or opened. */
private suspend fun openVersion(context: Context, file: WorkFileDto, v: WorkVersionDto): Boolean {
    val path = v.path ?: return false
    val saved = withContext(Dispatchers.IO) {
        runCatching {
            val response = ApiClient.freelance.file(auth(), "mobile/v1/freelance/$path")
            val body = response.body()
            if (!response.isSuccessful || body == null) return@runCatching null
            val dir = File(context.cacheDir, "exports").apply { mkdirs() }
            val safe = v.name.filter { it.isLetterOrDigit() || it in "._- " }.ifBlank { "file" }
            File(dir, "${file.uuid.take(8)}_v${v.number}_$safe").also { out -> body.byteStream().use { input -> out.outputStream().use { input.copyTo(it) } } }
        }.getOrNull()
    } ?: return false
    val uri = FileProvider.getUriForFile(context, context.packageName + ".files", saved)
    val view = Intent(Intent.ACTION_VIEW).setDataAndType(uri, v.mimeType.ifBlank { "*/*" }).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
    return runCatching { context.startActivity(Intent.createChooser(view, v.name).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }.isSuccess
}

/** A note on the file: who wrote it, on which version, when (and «edited»); the writer may change or remove it. */
@Composable
private fun NoteCard(c: WorkCommentDto, freelancer: String, onEdit: () -> Unit, onDelete: () -> Unit) {
    val name = sideName(c.authorSide, freelancer)
    Row(
        Modifier.fillMaxWidth().then(if (c.mine) Modifier.clip(FlCorners.tile).background(settingsAccent().copy(alpha = 0.08f)) else Modifier.settingsSurface(FlCorners.tile)).padding(12.dp),
        horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.Top,
    ) {
        FlAvatar(null, name, 34)
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(name, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                c.version?.let { WrTag("v$it", null, WrViolet) }
                Text(wrLocalDateTime(c.createdAt) + if (c.editedAt != null) "  ·  " + stringResource(R.string.wr_note_edited) else "", color = settingsMut(), fontSize = 11.sp, maxLines = 1)
            }
            Text(c.body, color = settingsInk(), fontSize = 14.sp, lineHeight = 21.sp)
            if (c.can.commentEdit || c.can.commentDelete) Row(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                if (c.can.commentEdit) WrLink(stringResource(R.string.wr_edit), Icons.Rounded.Edit, settingsAccent(), onEdit)
                if (c.can.commentDelete) WrLink(stringResource(R.string.wr_delete), Icons.Rounded.DeleteForever, WrRed, onDelete)
            }
        }
    }
}

@Composable
private fun NoteEditSheet(note: WorkCommentDto, busy: Boolean, onDismiss: () -> Unit, onSave: (String) -> Unit) {
    var text by rememberSaveable { mutableStateOf(note.body) }
    FlSheet(
        Icons.Rounded.Edit, stringResource(R.string.wr_note_edit), "", onDismiss = onDismiss,
        footer = {
            FlAction(stringResource(R.string.common_save), Icons.Rounded.Save, FlTone.Primary, enabled = text.isNotBlank() && text.trim() != note.body, loading = busy) { onSave(text.trim()) }
            FlAction(stringResource(R.string.common_cancel), null, enabled = !busy, onClick = onDismiss)
        },
    ) {
        DorrTextField(text, { text = it.take(2000) }, singleLine = false, minLines = 4, modifier = Modifier.fillMaxWidth())
    }
}

/** The file's details: its name, what it is and the part it belongs to (its versions stay as they are). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun FileEditSheet(file: WorkFileDto, parts: List<com.dorr.app.network.WorkPartDto>, busy: Boolean, onDismiss: () -> Unit, onSave: (Map<String, Any?>) -> Unit) {
    var title by rememberSaveable { mutableStateOf(file.title) }
    var description by rememberSaveable { mutableStateOf(file.description.orEmpty()) }
    var part by rememberSaveable { mutableStateOf(file.milestone?.uuid) }
    FlSheet(
        Icons.Rounded.Edit, stringResource(R.string.wr_file_edit), stringResource(R.string.wr_file_edit_body), onDismiss = onDismiss,
        footer = {
            FlAction(stringResource(R.string.common_save), Icons.Rounded.Save, FlTone.Primary, enabled = title.isNotBlank(), loading = busy) {
                onSave(mapOf("title" to title.trim(), "description" to description.trim(), "milestone" to (part ?: "")))
            }
            FlAction(stringResource(R.string.common_cancel), null, enabled = !busy, onClick = onDismiss)
        },
    ) {
        DorrTextField(title, { title = it.take(160) }, label = stringResource(R.string.wr_file_title), modifier = Modifier.fillMaxWidth())
        DorrTextField(description, { description = it.take(1000) }, label = stringResource(R.string.wr_file_description), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
        if (parts.isNotEmpty()) Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(stringResource(R.string.wr_part), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            PartPicker(parts, part) { part = it }
        }
    }
}
