package com.dorr.freelance.ui.screens.workroom

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AttachFile
import androidx.compose.material.icons.rounded.ChatBubbleOutline
import androidx.compose.material.icons.rounded.CloudUpload
import androidx.compose.material.icons.rounded.Folder
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.AppLocale
import com.dorr.freelance.net.OfferTeamDto
import com.dorr.freelance.net.WorkFileDto
import com.dorr.freelance.net.WorkFileLimitDto
import com.dorr.freelance.net.WorkFileSummaryDto
import com.dorr.freelance.net.WorkPartDto
import com.dorr.freelance.net.WorkUploadRulesDto
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.kit.Corners
import com.dorr.freelance.ui.kit.RedToggle
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.StatusPill
import com.dorr.freelance.ui.kit.Tints
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.screens.PickedFile
import com.dorr.freelance.ui.screens.readFile
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody

/** The files of the Workroom: the read (search included), the numbers and what the upload takes. */
internal class FilesState(private val project: String) {
    /** What is typed in the search box, and what the last read used. */
    var query by mutableStateOf("")
    var applied by mutableStateOf("")
    var summary by mutableStateOf(WorkFileSummaryDto())
    var limit by mutableStateOf(WorkFileLimitDto())
    var rules by mutableStateOf(WorkUploadRulesDto())
    var readOnly by mutableStateOf(false)
    var canUpload by mutableStateOf(false)

    val feed = WorkFeed<WorkFileDto>({ it.uuid }) { page ->
        val r = apiCall { ApiClient.workroom.files(project, search = applied.trim().takeIf { it.isNotEmpty() }, perPage = WORK_PAGE, page = page) }
        if (r is ApiResult.Ok) {
            summary = r.data.summary
            limit = r.data.limit
            rules = r.data.upload
            readOnly = r.data.readOnly
            canUpload = r.data.can.fileUpload
        }
        r.rows { it.files }
    }
}

/** The tab of the files: the numbers, the search, the cards (a tap opens the file) and the button to upload. */
@Composable
internal fun FilesTab(project: String, state: FilesState, parts: List<WorkPartDto>, team: OfferTeamDto?, onOpenFile: (String) -> Unit, onToast: (String) -> Unit, onChanged: () -> Unit) {
    val scope = rememberCoroutineScope()
    var adding by remember { mutableStateOf(false) }

    // A word typed in the search box is sent once the typing pauses (this answers the person's typing, nothing runs by itself).
    LaunchedEffect(state.query) {
        if (state.query == state.applied) return@LaunchedEffect
        delay(450)
        state.applied = state.query
        state.feed.reload()
    }

    FeedList(
        feed = state.feed, keyOf = { it.uuid },
        empty = if (state.applied.isBlank()) Triple(Icons.Rounded.Folder, stringResource(R.string.wr_files_empty_title), stringResource(R.string.wr_files_empty_body))
        else Triple(Icons.Rounded.Search, stringResource(R.string.wr_files_nomatch_title), stringResource(R.string.wr_files_nomatch_body)),
        fabLabel = if (state.canUpload && !state.readOnly) stringResource(R.string.wr_file_upload) else null,
        onFab = if (state.canUpload && !state.readOnly) ({ adding = true }) else null,
        header = {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                if (state.readOnly) ReadOnlyBanner()
                FileNumbers(state)
                DorrTextField(
                    value = state.query, onValueChange = { if (it.length <= 100) state.query = it }, placeholder = stringResource(R.string.wr_files_search),
                    icon = Icons.Rounded.Search, modifier = Modifier.fillMaxWidth(),
                )
            }
        },
    ) { f -> FileCard(f) { onOpenFile(f.uuid) } }

    if (adding) UploadSheet(
        project = project, existing = null, rules = state.rules, parts = parts, team = team, onDismiss = { adding = false },
        onDone = { adding = false; scope.launch { state.feed.reload() }; onChanged() },
    )
}

