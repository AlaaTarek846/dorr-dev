package com.dorr.app.ui.screens.chat

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Canvas
import android.graphics.Paint
import android.graphics.PorterDuff
import android.graphics.PorterDuffColorFilter
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTransformGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AutoFixHigh
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Crop54
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.IntSize
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.StickerDto
import com.dorr.app.network.apiFailure
import com.google.mlkit.vision.common.InputImage
import com.google.mlkit.vision.segmentation.subject.SubjectSegmentation
import com.google.mlkit.vision.segmentation.subject.SubjectSegmenterOptions
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.ByteArrayOutputStream
import kotlin.coroutines.resume

/** How the photo becomes a sticker. */
private enum class StickerShape { Cutout, Circle, Rounded }

/** A sticker is 512×512 with a transparent background (the WhatsApp size). */
private const val STICKER_PX = 512

/**
 * Make a sticker from my own photo, like WhatsApp: pick a photo, the subject is cut out of its
 * background on the phone (ML Kit) with a white sticker outline — or a circle / rounded square —
 * then pinch and drag it into place and save. It lands in "My stickers" and is sent right away.
 */
@Composable
fun StickerMakerDialog(onDismiss: () -> Unit, onCreated: (StickerDto) -> Unit) {
    val context = LocalContext.current
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var photo by remember { mutableStateOf<Bitmap?>(null) }
    var cutout by remember { mutableStateOf<Bitmap?>(null) }
    var cutting by remember { mutableStateOf(false) }
    var cutFailed by remember { mutableStateOf(false) }
    var shape by remember { mutableStateOf(StickerShape.Cutout) }
    var scale by remember { mutableFloatStateOf(1f) }
    var offset by remember { mutableStateOf(Offset.Zero) }
    var frame by remember { mutableStateOf(IntSize.Zero) }
    var saving by remember { mutableStateOf(false) }
    val noSubject = stringResource(R.string.ch_sticker_no_subject)
    val networkError = stringResource(R.string.ch_error_network)

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        if (uri == null) {
            if (photo == null) onDismiss()
            return@rememberLauncherForActivityResult
        }
        scope.launch {
            val bitmap = withContext(Dispatchers.IO) {
                copyToCache(context, uri, "sticker.jpg")?.let { loadUpright(it.file.absolutePath) }
            } ?: return@launch
            photo = bitmap
            cutout = null
            cutFailed = false
            scale = 1f
            offset = Offset.Zero
            shape = StickerShape.Cutout
            cutting = true
            cutout = withContext(Dispatchers.Default) { cutOut(bitmap) }?.let { withContext(Dispatchers.Default) { withOutline(it) } }
            cutting = false
            if (cutout == null) {
                cutFailed = true
                shape = StickerShape.Circle
                host.showToast(noSubject)
            }
        }
    }
    LaunchedEffect(Unit) { picker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }

    val source = if (shape == StickerShape.Cutout) cutout else photo

    Dialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Column(Modifier.fillMaxSize().background(Color(0xFF0E1116)).statusBarsPadding().navigationBarsPadding()) {
            // ---------------------------------------------------------------- top bar
            Row(Modifier.fillMaxWidth().padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(42.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.12f)).clickable(onClick = onDismiss), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Close, null, tint = Color.White)
                }
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.ch_sticker_make), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp)
                    Text(stringResource(R.string.ch_sticker_make_hint), color = Color.White.copy(alpha = 0.6f), fontSize = 12.sp)
                }
            }

            // ---------------------------------------------------------------- the square
            Box(Modifier.weight(1f).fillMaxWidth().padding(20.dp), contentAlignment = Alignment.Center) {
                Box(
                    Modifier.fillMaxWidth().aspectRatio(1f).clip(RoundedCornerShape(24.dp))
                        .onSizeChanged { frame = it }
                        .pointerInput(Unit) {
                            detectTransformGestures { _, pan, zoom, _ ->
                                scale = (scale * zoom).coerceIn(0.4f, 5f)
                                offset += pan
                            }
                        },
                    contentAlignment = Alignment.Center,
                ) {
                    Checkerboard()
                    if (source != null) {
                        val clip = when (shape) {
                            StickerShape.Circle -> Modifier.clip(CircleShape)
                            StickerShape.Rounded -> Modifier.clip(RoundedCornerShape(18))
                            StickerShape.Cutout -> Modifier
                        }
                        Box(Modifier.fillMaxSize().then(clip)) {
                            Image(
                                source.asImageBitmap(), null, contentScale = ContentScale.Fit,
                                modifier = Modifier.fillMaxSize().graphicsLayer {
                                    scaleX = scale; scaleY = scale; translationX = offset.x; translationY = offset.y
                                },
                            )
                        }
                    }
                    if (cutting || photo == null) {
                        Column(horizontalAlignment = Alignment.CenterHorizontally) {
                            CircularProgressIndicator(color = Color.White, strokeWidth = 3.dp)
                            if (cutting) Text(stringResource(R.string.ch_sticker_cutting), color = Color.White, fontSize = 13.sp, modifier = Modifier.padding(top = 10.dp))
                        }
                    }
                }
            }

            // ---------------------------------------------------------------- shapes
            Row(Modifier.fillMaxWidth().padding(horizontal = 20.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                ShapeChip(Icons.Rounded.AutoFixHigh, stringResource(R.string.ch_sticker_cutout), shape == StickerShape.Cutout, enabled = cutout != null, Modifier.weight(1f)) { shape = StickerShape.Cutout }
                ShapeChip(Icons.Rounded.RadioButtonUnchecked, stringResource(R.string.ch_sticker_circle), shape == StickerShape.Circle, enabled = photo != null, Modifier.weight(1f)) { shape = StickerShape.Circle }
                ShapeChip(Icons.Rounded.Crop54, stringResource(R.string.ch_sticker_rounded), shape == StickerShape.Rounded, enabled = photo != null, Modifier.weight(1f)) { shape = StickerShape.Rounded }
            }
            if (cutFailed) {
                Text(stringResource(R.string.ch_sticker_no_subject), color = Color.White.copy(alpha = 0.6f), fontSize = 12.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(top = 8.dp))
            }
            Spacer(Modifier.height(14.dp))
            Row(Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Text(
                    stringResource(R.string.ch_sticker_other_photo), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 14.sp, textAlign = TextAlign.Center,
                    modifier = Modifier.weight(1f).clip(RoundedCornerShape(18.dp)).background(Color.White.copy(alpha = 0.12f))
                        .clickable { picker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }.padding(vertical = 15.dp),
                )
                Row(
                    Modifier.weight(1f).clip(RoundedCornerShape(18.dp)).background(if (source != null && !saving) Ch.Red else Ch.Red.copy(alpha = 0.4f))
                        .clickable(enabled = source != null && !saving && !cutting) {
                            val art = source ?: return@clickable
                            saving = true
                            scope.launch {
                                try {
                                    val (bytes, webp) = withContext(Dispatchers.Default) { render(art, shape, scale, offset, frame) }
                                    val part = MultipartBody.Part.createFormData(
                                        "image", if (webp) "sticker.webp" else "sticker.png",
                                        bytes.toRequestBody((if (webp) "image/webp" else "image/png").toMediaTypeOrNull()),
                                    )
                                    ApiClient.chat.uploadMySticker(chatAuth(), part).data?.let(onCreated)
                                } catch (e: Exception) {
                                    host.showToast(e.apiFailure().message ?: networkError)
                                }
                                saving = false
                            }
                        }.padding(vertical = 15.dp),
                    horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
                ) {
                    if (saving) CircularProgressIndicator(Modifier.size(18.dp), color = Color.White, strokeWidth = 2.dp)
                    else Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(20.dp))
                    Spacer(Modifier.width(8.dp))
                    Text(stringResource(R.string.ch_sticker_save_send), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp)
                }
            }
        }
    }
}

