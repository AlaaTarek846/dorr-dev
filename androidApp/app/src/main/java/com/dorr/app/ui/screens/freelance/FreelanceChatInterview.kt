package com.dorr.app.ui.screens.freelance

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.*
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.material3.Icon
import androidx.compose.ui.Alignment
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.fillMaxSize
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelanceInterviewDto
import com.dorr.app.network.FreelanceInterviewRequest
import com.dorr.app.ui.screens.profile.SettingsPrimaryButton
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.LocalTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter

private fun interviewWhen(iso: String): String = runCatching {
    OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("EEE d MMM, HH:mm"))
}.getOrDefault(iso)

/**
 * A short interview with the freelancer (FL-310), arranged in the room: the open one (confirm / decline what the freelancer proposed,
 * or cancel) and a picker to propose a time. A confirmed interview also lands in the Dorr calendar. Read with the room - when it opens,
 * on a notice, on reconnect.
 */
@Composable
internal fun InterviewPanel(roomUuid: String, canWrite: Boolean, items: List<FreelanceInterviewDto>, onChanged: () -> Unit, onError: (String) -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var picking by remember { mutableStateOf(false) }
    val open = items.firstOrNull { it.status == "proposed" || it.status == "confirmed" }
    if (open == null && !canWrite) return

    fun run(block: suspend () -> Unit) {
        busy = true
        scope.launch {
            try {
                block()
                picking = false
                onChanged()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                onError(e.message(generic))
            }
            busy = false
        }
    }

    Column(
        Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 4.dp).settingsSurface(RoundedCornerShape(16.dp)).padding(12.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        if (open != null) {
            val toAnswer = open.status == "proposed" && open.proposedBy != "client"
            FlHeading(Icons.Rounded.Event, stringResource(R.string.fl_iv_title))
            Text(interviewWhen(open.startsAt) + " · " + stringResource(R.string.fl_iv_minutes, open.durationMinutes), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            open.note?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
            Text(
                stringResource(if (open.status == "confirmed") R.string.fl_iv_confirmed else if (toAnswer) R.string.fl_iv_asks_you else R.string.fl_iv_waiting),
                color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold,
            )
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                if (toAnswer) {
                    FlAction(stringResource(R.string.fl_iv_confirm), Icons.Rounded.CheckCircle, FlTone.Primary, enabled = !busy) { run { ApiClient.freelance.answerInterview(auth(), roomUuid, open.uuid, "confirm") } }
                    FlAction(stringResource(R.string.fl_iv_decline), Icons.Rounded.Cancel, FlTone.Danger, enabled = !busy) { run { ApiClient.freelance.answerInterview(auth(), roomUuid, open.uuid, "decline") } }
                } else {
                    FlAction(stringResource(R.string.fl_iv_cancel), Icons.Rounded.Cancel, FlTone.Danger, enabled = !busy) { run { ApiClient.freelance.answerInterview(auth(), roomUuid, open.uuid, "cancel") } }
                }
                if (canWrite) FlAction(stringResource(R.string.fl_iv_other_time), Icons.Rounded.Schedule) { picking = !picking }
            }
        } else {
            FlAction(stringResource(R.string.fl_iv_schedule), Icons.Rounded.EventAvailable) { picking = !picking }
        }
        if (picking && canWrite) InterviewPicker(busy) { startsAt, minutes -> run { ApiClient.freelance.proposeInterview(auth(), roomUuid, FreelanceInterviewRequest(startsAt, minutes)) } }
    }
}

@Composable
private fun InterviewPicker(busy: Boolean, onSend: (String, Int) -> Unit) {
    val today = remember { LocalDate.now() }
    var day by remember { mutableStateOf(today.plusDays(1)) }
    var time by remember { mutableStateOf(LocalTime.of(10, 0)) }
    var minutes by remember { mutableStateOf(15) }
    val slots = remember { (8..22).flatMap { h -> listOf(LocalTime.of(h, 0), LocalTime.of(h, 30)) } }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        FlHeading(Icons.Rounded.CalendarMonth, stringResource(R.string.fl_iv_title))
        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            (0..13).forEach { n -> val d = today.plusDays(n.toLong()); Chip(d.format(DateTimeFormatter.ofPattern("EEE d MMM")), d == day, Icons.Rounded.CalendarMonth) { day = d } }
        }
        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            slots.forEach { t -> Chip(t.format(DateTimeFormatter.ofPattern("HH:mm")), t == time, Icons.Rounded.Schedule) { time = t } }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            listOf(10, 15, 30, 45, 60).forEach { m -> Chip(stringResource(R.string.fl_iv_minutes, m), m == minutes, Icons.Rounded.Timer) { minutes = m } }
        }
        SettingsPrimaryButton(text = stringResource(R.string.fl_iv_send), loading = busy, onClick = {
            onSend(ZonedDateTime.of(day, time, ZoneId.systemDefault()).toOffsetDateTime().toString(), minutes)
        })
    }
}

/** The line the server writes in the room when an interview is proposed, answered or cancelled. */
@Composable
internal fun InterviewSystemLine(text: String) {
    Text(text, color = settingsMut(), fontSize = 12.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp))
}
