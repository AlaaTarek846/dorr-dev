package com.dorr.provider.ui.screens

import android.Manifest
import android.content.pm.PackageManager
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Badge
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
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
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.DocumentTypeDto
import com.dorr.provider.net.DocumentsDto
import com.dorr.provider.net.EstablishmentDto
import com.dorr.provider.net.ProviderDocumentDto
import com.dorr.provider.net.Realtime
import com.dorr.provider.net.Session
import com.dorr.provider.net.collectReconnectTick
import com.dorr.provider.net.errorText
import com.dorr.provider.ui.components.Card
import com.dorr.provider.ui.components.DateField
import com.dorr.provider.ui.components.DorrTextField
import com.dorr.provider.ui.components.ErrorBanner
import com.dorr.provider.ui.components.FieldLabel
import com.dorr.provider.ui.components.FormGroup
import com.dorr.provider.ui.components.FormIntro
import com.dorr.provider.ui.components.Page
import com.dorr.provider.ui.kit.SettingsPrimaryButton
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
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

/** The server takes up to 10MB a side (DocumentUploadRequest); a scaled photo is far smaller. */
private const val MAX_DOC_BYTES = 10L * 1024 * 1024

/**
 * The establishment's papers (C-02, A-06): what is required for its country and services, the state of each upload, uploading one
 * (front and back from the camera or the gallery, its number and expiry when the type asks for them), and sending the establishment
 * for review. Read once when the page opens, when the connection returns and when a live notice arrives (a paper reviewed); an upload
 * and the submit each answer with one request. Only the owner uploads the establishment's papers (the server checks it too).
 */
@Composable
fun DocumentsScreen(onBack: () -> Unit, driverUuid: String? = null) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val seat by Session.membership.collectAsState()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val submittedOk = stringResource(R.string.pd_submitted_ok)

    var docs by remember { mutableStateOf<DocumentsDto?>(null) }
    var establishment by remember { mutableStateOf<EstablishmentDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var info by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    // null = the list; else the type being uploaded (kept across the app being killed while a picker or the camera is open).
    var formTypeId by rememberSaveable { mutableStateOf<Int?>(null) }

    suspend fun reload() {
        val result = apiCall { ApiClient.workspace.documents(driverUuid) }
        if (result is ApiResult.Ok) {
            docs = result.data
            error = null
        } else {
            error = result.errorText(offlineText) ?: genericError
        }
        (apiCall { ApiClient.workspace.establishment() } as? ApiResult.Ok)?.data?.let {
            establishment = it.establishment
            Session.setMembership(it.membership)
        }
    }

    LaunchedEffect(reconnect) { reload() }
    LaunchedEffect(Unit) { Realtime.changed.collect { reload() } }

    val current = docs
    val typeInForm = formTypeId?.let { id -> current?.required?.firstOrNull { it.id == id } }
    if (formTypeId != null && typeInForm != null) {
        BackHandler { formTypeId = null }
        DocumentForm(
            type = typeInForm,
            subject = if (driverUuid != null) "driver" else "establishment",
            subjectUuid = driverUuid,
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
            val result = apiCall { ApiClient.workspace.submit() }
            busy = false
            if (result is ApiResult.Ok) {
                establishment = result.data
                info = submittedOk
            } else {
                // `provider_documents_missing` comes with the server's own sentence ("complete the papers first").
                error = result.errorText(offlineText) ?: genericError
            }
            reload()
        }
    }

    val isOwner = seat?.isOwner == true
    // A driver's licence (C-03): the driver themself or someone with fleet.drivers uploads it; the establishment's own status
    // and review are not part of that page.
    val forDriver = driverUuid != null
    val status = if (forDriver) null else establishment?.status
    // Papers can change until the review starts, and again after a refusal; an approved establishment may replace one.
    val editable = if (forDriver) true else isOwner && status != "under_review"
    Page(title = stringResource(if (forDriver) R.string.drv_docs_title else R.string.pd_title), onBack = onBack) {
        if (current == null) {
            if (error == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.padding(top = 40.dp).size(28.dp))
        } else {
            FormIntro(
                Icons.Rounded.Description, stringResource(R.string.pd_intro_title),
                stringResource(if (forDriver) R.string.drv_docs_intro else if (isOwner) R.string.pd_intro_body else R.string.pd_owner_only),
            )
            if (status != null) {
                Card {
                    Text(establishmentStatusLabel(status), color = establishmentStatusColor(status), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                    if (status == "rejected" || status == "suspended") {
                        establishment?.statusReason?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.pd_reason, it), color = settingsMut(), fontSize = 13.sp) }
                    }
                    if (status == "under_review") Text(stringResource(R.string.pd_under_review), color = settingsMut(), fontSize = 13.sp)
                }
            }

            if (current.required.isEmpty()) Text(stringResource(R.string.pd_none_required), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.fillMaxWidth())
            current.required.forEach { type ->
                val doc = current.documents.firstOrNull { it.documentTypeId == type.id }
                RequiredRow(type, doc, missing = type.code in current.missing, editable = editable, busy = busy, onAdd = { formTypeId = type.id })
            }

            if (!forDriver && isOwner && (status == "draft" || status == "rejected")) {
                if (current.missing.isNotEmpty()) {
                    Text(stringResource(R.string.pd_missing_count, current.missing.size), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.fillMaxWidth())
                }
                SettingsPrimaryButton(text = stringResource(R.string.pd_submit), enabled = current.missing.isEmpty(), loading = busy, onClick = ::submit)
            }
        }
        info?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

