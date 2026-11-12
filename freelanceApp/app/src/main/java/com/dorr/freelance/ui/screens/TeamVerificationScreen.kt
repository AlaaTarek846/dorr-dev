package com.dorr.freelance.ui.screens

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.webkit.MimeTypeMap
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Verified
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.AppLocale
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.TeamVerificationDocDto
import com.dorr.freelance.net.TeamVerificationDto
import com.dorr.freelance.net.apiDone
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.FieldLabel
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.components.SkeletonDetail
import com.dorr.freelance.ui.kit.Corners
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.StatusPill
import com.dorr.freelance.ui.kit.Tints
import com.dorr.freelance.ui.kit.appCard
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsCard
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.locale.LocaleAwareBottomSheet
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.File
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle

private const val MAX_PAPER_BYTES = 8 * 1024 * 1024
private const val MAX_PAPERS = 10
private val PAPER_TYPES = arrayOf("image/jpeg", "image/png", "application/pdf")

/**
 * FS-209c: verifying a team (FS-202b) — optional, and the team's Owners only. The Owners add the team's papers (a title and a JPG / PNG /
 * PDF up to 8 MB, up to ten), send them for review, and read the staff's answer; while the papers are with the staff nothing can change.
 * It reads when it opens, when a notice arrives (the decision) and when the connection returns; an upload, a removal and the sending each
 * answer with the new state.
 */
@Composable
fun TeamVerificationScreen(teamUuid: String, onBack: () -> Unit) {
    val reconnect = collectReconnectTick()
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val sentText = stringResource(R.string.tv_submitted_ok)
    var state by remember { mutableStateOf<TeamVerificationDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var info by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var adding by rememberSaveable { mutableStateOf(false) }

    suspend fun load() {
        val r = apiCall { ApiClient.teams.verification(teamUuid) }
        if (r is ApiResult.Ok) { state = r.data; error = null } else if (state == null) error = r.errorText(offlineText) ?: genericError
    }
    LaunchedEffect(teamUuid, reconnect) { load() }
    LaunchedEffect(teamUuid) { Realtime.changed.collect { load() } }

    fun view(doc: TeamVerificationDocDto) {
        busy = true
        error = null
        scope.launch {
            val file = withContext(Dispatchers.IO) { downloadPaper(context, teamUuid, doc.uuid) }
            busy = false
            if (file == null || !openPaper(context, file)) error = genericError
        }
    }

    fun remove(doc: TeamVerificationDocDto) {
        busy = true
        error = null
        info = null
        scope.launch {
            val problem = apiDone(offlineText, genericError) { ApiClient.teams.deleteVerificationDocument(teamUuid, doc.uuid) }
            if (problem == null) load() else error = problem
            busy = false
        }
    }

    fun submit() {
        busy = true
        error = null
        info = null
        scope.launch {
            val r = apiCall { ApiClient.teams.submitVerification(teamUuid) }
            if (r is ApiResult.Ok) { state = r.data; info = sentText } else error = r.errorText(offlineText) ?: genericError
            busy = false
        }
    }

    val v = state
    Page(title = stringResource(R.string.tv_title), onBack = onBack) {
        if (v == null) {
            if (error == null) SkeletonDetail()
        } else {
            StatusCard(v)
            Text(stringResource(R.string.tv_intro), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp, modifier = Modifier.fillMaxWidth())

            Text(stringResource(R.string.tv_docs_title), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.fillMaxWidth())
            if (v.documents.isEmpty()) Text(stringResource(R.string.tv_docs_empty), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.fillMaxWidth())
            v.documents.forEach { d -> PaperRow(d, canEdit = v.canEdit, busy = busy, onView = { view(d) }, onRemove = { remove(d) }) }

            if (v.canEdit) {
                if (v.documents.size < MAX_PAPERS) SettingsPrimaryButton(text = stringResource(R.string.tv_add), enabled = !busy, onClick = { adding = true })
                else Text(stringResource(R.string.tv_limit), color = settingsMut(), fontSize = 12.5.sp, modifier = Modifier.fillMaxWidth())
            } else if (v.status == "submitted") {
                Text(stringResource(R.string.tv_locked), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp, modifier = Modifier.fillMaxWidth())
            }
            if (v.canEdit) {
                SettingsPrimaryButton(text = stringResource(R.string.tv_submit), enabled = v.canSubmit && !busy, loading = busy, onClick = ::submit)
                if (!v.canSubmit && v.documents.isEmpty()) Text(stringResource(R.string.tv_submit_hint), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.fillMaxWidth())
            }

            v.lastRequest?.let { last ->
                Text(stringResource(R.string.tv_last_title), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.fillMaxWidth())
                Card {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        StatusPill(statusText(last.status), statusTint(last.status))
                    }
                    last.submittedAt?.let { Text(stringResource(R.string.tv_req_submitted, dayText(it)), color = settingsMut(), fontSize = 12.sp) }
                    last.decidedAt?.let { Text(stringResource(R.string.tv_req_decided, dayText(it)), color = settingsMut(), fontSize = 12.sp) }
                    last.reason?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.tv_reason, it), color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp) }
                    last.documents.forEach { d -> PaperRow(d, canEdit = false, busy = busy, onView = { view(d) }, onRemove = {}) }
                }
            }
        }
        info?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }

    if (adding) AddPaperSheet(
        teamUuid = teamUuid,
        onUploaded = { adding = false; scope.launch { load() } },
        onDismiss = { adding = false },
    )
}

