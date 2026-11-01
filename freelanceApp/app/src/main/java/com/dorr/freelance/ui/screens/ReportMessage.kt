package com.dorr.freelance.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.ReportMessageRequest
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import kotlinx.coroutines.launch

private val MESSAGE_REPORT_REASONS = listOf(
    "abuse" to R.string.rm_abuse,
    "harassment" to R.string.rm_harassment,
    "blackmail" to R.string.rm_blackmail,
    "contact_info" to R.string.rm_contact_info,
    "spam" to R.string.rm_spam,
    "other" to R.string.rm_other,
)

/**
 * FL-709: reporting the other person's message (long press on it). One reason and, if wanted, a few words; the team looks at it and the
 * other person is not told. A report that was already sent says so.
 */
@Composable
internal fun ReportMessageDialog(messageId: Int, onDone: (String) -> Unit, onDismiss: () -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val sentText = stringResource(R.string.rm_sent)
    var code by remember { mutableStateOf<String?>(null) }
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(R.string.rm_title)) },
        text = {
            Column(Modifier.fillMaxWidth().heightIn(max = 420.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(stringResource(R.string.rm_hint), color = settingsMut(), fontSize = 12.sp)
                MESSAGE_REPORT_REASONS.forEach { (key, label) ->
                    val on = code == key
                    Text(
                        stringResource(label), color = if (on) Color.White else settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(if (on) settingsAccent() else settingsAccent().copy(alpha = 0.08f))
                            .clickable { code = key }.padding(horizontal = 14.dp, vertical = 7.dp),
                    )
                }
                DorrTextField(note, { note = it.take(500) }, placeholder = stringResource(R.string.cr_note_hint), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
                error?.let { Text(it, color = Color(0xFFDC2626), fontSize = 12.sp) }
            }
        },
        confirmButton = {
            TextButton(enabled = code != null && !busy, onClick = {
                busy = true
                error = null
                scope.launch {
                    val result = apiCall { ApiClient.chat.reportMessage(messageId, ReportMessageRequest(code.orEmpty(), note.trim().ifEmpty { null })) }
                    if (result is ApiResult.Ok) onDone(sentText) else error = result.errorText(offlineText) ?: genericError
                    busy = false
                }
            }) { Text(stringResource(R.string.rm_send), color = Color(0xFFDC2626)) }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
    )
}
