package com.dorr.driver.ui

import android.content.Context
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.RadioButtonUnchecked
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.core.ui.DorrBrand
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.OnboardingDto
import com.dorr.driver.net.OnboardingOptionsDto
import com.dorr.driver.net.RegulatorDto
import com.dorr.driver.net.RequiredDocDto
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody

/**
 * Signing up as a captain, Jawad's steps on one page: details → vehicle → the documents the server
 * says this driver needs (their country, citizen or resident) → send for review — then Wasl.
 */
@Composable
fun SignupScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var state by remember { mutableStateOf<OnboardingDto?>(null) }
    var options by remember { mutableStateOf(OnboardingOptionsDto()) }
    var regulator by remember { mutableStateOf<RegulatorDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var uploading by remember { mutableStateOf<RequiredDocDto?>(null) }

    suspend fun load() {
        (apiCall { DriverBackend.api.onboarding() } as? ApiResult.Ok)?.let { state = it.data }
        (apiCall { DriverBackend.api.regulator() } as? ApiResult.Ok)?.let { regulator = it.data }
    }
    LaunchedEffect(Unit) {
        load()
        (apiCall { DriverBackend.api.onboardingOptions() } as? ApiResult.Ok)?.let { options = it.data }
    }
    BackHandler(onBack = onBack)

    fun <T> run(call: suspend () -> ApiResult<T>, done: (T) -> Unit = {}) {
        busy = true
        error = null
        scope.launch {
            when (val r = call()) {
                is ApiResult.Ok -> done(r.data)
                is ApiResult.Failed -> error = r.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        Row(Modifier.fillMaxWidth().background(DorrBrand.navy).statusBarsPadding().padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, null, tint = Color.White) }
            Text(stringResource(R.string.ob_title), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 18.sp)
        }
        val s = state ?: run {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { androidx.compose.material3.CircularProgressIndicator() }
            return
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            s.workProblem?.let { Text(problemText(context, it), color = DorrBrand.warning, fontWeight = FontWeight.SemiBold) }
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }

            // 1. Details
            Step(stringResource(R.string.ob_details), s.steps.profile) {
                var name by remember(s.profile.name) { mutableStateOf(s.profile.name.orEmpty()) }
                var nationalId by remember(s.profile.nationalId) { mutableStateOf(s.profile.nationalId.orEmpty()) }
                var dob by remember(s.profile.dateOfBirth) { mutableStateOf(s.profile.dateOfBirth.orEmpty()) }
                var email by remember(s.profile.email) { mutableStateOf(s.profile.email.orEmpty()) }
                var idType by remember(s.profile.idType) { mutableStateOf(s.profile.idType ?: "national_id") }
                Field(stringResource(R.string.ob_name), name) { name = it }
                if (!s.profile.locked) {
                    Picker(stringResource(R.string.ob_id_type), options.idTypes.ifEmpty { listOf("national_id", "iqama", "passport") }.map { it to idLabel(context, it) }, idType) { idType = it }
                    Field(stringResource(R.string.ob_national_id), nationalId, KeyboardType.Number) { nationalId = it }
                    Field(stringResource(R.string.ob_dob), dob) { dob = it }
                }
                Field(stringResource(R.string.ob_email), email, KeyboardType.Email) { email = it }
                BigButton(stringResource(R.string.ob_save), color = DorrBrand.navy, busy = busy) {
                    val body = JsonObject().apply {
                        addProperty("name", name.trim())
                        if (email.isNotBlank()) addProperty("email", email.trim())
                        if (!s.profile.locked) {
                            addProperty("id_type", idType)
                            if (nationalId.isNotBlank()) addProperty("national_id", nationalId.trim())
                            if (dob.isNotBlank()) addProperty("date_of_birth", dob.trim())
                        }
                    }
                    run({ apiCall { DriverBackend.api.updateProfile(body) } }) { state = it }
                }
            }

            // 2. Vehicle
            Step(stringResource(R.string.ob_vehicle), s.steps.vehicle) {
                val v = s.vehicle
                val locked = v?.status == "approved"
                var make by remember(v?.id) { mutableStateOf(v?.makeId) }
                var model by remember(v?.id) { mutableStateOf(v?.modelId) }
                var color by remember(v?.id) { mutableStateOf(v?.colorId) }
                var year by remember(v?.id) { mutableStateOf(v?.year?.toString().orEmpty()) }
                var letters by remember(v?.id) { mutableStateOf(v?.plateLetters.orEmpty()) }
                var numbers by remember(v?.id) { mutableStateOf(v?.plateNumber.orEmpty()) }
                var plateType by remember(v?.id) { mutableStateOf(v?.plateType?.toString() ?: "1") }
                var sequence by remember(v?.id) { mutableStateOf(v?.sequenceNumber.orEmpty()) }
                val models = options.makes.firstOrNull { it.id == make }?.models.orEmpty()
                if (v != null) Text("${v.make.orEmpty()} ${v.model.orEmpty()} · ${v.plateLetters.orEmpty()} ${v.plateNumber.orEmpty()} · ${docStatus(context, v.status)}", fontWeight = FontWeight.SemiBold)
                if (!locked) {
                    Picker(stringResource(R.string.ob_make), options.makes.map { it.id.toString() to (it.name ?: "#${it.id}") }, make?.toString()) { make = it.toInt(); model = null }
                    Picker(stringResource(R.string.ob_model), models.map { it.id.toString() to (it.name ?: "#${it.id}") }, model?.toString()) { model = it.toInt() }
                    Picker(stringResource(R.string.ob_color), options.colors.map { it.id.toString() to (it.name ?: it.hex.orEmpty()) }, color?.toString()) { color = it.toInt() }
                    Field(stringResource(R.string.ob_year), year, KeyboardType.Number) { year = it.filter(Char::isDigit).take(4) }
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Box(Modifier.weight(1f)) { Field(stringResource(R.string.ob_plate_letters), letters) { letters = it.take(8) } }
                        Box(Modifier.weight(1f)) { Field(stringResource(R.string.ob_plate_number), numbers, KeyboardType.Number) { numbers = it.filter(Char::isDigit).take(8) } }
                    }
                    Field(stringResource(R.string.ob_plate_type), plateType, KeyboardType.Number) { plateType = it.filter(Char::isDigit).take(4) }
                    Field(stringResource(R.string.ob_sequence), sequence, KeyboardType.Number) { sequence = it.filter(Char::isDigit).take(16) }
                    BigButton(stringResource(R.string.ob_save), color = DorrBrand.navy, busy = busy, enabled = make != null && model != null && color != null && year.length == 4 && letters.isNotBlank() && numbers.isNotBlank()) {
                        val chosen = models.firstOrNull { it.id == model }
                        val body = JsonObject().apply {
                            addProperty("make_id", make); addProperty("model_id", model); addProperty("color_id", color)
                            addProperty("year", year.toInt()); addProperty("body_type", chosen?.bodyType ?: "sedan"); addProperty("seats", chosen?.seats ?: 4)
                            addProperty("plate_letters", letters.trim()); addProperty("plate_number", numbers.trim()); addProperty("plate_type", plateType.toIntOrNull() ?: 1)
                            if (sequence.isNotBlank()) addProperty("sequence_number", sequence)
                        }
                        run({ if (v == null) apiCall { DriverBackend.api.addVehicle(body) } else apiCall { DriverBackend.api.updateVehicle(v.id, body) } }) { state = it }
                    }
                }
            }

            // 3. Documents
            Step(stringResource(R.string.ob_documents), s.steps.documents) {
                s.requiredDocuments.forEach { doc ->
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(MaterialTheme.colorScheme.onSurface.copy(alpha = 0.04f)).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(doc.type.name ?: doc.type.code, fontWeight = FontWeight.SemiBold)
                            val status = doc.document?.status
                            Text(docStatus(context, status), fontSize = 12.sp, color = when (status) { "approved" -> DorrBrand.success; "rejected" -> DorrBrand.danger; null -> DorrBrand.warning; else -> DorrBrand.info })
                            doc.document?.rejectionReason?.let { Text(it, fontSize = 12.sp, color = DorrBrand.danger) }
                        }
                        if (doc.document?.status != "approved") {
                            OutlinedButton(onClick = { uploading = doc }) { Text(stringResource(if (doc.document == null) R.string.ob_upload else R.string.ob_reupload)) }
                        }
                    }
                }
            }

            // Wasl (only where there is a regulator).
            regulator?.takeIf { it.required }?.let { reg ->
                Step(stringResource(R.string.ob_wasl), reg.driverStatus == "valid" && reg.vehicleStatus == "valid") {
                    Text(
                        when {
                            reg.blockedUntil != null -> stringResource(R.string.ob_wasl_blocked, reg.blockedUntil)
                            reg.driverStatus == "valid" && reg.vehicleStatus == "valid" -> stringResource(R.string.ob_wasl_valid)
                            else -> stringResource(R.string.ob_wasl_pending)
                        },
                        fontWeight = FontWeight.SemiBold,
                    )
                    reg.reasons.forEach { Text("• ${it.message ?: it.code}", fontSize = 13.sp, color = DorrBrand.danger) }
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        OutlinedButton(onClick = { run({ apiCall { DriverBackend.api.checkRegulator() } }) { regulator = it } }) { Text(stringResource(R.string.ob_wasl_check)) }
                        if (reg.canRegister && reg.driverStatus != "valid") {
                            OutlinedButton(onClick = { run({ apiCall { DriverBackend.api.registerRegulator() } }) { regulator = it } }) { Text(stringResource(R.string.ob_wasl_register)) }
                        }
                    }
                }
            }
        }
        Column(Modifier.fillMaxWidth().background(MaterialTheme.colorScheme.surface).padding(16.dp).navigationBarsPadding()) {
            if (s.driver.status == "under_review") Text(stringResource(R.string.ob_submitted), color = DorrBrand.success, fontWeight = FontWeight.Bold)
            else BigButton(stringResource(R.string.ob_submit), busy = busy, enabled = s.canSubmit) {
                run({ apiCall { DriverBackend.api.submitOnboarding() } }) { state = it }
            }
        }
    }

    uploading?.let { doc -> UploadDialog(doc, onDismiss = { uploading = null }) { updated -> state = updated; uploading = null } }
}

