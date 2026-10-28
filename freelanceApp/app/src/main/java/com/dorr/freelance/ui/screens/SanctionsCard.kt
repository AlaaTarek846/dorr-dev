package com.dorr.freelance.ui.screens

import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
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
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.SanctionAppealRequest
import com.dorr.freelance.net.SanctionDto
import com.dorr.freelance.net.SanctionsDto
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsSurface
import kotlinx.coroutines.launch

private val Danger = Color(0xFFDC2626)
private val Warn = Color(0xFFD97706)

/**
 * FL-708: my warnings and restrictions, at the top of Home while one is active or its appeal waits. It says what stops (messages, new
 * work - never the money or the work already agreed), until when, why, and lets me appeal once within the days. Read on open, when the
 * connection returns, and on any live notice (a new one, a lift, an appeal decision) - nothing on a timer.
 */
@Composable
internal fun SanctionsCard() {
    val reconnect = collectReconnectTick()
    var data by remember { mutableStateOf<SanctionsDto?>(null) }
    var appealFor by remember { mutableStateOf<SanctionDto?>(null) }
    suspend fun load() {
        (apiCall { ApiClient.account.sanctions() } as? ApiResult.Ok)?.data?.let { data = it }
    }
    LaunchedEffect(reconnect) { load() }
    LaunchedEffect(Unit) { Realtime.changed.collect { load() } }
    val current = data?.items.orEmpty().filter { it.state == "active" || it.appeal?.status == "open" }
    if (current.isEmpty()) return
    Column(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).border(1.dp, Danger.copy(alpha = 0.35f), RoundedCornerShape(20.dp)).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text(stringResource(R.string.sc_title), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
        current.forEach { s -> SanctionRow(s) { appealFor = s } }
    }
    appealFor?.let { s -> AppealDialog(s, onDone = { appealFor = null; data = it }, onDismiss = { appealFor = null }) }
}

@Composable
private fun SanctionRow(s: SanctionDto, onAppeal: () -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(3.dp)) {
        Text(
            stringResource(when (s.type) { "chat_limit" -> R.string.sc_chat_limit; "work_limit" -> R.string.sc_work_limit; "suspension" -> R.string.sc_suspension; else -> R.string.sc_warning }),
            color = if (s.type == "warning") Warn else Danger, fontSize = 14.sp, fontWeight = FontWeight.Bold,
        )
        val blocks = s.blocks.orEmpty()
        if (blocks.isEmpty()) Text(stringResource(R.string.sc_blocks_none), color = settingsMut(), fontSize = 12.sp)
        if ("chat" in blocks) Text(stringResource(R.string.sc_blocks_chat), color = settingsInk(), fontSize = 12.sp)
        if ("work" in blocks) Text(stringResource(R.string.sc_blocks_work), color = settingsInk(), fontSize = 12.sp)
        Text(
            s.endsAt?.let { stringResource(R.string.sc_until, "‎" + it.take(10)) } ?: stringResource(R.string.sc_until_lifted),
            color = settingsMut(), fontSize = 12.sp,
        )
        s.reason?.let { Text(stringResource(R.string.sc_reason, it), color = settingsInk(), fontSize = 13.sp) }
        s.appeal?.let { a ->
            Text(
                stringResource(when (a.status) { "upheld" -> R.string.sc_appeal_upheld; "reduced" -> R.string.sc_appeal_reduced; "lifted" -> R.string.sc_appeal_lifted; else -> R.string.sc_appeal_open }),
                color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold,
            )
            a.decisionNote?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
        }
        if (s.canAppeal == true) {
            Text(stringResource(R.string.sc_appeal), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(onClick = onAppeal).padding(vertical = 4.dp))
            s.appealUntil?.let { Text(stringResource(R.string.sc_appeal_until, "‎" + it.take(10)), color = settingsMut(), fontSize = 11.sp) }
        }
    }
}

@Composable
private fun AppealDialog(s: SanctionDto, onDone: (SanctionsDto) -> Unit, onDismiss: () -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(R.string.sc_appeal_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(stringResource(R.string.sc_appeal_hint), color = settingsMut(), fontSize = 12.sp)
                DorrTextField(note, { note = it.take(1000) }, placeholder = stringResource(R.string.sc_appeal_note), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
                error?.let { Text(it, color = Danger, fontSize = 12.sp) }
            }
        },
        confirmButton = {
            TextButton(enabled = note.trim().length >= 5 && !busy, onClick = {
                busy = true
                error = null
                scope.launch {
                    val r = apiCall { ApiClient.account.appealSanction(s.uuid, SanctionAppealRequest(note.trim())) }
                    val done = (r as? ApiResult.Ok)?.data
                    if (done != null) onDone(done) else error = r.errorText(offlineText) ?: genericError
                    busy = false
                }
            }) { Text(stringResource(R.string.sc_appeal_send), color = settingsAccent()) }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
    )
}
