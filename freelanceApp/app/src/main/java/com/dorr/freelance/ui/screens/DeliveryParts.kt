package com.dorr.freelance.ui.screens

import com.dorr.freelance.ui.kit.settingsSurface
import androidx.compose.foundation.background
import androidx.compose.ui.draw.clip
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.Spacer
import androidx.compose.material3.Icon
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.AttachFile
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.CloudUpload
import android.content.Context
import android.content.Intent
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.listSaver
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.DeliveryDto
import com.dorr.freelance.net.DeliveryFileDto
import com.dorr.freelance.net.MilestoneDto
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File

private const val MAX_DELIVERY_FILES = 10

/** A picked file kept in the cache folder, so the dialog survives the picker taking the app out of memory. */
private class SavedPick(val name: String, val type: String, val path: String)

private val SavedPickListSaver = listSaver<androidx.compose.runtime.snapshots.SnapshotStateList<SavedPick>, List<String>>(
    save = { list -> list.map { listOf(it.name, it.type, it.path) } },
    restore = { saved -> mutableStateListOf<SavedPick>().apply { saved.filter { File(it[2]).exists() }.forEach { add(SavedPick(it[0], it[1], it[2])) } } },
)

/** FL-601: the note and the files of a delivery. Sent as one new version of the part. */
@Composable
internal fun DeliverDialog(busy: Boolean, error: String?, onDismiss: () -> Unit, onSend: (note: String, files: List<PickedFile>) -> Unit) {
    val context = LocalContext.current
    var note by rememberSaveable { mutableStateOf("") }
    val picks = rememberSaveable(saver = SavedPickListSaver) { mutableStateListOf<SavedPick>() }
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri != null && picks.size < MAX_DELIVERY_FILES) readFile(context, uri)?.let { f ->
            val dir = File(context.cacheDir, "delivery-picks").apply { mkdirs() }
            val out = File(dir, "${System.nanoTime()}.bin").also { it.writeBytes(f.bytes) }
            picks.add(SavedPick(f.name, f.type, out.absolutePath))
        }
    }
    val dismiss = { File(context.cacheDir, "delivery-picks").deleteRecursively(); onDismiss() }
    FormSheet(Icons.Rounded.CloudUpload, stringResource(R.string.ag_ms_deliver), stringResource(R.string.ag_form_deliver_intro), onDismiss = { if (!busy) dismiss() }) {
        DorrTextField(note, { note = it.take(3000) }, label = stringResource(R.string.ag_ms_deliver_hint), icon = Icons.Rounded.Notes, singleLine = false, minLines = 4, modifier = Modifier.fillMaxWidth())
        Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            picks.forEachIndexed { i, f ->
                Row(Modifier.fillMaxWidth(), verticalAlignment = androidx.compose.ui.Alignment.CenterVertically) {
                    Icon(Icons.Rounded.AttachFile, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                    Spacer(Modifier.width(8.dp))
                    Text(f.name, color = settingsInk(), fontSize = 13.sp, maxLines = 1, modifier = Modifier.weight(1f))
                    Icon(Icons.Rounded.Close, stringResource(R.string.ag_dl_remove), tint = settingsMut(), modifier = Modifier.size(28.dp).clip(RoundedCornerShape(50)).clickable(enabled = !busy) { picks.removeAt(i) }.padding(5.dp))
                }
            }
            if (picks.size < MAX_DELIVERY_FILES) ActionButton(stringResource(R.string.ag_dl_attach, picks.size, MAX_DELIVERY_FILES), Icons.Rounded.AttachFile, Tone.Soft, enabled = !busy) { picker.launch("*/*") }
            Text(stringResource(R.string.ag_dl_kept), color = settingsMut(), fontSize = 11.sp)
        }
        error?.let { Text(it, color = Color(0xFFDC2626), fontSize = 12.sp, fontWeight = FontWeight.Bold) }
        ActionButton(stringResource(R.string.ag_ms_deliver_send), Icons.Rounded.CloudUpload, Tone.Primary, enabled = note.trim().length >= 5 && !busy, loading = busy) {
            onSend(note.trim(), picks.map { PickedFile(it.name, it.type, File(it.path).readBytes()) })
        }
    }
}