@Composable
private fun Step(title: String, done: Boolean, content: @Composable () -> Unit) {
    Card {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(if (done) Icons.Filled.CheckCircle else Icons.Filled.RadioButtonUnchecked, null, tint = if (done) DorrBrand.success else Color.Gray)
            Spacer(Modifier.width(8.dp))
            Text(title, fontWeight = FontWeight.Bold, fontSize = 17.sp)
        }
        Spacer(Modifier.height(10.dp))
        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) { content() }
    }
}

@Composable
private fun Field(label: String, value: String, keyboard: KeyboardType = KeyboardType.Text, onChange: (String) -> Unit) {
    OutlinedTextField(value, onChange, label = { Text(label) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = keyboard), modifier = Modifier.fillMaxWidth())
}

@Composable
private fun Picker(label: String, items: List<Pair<String, String>>, selected: String?, onPick: (String) -> Unit) {
    var open by remember { mutableStateOf(false) }
    Box {
        Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(6.dp)).background(MaterialTheme.colorScheme.onSurface.copy(alpha = 0.05f)).clickable { open = true }.padding(12.dp)) {
            Text(label, fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
            Text(items.firstOrNull { it.first == selected }?.second ?: "—", fontWeight = FontWeight.SemiBold)
        }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            items.forEach { (id, name) -> DropdownMenuItem(text = { Text(name) }, onClick = { onPick(id); open = false }) }
        }
    }
}