/** What the Workroom holds: files, versions and the storage used (with the limit when there is one). */
@Composable
private fun FileNumbers(state: FilesState) {
    val s = state.summary
    val storage = fileSize(s.storageBytes)
    Row(Modifier.fillMaxWidth().clip(Corners.card).background(settingsMut().copy(alpha = 0.07f)).padding(14.dp), horizontalArrangement = Arrangement.SpaceBetween) {
        FileCell(stringResource(R.string.wr_n_files), s.files.toString() + (state.limit.maxFiles?.let { " / $it" } ?: ""))
        FileCell(stringResource(R.string.wr_n_versions), s.versions.toString())
        FileCell(stringResource(R.string.wr_n_storage), storage + (state.limit.maxStorageMb?.let { " / $it MB" } ?: ""))
    }
}

@Composable
private fun FileCell(label: String, value: String) {
    Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(1.dp)) {
        Text(value, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.Black, maxLines = 1)
        Text(label, color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
    }
}

/** One file: a tile by its type, the title, the newest version, who added it and what hangs on it (versions, notes). */
@Composable
private fun FileCard(f: WorkFileDto, onClick: () -> Unit) {
    val locale = AppLocale.java()
    val cur = f.current
    val (icon, tint) = fileIcon(cur?.mimeType.orEmpty(), cur?.name.orEmpty())
    WorkCard(onClick = onClick) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(Modifier.size(46.dp).clip(Corners.small).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(24.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(f.title, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                cur?.let {
                    Text(
                        listOfNotNull(it.name.takeIf { n -> n.isNotBlank() }, fileSize(it.sizeBytes)).joinToString(" · "),
                        color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
                    )
                }
            }
        }
        f.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp, maxLines = 2, overflow = TextOverflow.Ellipsis) }
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth()) {
            if (f.scope == "team") TeamOnlyPill()
            if (cur?.previewOnly == true) PreviewOnlyPill()
            if (f.versionsCount > 1) StatusPill(stringResource(R.string.wr_version_n, cur?.number ?: f.versionsCount), Tints.blue, Icons.Rounded.History)
            if (f.commentsCount > 0) StatusPill(f.commentsCount.toString(), settingsAccent(), Icons.Rounded.ChatBubbleOutline)
            Spacer(Modifier.weight(1f))
            workWhen(cur?.createdAt ?: f.updatedAt ?: f.createdAt, locale)?.let { Text(it, color = settingsMut(), fontSize = 11.sp) }
        }
        f.milestone?.let { PartTag(it) }
    }
}

/**
 * Uploading a file (the first version), or a new version of [existing]: pick the file (checked for size and type before it is sent),
 * its title, a line about it, the part it belongs to, and for a team's project whether it is only for the team.
 */
