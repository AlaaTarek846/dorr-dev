package com.dorr.app.ui.screens.freelance

import androidx.compose.material.icons.Icons
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.ui.Alignment
import com.dorr.app.ui.screens.profile.settingsSurface
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Icon
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.rounded.*
import android.content.Context
import android.content.Intent
import android.graphics.BitmapFactory
import androidx.compose.foundation.Image
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.AlertDialog
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
import androidx.core.content.FileProvider
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelanceChecklistItemDto
import com.dorr.app.network.FreelanceDeliveryDto
import com.dorr.app.network.FreelanceDeliveryFileDto
import com.dorr.app.network.FreelanceChecklistRequest
import com.dorr.app.network.FreelanceMilestoneDto
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File

private const val MAX_CHECKS = 10

/**
 * What the freelancer delivered for a part (FL-602) and what I check before approving (FL-604). The versions open in place - images are
 * shown, anything else is downloaded with my sign-in and handed to a viewer; nothing runs. The checklist is written before the work starts
 * and ticked while a delivery is in front of me; ticking never approves anything.
 */
@Composable
internal fun DeliveryReview(projectUuid: String, m: FreelanceMilestoneDto) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var open by remember(m.uuid) { mutableStateOf(m.status == "delivered") }
    var versions by remember { mutableStateOf<List<FreelanceDeliveryDto>>(emptyList()) }
    var checks by remember(m.uuid, m.status, m.checklist) { mutableStateOf(m.checklist) }
    var editing by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val fileError = stringResource(R.string.fl_dl_open_failed)

    // Read when opened and again when a new version or a status change arrives with the agreement.
    LaunchedEffect(open, m.deliveriesCount, m.status) {
        if (!open || m.deliveriesCount == 0) return@LaunchedEffect
        try {
            versions = ApiClient.freelance.deliveries(auth(), projectUuid, m.uuid).data.orEmpty()
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        if (checks.isNotEmpty()) {
            Text(stringResource(R.string.fl_ck_title), color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.Bold)
            checks.forEach { c ->
                val tickable = m.can.checklistTick
                Text(
                    (if (c.checked) "☑ " else "☐ ") + c.text, color = if (c.checked) Color(0xFF16A34A) else settingsInk(), fontSize = 13.sp,
                    modifier = Modifier.fillMaxWidth().clickable(enabled = tickable) {
                        scope.launch {
                            try {
                                checks = ApiClient.freelance.tickChecklist(auth(), projectUuid, m.uuid, c.uuid, if (c.checked) "uncheck" else "check").data.orEmpty()
                            } catch (e: CancellationException) {
                                throw e
                            } catch (e: Exception) {
                                error = e.message(generic)
                            }
                        }
                    }.padding(vertical = 3.dp),
                )
            }
            if (m.can.checklistTick) Text(stringResource(R.string.fl_ck_hint), color = settingsMut(), fontSize = 11.sp)
        }
        if (m.can.checklistEdit) {
            Text(
                stringResource(if (checks.isEmpty()) R.string.fl_ck_set else R.string.fl_ck_edit), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clickable { editing = true }.padding(vertical = 4.dp),
            )
        }
        if (m.deliveriesCount > 0) {
            Text(
                stringResource(if (open) R.string.fl_dl_hide else R.string.fl_dl_show, m.deliveriesCount), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clickable { open = !open }.padding(vertical = 4.dp),
            )
            if (open) versions.forEach { v ->
                Column(Modifier.fillMaxWidth().padding(start = 8.dp, bottom = 6.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                    Text(stringResource(R.string.fl_dl_version, v.number) + (v.deliveredAt?.let { " · " + it.take(10) } ?: ""), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                    v.note?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
                    v.files.forEach { f ->
                        if (f.isImage) DeliveredImage(f) else Text(
                            "📎 ${f.name}", color = settingsAccent(), fontSize = 12.sp,
                            modifier = Modifier.clickable { scope.launch { if (!openDelivered(context, f)) error = fileError } }.padding(vertical = 3.dp),
                        )
                    }
                    v.revisionReason?.let { Text(stringResource(R.string.fl_dl_asked, it), color = Color(0xFFD97706), fontSize = 12.sp) }
                }
            }
        }
        error?.let { Text(it, color = Color(0xFFDC2626), fontSize = 12.sp) }
    }

    if (editing) ChecklistEditor(initial = checks.map { it.text }, onDismiss = { editing = false }, onSave = { texts ->
        scope.launch {
            try {
                checks = ApiClient.freelance.setChecklist(auth(), projectUuid, m.uuid, FreelanceChecklistRequest(texts)).data.orEmpty()
                editing = false
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.message(generic)
                editing = false
            }
        }
    })
}

/** An image of a delivery, downloaded with my sign-in and shown in place (tap to open it in a viewer). */
@Composable
private fun DeliveredImage(f: FreelanceDeliveryFileDto) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var bitmap by remember(f.id) { mutableStateOf<android.graphics.Bitmap?>(null) }
    LaunchedEffect(f.id) {
        bitmap = withContext(Dispatchers.IO) {
            runCatching {
                val body = ApiClient.freelance.file(auth(), "mobile/v1/freelance/${f.path}").body() ?: return@runCatching null
                val bytes = body.bytes()
                // Decoded at a size that fits a phone card; a huge picture never fills the memory.
                val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }.also { BitmapFactory.decodeByteArray(bytes, 0, bytes.size, it) }
                val sample = (maxOf(bounds.outWidth, bounds.outHeight) / 1200).coerceAtLeast(1)
                BitmapFactory.decodeByteArray(bytes, 0, bytes.size, BitmapFactory.Options().apply { inSampleSize = sample })
            }.getOrNull()
        }
    }
    val b = bitmap
    if (b == null) Text("🖼 ${f.name}", color = settingsMut(), fontSize = 12.sp)
    else Image(
        b.asImageBitmap(), f.name, contentScale = ContentScale.FillWidth,
        modifier = Modifier.fillMaxWidth().heightIn(max = 320.dp).clip(RoundedCornerShape(12.dp)).clickable { scope.launch { openDelivered(context, f) } },
    )
}