private fun idLabel(context: Context, type: String): String = context.getString(
    when (type) { "iqama" -> R.string.ob_id_iqama; "passport" -> R.string.ob_id_passport; else -> R.string.ob_id_national_id },
)

private fun docStatus(context: Context, status: String?): String = context.getString(
    when (status) {
        "approved" -> R.string.ob_status_approved
        "rejected" -> R.string.ob_status_rejected
        null -> R.string.ob_status_missing
        else -> R.string.ob_status_pending
    },
)

/** One document: its number / expiry if the type has them, the photo(s), then upload. */
@Composable
private fun UploadDialog(doc: RequiredDocDto, onDismiss: () -> Unit, onDone: (OnboardingDto) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var number by remember { mutableStateOf("") }
    var expiry by remember { mutableStateOf("") }
    var front by remember { mutableStateOf<Uri?>(null) }
    var back by remember { mutableStateOf<Uri?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val pickFront = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { front = it ?: front }
    val pickBack = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { back = it ?: back }
    val image = PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(doc.type.name ?: doc.type.code) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                doc.type.instructions?.let { Text(it, fontSize = 12.sp) }
                if (doc.type.hasNumber) Field(stringResource(R.string.ob_number), number) { number = it }
                if (doc.type.hasExpiry) Field(stringResource(R.string.ob_expiry), expiry) { expiry = it }
                OutlinedButton(onClick = { pickFront.launch(image) }, modifier = Modifier.fillMaxWidth()) { Text(if (front != null) stringResource(R.string.ob_picked) else stringResource(R.string.ob_front)) }
                if (doc.type.needsBackSide) OutlinedButton(onClick = { pickBack.launch(image) }, modifier = Modifier.fillMaxWidth()) { Text(if (back != null) stringResource(R.string.ob_picked) else stringResource(R.string.ob_back)) }
                error?.let { Text(it, color = MaterialTheme.colorScheme.error, fontSize = 13.sp) }
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && front != null && (!doc.type.needsBackSide || back != null), onClick = {
                busy = true
                scope.launch {
                    val text = "text/plain".toMediaType()
                    val fields = buildMap<String, RequestBody> {
                        put("document_type_id", doc.type.id.toString().toRequestBody(text))
                        if (number.isNotBlank()) put("number", number.trim().toRequestBody(text))
                        if (expiry.isNotBlank()) put("expires_at", expiry.trim().toRequestBody(text))
                    }
                    val frontPart = part(context, "front", front!!)
                    val backPart = back?.let { part(context, "back", it) }
                    when (val r = apiCall { DriverBackend.api.uploadDocument(fields, frontPart, backPart) }) {
                        is ApiResult.Ok -> onDone(r.data)
                        is ApiResult.Failed -> error = r.error.displayMessage
                        is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                    }
                    busy = false
                }
            }) { Text(stringResource(R.string.ob_upload)) }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.offer_decline)) } },
    )
}

private fun part(context: Context, name: String, uri: Uri): MultipartBody.Part {
    val type = context.contentResolver.getType(uri) ?: "image/jpeg"
    val bytes = context.contentResolver.openInputStream(uri)?.use { it.readBytes() } ?: ByteArray(0)
    val ext = if (type.contains("png")) "png" else if (type.contains("webp")) "webp" else "jpg"
    return MultipartBody.Part.createFormData(name, "$name.$ext", bytes.toRequestBody(type.toMediaType()))
}

@Suppress("unused")
private val dot = CircleShape