@Composable
private fun ShapeChip(icon: ImageVector, label: String, selected: Boolean, enabled: Boolean, modifier: Modifier, onClick: () -> Unit) {
    val bg by animateColorAsState(if (selected) Ch.Red else Color.White.copy(alpha = 0.1f), label = "shapeBg")
    Column(
        modifier.clip(RoundedCornerShape(16.dp)).background(bg).border(1.dp, Color.White.copy(alpha = if (selected) 0f else 0.12f), RoundedCornerShape(16.dp))
            .clickable(enabled = enabled, onClick = onClick).padding(vertical = 10.dp)
            .graphicsLayer { alpha = if (enabled) 1f else 0.4f },
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Icon(icon, null, tint = Color.White, modifier = Modifier.size(22.dp))
        Text(label, color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 4.dp))
    }
}

/** Grey squares behind the sticker: what you see through is transparent. */
@Composable
private fun Checkerboard() {
    Canvas(Modifier.fillMaxSize()) {
        val cell = 16.dp.toPx()
        drawRect(Color(0xFF2A2F38))
        var y = 0f
        var row = 0
        while (y < size.height) {
            var x = if (row % 2 == 0) 0f else cell
            while (x < size.width) {
                drawRect(Color(0xFF353B46), topLeft = Offset(x, y), size = androidx.compose.ui.geometry.Size(cell, cell))
                x += cell * 2
            }
            y += cell
            row++
        }
    }
}

