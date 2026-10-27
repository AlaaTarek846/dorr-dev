package com.dorr.freelance.ui.screens

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

/** FL-601: the note and the files of a delivery. Sent as one new version of the part. */
@Composable
internal fun DeliverDialog(busy: Boolean, onDismiss: () -> Unit, onSend: (note: String, files: List<PickedFile>) -> Unit) {
    val context = LocalContext.current
    var note by remember { mutableStateOf("") }
    val files = remember { mutableStateListOf<PickedFile>() }
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri != null && files.size < MAX_DELIVERY_FILES) readFile(context, uri)?.let { files.add(it) }
    }
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(R.string.ag_ms_deliver)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                DorrTextField(note, { note = it.take(3000) }, placeholder = stringResource(R.string.ag_ms_deliver_hint), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
                files.forEachIndexed { i, f ->
                    Row(Modifier.fillMaxWidth(), verticalAlignment = androidx.compose.ui.Alignment.CenterVertically) {
                        Text(f.name, color = settingsInk(), fontSize = 13.sp, maxLines = 1, modifier = Modifier.weight(1f))
                        Text(stringResource(R.string.ag_dl_remove), color = Color(0xFFDC2626), fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(enabled = !busy) { files.removeAt(i) }.padding(8.dp))
                    }
                }
                if (files.size < MAX_DELIVERY_FILES) Text(
                    stringResource(R.string.ag_dl_attach, files.size, MAX_DELIVERY_FILES), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.clickable(enabled = !busy) { picker.launch("*/*") }.padding(vertical = 6.dp),
                )
                Text(stringResource(R.string.ag_dl_kept), color = settingsMut(), fontSize = 11.sp)
            }
        },
        confirmButton = { TextButton(enabled = note.trim().length >= 5 && !busy, onClick = { onSend(note.trim(), files.toList()) }) { Text(stringResource(R.string.ag_ms_deliver_send), color = settingsAccent()) } },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
    )
}

/** What the client will check before approving a part (FL-604), shown to me while I work on it. */
@Composable
internal fun ChecklistView(m: MilestoneDto) {
    if (m.checklist.isEmpty()) return
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(stringResource(R.string.ag_ck_title), color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.Bold)
        m.checklist.forEach { c -> Text((if (c.checked) "☑ " else "☐ ") + c.text, color = settingsInk(), fontSize = 12.sp) }
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
    Text(
        stringResource(if (open) R.string.ag_dl_hide else R.string.ag_dl_show, m.deliveriesCount), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
        modifier = Modifier.clickable { open = !open }.padding(vertical = 6.dp),
    )
    if (open) versions.forEach { v ->
        Column(Modifier.fillMaxWidth().padding(start = 8.dp, bottom = 6.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(stringResource(R.string.ag_dl_version, v.number) + (v.deliveredAt?.let { " · " + it.take(10) } ?: ""), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            v.note?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
            v.files.forEach { f ->
                Text("📎 ${f.name}", color = settingsAccent(), fontSize = 12.sp, modifier = Modifier.clickable { scope.launch { if (!openDeliveryFile(context, f)) error = fileError } }.padding(vertical = 3.dp))
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
