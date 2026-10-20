package com.dorr.driver.ui

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.ImageDecoder
import android.net.Uri
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddAPhoto
import androidx.compose.material.icons.rounded.AssignmentInd
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.CloudUpload
import androidx.compose.material.icons.rounded.DirectionsCar
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Numbers
import androidx.compose.material.icons.rounded.PhotoCamera
import androidx.compose.material.icons.rounded.PhotoLibrary
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import coil.compose.AsyncImage
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.OnboardingDto
import com.dorr.driver.net.RequiredDocDto
import com.dorr.driver.ui.kit.Banner
import com.dorr.driver.ui.kit.BannerKind
import com.dorr.driver.ui.kit.DateField
import com.dorr.driver.ui.kit.DorrTextField
import com.dorr.driver.ui.kit.PrimaryButton
import com.dorr.driver.ui.kit.TileIcon
import com.dorr.driver.ui.kit.bounceClick
import com.dorr.driver.ui.kit.prettyDate
import com.dorr.driver.ui.kit.surface
import com.dorr.driver.ui.theme.Pal
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import okio.BufferedSink
import java.io.ByteArrayOutputStream
import java.io.File
import java.time.LocalDate

/** A document's state in words and colour (never colour alone). */
private data class DocLook(val label: Int, val color: Color, val icon: ImageVector)

private fun lookOf(status: String?): DocLook = when (status) {
    "approved" -> DocLook(R.string.ob_status_approved, Pal.success, Icons.Rounded.CheckCircle)
    "rejected" -> DocLook(R.string.ob_status_rejected, Pal.danger, Icons.Rounded.ErrorOutline)
    null -> DocLook(R.string.ob_status_missing, Pal.warning, Icons.Rounded.CloudUpload)
    else -> DocLook(R.string.ob_status_pending, Pal.info, Icons.Rounded.HourglassTop)
}

/** One required document on the sign-up: what it is, its state, the photos already sent, and the way to send it. */
@Composable
internal fun DocumentCard(doc: RequiredDocDto, modifier: Modifier = Modifier, onOpen: () -> Unit) {
    val look = lookOf(doc.document?.status)
    val canSend = doc.document?.status != "approved"
    Column(modifier.fillMaxWidth().bounceClick(enabled = canSend, onClick = onOpen).surface(RoundedCornerShape(20.dp)).padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            TileIcon(if (doc.type.appliesTo == "vehicle") Icons.Rounded.DirectionsCar else Icons.Rounded.AssignmentInd, Pal.accent, 44.dp)
            Column(Modifier.weight(1f)) {
                Text(doc.type.name ?: doc.type.code, color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp), modifier = Modifier.padding(top = 3.dp)) {
                    Icon(look.icon, null, tint = look.color, modifier = Modifier.size(14.dp))
                    Text(stringResource(look.label), color = look.color, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    doc.document?.expiresAt?.let { Text(" · " + stringResource(R.string.doc_until, prettyDate(it)), color = Pal.mut, fontSize = 12.sp) }
                }
            }
        }
        doc.document?.rejectionReason?.let { Banner(it, Modifier.padding(top = 10.dp)) }
        val shots = listOfNotNull(doc.document?.frontUrl, doc.document?.backUrl)
        if (shots.isNotEmpty()) {
            Row(Modifier.padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                shots.forEach { url ->
                    AsyncImage(url, null, contentScale = ContentScale.Crop, modifier = Modifier.size(width = 86.dp, height = 58.dp).clip(RoundedCornerShape(10.dp)).background(Pal.well))
                }
            }
        }
        if (canSend) {
            Text(
                stringResource(if (doc.document == null) R.string.doc_add else R.string.ob_reupload),
                color = Pal.accent, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(top = 10.dp),
            )
        }
    }
}