@Composable
internal fun UploadSheet(
    project: String, existing: WorkFileDto?, rules: WorkUploadRulesDto, parts: List<WorkPartDto>, team: OfferTeamDto?,
    onDismiss: () -> Unit, onDone: () -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val tooLarge = stringResource(R.string.wr_file_too_large, rules.maxKb / 1024)
    val wrongType = stringResource(R.string.wr_file_wrong_type, rules.mimes.joinToString(", "))
    val unreadable = stringResource(R.string.wr_file_unreadable)
    var picked by remember { mutableStateOf<PickedFile?>(null) }
    var title by rememberSaveable { mutableStateOf("") }
    var description by rememberSaveable { mutableStateOf("") }
    var note by rememberSaveable { mutableStateOf("") }
    var part by rememberSaveable { mutableStateOf<String?>(null) }
    var teamOnly by rememberSaveable { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri: Uri? ->
        if (uri == null) return@rememberLauncherForActivityResult
        scope.launch {
            val file = withContext(Dispatchers.IO) { readFile(context, uri) }
            val ext = file?.name?.substringAfterLast('.', "")?.lowercase().orEmpty()
            when {
                file == null -> { picked = null; error = unreadable }
                rules.maxKb > 0 && file.bytes.size > rules.maxKb * 1024L -> { picked = null; error = tooLarge }
                rules.mimes.isNotEmpty() && ext !in rules.mimes.map { it.lowercase() } -> { picked = null; error = wrongType }
                else -> {
                    picked = file
                    error = null
                    if (existing == null && title.isBlank()) title = file.name.substringBeforeLast('.').take(160)
                }
            }
        }
    }

    fun send() {
        val file = picked ?: return
        busy = true
        error = null
        scope.launch {
            val text = "text/plain".toMediaTypeOrNull()
            fun field(v: String): RequestBody = v.toRequestBody(text)
            val bytes = file.bytes.toRequestBody(file.type.toMediaTypeOrNull())
            val body = MultipartBody.Part.createFormData("file", file.name, bytes)
            val r = if (existing == null) {
                val fields = buildMap<String, RequestBody> {
                    if (title.isNotBlank()) put("title", field(title.trim()))
                    if (description.isNotBlank()) put("description", field(description.trim()))
                    part?.let { put("milestone", field(it)) }
                    if (note.isNotBlank()) put("note", field(note.trim()))
                    if (teamOnly) put("scope", field("team"))
                }
                apiCall { ApiClient.workroom.uploadFile(project, fields, body) }
            } else {
                val fields = buildMap<String, RequestBody> { if (note.isNotBlank()) put("note", field(note.trim())) }
                apiCall { ApiClient.workroom.addVersion(project, existing.uuid, fields, body) }
            }
            busy = false
            if (r is ApiResult.Ok) onDone() else error = r.errorText(offlineText) ?: genericError
        }
    }

    WorkSheet(title = stringResource(if (existing == null) R.string.wr_file_upload else R.string.wr_version_add), onDismiss = onDismiss, dismissible = !busy) {
        // The file first: a big dashed tile that turns into the chosen file.
        val chosen = picked
        Row(
            Modifier.fillMaxWidth().clip(Corners.card).background(settingsAccent().copy(alpha = 0.08f)).clickable(enabled = !busy) { picker.launch(arrayOf("*/*")) }.padding(16.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Icon(if (chosen == null) Icons.Rounded.CloudUpload else Icons.Rounded.AttachFile, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(26.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(chosen?.name ?: stringResource(R.string.wr_file_pick), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(
                    chosen?.let { fileSize(it.bytes.size.toLong()) } ?: stringResource(R.string.wr_file_rules, rules.maxKb / 1024),
                    color = settingsMut(), fontSize = 12.sp,
                )
            }
        }
        if (existing == null) {
            DorrTextField(value = title, onValueChange = { if (it.length <= 160) title = it }, label = stringResource(R.string.wr_file_title), modifier = Modifier.fillMaxWidth())
            DorrTextField(value = description, onValueChange = { if (it.length <= 1000) description = it }, label = stringResource(R.string.wr_description), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
            if (parts.isNotEmpty()) {
                Text(stringResource(R.string.wr_part), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                PartPicker(parts, part) { part = it }
            }
        }
        DorrTextField(value = note, onValueChange = { if (it.length <= 500) note = it }, label = stringResource(R.string.wr_version_note), placeholder = stringResource(R.string.wr_version_note_hint), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
        if (team != null && existing == null) Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(stringResource(R.string.wr_team_only), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                Text(stringResource(R.string.wr_team_only_file_hint), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
            }
            RedToggle(on = teamOnly, onClick = { teamOnly = !teamOnly })
        }
        error?.let { Text(it, color = WorkRed, fontSize = 12.5.sp, lineHeight = 18.sp) }
        SettingsPrimaryButton(text = stringResource(R.string.wr_send), loading = busy, enabled = picked != null, onClick = ::send)
        Spacer(Modifier.width(1.dp))
    }
}
