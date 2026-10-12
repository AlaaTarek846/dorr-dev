package com.dorr.app.ui.screens.aichat

import android.content.ContentValues
import android.content.Context
import android.os.Build
import android.os.Environment
import android.provider.MediaStore
import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.FolderZip
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AiSiteSourceDownloadDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.chatAuth
import com.dorr.app.ui.screens.wallet.PadResult
import com.dorr.app.ui.screens.wallet.walletAuth
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File
import java.time.Instant

/** Writes the ZIP into the phone's Downloads (MediaStore on Android 10+, the app's own downloads folder before). */
private suspend fun saveZip(context: Context, name: String, body: okhttp3.ResponseBody): Boolean = withContext(Dispatchers.IO) {
    body.use { source ->
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            val resolver = context.contentResolver
            val values = ContentValues().apply {
                put(MediaStore.Downloads.DISPLAY_NAME, name)
                put(MediaStore.Downloads.MIME_TYPE, "application/zip")
                put(MediaStore.Downloads.IS_PENDING, 1)
            }
            val uri = resolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, values) ?: return@withContext false
            resolver.openOutputStream(uri)?.use { out -> source.byteStream().copyTo(out) } ?: return@withContext false
            values.clear()
            values.put(MediaStore.Downloads.IS_PENDING, 0)
            resolver.update(uri, values, null, null)
            true
        } else {
            val dir = context.getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS) ?: return@withContext false
            File(dir, name).outputStream().use { out -> source.byteStream().copyTo(out) }
            true
        }
    }
}

/**
 * "Download the source": the site's files as a ZIP. The admin switches it on/off and decides whether it is
 * free or paid (once per site, from the wallet behind the PIN), so the card only shows what is possible.
 */
@Composable
internal fun SiteSourceCard(night: Boolean, projectId: Int) {
    val c = sitePalette()
    val context = LocalContext.current
    val scope = rememberCoroutineScope()

    var status by remember { mutableStateOf<AiSiteSourceDownloadDto?>(null) }
    var askPin by remember { mutableStateOf(false) }
    var noPin by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var message by remember { mutableStateOf<String?>(null) }
    var failed by remember { mutableStateOf(false) }

    LaunchedEffect(projectId) {
        status = runCatching { ApiClient.aiSites.sourceDownload(chatAuth(), projectId).data }.getOrNull()
    }

    val s = status ?: return
    if (!s.enabled) return

    val savedText = stringResource(R.string.ai_sites_src_saved)
    val failText = stringResource(R.string.ai_sites_src_failed)

    fun download() {
        busy = true
        message = null
        scope.launch {
            val ok = runCatching {
                val body = ApiClient.aiSites.downloadSource(chatAuth(), projectId)
                saveZip(context, "site-$projectId.zip", body)
            }.getOrElse { e ->
                message = e.apiFailure().message
                false
            }
            failed = !ok
            if (ok) {
                message = savedText
                Toast.makeText(context, savedText, Toast.LENGTH_SHORT).show()
            } else if (message == null) {
                message = failText
            }
            busy = false
        }
    }

    SiteCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(40.dp).clip(RoundedCornerShape(13.dp)).background(Ai.Red.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.FolderZip, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(22.dp))
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.ai_sites_src_title), color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
                Text(stringResource(R.string.ai_sites_src_sub), color = c.mut, fontSize = 12.sp, lineHeight = 18.sp, modifier = Modifier.padding(top = 2.dp))
            }
            val badge = when {
                s.free -> stringResource(R.string.ai_sites_src_free) to c.success
                s.unlocked -> stringResource(R.string.ai_sites_src_unlocked) to c.success
                s.price != null -> "${s.price} ${s.currency.orEmpty()}".trim() to Ai.Red
                else -> null
            }
            if (badge != null) {
                Spacer(Modifier.width(8.dp))
                Box(Modifier.clip(RoundedCornerShape(50)).background(badge.second.copy(alpha = 0.13f)).padding(horizontal = 10.dp, vertical = 5.dp)) {
                    Text(badge.first, color = badge.second, fontWeight = FontWeight.ExtraBold, fontSize = 11.5.sp)
                }
            }
        }

        Spacer(Modifier.height(14.dp))

        when {
            busy -> Box(Modifier.fillMaxWidth().height(52.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ai.Red, modifier = Modifier.size(24.dp), strokeWidth = 2.5.dp) }

            s.canDownload -> PrimaryButton(stringResource(R.string.ai_sites_src_download)) { download() }

            s.purchasable -> PrimaryButton(stringResource(R.string.ai_sites_src_unlock, "${s.price ?: ""} ${s.currency.orEmpty()}".trim())) {
                scope.launch {
                    val pin = runCatching { ApiClient.wallet.pinStatus(walletAuth()).data }.getOrNull()
                    if (pin?.hasPin == false) noPin = true else askPin = true
                }
            }

            else -> Text(stringResource(R.string.ai_sites_src_unavailable), color = c.mut, fontSize = 12.5.sp)
        }

        message?.let {
            Text(it, color = if (failed) c.danger else c.success, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 10.dp))
        }
    }

    if (noPin) {
        AiInfoDialog(night = night, message = stringResource(R.string.ai_subscription_no_pin_set), onDismiss = { noPin = false })
    }

    if (askPin) {
        AiWalletPinSheet(
            night = night,
            subtitle = stringResource(R.string.ai_sites_src_pin_sub),
            onDismiss = { askPin = false },
            onSubmit = { pin ->
                runCatching { ApiClient.aiSites.buySourceDownload(chatAuth(), pin, projectId).data }.fold(
                    onSuccess = { updated ->
                        if (updated != null) status = updated
                        message = null
                        PadResult.Ok
                    },
                    onFailure = { e ->
                        val failure = e.apiFailure()
                        val until = failure.lockedUntil?.let { runCatching { Instant.parse(it).toEpochMilli() }.getOrNull() }
                        if (failure.errorCode == "wallet_pin_locked" && until != null) PadResult.Locked(until) else PadResult.Error(failure.message ?: "")
                    },
                )
            },
        )
    }
}
