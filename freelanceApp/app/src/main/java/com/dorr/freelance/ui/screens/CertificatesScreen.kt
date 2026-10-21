package com.dorr.freelance.ui.screens

import android.content.Context
import android.content.Intent
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
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.CertificateDto
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

private const val MAX_CERTIFICATES = 20
private const val MAX_PROOF_BYTES = 5L * 1024 * 1024

/** The freelancer's certificates: the list, and a form with an optional proof (image or PDF, private to the owner). Up to 20. */
@Composable
fun CertificatesScreen(onBack: () -> Unit) {
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val items = remember { mutableStateListOf<CertificateDto>() }
    var loaded by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    // null = the list; 0 = a new one; else the id being edited (kept across the app being killed while a picker is open).
    var formId by rememberSaveable { mutableStateOf<Int?>(null) }

    LaunchedEffect(reconnect) {
        val result = apiCall { ApiClient.lists.certificates() }
        if (result is ApiResult.Ok) {
            items.clear()
            items.addAll(result.data)
            loaded = true
            error = null
        } else {
            error = result.errorText(offlineText) ?: genericError
        }
    }

    val editing = formId?.let { id -> if (id == 0) CertificateDto(id = 0) else items.firstOrNull { it.id == id } }
    if (editing != null) {
        BackHandler { formId = null }
        CertificateForm(
            initial = editing,
            onBack = { formId = null },
            onChanged = { saved, removedId ->
                if (removedId != null) items.removeAll { it.id == removedId }
                if (saved != null) {
                    val at = items.indexOfFirst { it.id == saved.id }
                    if (at >= 0) items[at] = saved else items.add(0, saved)
                }
                formId = null
            },
        )
        return
    }

    Page(title = stringResource(R.string.certificates_title), onBack = onBack) {
        if (loaded) {
            if (items.size < MAX_CERTIFICATES) SettingsPrimaryButton(text = stringResource(R.string.certificates_add), onClick = { formId = 0 })
            if (items.isEmpty()) Text(stringResource(R.string.common_empty), color = settingsMut(), fontSize = 13.sp)
            items.forEach { c ->
                Card {
                    Column(Modifier.fillMaxWidth().clickable { formId = c.id }, verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(c.title, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                        Text(c.issuer, color = settingsMut(), fontSize = 13.sp)
                        val dates = listOfNotNull(c.issuedOn, c.expiresOn).joinToString(" → ")
                        if (dates.isNotEmpty()) Text(dates + if (c.isExpired) " · " + stringResource(R.string.certificate_expired) else "", color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                    }
                }
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

@Composable
private fun CertificateForm(initial: CertificateDto, onBack: () -> Unit, onChanged: (saved: CertificateDto?, removedId: Int?) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val tooLarge = stringResource(R.string.certificate_proof_large)
    val wrongType = stringResource(R.string.certificate_proof_type)

    var title by rememberSaveable { mutableStateOf(initial.title) }
    var issuer by rememberSaveable { mutableStateOf(initial.issuer) }
    var issued by rememberSaveable { mutableStateOf(initial.issuedOn.orEmpty()) }
    var expires by rememberSaveable { mutableStateOf(initial.expiresOn.orEmpty()) }
    var url by rememberSaveable { mutableStateOf(initial.credentialUrl.orEmpty()) }
    // The chosen proof is a cache file: its path is kept, not the File.
    var proofPath by rememberSaveable { mutableStateOf<String?>(null) }
    val proofFile = proofPath?.let { File(it) }?.takeIf { it.exists() }
    var proofName by rememberSaveable { mutableStateOf(initial.proof?.name) }
    var removeProof by rememberSaveable { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var confirmDelete by remember { mutableStateOf(false) }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        if (uri == null) return@rememberLauncherForActivityResult
        scope.launch {
            val picked = withContext(Dispatchers.IO) { copyProof(context, uri) }
            when {
                picked == null -> error = wrongType
                picked.length() > MAX_PROOF_BYTES -> {
                    picked.delete()
                    error = tooLarge
                }
                else -> {
                    error = null
                    proofPath = picked.absolutePath
                    proofName = picked.name
                    removeProof = false
                }
            }
        }
    }

    fun save() {
        busy = true
        error = null
        val text = "text/plain".toMediaType()
        val fields = mutableMapOf<String, RequestBody>(
            "title" to title.trim().toRequestBody(text),
            "issuer" to issuer.trim().toRequestBody(text),
            "issued_on" to issued.toRequestBody(text),
            "expires_on" to expires.toRequestBody(text),
            "credential_url" to url.trim().toRequestBody(text),
        )
        if (removeProof) fields["remove_proof"] = "1".toRequestBody(text)
        val part = proofFile?.let { f ->
            val mime = if (f.extension.equals("pdf", true)) "application/pdf" else if (f.extension.equals("png", true)) "image/png" else "image/jpeg"
            MultipartBody.Part.createFormData("proof", f.name, f.asRequestBody(mime.toMediaTypeOrNull()))
        }
        scope.launch {
            val result = if (initial.id == 0) apiCall { ApiClient.lists.addCertificate(fields, part) } else apiCall { ApiClient.lists.updateCertificate(initial.id, fields, part) }
            busy = false
            if (result is ApiResult.Ok) onChanged(result.data, null) else error = result.errorText(offlineText) ?: genericError
        }
    }

    fun delete() {
        busy = true
        scope.launch {
            val failed = apiDone(offlineText, genericError) { ApiClient.lists.deleteCertificate(initial.id) }
            busy = false
            if (failed == null) onChanged(null, initial.id) else error = failed
        }
    }

    fun viewProof() {
        busy = true
        scope.launch {
            val file = withContext(Dispatchers.IO) { downloadProof(context, initial.id, initial.proof?.name) }
            busy = false
            if (file == null) {
                error = genericError
            } else {
                val uri = FileProvider.getUriForFile(context, "${context.packageName}.files", file)
                val type = MimeTypeMap.getSingleton().getMimeTypeFromExtension(file.extension.lowercase()) ?: "*/*"
                runCatching {
                    context.startActivity(Intent(Intent.ACTION_VIEW).setDataAndType(uri, type).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK))
                }.onFailure { error = genericError }
            }
        }
    }

    Page(title = stringResource(if (initial.id == 0) R.string.certificates_add else R.string.common_edit), onBack = onBack) {
        Card {
            DorrTextField(title, { title = it }, label = stringResource(R.string.certificate_title), modifier = Modifier.fillMaxWidth())
            DorrTextField(issuer, { issuer = it }, label = stringResource(R.string.certificate_issuer), modifier = Modifier.fillMaxWidth())
            DateField(issued, { issued = it }, label = stringResource(R.string.certificate_issued), modifier = Modifier.fillMaxWidth())
            DateField(expires, { expires = it }, label = stringResource(R.string.certificate_expires), modifier = Modifier.fillMaxWidth())
            DorrTextField(
                url, { url = it }, label = stringResource(R.string.certificate_url), placeholder = "https://",
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri), modifier = Modifier.fillMaxWidth(),
                textStyle = MaterialTheme.typography.bodyLarge.copy(textDirection = TextDirection.Ltr),
            )
            FieldLabel(stringResource(R.string.certificate_proof))
            if (!proofName.isNullOrEmpty() && !removeProof) Text(proofName.orEmpty(), color = settingsMut(), fontSize = 13.sp)
            Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                Text(stringResource(R.string.certificate_proof_pick), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable { picker.launch(arrayOf("image/jpeg", "image/png", "application/pdf")) })
                if (initial.proof != null && proofFile == null && !removeProof) {
                    Text(stringResource(R.string.certificate_proof_view), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable { viewProof() })
                }
                if (!proofName.isNullOrEmpty() && !removeProof) {
                    Text(stringResource(R.string.certificate_proof_remove), color = settingsMut(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable {
                        proofPath = null
                        proofName = null
                        removeProof = initial.proof != null
                    })
                }
            }
        }
        SettingsPrimaryButton(text = stringResource(R.string.profile_save), enabled = title.trim().length >= 2 && issuer.trim().length >= 2, loading = busy, onClick = ::save)
        if (initial.id != 0) {
            Text(stringResource(R.string.common_delete), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable { confirmDelete = true }.padding(8.dp))
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }

    if (confirmDelete) {
        AlertDialog(
            onDismissRequest = { confirmDelete = false },
            text = { Text(stringResource(R.string.common_delete_confirm)) },
            confirmButton = { TextButton(onClick = { confirmDelete = false; delete() }) { Text(stringResource(R.string.common_delete), color = settingsAccent()) } },
            dismissButton = { TextButton(onClick = { confirmDelete = false }) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
        )
    }
}

/** Copies the chosen document into the cache (so it can be uploaded as a file); null when it is not a JPG, PNG or PDF. */
private fun copyProof(context: Context, uri: Uri): File? = runCatching {
    val type = context.contentResolver.getType(uri)
    val ext = when (type) {
        "image/jpeg" -> "jpg"
        "image/png" -> "png"
        "application/pdf" -> "pdf"
        else -> return@runCatching null
    }
    val file = File(context.cacheDir, "proof_upload.$ext")
    context.contentResolver.openInputStream(uri)?.use { input -> file.outputStream().use { input.copyTo(it) } } ?: return@runCatching null
    file
}.getOrNull()

/** Downloads a certificate's proof (signed in) into the cache, to open it in a viewer. */
private suspend fun downloadProof(context: Context, id: Int, name: String?): File? = runCatching {
    val response = ApiClient.lists.certificateProof(id)
    if (!response.isSuccessful) return@runCatching null
    val ext = name?.substringAfterLast('.', "")?.takeIf { it.length in 2..4 } ?: "pdf"
    val file = File(context.cacheDir, "proofs").apply { mkdirs() }.let { File(it, "proof_$id.$ext") }
    response.body()?.byteStream()?.use { input -> file.outputStream().use { input.copyTo(it) } } ?: return@runCatching null
    file
}.getOrNull()