/**
 * Sending one document: its number and expiry when the type has them (checked before sending), the front and — if
 * needed — the back, each from the camera or the gallery with a preview, then the upload with its progress.
 * Photos are made smaller on the phone first (the server takes up to 10 MB); a vehicle document names the vehicle.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun DocumentSheet(doc: RequiredDocDto, vehicleId: Int?, onDismiss: () -> Unit, onDone: (OnboardingDto) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var number by remember { mutableStateOf(doc.document?.number.orEmpty()) }
    var expiry by remember { mutableStateOf(doc.document?.expiresAt) }
    var front by remember { mutableStateOf<Uri?>(null) }
    var back by remember { mutableStateOf<Uri?>(null) }
    var busy by remember { mutableStateOf(false) }
    var progress by remember { mutableFloatStateOf(0f) }
    var error by remember { mutableStateOf<String?>(null) }
    var tried by remember { mutableStateOf(false) }
    val shown by animateFloatAsState(progress, tween(250), label = "upload")

    val numberMissing = doc.type.hasNumber && number.isBlank()
    val expiryMissing = doc.type.hasExpiry && expiry.isNullOrBlank()
    val backMissing = doc.type.needsBackSide && back == null
    val ready = front != null && !backMissing && !numberMissing && !expiryMissing

    fun send() {
        tried = true
        if (!ready || busy) return
        busy = true
        error = null
        progress = 0f
        scope.launch {
            val text = "text/plain".toMediaType()
            val fields = buildMap<String, RequestBody> {
                put("document_type_id", doc.type.id.toString().toRequestBody(text))
                if (doc.type.appliesTo == "vehicle" && vehicleId != null) put("vehicle_id", vehicleId.toString().toRequestBody(text))
                if (number.isNotBlank()) put("number", number.trim().toRequestBody(text))
                expiry?.takeIf { it.isNotBlank() }?.let { put("expires_at", it.toRequestBody(text)) }
            }
            val parts = withContext(Dispatchers.IO) {
                listOfNotNull(front?.let { "front" to it }, back?.let { "back" to it }).map { (name, uri) -> name to prepared(context, uri) }
            }
            val total = parts.sumOf { it.second.first.size }.coerceAtLeast(1).toFloat()
            // Each side counts from where the sides before it end (the front is sent first).
            fun body(name: String, data: Pair<ByteArray, String>, before: Long): MultipartBody.Part {
                val ext = if (data.second.contains("pdf")) "pdf" else "jpg"
                val counting = CountingBody(data.first, data.second.toMediaType()) { written ->
                    progress = ((before + written) / total).coerceIn(0f, 1f)
                }
                return MultipartBody.Part.createFormData(name, "$name.$ext", counting)
            }
            val frontData = parts.first { it.first == "front" }.second
            val frontPart = body("front", frontData, 0L)
            val backPart = parts.firstOrNull { it.first == "back" }?.let { body("back", it.second, frontData.first.size.toLong()) }
            when (val r = apiCall { DriverBackend.api.uploadDocument(fields, frontPart, backPart) }) {
                is ApiResult.Ok -> { progress = 1f; onDone(r.data) }
                is ApiResult.Failed -> error = r.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }

    ModalBottomSheet(onDismissRequest = { if (!busy) onDismiss() }, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Pal.card) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 18.dp).navigationBarsPadding().imePadding(),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(doc.type.name ?: doc.type.code, color = Pal.ink, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold)
            doc.type.instructions?.takeIf { it.isNotBlank() }?.let { Banner(it, kind = BannerKind.Info) }

            if (doc.type.hasNumber) {
                DorrTextField(
                    value = number, onValueChange = { number = it.take(64) }, label = stringResource(R.string.ob_number), icon = Icons.Rounded.Numbers,
                    placeholder = stringResource(R.string.doc_number_hint), error = tried && numberMissing,
                )
            }
            if (doc.type.hasExpiry) {
                DateField(stringResource(R.string.doc_expiry), expiry, onPick = { expiry = it }, min = LocalDate.now().plusDays(1), error = tried && expiryMissing)
            }

            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                PhotoTile(stringResource(R.string.ob_front), front, doc.document?.frontUrl, Modifier.weight(1f), error = tried && front == null) { front = it }
                if (doc.type.needsBackSide) PhotoTile(stringResource(R.string.ob_back), back, doc.document?.backUrl, Modifier.weight(1f), error = tried && backMissing) { back = it }
            }
            Text(stringResource(R.string.doc_photo_hint), color = Pal.mut, fontSize = 12.sp, lineHeight = 17.sp)

            AnimatedVisibility(busy) {
                Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    LinearProgressIndicator(progress = { shown }, color = Pal.accent, trackColor = Pal.well, modifier = Modifier.fillMaxWidth().height(6.dp).clip(CircleShape))
                    Text(stringResource(R.string.doc_uploading, (shown * 100).toInt()), color = Pal.mut, fontSize = 12.sp)
                }
            }
            Banner(error)
            Banner(if (tried && !ready && !busy) stringResource(R.string.doc_complete_first) else null, kind = BannerKind.Warning)
            PrimaryButton(stringResource(R.string.doc_send), busy = busy, icon = Icons.Rounded.CloudUpload, onClick = { send() })
        }
    }
}

/** One side of a document: a preview, and the camera or the gallery to (re)take it. */
@Composable
private fun PhotoTile(label: String, picked: Uri?, existing: String?, modifier: Modifier, error: Boolean, onPicked: (Uri) -> Unit) {
    val context = LocalContext.current
    var shot by remember { mutableStateOf<Uri?>(null) }
    val camera = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { ok -> if (ok) shot?.let(onPicked) }
    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { it?.let(onPicked) }
    val shape = RoundedCornerShape(16.dp)
    Column(modifier, verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(label, color = Pal.ink, fontSize = 13.sp, fontWeight = FontWeight.Bold)
        Box(
            Modifier.fillMaxWidth().aspectRatio(1.5f).clip(shape).background(Pal.fieldFill)
                .border(if (error) 1.5.dp else 1.dp, if (error) Pal.danger else Pal.fieldLine, shape),
            contentAlignment = Alignment.Center,
        ) {
            val preview: Any? = picked ?: existing
            if (preview != null) {
                AsyncImage(preview, null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
            } else {
                Icon(Icons.Rounded.AddAPhoto, null, tint = Pal.accent.copy(alpha = 0.7f), modifier = Modifier.size(30.dp))
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            SmallAction(Icons.Rounded.PhotoCamera, stringResource(R.string.doc_camera), Modifier.weight(1f)) {
                val file = File(context.cacheDir, "camera").apply { mkdirs() }.let { File(it, "doc_${System.currentTimeMillis()}.jpg") }
                val uri = FileProvider.getUriForFile(context, "${context.packageName}.files", file)
                shot = uri
                camera.launch(uri)
            }
            SmallAction(Icons.Rounded.PhotoLibrary, stringResource(R.string.doc_gallery), Modifier.weight(1f)) {
                gallery.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly))
            }
        }
    }
}

