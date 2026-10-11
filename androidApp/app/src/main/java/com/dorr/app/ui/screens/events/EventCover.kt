package com.dorr.app.ui.screens.events

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddPhotoAlternate
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.ui.screens.wallet.Wa
import com.google.gson.JsonObject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.ByteArrayOutputStream

/*
 * An event's cover from the organizer's phone (spec 180): picked from the gallery, shrunk to
 * 1600 px (JPEG) so it always fits the server's 5 MB, and sent with the event as multipart.
 */
internal object EventCover {
    private const val MAX_SIDE = 1600

    /** The picked image as the `cover` part, or null when it can't be read. */
    suspend fun part(context: Context, uri: Uri): MultipartBody.Part? = withContext(Dispatchers.IO) {
        runCatching {
            val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
            context.contentResolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, bounds) }
            var sample = 1
            while (maxOf(bounds.outWidth, bounds.outHeight) / (sample * 2) >= MAX_SIDE) sample *= 2
            val decoded = context.contentResolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, BitmapFactory.Options().apply { inSampleSize = sample }) } ?: return@runCatching null
            val scale = MAX_SIDE.toFloat() / maxOf(decoded.width, decoded.height)
            val bitmap = if (scale < 1f) Bitmap.createScaledBitmap(decoded, (decoded.width * scale).toInt(), (decoded.height * scale).toInt(), true) else decoded
            val bytes = ByteArrayOutputStream().also { bitmap.compress(Bitmap.CompressFormat.JPEG, 85, it) }.toByteArray()
            MultipartBody.Part.createFormData("cover", "cover.jpg", bytes.toRequestBody("image/jpeg".toMediaTypeOrNull()))
        }.getOrNull()
    }

    /** A JSON body as multipart fields (Laravel's "boolean" rule takes 1/0, not "true"/"false"). */
    fun fields(body: JsonObject): Map<String, RequestBody> = body.entrySet().associate { (k, v) ->
        val text = when {
            v.isJsonPrimitive && v.asJsonPrimitive.isBoolean -> if (v.asBoolean) "1" else "0"
            else -> v.asString
        }
        k to text.toRequestBody("text/plain".toMediaTypeOrNull())
    }
}

/** The gallery picker, images only. */
@Composable
internal fun rememberCoverPicker(onPicked: (Uri) -> Unit): () -> Unit {
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri -> uri?.let(onPicked) }
    return { launcher.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }
}

/** The cover box of the new-event form: a dashed invitation, or the picked photo with change / remove. */
@Composable
internal fun CoverField(cover: Uri?, onPick: () -> Unit, onRemove: () -> Unit) {
    Box(
        Modifier.fillMaxWidth().aspectRatio(16f / 9f).clip(RoundedCornerShape(22.dp)).background(Wa.Field)
            .then(if (cover == null) Modifier.border(1.5.dp, Wa.Line, RoundedCornerShape(22.dp)) else Modifier)
            .clickable(onClick = onPick),
        contentAlignment = Alignment.Center,
    ) {
        if (cover == null) {
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Icon(Icons.Rounded.AddPhotoAlternate, null, tint = Wa.Red, modifier = Modifier.size(34.dp))
                Spacer(Modifier.height(6.dp))
                Text(stringResource(R.string.ev_cover_add), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold)
                Text(stringResource(R.string.ev_cover_hint), color = Wa.Mut, fontSize = 11.5.sp)
            }
        } else {
            AsyncImage(model = cover, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
            Row(Modifier.align(Alignment.TopEnd).padding(10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf(Icons.Rounded.Edit to onPick, Icons.Rounded.Close to onRemove).forEach { (icon, action) ->
                    Box(Modifier.size(34.dp).clip(CircleShape).background(Color.Black.copy(alpha = 0.55f)).clickable(onClick = action), contentAlignment = Alignment.Center) {
                        Icon(icon, null, tint = Color.White, modifier = Modifier.size(18.dp))
                    }
                }
            }
        }
    }
}

/** The small "change cover" pill on an event's photo (the organizer's own events). */
@Composable
internal fun ChangeCoverPill(busy: Boolean, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Row(
        modifier.clip(CircleShape).background(Color.Black.copy(alpha = 0.55f)).clickable(enabled = !busy, onClick = onClick).padding(horizontal = 12.dp, vertical = 7.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.AddPhotoAlternate, null, tint = Color.White, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(6.dp))
        Text(stringResource(if (busy) R.string.ev_cover_uploading else R.string.ev_cover_change), color = Color.White, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
    }
}