/** Downloads one file of a delivery with the client's own sign-in (never a public link) and opens it. */
private suspend fun openDelivered(context: Context, f: FreelanceDeliveryFileDto): Boolean {
    val file = withContext(Dispatchers.IO) {
        runCatching {
            val response = ApiClient.freelance.file(auth(), "mobile/v1/freelance/${f.path}")
            val body = response.body()
            if (!response.isSuccessful || body == null) return@runCatching null
            val dir = File(context.cacheDir, "exports").apply { mkdirs() }
            val safe = f.name.filter { it.isLetterOrDigit() || it in "._- " }.ifBlank { "file" }
            File(dir, "${f.id}_$safe").also { out -> body.byteStream().use { input -> out.outputStream().use { input.copyTo(it) } } }
        }.getOrNull()
    } ?: return false
    val uri = FileProvider.getUriForFile(context, context.packageName + ".files", file)
    val view = Intent(Intent.ACTION_VIEW).setDataAndType(uri, f.mime.ifBlank { "*/*" }).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
    return runCatching { context.startActivity(Intent.createChooser(view, f.name).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }.isSuccess
}

/** Up to ten lines: what I will check before approving this part. */
@Composable
private fun ChecklistEditor(initial: List<String>, onDismiss: () -> Unit, onSave: (List<String>) -> Unit) {
    val lines = remember { mutableStateListOf<String>().also { it.addAll(initial.ifEmpty { listOf("") }) } }
    FlSheet(Icons.Rounded.Checklist, stringResource(R.string.fl_ck_set), stringResource(R.string.fl_ck_editor_hint), onDismiss = onDismiss) {
        lines.forEachIndexed { i, text ->
            Row(verticalAlignment = androidx.compose.ui.Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                DorrTextField(text, { lines[i] = it.take(200) }, placeholder = stringResource(R.string.fl_ck_line), icon = Icons.Rounded.CheckCircle, modifier = Modifier.weight(1f))
                Icon(Icons.Rounded.Close, null, tint = Color(0xFFDC2626), modifier = Modifier.size(34.dp).clickable { lines.removeAt(i) }.padding(7.dp))
            }
        }
        if (lines.size < MAX_CHECKS) FlAction(stringResource(R.string.fl_ck_add), Icons.Rounded.Add) { lines.add("") }
        FlAction(stringResource(R.string.fl_ck_save), Icons.Rounded.Save, FlTone.Primary) { onSave(lines.map { it.trim() }.filter { it.isNotEmpty() }) }
        FlAction(stringResource(R.string.common_cancel), null, onClick = onDismiss)
    }
}