@Composable
private fun SmallAction(icon: ImageVector, label: String, modifier: Modifier, onClick: () -> Unit) {
    Row(
        modifier.clip(RoundedCornerShape(12.dp)).background(Pal.well).bounceClick(onClick = onClick).padding(vertical = 8.dp),
        horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = Pal.accent, modifier = Modifier.size(16.dp))
        Text(" $label", color = Pal.accent, fontSize = 12.sp, fontWeight = FontWeight.Bold)
    }
}

/**
 * The bytes to send and their type: a photo is turned the right way up and made at most 2000 px on its longer side as
 * a JPEG (a phone photo is often 5–12 MB, the server takes 10); a PDF goes as it is.
 */
private fun prepared(context: Context, uri: Uri): Pair<ByteArray, String> {
    val type = context.contentResolver.getType(uri) ?: "image/jpeg"
    if (type.contains("pdf")) return (context.contentResolver.openInputStream(uri)?.use { it.readBytes() } ?: ByteArray(0)) to "application/pdf"
    val bitmap: Bitmap? = runCatching {
        if (Build.VERSION.SDK_INT >= 28) {
            ImageDecoder.decodeBitmap(ImageDecoder.createSource(context.contentResolver, uri)) { decoder, info, _ ->
                val longest = maxOf(info.size.width, info.size.height)
                if (longest > MAX_SIDE) {
                    val scale = MAX_SIDE.toFloat() / longest
                    decoder.setTargetSize((info.size.width * scale).toInt(), (info.size.height * scale).toInt())
                }
                decoder.allocator = ImageDecoder.ALLOCATOR_SOFTWARE
            }
        } else {
            val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
            context.contentResolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, bounds) }
            var sample = 1
            while (maxOf(bounds.outWidth, bounds.outHeight) / sample > MAX_SIDE) sample *= 2
            context.contentResolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, BitmapFactory.Options().apply { inSampleSize = sample }) }
        }
    }.getOrNull()
    if (bitmap == null) return (context.contentResolver.openInputStream(uri)?.use { it.readBytes() } ?: ByteArray(0)) to type
    val out = ByteArrayOutputStream()
    bitmap.compress(Bitmap.CompressFormat.JPEG, 85, out)
    return out.toByteArray() to "image/jpeg"
}

private const val MAX_SIDE = 2000

/** A request body that says how much of it has been written, for the progress bar. */
private class CountingBody(private val bytes: ByteArray, private val type: MediaType, private val onWritten: (Long) -> Unit) : RequestBody() {
    override fun contentType(): MediaType = type
    override fun contentLength(): Long = bytes.size.toLong()
    override fun writeTo(sink: BufferedSink) {
        var offset = 0
        while (offset < bytes.size) {
            val n = minOf(CHUNK, bytes.size - offset)
            sink.write(bytes, offset, n)
            offset += n
            onWritten(offset.toLong())
        }
    }

    private companion object {
        const val CHUNK = 16 * 1024
    }
}