/** The photo's main subject on a transparent background (ML Kit, on the phone); null if none found. */
private suspend fun cutOut(photo: Bitmap): Bitmap? = suspendCancellableCoroutine { cont ->
    runCatching {
        val segmenter = SubjectSegmentation.getClient(SubjectSegmenterOptions.Builder().enableForegroundBitmap().build())
        segmenter.process(InputImage.fromBitmap(photo, 0))
            .addOnSuccessListener { result -> if (cont.isActive) cont.resume(result.foregroundBitmap?.let(::trimmed)) }
            .addOnFailureListener { if (cont.isActive) cont.resume(null) }
            .addOnCompleteListener { segmenter.close() }
    }.onFailure { if (cont.isActive) cont.resume(null) }
}

/** Cropped to its visible pixels (a cut-out is mostly empty space). */
private fun trimmed(bitmap: Bitmap): Bitmap? {
    val w = bitmap.width
    val h = bitmap.height
    val pixels = IntArray(w * h)
    bitmap.getPixels(pixels, 0, w, 0, 0, w, h)
    var minX = w; var minY = h; var maxX = -1; var maxY = -1
    for (y in 0 until h) for (x in 0 until w) {
        if ((pixels[y * w + x] ushr 24) > 24) {
            if (x < minX) minX = x
            if (x > maxX) maxX = x
            if (y < minY) minY = y
            if (y > maxY) maxY = y
        }
    }
    if (maxX < 0) return null
    return Bitmap.createBitmap(bitmap, minX, minY, maxX - minX + 1, maxY - minY + 1)
}

/** The white outline of a sticker: the shape's silhouette drawn in white around it, then the shape on top. */
private fun withOutline(cut: Bitmap): Bitmap {
    val stroke = (maxOf(cut.width, cut.height) * 0.025f).coerceAtLeast(4f)
    val pad = stroke.toInt() + 2
    val out = Bitmap.createBitmap(cut.width + pad * 2, cut.height + pad * 2, Bitmap.Config.ARGB_8888)
    val canvas = Canvas(out)
    val white = Paint(Paint.ANTI_ALIAS_FLAG).apply { colorFilter = PorterDuffColorFilter(android.graphics.Color.WHITE, PorterDuff.Mode.SRC_IN) }
    val steps = 24
    for (i in 0 until steps) {
        val a = (2 * Math.PI * i / steps).toFloat()
        canvas.drawBitmap(cut, pad + kotlin.math.cos(a) * stroke, pad + kotlin.math.sin(a) * stroke, white)
    }
    canvas.drawBitmap(cut, pad.toFloat(), pad.toFloat(), null)
    return out
}

/**
 * The final 512×512 transparent sticker, exactly as framed on screen: the art fitted into the
 * square, moved / zoomed by the same amounts, clipped to a circle or rounded square if chosen.
 */
