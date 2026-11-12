package com.dorr.freelance.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
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
import com.dorr.freelance.net.InterviewDto
import com.dorr.freelance.net.InterviewProposeRequest
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsSurface
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.LocalTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter

private val Red = Color(0xFFDC2626)

private fun whenText(iso: String): String = runCatching {
    OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("EEE d MMM, HH:mm"))
}.getOrDefault(iso)

/**
 * A short interview with the client (FL-310), arranged in the room: the open one (confirm / decline what the client proposed, or
 * cancel), and a picker to propose a time. Read with the room - when it opens, on a notice, on reconnect.
 */
@Composable
fun InterviewPanel(roomUuid: String, canWrite: Boolean, items: List<InterviewDto>, onChanged: () -> Unit, onError: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var busy by remember { mutableStateOf(false) }
    var picking by remember { mutableStateOf(false) }
    val open = items.firstOrNull { it.status == "proposed" || it.status == "confirmed" }
    if (open == null && !canWrite) return

    fun run(block: suspend () -> ApiResult<*>) {
        busy = true
        scope.launch {
            val r = block()
            if (r is ApiResult.Ok) {
                picking = false
                onChanged()
            } else onError(r.errorText(offlineText) ?: genericError)
            busy = false
        }
    }

    Column(
        Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 4.dp).settingsSurface(RoundedCornerShape(16.dp)).padding(12.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        if (open != null) {
            val mineToAnswer = open.status == "proposed" && open.proposedBy != "freelancer"
            Text(stringResource(R.string.iv_title), color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.Bold)
            Text(
                whenText(open.startsAt) + " · " + stringResource(R.string.iv_minutes, open.durationMinutes),
                color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
            )
            open.note?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
            Text(
                stringResource(if (open.status == "confirmed") R.string.iv_confirmed else if (mineToAnswer) R.string.iv_asks_you else R.string.iv_waiting),
                color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold,
            )
            Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                if (mineToAnswer) {
                    Action(stringResource(R.string.iv_confirm), false) { run { apiCall { ApiClient.chat.answerInterview(roomUuid, open.uuid, "confirm") } } }
                    Action(stringResource(R.string.iv_decline), true) { run { apiCall { ApiClient.chat.answerInterview(roomUuid, open.uuid, "decline") } } }
                } else {
                    Action(stringResource(R.string.iv_cancel), true) { run { apiCall { ApiClient.chat.answerInterview(roomUuid, open.uuid, "cancel") } } }
                }
                if (canWrite) Action(stringResource(R.string.iv_other_time), false) { picking = !picking }
            }
        } else {
            Action(stringResource(R.string.iv_schedule), false) { picking = !picking }
        }
        if (picking && canWrite) Picker(busy) { startsAt, minutes, note -> run { apiCall { ApiClient.chat.proposeInterview(roomUuid, InterviewProposeRequest(startsAt, minutes, note)) } } }
    }
}

@Composable
private fun Action(text: String, danger: Boolean, onClick: () -> Unit) {
    Text(text, color = if (danger) Red else settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable(onClick = onClick).padding(vertical = 4.dp))
}

@Composable
private fun Pill(text: String, selected: Boolean, onClick: () -> Unit) {
    val accent = settingsAccent()
    Text(
        text, color = if (selected) Color.White else settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold,
        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(if (selected) accent else accent.copy(alpha = 0.08f)).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 6.dp),
    )
}

@Composable
private fun Picker(busy: Boolean, onSend: (String, Int, String?) -> Unit) {
    val today = remember { LocalDate.now() }
    var day by remember { mutableStateOf(today.plusDays(1)) }
    var time by remember { mutableStateOf(LocalTime.of(10, 0)) }
    var minutes by remember { mutableStateOf(15) }
    val slots = remember { (8..22).flatMap { h -> listOf(LocalTime.of(h, 0), LocalTime.of(h, 30)) } }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            (0..13).forEach { n -> val d = today.plusDays(n.toLong()); Pill(d.format(DateTimeFormatter.ofPattern("EEE d MMM")), d == day) { day = d } }
        }
        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            slots.forEach { t -> Pill(t.format(DateTimeFormatter.ofPattern("HH:mm")), t == time) { time = t } }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            listOf(10, 15, 30, 45, 60).forEach { m -> Pill(stringResource(R.string.iv_minutes, m), m == minutes) { minutes = m } }
        }
        SettingsPrimaryButton(text = stringResource(R.string.iv_send), loading = busy, onClick = {
            val at = ZonedDateTime.of(day, time, ZoneId.systemDefault()).toOffsetDateTime().toString()
            onSend(at, minutes, null)
        })
    }
}

/** The line the server writes in the room when an interview is proposed, answered or cancelled. */
@Composable
fun SystemLine(text: String) {
    Text(text, color = settingsMut(), fontSize = 12.sp, modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp), textAlign = androidx.compose.ui.text.style.TextAlign.Center)
}