@Composable
internal fun RequiredRow(type: DocumentTypeDto, doc: ProviderDocumentDto?, missing: Boolean, editable: Boolean, busy: Boolean, onAdd: () -> Unit) {
    Card {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text(type.name, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
            val (label, color) = docState(doc, missing)
            Text(label, color = color, fontSize = 12.sp, fontWeight = FontWeight.Bold)
        }
        type.instructions?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
        if (doc != null) {
            if (!doc.number.isNullOrBlank()) Text(stringResource(R.string.pd_number_line, doc.number), color = settingsMut(), fontSize = 12.sp)
            if (!doc.expiresOn.isNullOrBlank()) Text(stringResource(R.string.pd_expires_line, doc.expiresOn), color = settingsMut(), fontSize = 12.sp)
            if (doc.status == "rejected") doc.rejectionReason?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.pd_reason, it), color = settingsMut(), fontSize = 12.sp) }
        }
        if (editable) {
            Text(
                stringResource(if (doc == null) R.string.pd_upload else R.string.pd_replace), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clickable(enabled = !busy, onClick = onAdd),
            )
        }
    }
}

/** What to say about one required paper: missing, waiting, approved, refused or expired. */
@Composable
internal fun docState(doc: ProviderDocumentDto?, missing: Boolean): Pair<String, Color> = when {
    doc == null -> stringResource(R.string.pd_state_missing) to Color(0xFFDC2626)
    doc.status == "approved" -> stringResource(R.string.pd_state_approved) to Color(0xFF16A34A)
    doc.status == "pending" -> stringResource(R.string.pd_state_pending) to Color(0xFFD97706)
    doc.status == "rejected" -> stringResource(R.string.pd_state_rejected) to Color(0xFFDC2626)
    doc.status == "expired" || missing -> stringResource(R.string.pd_state_expired) to Color(0xFFDC2626)
    else -> doc.status.orEmpty() to settingsMut()
}

/**
 * Uploading one paper or photo of [subject] (`establishment`, `vehicle` with [subjectUuid], `driver` with [subjectUuid]): its number
 * and expiry when the type asks for them, and the front (and back). A photo (`kind = photo`, e.g. the vehicle showing its plate) is
 * taken with the camera first; a paper may also come from the gallery or a file.
 */
@Composable
internal fun DocumentForm(type: DocumentTypeDto, subject: String, subjectUuid: String?, onBack: () -> Unit, onUploaded: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val tooLarge = stringResource(R.string.pd_file_large)
    val wrongType = stringResource(R.string.pd_file_type)

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
        val file = File(context.cacheDir, "camera").apply { mkdirs() }.let { File(it, "doc_camera_$side.jpg") }
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
        val fields = mutableMapOf<String, RequestBody>(
            "subject" to subject.toRequestBody(text),
            "document_type_id" to type.id.toString().toRequestBody(text),
        )
        if (subjectUuid != null) fields["subject_uuid"] = subjectUuid.toRequestBody(text)
        if (type.hasNumber) fields["number"] = number.trim().toRequestBody(text)
        if (type.hasExpiry) fields["expires_on"] = expires.toRequestBody(text)
        fun part(name: String, f: File) = MultipartBody.Part.createFormData(
            name, f.name, f.asRequestBody((if (f.extension.equals("pdf", true)) "application/pdf" else "image/jpeg").toMediaTypeOrNull()),
        )
        scope.launch {
            val result = apiCall { ApiClient.workspace.upload(fields, part("front", front), if (type.needsBackSide) backFile?.let { part("back", it) } else null) }
            busy = false
            if (result is ApiResult.Ok) {
                // The papers do not stay in the cache once they are on the server.
                frontFile.delete()
                backFile?.delete()
                onUploaded()
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    val photo = type.kind == "photo"
    Page(title = type.name, onBack = onBack) {
        FormIntro(Icons.Rounded.Badge, type.name, type.instructions?.takeIf { it.isNotBlank() } ?: if (photo) stringResource(R.string.vh_photo_hint) else "")
        FormGroup {
            if (type.hasNumber) {
                DorrTextField(number, { number = it.take(64) }, label = stringResource(R.string.pd_number), icon = Icons.Rounded.Badge, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Ascii), modifier = Modifier.fillMaxWidth())
            }
            if (type.hasExpiry) DateField(expires, { expires = it }, label = stringResource(R.string.pd_expires), modifier = Modifier.fillMaxWidth())
        }
        // A photo is an image only; a paper may also be a PDF.
        val types = if (photo) arrayOf("image/jpeg", "image/png") else arrayOf("image/jpeg", "image/png", "application/pdf")
        SideCard(stringResource(if (photo) R.string.vh_photo_side else R.string.pd_front), frontFile, { pendingSide = "front"; picker.launch(types) }, { openCamera("front") })
        if (type.needsBackSide) SideCard(stringResource(R.string.pd_back), backFile, { pendingSide = "back"; picker.launch(types) }, { openCamera("back") })
        SettingsPrimaryButton(text = stringResource(R.string.pd_send), enabled = ready, loading = busy, onClick = ::upload)
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

@Composable
private fun SideCard(title: String, file: File?, onPick: () -> Unit, onCamera: () -> Unit) {
    Card {
        FieldLabel(title)
        if (file != null) Text(stringResource(R.string.pd_chosen), color = Color(0xFF16A34A), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        Row(horizontalArrangement = Arrangement.spacedBy(20.dp), modifier = Modifier.padding(top = 2.dp)) {
            Text(stringResource(R.string.pd_camera), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(onClick = onCamera))
            Text(stringResource(R.string.pd_gallery), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(onClick = onPick))
        }
    }
}