/** What the client will check before approving a part (FL-604), shown to me while I work on it. */
@Composable
internal fun ChecklistView(m: MilestoneDto) {
    if (m.checklist.isEmpty()) return
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(stringResource(R.string.ag_ck_title), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.ExtraBold)
        m.checklist.forEach { c ->
            Row(verticalAlignment = androidx.compose.ui.Alignment.CenterVertically) {
                Icon(if (c.checked) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (c.checked) Color(0xFF16A34A) else settingsMut(), modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(8.dp))
                Text(c.text, color = settingsInk(), fontSize = 12.5.sp, lineHeight = 18.sp)
            }
        }
    }
}

/** The versions I delivered for a part: the note, the files (open with the sign-in) and what the client asked to change. */
@Composable
internal fun DeliveriesBlock(projectUuid: String, m: MilestoneDto) {
    if (m.deliveriesCount == 0) return
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val fileError = stringResource(R.string.ag_dl_open_failed)
    var open by remember { mutableStateOf(false) }
    var versions by remember { mutableStateOf<List<DeliveryDto>>(emptyList()) }
    var error by remember { mutableStateOf<String?>(null) }
    // Read when opened and again when a new version or a status change arrives with the agreement.
    LaunchedEffect(open, m.deliveriesCount, m.status) {
        if (!open) return@LaunchedEffect
        (apiCall { ApiClient.agreements.deliveries(projectUuid, m.uuid) } as? ApiResult.Ok)?.data?.let { versions = it }
    }
    ActionButton(stringResource(if (open) R.string.ag_dl_hide else R.string.ag_dl_show, m.deliveriesCount), Icons.Rounded.Inventory2, Tone.Soft) { open = !open }
    if (open) versions.forEach { v ->
        Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsMut().copy(alpha = 0.08f)).padding(12.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Text(stringResource(R.string.ag_dl_version, v.number) + (v.deliveredAt?.let { " · " + it.take(10) } ?: ""), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            v.note?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
            v.files.forEach { f ->
                Text("\u200E${f.name}", color = settingsAccent(), fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.clickable { scope.launch { if (!openDeliveryFile(context, f)) error = fileError } }.padding(vertical = 3.dp))
            }
            v.revisionReason?.let { Text(stringResource(R.string.ag_ms_revision_reason, it), color = Color(0xFFD97706), fontSize = 12.sp) }
        }
    }
    error?.let { Text(it, color = Color(0xFFDC2626), fontSize = 12.sp) }
}

/** Downloads one file of a delivery with my own sign-in (never a public link) and opens it in a viewer. */
private suspend fun openDeliveryFile(context: Context, f: DeliveryFileDto): Boolean {
    val file = withContext(Dispatchers.IO) {
        runCatching {
            val response = ApiClient.agreements.file(ApiClient.FREELANCE + f.path)
            val body = response.body()
            if (!response.isSuccessful || body == null) return@runCatching null
            val dir = File(context.cacheDir, "deliveries").apply { mkdirs() }
            val safe = f.name.filter { it.isLetterOrDigit() || it in "._- " }.ifBlank { "file" }
            File(dir, "${f.id}_$safe").also { out -> body.byteStream().use { input -> out.outputStream().use { input.copyTo(it) } } }
        }.getOrNull()
    } ?: return false
    val uri = FileProvider.getUriForFile(context, context.packageName + ".files", file)
    val view = Intent(Intent.ACTION_VIEW).setDataAndType(uri, f.mime.ifBlank { "*/*" }).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
    return runCatching { context.startActivity(Intent.createChooser(view, f.name).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }.isSuccess
}