@Composable
private fun statusText(status: String): String = when (status) {
    "verified", "approved" -> stringResource(R.string.tv_status_verified)
    "rejected" -> stringResource(R.string.tv_status_rejected)
    "submitted" -> stringResource(R.string.tv_status_submitted)
    else -> stringResource(R.string.tv_status_none)
}

private fun statusTint(status: String): Color = when (status) {
    "verified", "approved" -> Tints.blue
    "rejected" -> Tints.rose
    else -> Tints.amber
}

/** Where the team stands: not verified, with the staff, turned down (with why), or verified. */
@Composable
private fun StatusCard(v: TeamVerificationDto) {
    val tint = statusTint(v.status)
    Row(
        Modifier.fillMaxWidth().appCard(Corners.card).padding(16.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(
            Modifier.size(44.dp).clip(Corners.small).background(tint.copy(alpha = 0.14f)),
            contentAlignment = Alignment.Center,
        ) { Icon(Icons.Rounded.Verified, contentDescription = null, tint = tint, modifier = Modifier.size(24.dp)) }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Text(statusText(v.status), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
            when (v.status) {
                "verified" -> v.verifiedAt?.let { Text(stringResource(R.string.tv_verified_at, dayText(it)), color = settingsMut(), fontSize = 12.5.sp) }
                "rejected" -> {
                    Text(stringResource(R.string.tv_rejected_hint), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
                    v.lastRequest?.reason?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.tv_reason, it), color = settingsInk(), fontSize = 12.5.sp, lineHeight = 18.sp) }
                }
                "submitted" -> Text(stringResource(R.string.tv_submitted_hint), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
                else -> Text(stringResource(R.string.tv_none_hint), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
            }
        }
    }
}

/** One paper: its title and file, the way to open it, and (for a draft of an Owner) to remove it. */
@Composable
private fun PaperRow(d: TeamVerificationDocDto, canEdit: Boolean, busy: Boolean, onView: () -> Unit, onRemove: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().appCard(Corners.card).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Icon(Icons.Rounded.Description, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(d.title, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2)
            d.file?.name?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 1) }
        }
        if (d.file != null) Text(
            stringResource(R.string.tv_view), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.clickable(enabled = !busy, onClick = onView).padding(4.dp),
        )
        if (canEdit) Text(
            stringResource(R.string.tv_remove), color = Color(0xFFDC2626), fontSize = 13.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.clickable(enabled = !busy, onClick = onRemove).padding(4.dp),
        )
    }
}

