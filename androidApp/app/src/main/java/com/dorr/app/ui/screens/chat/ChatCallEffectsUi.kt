package com.dorr.app.ui.screens.chat

import android.graphics.BitmapFactory
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddPhotoAlternate
import androidx.compose.material.icons.rounded.BlurOn
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.chat.CallController
import com.dorr.app.chat.CallEffects

/** Camera backgrounds (spec 94): none, blur, a ready-made one or my own photo — all on the phone. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun CallBackgroundSheet(onDismiss: () -> Unit) {
    val context = LocalContext.current
    val presets = remember { CallEffects.presets.indices.map { it to CallEffects.presetBitmap(it) } }
    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        uri ?: return@rememberLauncherForActivityResult
        val bmp = runCatching {
            context.contentResolver.openInputStream(uri)?.use { s -> BitmapFactory.decodeStream(s, null, BitmapFactory.Options().apply { inSampleSize = 2 }) }
        }.getOrNull()
        if (bmp != null) { CallController.setBackground(CallEffects.Mode.Image, bmp); onDismiss() }
    }
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 28.dp)) {
            Text(stringResource(R.string.ce_title), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.ce_sub), color = Ch.Mut, fontSize = 12.5.sp)
            Spacer(Modifier.height(14.dp))
            Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Tile(stringResource(R.string.ce_none), CallEffects.mode == CallEffects.Mode.None, { CallController.setBackground(CallEffects.Mode.None); onDismiss() }) {
                    Icon(Icons.Rounded.Block, null, tint = Ch.Mut, modifier = Modifier.size(28.dp))
                }
                Tile(stringResource(R.string.ce_blur), CallEffects.mode == CallEffects.Mode.Blur, { CallController.setBackground(CallEffects.Mode.Blur); onDismiss() }) {
                    Icon(Icons.Rounded.BlurOn, null, tint = Ch.Red, modifier = Modifier.size(30.dp))
                }
                presets.forEach { (i, bmp) ->
                    Tile(null, CallEffects.mode == CallEffects.Mode.Image && CallEffects.picked == i, { CallController.setBackground(CallEffects.Mode.Image, bmp, i); onDismiss() }) {
                        Image(bmp.asImageBitmap(), null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                    }
                }
                Tile(stringResource(R.string.ce_photo), CallEffects.mode == CallEffects.Mode.Image && CallEffects.picked == null, { gallery.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }) {
                    Icon(Icons.Rounded.AddPhotoAlternate, null, tint = Ch.Red, modifier = Modifier.size(28.dp))
                }
            }
            Text(stringResource(R.string.ce_note), color = Ch.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 12.dp))
        }
    }
}

@Composable
private fun Tile(label: String?, on: Boolean, onClick: () -> Unit, content: @Composable () -> Unit) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Box(
            Modifier.size(width = 70.dp, height = 96.dp).clip(RoundedCornerShape(16.dp)).background(Ch.SurfaceMuted)
                .border(if (on) 3.dp else 1.dp, if (on) Ch.Red else Ch.Line, RoundedCornerShape(16.dp)).clickable(onClick = onClick),
            contentAlignment = Alignment.Center,
        ) { content() }
        if (label != null) Text(label, color = if (on) Ch.Red else Ch.Ink, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 4.dp))
    }
}
