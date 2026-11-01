package com.dorr.freelance.ui.screens

import androidx.compose.foundation.background
import androidx.compose.ui.draw.clip
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material.icons.rounded.Percent
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Timeline
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.ReportDto
import com.dorr.freelance.net.ReportSummaryDto
import com.dorr.freelance.net.ReportRequest
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.FieldLabel
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import kotlinx.coroutines.launch

/**
 * FL-605: progress reports on an active agreement. The client may have asked for one every few days; I can send one at any time (a
 * note and, if I want, how much is done). It says plainly when one is due or late - nothing is blocked. Read again when the agreement
 * changes or the connection returns; the due reminder comes as a notice, nothing runs on a timer.
 */
@Composable
internal fun AgreementReportsCard(projectUuid: String, summary: ReportSummaryDto, canSend: Boolean, refreshKey: String) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var list by remember { mutableStateOf<List<ReportDto>>(emptyList()) }
    var shown by remember { mutableStateOf<ReportSummaryDto?>(null) }
    var dialog by remember { mutableStateOf(false) }
    var note by remember { mutableStateOf("") }
    var percent by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(projectUuid, refreshKey, reconnect) {
        (apiCall { ApiClient.agreements.reports(projectUuid) } as? ApiResult.Ok)?.data?.let { list = it.reports; shown = it.summary }
    }
    val s = shown ?: summary
    if (!canSend && list.isEmpty() && s.everyDays == null) return
    Card {
        FieldLabel(stringResource(R.string.rp_title))
        s.everyDays?.let { Text(stringResource(R.string.rp_every, it), color = settingsMut(), fontSize = 12.sp) }
        if (s.late) Text(stringResource(R.string.rp_late), color = Color(0xFFD97706), fontSize = 13.sp, fontWeight = FontWeight.Bold)
        else s.nextDueAt?.let { Text(stringResource(R.string.rp_next, "‎" + it.take(10)), color = settingsMut(), fontSize = 12.sp) }
        if (canSend) ActionButton(stringResource(R.string.rp_send), Icons.Rounded.Send, Tone.Primary) { error = null; dialog = true }
        if (list.isEmpty()) Text(stringResource(R.string.rp_empty), color = settingsMut(), fontSize = 13.sp)
        list.forEach { r ->
            Column(Modifier.fillMaxWidth().clip(androidx.compose.foundation.shape.RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(12.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Row {
                    Text("‎" + r.createdAt?.take(16)?.replace('T', ' ').orEmpty(), color = settingsMut(), fontSize = 11.sp, modifier = Modifier.weight(1f))
                    r.percent?.let { Text(stringResource(R.string.rp_percent, it), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold) }
                }
                Text(r.note, color = settingsInk(), fontSize = 13.sp)
            }
        }
    }
    if (dialog) FormSheet(Icons.Rounded.Timeline, stringResource(R.string.rp_send), stringResource(R.string.rp_form_intro), onDismiss = { if (!busy) dialog = false }) {
        DorrTextField(note, { note = it.take(2000) }, label = stringResource(R.string.rp_note_hint), icon = Icons.Rounded.Notes, singleLine = false, minLines = 4, modifier = Modifier.fillMaxWidth())
        DorrTextField(
            percent, { percent = it.filter(Char::isDigit).take(3) }, label = stringResource(R.string.rp_percent_hint), icon = Icons.Rounded.Percent,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth(),
        )
        error?.let { Text(it, color = Color(0xFFDC2626), fontSize = 12.sp) }
        ActionButton(stringResource(R.string.rp_send_confirm), Icons.Rounded.Send, Tone.Primary, enabled = note.trim().length >= 3 && !busy, loading = busy) {
            busy = true
            scope.launch {
                val result = apiCall { ApiClient.agreements.sendReport(projectUuid, ReportRequest(note.trim(), percent.toIntOrNull()?.coerceIn(0, 100))) }
                if (result is ApiResult.Ok) {
                    result.data?.let { list = it.reports; shown = it.summary }
                    note = ""
                    percent = ""
                    dialog = false
                } else error = result.errorText(offlineText) ?: genericError
                busy = false
            }
        }
    }
}