private fun render(art: Bitmap, shape: StickerShape, scale: Float, offset: Offset, frame: IntSize): Pair<ByteArray, Boolean> {
    val out = Bitmap.createBitmap(STICKER_PX, STICKER_PX, Bitmap.Config.ARGB_8888)
    val canvas = Canvas(out)
    val ratio = if (frame.width > 0) STICKER_PX / frame.width.toFloat() else 1f
    val clip = android.graphics.Path()
    when (shape) {
        StickerShape.Circle -> clip.addCircle(STICKER_PX / 2f, STICKER_PX / 2f, STICKER_PX / 2f, android.graphics.Path.Direction.CW)
        StickerShape.Rounded -> clip.addRoundRect(0f, 0f, STICKER_PX.toFloat(), STICKER_PX.toFloat(), STICKER_PX * 0.18f, STICKER_PX * 0.18f, android.graphics.Path.Direction.CW)
        StickerShape.Cutout -> clip.addRect(0f, 0f, STICKER_PX.toFloat(), STICKER_PX.toFloat(), android.graphics.Path.Direction.CW)
    }
    canvas.clipPath(clip)
    // "Fit" into the square, like the preview, then the user's zoom and pan around the centre.
    val fit = minOf(STICKER_PX / art.width.toFloat(), STICKER_PX / art.height.toFloat())
    val w = art.width * fit * scale
    val h = art.height * fit * scale
    val left = (STICKER_PX - w) / 2f + offset.x * ratio
    val top = (STICKER_PX - h) / 2f + offset.y * ratio
    canvas.drawBitmap(art, null, android.graphics.RectF(left, top, left + w, top + h), Paint(Paint.ANTI_ALIAS_FLAG or Paint.FILTER_BITMAP_FLAG))

    // Lossless keeps the edges clean; a busy photo square falls back to lossy WebP to stay under
    // the 1 MB a sticker may weigh (both keep the transparency).
    val bytes = ByteArrayOutputStream()
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
        out.compress(Bitmap.CompressFormat.WEBP_LOSSLESS, 100, bytes)
        if (bytes.size() <= MAX_BYTES) return bytes.toByteArray() to true
        bytes.reset()
        out.compress(Bitmap.CompressFormat.WEBP_LOSSY, 88, bytes)
        return bytes.toByteArray() to true
    }
    out.compress(Bitmap.CompressFormat.PNG, 100, bytes)
    if (bytes.size() <= MAX_BYTES) return bytes.toByteArray() to false
    bytes.reset()
    @Suppress("DEPRECATION")
    out.compress(Bitmap.CompressFormat.WEBP, 88, bytes)
    return bytes.toByteArray() to true
}

/** The server takes stickers up to 1 MB. */
private const val MAX_BYTES = 950_000

/**
 * The picked photo, right way up (camera EXIF) and no bigger than the cut-out needs — the
 * sticker is 512px, so 1280px leaves room to zoom in without wasting memory.
 */
private fun loadUpright(path: String): Bitmap? = runCatching {
    val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
    BitmapFactory.decodeFile(path, bounds)
    val longest = maxOf(bounds.outWidth, bounds.outHeight)
    if (longest <= 0) return null
    var sample = 1
    while (longest / (sample * 2) >= 1280) sample *= 2
    var bitmap = BitmapFactory.decodeFile(path, BitmapFactory.Options().apply { inSampleSize = sample }) ?: return null
    val fit = 1280f / maxOf(bitmap.width, bitmap.height)
    if (fit < 1f) bitmap = Bitmap.createScaledBitmap(bitmap, (bitmap.width * fit).toInt(), (bitmap.height * fit).toInt(), true)
    val rotation = when (android.media.ExifInterface(path).getAttributeInt(android.media.ExifInterface.TAG_ORIENTATION, android.media.ExifInterface.ORIENTATION_NORMAL)) {
        android.media.ExifInterface.ORIENTATION_ROTATE_90 -> 90f
        android.media.ExifInterface.ORIENTATION_ROTATE_180 -> 180f
        android.media.ExifInterface.ORIENTATION_ROTATE_270 -> 270f
        else -> 0f
    }
    if (rotation != 0f) bitmap = Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, android.graphics.Matrix().apply { postRotate(rotation) }, true)
    // ARGB so the cut-out keeps its transparency.
    if (bitmap.config != Bitmap.Config.ARGB_8888) bitmap.copy(Bitmap.Config.ARGB_8888, false) else bitmap
}.getOrNull()
