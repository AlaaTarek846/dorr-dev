package com.dorr.app.ui.screens.freelance

import androidx.compose.material.icons.Icons
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.ui.Alignment
import androidx.compose.foundation.shape.RoundedCornerShape
import com.dorr.app.ui.screens.profile.settingsSurface
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
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelanceSanctionAppealRequest
import com.dorr.app.network.FreelanceSanctionDto
import com.dorr.app.network.FreelanceSanctionsDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

private val Danger = Color(0xFFDC2626)
private val Warn = Color(0xFFD97706)

/**
 * FL-708: my warnings and restrictions on freelance work, at the top of my projects while one is active or its appeal waits: what stops
 * (messages, new projects and hiring - never the money or the work already agreed), until when, why, and appealing once within the
 * days. Read on open, when the screen is told something changed, and when the connection returns - nothing on a timer.
 */
@Composable
internal fun SanctionsCard(refreshKey: Int) {
    val reconnect = collectReconnectTick()
    var data by remember { mutableStateOf<FreelanceSanctionsDto?>(null) }
    var appealFor by remember { mutableStateOf<FreelanceSanctionDto?>(null) }
    LaunchedEffect(refreshKey, reconnect) {
        try {
            data = ApiClient.freelance.sanctions(auth()).data
        } catch (e: CancellationException) {
            throw e
        } catch (_: Throwable) {
        }
    }
    val current = data?.items.orEmpty().filter { it.state == "active" || it.appeal?.status == "open" }
    if (current.isEmpty()) return
    Card(Icons.Rounded.Gavel, stringResource(R.string.fl_sc_title)) {
        current.forEach { s -> SanctionRow(s) { appealFor = s } }
    }
    appealFor?.let { s -> AppealDialog(s, onDone = { appealFor = null; data = it }, onDismiss = { appealFor = null }) }
}

@Composable
private fun SanctionRow(s: FreelanceSanctionDto, onAppeal: () -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(3.dp)) {
        Text(
            stringResource(when (s.type) { "chat_limit" -> R.string.fl_sc_chat_limit; "work_limit" -> R.string.fl_sc_work_limit; "suspension" -> R.string.fl_sc_suspension; else -> R.string.fl_sc_warning }),
            color = if (s.type == "warning") Warn else Danger, fontSize = 14.sp, fontWeight = FontWeight.Bold,
        )
        val blocks = s.blocks.orEmpty()
        if (blocks.isEmpty()) Text(stringResource(R.string.fl_sc_blocks_none), color = settingsMut(), fontSize = 12.sp)
        if ("chat" in blocks) Text(stringResource(R.string.fl_sc_blocks_chat), color = settingsInk(), fontSize = 12.sp)
        if ("work" in blocks) Text(stringResource(R.string.fl_sc_blocks_work), color = settingsInk(), fontSize = 12.sp)
        Text(s.endsAt?.let { stringResource(R.string.fl_sc_until, "‎" + it.take(10)) } ?: stringResource(R.string.fl_sc_until_lifted), color = settingsMut(), fontSize = 12.sp)
        s.reason?.let { Text(stringResource(R.string.fl_sc_reason, it), color = settingsInk(), fontSize = 13.sp) }
        s.appeal?.let { a ->
            Text(
                stringResource(when (a.status) { "upheld" -> R.string.fl_sc_appeal_upheld; "reduced" -> R.string.fl_sc_appeal_reduced; "lifted" -> R.string.fl_sc_appeal_lifted; else -> R.string.fl_sc_appeal_open }),
                color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold,
            )
            a.decisionNote?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
        }
        if (s.canAppeal == true) {
            TextLink(stringResource(R.string.fl_sc_appeal), onAppeal)
            s.appealUntil?.let { Text(stringResource(R.string.fl_sc_appeal_until, "‎" + it.take(10)), color = settingsMut(), fontSize = 11.sp) }
        }
    }
}

@Composable
private fun AppealDialog(s: FreelanceSanctionDto, onDone: (FreelanceSanctionsDto) -> Unit, onDismiss: () -> Unit) {
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.fl_error_generic)
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    FlSheet(Icons.Rounded.Gavel, stringResource(R.string.fl_sc_appeal_title), stringResource(R.string.fl_sc_appeal_hint), onDismiss = { if (!busy) onDismiss() }) {
        DorrTextField(note, { note = it.take(1000) }, placeholder = stringResource(R.string.fl_sc_appeal_note), icon = Icons.Rounded.Notes, singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
        error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Danger) }
        FlAction(stringResource(R.string.fl_sc_appeal_send), Icons.Rounded.Send, FlTone.Primary, enabled = note.trim().length >= 5, loading = busy) {
            busy = true
            error = null
            scope.launch {
                try {
                    ApiClient.freelance.appealSanction(auth(), s.uuid, FreelanceSanctionAppealRequest(note.trim())).data?.let(onDone)
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
