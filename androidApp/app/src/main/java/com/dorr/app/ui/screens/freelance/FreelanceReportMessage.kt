package com.dorr.app.ui.screens.freelance

import androidx.compose.material.icons.Icons
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.ui.Alignment
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.ui.text.font.FontWeight
import com.dorr.app.ui.screens.profile.settingsSurface
import com.dorr.app.ui.screens.profile.settingsInk
import androidx.compose.foundation.layout.fillMaxSize
import com.dorr.app.ui.screens.profile.settingsAccent
import androidx.compose.ui.draw.clip
import androidx.compose.material3.Icon
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.rounded.*
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.rememberScrollState
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelanceReportMessageRequest
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.profile.settingsMut
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

private val MESSAGE_REPORT_REASONS = listOf(
    "abuse" to R.string.fl_rm_abuse,
    "harassment" to R.string.fl_rm_harassment,
    "blackmail" to R.string.fl_rm_blackmail,
    "contact_info" to R.string.fl_rm_contact_info,
    "spam" to R.string.fl_rm_spam,
    "other" to R.string.fl_rm_other,
)

/**
 * FL-709: reporting the other person's message (long press on it). One reason and, if wanted, a few words; the team looks at it and the
 * other person is not told. A report that was already sent says so.
 */
@Composable
internal fun ReportMessageDialog(messageId: Int, onDone: (String) -> Unit, onDismiss: () -> Unit) {
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.fl_error_generic)
    val sentText = stringResource(R.string.fl_rm_sent)
    var code by remember { mutableStateOf<String?>(null) }
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    FlSheet(Icons.Rounded.Flag, stringResource(R.string.fl_rm_title), stringResource(R.string.fl_rm_hint), onDismiss = { if (!busy) onDismiss() }) {
        MESSAGE_REPORT_REASONS.forEach { (key, label) -> Chip(stringResource(label), code == key, Icons.Rounded.Flag) { code = key } }
        DorrTextField(note, { note = it.take(500) }, placeholder = stringResource(R.string.fl_cr_note_hint), icon = Icons.Rounded.Notes, singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
        error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Color(0xFFDC2626)) }
        FlAction(stringResource(R.string.fl_rm_send), Icons.Rounded.Send, FlTone.Danger, enabled = code != null, loading = busy) {
            busy = true
            error = null
            scope.launch {
                try {
                    ApiClient.freelance.reportMessage(auth(), messageId, FreelanceReportMessageRequest(code.orEmpty(), note.trim().ifEmpty { null }))
                    onDone(sentText)
                } catch (e: CancellationException) {
                    throw e
                } catch (e: Exception) {
                    error = e.message(generic)
                }
                busy = false
            }
        }
        FlAction(stringResource(R.string.fl_not_now), null, onClick = onDismiss)
    }
}
