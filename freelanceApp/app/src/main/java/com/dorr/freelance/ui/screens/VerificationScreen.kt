package com.dorr.freelance.ui.screens

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Badge
import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.webkit.MimeTypeMap
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.DocTypeDto
import com.dorr.freelance.net.RequiredDocDto
import com.dorr.freelance.net.VerificationDocDto
import com.dorr.freelance.net.VerificationOverviewDto
import com.dorr.freelance.net.apiDone
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.components.DateField
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.FieldLabel
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.File

private const val MAX_DOC_BYTES = 8L * 1024 * 1024

/**
 * Verifying the account by hand (FL-107 / FL-108): what is required for the freelancer's country, uploading each document
 * (front and back, from the gallery, a file or the camera), and sending them for review. The state comes from one request
 * when the page opens (and when the connection returns); an upload, a delete and the submit each answer with one request.
 * While the documents are under review nothing can be changed.
 */
@Composable
fun VerificationScreen(onBack: () -> Unit, onOpenProfile: () -> Unit) {
    val reconnect = collectReconnectTick()
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val submittedOk = stringResource(R.string.verification_submitted_ok)

    var overview by remember { mutableStateOf<VerificationOverviewDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var info by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    // null = the list; else the type being uploaded (kept across the app being killed while a picker or the camera is open).
    var formTypeId by rememberSaveable { mutableStateOf<Int?>(null) }

    suspend fun reload() {
        val result = apiCall { ApiClient.verification.overview() }
        if (result is ApiResult.Ok) {
            overview = result.data
            error = null
        } else {
            error = result.errorText(offlineText) ?: genericError
        }
    }

    LaunchedEffect(reconnect) { reload() }

    val current = overview
    val typeInForm: DocTypeDto? = formTypeId?.let { id -> current?.required?.map { it.type }?.firstOrNull { it.id == id } }
    if (formTypeId != null && typeInForm != null) {
        BackHandler { formTypeId = null }
        DocumentForm(
            type = typeInForm,
            onBack = { formTypeId = null },
            onUploaded = {
                scope.launch {
                    reload()
                    formTypeId = null
                }
            },
        )
        return
    }

    fun submit() {
        busy = true
        error = null
        info = null
        scope.launch {
            val result = apiCall { ApiClient.verification.submit() }
            busy = false
            if (result is ApiResult.Ok) {
                overview = result.data
                info = submittedOk
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    fun deleteDraft(doc: VerificationDocDto) {
        busy = true
        error = null
        scope.launch {
            val failed = apiDone(offlineText, genericError) { ApiClient.verification.deleteDocument(doc.id) }
            if (failed == null) reload() else error = failed
            busy = false
        }
    }

    fun view(doc: VerificationDocDto, side: String) {
        busy = true
        scope.launch {
            val file = withContext(Dispatchers.IO) { downloadDocFile(context, doc.id, side) }
            busy = false
            if (file == null) {
                error = genericError
            } else {
                openFile(context, file) { error = genericError }
            }
        }
    }

    Page(title = stringResource(R.string.verification_title), onBack = onBack) {
        if (current != null) {
            val editable = current.step != "under_review"
            Card {
                Text(stepText(current.step, current.status), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                current.lastRequest?.reason?.takeIf { it.isNotBlank() }?.let {
                    Text(stringResource(R.string.verification_reason, it), color = settingsMut(), fontSize = 13.sp)
                }
            }

            if (current.needsResidency) {
                Card {
                    Text(stringResource(R.string.verification_residency_hint), color = settingsMut(), fontSize = 13.sp)
                    Text(stringResource(R.string.verification_open_profile), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(onClick = onOpenProfile))
                }
            }

            Text(stringResource(R.string.verification_required_docs), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.fillMaxWidth())
            if (current.required.isEmpty()) Text(stringResource(R.string.verification_none_required), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.fillMaxWidth())
            current.required.forEach { row ->
                RequiredRow(row, editable, busy, onAdd = { formTypeId = row.type.id }, onView = ::view, onDelete = ::deleteDraft)
            }

            if (current.others.isNotEmpty()) {
                Text(stringResource(R.string.verification_other_docs), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.fillMaxWidth())
                current.others.forEach { doc ->
                    Card {
                        Text(doc.type?.name.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                        Text(docStatusText(doc), color = settingsMut(), fontSize = 12.sp)
                    }
                }
            }

            val blockers = current.submitBlockers.mapNotNull { blockerText(it, current.required) }
            if (editable && blockers.isNotEmpty()) {
                Card { blockers.forEach { Text("• $it", color = settingsMut(), fontSize = 13.sp) } }
            }
            if (editable && current.status != "verified") {
                SettingsPrimaryButton(text = stringResource(R.string.verification_submit), enabled = current.canSubmit, loading = busy, onClick = ::submit)
            }
        }
        info?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

@Composable
private fun RequiredRow(
    row: RequiredDocDto,
    editable: Boolean,
    busy: Boolean,
    onAdd: () -> Unit,
    onView: (VerificationDocDto, String) -> Unit,
    onDelete: (VerificationDocDto) -> Unit,
) {
    val doc = row.document
    Card {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text(row.type.name, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
            Text(if (doc == null) stringResource(R.string.doc_missing) else docStatusText(doc), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
        }
        row.type.instructions?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
        if (doc != null) {
            if (!doc.number.isNullOrBlank()) Text(doc.number, color = settingsMut(), fontSize = 12.sp)
            doc.rejectionReason?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.verification_reason, it), color = settingsMut(), fontSize = 12.sp) }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
            if (editable) {
                Text(
                    stringResource(if (doc == null) R.string.doc_add else R.string.doc_replace), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.clickable(enabled = !busy, onClick = onAdd),
                )
            }
            if (doc?.files?.get("front") != null) {
                Text(stringResource(R.string.doc_view_front), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(enabled = !busy) { onView(doc, "front") })
            }
            if (doc?.files?.get("back") != null) {
                Text(stringResource(R.string.doc_view_back), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(enabled = !busy) { onView(doc, "back") })
            }
            if (doc != null && doc.status == "draft" && editable) {
                Text(stringResource(R.string.doc_delete_draft), color = settingsMut(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(enabled = !busy) { onDelete(doc) })
            }
        }
    }
}

/** Uploading one document: its number and expiry when the type asks for them, and the front (and back) as a photo or a file. */
@Composable
private fun DocumentForm(type: DocTypeDto, onBack: () -> Unit, onUploaded: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val tooLarge = stringResource(R.string.doc_file_large)
    val wrongType = stringResource(R.string.doc_file_type)

    var number by rememberSaveable { mutableStateOf("") }
    var expires by rememberSaveable { mutableStateOf("") }
    var frontPath by rememberSaveable { mutableStateOf<String?>(null) }
    var backPath by rememberSaveable { mutableStateOf<String?>(null) }
    // Which side the picker or the camera that is open now is for, and where the camera writes (kept across the app being killed).
    var pendingSide by rememberSaveable { mutableStateOf("front") }
    var cameraUriText by rememberSaveable { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun take(uri: Uri, side: String) {
        scope.launch {
            val file = withContext(Dispatchers.IO) { prepareDocFile(context, uri, "doc_$side") }
            when {
                file == null -> error = wrongType
                file.length() > MAX_DOC_BYTES -> {
                    file.delete()
                    error = tooLarge
                }
                else -> {
                    error = null
                    if (side == "front") frontPath = file.absolutePath else backPath = file.absolutePath
                }
            }
        }
    }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri -> uri?.let { take(it, pendingSide) } }
    val camera = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { taken -> if (taken) cameraUriText?.let { take(Uri.parse(it), pendingSide) } }
    fun launchCamera(side: String) {
        pendingSide = side
        val file = File(context.cacheDir, "camera").apply { mkdirs() }.let { File(it, "doc_camera.jpg") }
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.files", file)
        cameraUriText = uri.toString()
        camera.launch(uri)
    }
    val cameraPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted -> if (granted) launchCamera(pendingSide) }
    fun openCamera(side: String) {
        pendingSide = side
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) launchCamera(side)
        else cameraPermission.launch(Manifest.permission.CAMERA)
    }

    val frontFile = frontPath?.let { File(it) }?.takeIf { it.exists() }
    val backFile = backPath?.let { File(it) }?.takeIf { it.exists() }
    val ready = frontFile != null && (!type.needsBackSide || backFile != null) &&
        (!type.hasNumber || number.isNotBlank()) && (!type.hasExpiry || expires.isNotBlank())

    fun upload() {
        val front = frontFile ?: return
        busy = true
        error = null
        val text = "text/plain".toMediaType()
        val fields = mutableMapOf<String, RequestBody>("document_type_id" to type.id.toString().toRequestBody(text))
        if (type.hasNumber) fields["number"] = number.trim().toRequestBody(text)
        if (type.hasExpiry) fields["expires_on"] = expires.toRequestBody(text)
        fun part(name: String, f: File) = MultipartBody.Part.createFormData(
            name, f.name, f.asRequestBody((if (f.extension.equals("pdf", true)) "application/pdf" else "image/jpeg").toMediaTypeOrNull()),
        )
        scope.launch {
            val result = apiCall { ApiClient.verification.upload(fields, part("front", front), if (type.needsBackSide) backFile?.let { part("back", it) } else null) }
            busy = false
            if (result is ApiResult.Ok) {
                // The identity files do not stay in the cache once they are on the server.
                frontFile.delete()
                backFile?.delete()
                onUploaded()
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    Page(title = type.name, onBack = onBack) {
        com.dorr.freelance.ui.components.FormIntro(Icons.Rounded.Badge, type.name, type.instructions.orEmpty())
        com.dorr.freelance.ui.components.FormGroup {
            if (type.hasNumber) {
                DorrTextField(number, { number = it.take(64) }, label = stringResource(R.string.doc_number), icon = Icons.Rounded.Badge, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Ascii), modifier = Modifier.fillMaxWidth())
            }
            if (type.hasExpiry) DateField(expires, { expires = it }, label = stringResource(R.string.doc_expires), modifier = Modifier.fillMaxWidth())
        }
        SideCard(stringResource(R.string.doc_front), frontFile, { pendingSide = "front"; picker.launch(arrayOf("image/jpeg", "image/png", "application/pdf")) }, { openCamera("front") })
        if (type.needsBackSide) SideCard(stringResource(R.string.doc_back), backFile, { pendingSide = "back"; picker.launch(arrayOf("image/jpeg", "image/png", "application/pdf")) }, { openCamera("back") })
        SettingsPrimaryButton(text = stringResource(R.string.doc_upload), enabled = ready, loading = busy, onClick = ::upload)
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

@Composable
private fun SideCard(title: String, file: File?, onPick: () -> Unit, onCamera: () -> Unit) {
    Card {
        FieldLabel(title)
        if (file != null) Text(stringResource(R.string.doc_chosen) + ": " + file.name, color = settingsMut(), fontSize = 12.sp)
        Row(horizontalArrangement = Arrangement.spacedBy(20.dp), modifier = Modifier.padding(top = 2.dp)) {
            Text(stringResource(R.string.doc_choose), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(onClick = onPick))
            Text(stringResource(R.string.doc_camera), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(onClick = onCamera))
        }
    }
}

@Composable
internal fun stepText(step: String?, status: String?): String = when (step) {
    "complete_profile" -> stringResource(R.string.step_complete_profile)
    "submit_verification" -> stringResource(R.string.step_submit_verification)
    "under_review" -> stringResource(R.string.step_under_review)
    "fix_documents" -> stringResource(R.string.step_fix_documents)
    "suspended" -> stringResource(R.string.step_suspended)
    "blocked" -> stringResource(R.string.step_blocked)
    else -> if (status == "verified") stringResource(R.string.status_verified) else status.orEmpty()
}

@Composable
private fun docStatusText(doc: VerificationDocDto): String = when {
    doc.isExpired || doc.status == "expired" -> stringResource(R.string.doc_status_expired)
    doc.status == "draft" -> stringResource(R.string.doc_status_draft)
    doc.status == "pending" -> stringResource(R.string.doc_status_pending)
    doc.status == "approved" -> stringResource(R.string.doc_status_approved)
    doc.status == "rejected" -> stringResource(R.string.doc_status_rejected)
    doc.status == "replaced" -> stringResource(R.string.doc_status_replaced)
    else -> doc.status
}

@Composable
private fun blockerText(code: String, required: List<RequiredDocDto>): String? {
    fun nameOf(typeCode: String) = required.firstOrNull { it.type.code == typeCode }?.type?.name ?: typeCode
    return when {
        code == "not_allowed" -> stringResource(R.string.verification_blocker_not_allowed)
        code == "residency" -> stringResource(R.string.verification_blocker_residency)
        code.startsWith("missing:") -> stringResource(R.string.verification_blocker_missing, nameOf(code.removePrefix("missing:")))
        code.startsWith("expired:") -> stringResource(R.string.verification_blocker_expired, nameOf(code.removePrefix("expired:")))
        else -> null
    }
}

/** Downloads one of my verification files (signed in, private) into the cache; null when it can't. */
private suspend fun downloadDocFile(context: Context, id: Int, side: String): File? = runCatching {
    val response = ApiClient.verification.file(id, side)
    if (!response.isSuccessful) return@runCatching null
    val type = response.body()?.contentType()?.toString() ?: ""
    val ext = MimeTypeMap.getSingleton().getExtensionFromMimeType(type.substringBefore(';')) ?: "jpg"
    val file = File(context.cacheDir, "proofs").apply { mkdirs() }.let { File(it, "verification_${id}_$side.$ext") }
    response.body()?.byteStream()?.use { input -> file.outputStream().use { input.copyTo(it) } } ?: return@runCatching null
    file
}.getOrNull()

private fun openFile(context: Context, file: File, onFail: () -> Unit) {
    val uri = FileProvider.getUriForFile(context, "${context.packageName}.files", file)
    val type = MimeTypeMap.getSingleton().getMimeTypeFromExtension(file.extension.lowercase()) ?: "*/*"
    runCatching {
        context.startActivity(Intent(Intent.ACTION_VIEW).setDataAndType(uri, type).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK))
    }.onFailure { onFail() }
}