/** Adding one paper: a title and the file (JPG, PNG or PDF, up to 8 MB). */
@Composable
private fun AddPaperSheet(teamUuid: String, onUploaded: () -> Unit, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val tooLarge = stringResource(R.string.tv_file_large)
    val wrongType = stringResource(R.string.tv_file_type)
    var title by rememberSaveable { mutableStateOf("") }
    var picked by remember { mutableStateOf<PickedFile?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri: Uri? ->
        if (uri == null) return@rememberLauncherForActivityResult
        scope.launch {
            val file = withContext(Dispatchers.IO) { readFile(context, uri) }
            when {
                file == null || file.type !in PAPER_TYPES -> { picked = null; error = wrongType }
                file.bytes.size > MAX_PAPER_BYTES -> { picked = null; error = tooLarge }
                else -> { picked = file; error = null }
            }
        }
    }

    fun upload() {
        val file = picked ?: return
        busy = true
        error = null
        scope.launch {
            val part = MultipartBody.Part.createFormData("file", file.name, file.bytes.toRequestBody(file.type.toMediaTypeOrNull()))
            val r = apiCall { ApiClient.teams.addVerificationDocument(teamUuid, title.trim().toRequestBody("text/plain".toMediaType()), part) }
            busy = false
            if (r is ApiResult.Ok) onUploaded() else error = r.errorText(offlineText) ?: genericError
        }
    }

    LocaleAwareBottomSheet(onDismissRequest = { if (!busy) onDismiss() }, containerColor = settingsCard(), shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp)) {
        Column(
            Modifier.fillMaxWidth().heightIn(max = 560.dp).imePadding().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            Text(stringResource(R.string.tv_add_title), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            DorrTextField(
                value = title, onValueChange = { title = it.take(120) }, modifier = Modifier.fillMaxWidth(),
                label = stringResource(R.string.tv_doc_title_label), enabled = !busy,
            )
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                FieldLabel(stringResource(R.string.tv_file_label))
                picked?.let { Text(stringResource(R.string.tv_file_chosen, it.name), color = settingsMut(), fontSize = 12.sp) }
                Text(
                    stringResource(R.string.tv_choose), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.clickable(enabled = !busy) { picker.launch(PAPER_TYPES) }.padding(vertical = 4.dp),
                )
                Text(stringResource(R.string.tv_file_hint), color = settingsMut(), fontSize = 11.sp)
            }
            SettingsPrimaryButton(text = stringResource(R.string.tv_upload), enabled = title.trim().length >= 2 && picked != null, loading = busy, onClick = ::upload)
            ErrorBanner(error, Modifier.fillMaxWidth())
        }
    }
}

private fun dayText(iso: String): String = runCatching {
    OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).withLocale(AppLocale.java()))
}.getOrDefault(iso)

/** Downloads one of the team's papers (private, signed in) into the cache; null when it can't. */
private suspend fun downloadPaper(context: Context, team: String, uuid: String): File? = runCatching {
    val response = ApiClient.teams.verificationFile(team, uuid)
    if (!response.isSuccessful) return@runCatching null
    val type = response.body()?.contentType()?.toString().orEmpty()
    val ext = MimeTypeMap.getSingleton().getExtensionFromMimeType(type.substringBefore(';')) ?: "jpg"
    val file = File(context.cacheDir, "proofs").apply { mkdirs() }.let { File(it, "team_verification_$uuid.$ext") }
    response.body()?.byteStream()?.use { input -> file.outputStream().use { input.copyTo(it) } } ?: return@runCatching null
    file
}.getOrNull()

private fun openPaper(context: Context, file: File): Boolean {
    val uri = FileProvider.getUriForFile(context, "${context.packageName}.files", file)
    val type = MimeTypeMap.getSingleton().getMimeTypeFromExtension(file.extension.lowercase()) ?: "*/*"
    return runCatching {
        context.startActivity(Intent(Intent.ACTION_VIEW).setDataAndType(uri, type).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK))
    }.isSuccess
}
