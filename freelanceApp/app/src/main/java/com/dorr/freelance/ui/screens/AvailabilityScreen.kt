package com.dorr.freelance.ui.screens

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.AvailabilityRequest
import com.dorr.freelance.net.TimeOffDto
import com.dorr.freelance.net.TimeOffRequest
import com.dorr.freelance.net.WeeklyHoursDto
import com.dorr.freelance.net.apiDone
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.components.ChipRow
import com.dorr.freelance.ui.components.DateField
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.MessageToast
import com.dorr.freelance.ui.components.FieldLabel
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.kit.RedToggle
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import kotlinx.coroutines.launch

private val DAY_NAMES = listOf(R.string.day_sun, R.string.day_mon, R.string.day_tue, R.string.day_wed, R.string.day_thu, R.string.day_fri, R.string.day_sat)

/** One weekday in the editor: switched on or off, and its hours (kept as the typed `HH:MM` text). */
private class DayState(val day: Int, on: Boolean, from: String, to: String) {
    var on by mutableStateOf(on)
    var from by mutableStateOf(from)
    var to by mutableStateOf(to)
}

private val TIME_SHAPE = Regex("""^([01]\d|2[0-3]):[0-5]\d$""")

/**
 * Availability and guide price (FL-106): whether new requests are taken (with an optional day it lifts by itself), a guide price
 * (hourly or fixed, from – to), the weekly hours, and time off. Read once when the page opens; the form saves with one button, time
 * off is added and removed at once.
 */
@Composable
fun AvailabilityScreen(onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val savedText = stringResource(R.string.profile_saved)
    val badMoney = stringResource(R.string.availability_rate_invalid)
    val badHours = stringResource(R.string.availability_hours_invalid)

    var loaded by remember { mutableStateOf(false) }
    var accepting by remember { mutableStateOf(true) }
    var pausedUntil by remember { mutableStateOf("") }
    var pauseReason by remember { mutableStateOf("") }
    var rateType by remember { mutableStateOf("none") }
    var rateMin by remember { mutableStateOf("") }
    var rateMax by remember { mutableStateOf("") }
    var currency by remember { mutableStateOf("") }
    val days = remember { DAY_NAMES.indices.map { DayState(it, false, "09:00", "17:00") } }
    val timeOff = remember { mutableStateListOf<TimeOffDto>() }
    var newStart by remember { mutableStateOf("") }
    var newEnd by remember { mutableStateOf("") }
    var newReason by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) {
        (apiCall { ApiClient.wallet.balance() } as? ApiResult.Ok)?.data?.let { currency = it.currencyCode.orEmpty() }
        val result = apiCall { ApiClient.availability.show() }
        if (result is ApiResult.Ok) {
            val a = result.data
            accepting = a.acceptingRequests
            pausedUntil = a.pausedUntil.orEmpty()
            pauseReason = a.pauseReason.orEmpty()
            rateType = a.rate.type
            rateMin = a.rate.minMinor?.let { formatMinor(it, null) }.orEmpty()
            rateMax = a.rate.maxMinor?.let { formatMinor(it, null) }.orEmpty()
            days.forEach { d ->
                val h = a.weeklyHours.firstOrNull { it.day == d.day }
                d.on = h != null
                if (h != null) { d.from = h.from; d.to = h.to }
            }
            timeOff.clear()
            timeOff.addAll(a.timeOff)
            loaded = true
        } else error = result.errorText(offlineText) ?: genericError
    }

    fun save() {
        error = null
        notice = null
        val min = parseMinor(rateMin)
        val max = rateMax.takeIf { it.isNotBlank() }?.let { parseMinor(it) }
        if (rateType != "none" && (min == null || min <= 0 || (rateMax.isNotBlank() && (max == null || max < min)))) {
            error = badMoney
            return
        }
        val on = days.filter { it.on }
        if (on.any { !TIME_SHAPE.matches(it.from) || !TIME_SHAPE.matches(it.to) || it.to <= it.from }) {
            error = badHours
            return
        }
        busy = true
        scope.launch {
            val result = apiCall {
                ApiClient.availability.update(
                    AvailabilityRequest(
                        acceptingRequests = accepting,
                        pausedUntil = if (accepting) null else pausedUntil.ifBlank { null },
                        pauseReason = if (accepting) null else pauseReason.trim().ifBlank { null },
                        rateType = rateType,
                        rateMinMinor = if (rateType == "none") null else min,
                        rateMaxMinor = if (rateType == "none") null else max,
                        weeklyHours = on.map { WeeklyHoursDto(it.day, it.from, it.to) },
                    ),
                )
            }
            if (result is ApiResult.Ok) notice = savedText else error = result.errorText(offlineText) ?: genericError
            busy = false
        }
    }

    fun addTimeOff() {
        error = null
        scope.launch {
            val result = apiCall { ApiClient.availability.addTimeOff(TimeOffRequest(newStart, newEnd, newReason.trim().ifBlank { null })) }
            if (result is ApiResult.Ok) {
                timeOff.add(result.data)
                timeOff.sortBy { it.startsOn }
                newStart = ""; newEnd = ""; newReason = ""
            } else error = result.errorText(offlineText) ?: genericError
        }
    }

    fun removeTimeOff(period: TimeOffDto) {
        scope.launch {
            val failed = apiDone(offlineText, genericError) { ApiClient.availability.deleteTimeOff(period.id) }
            if (failed == null) timeOff.remove(period) else error = failed
        }
    }

    Page(title = stringResource(R.string.availability_title), onBack = onBack) {
        // The pause: no new offers while it is on.
        Card {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.availability_accepting), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                    Text(stringResource(R.string.availability_accepting_sub), color = settingsMut(), fontSize = 12.sp)
                }
                RedToggle(on = accepting, onClick = { accepting = !accepting })
            }
            if (!accepting) {
                DateField(pausedUntil, { pausedUntil = it }, label = stringResource(R.string.availability_back_on), modifier = Modifier.fillMaxWidth())
                DorrTextField(pauseReason, { pauseReason = it.take(200) }, label = stringResource(R.string.availability_pause_reason), icon = Icons.Rounded.Notes, modifier = Modifier.fillMaxWidth())
            }
        }

        // The guide price.
        Card {
            FieldLabel(stringResource(R.string.availability_rate))
            ChipRow(
                options = listOf("none" to stringResource(R.string.availability_rate_none), "hourly" to stringResource(R.string.availability_rate_hourly), "fixed" to stringResource(R.string.availability_rate_fixed)),
                selected = setOf(rateType),
                onToggle = { rateType = it },
            )
            if (rateType != "none") {
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    DorrTextField(rateMin, { rateMin = it }, label = stringResource(R.string.availability_rate_from, currency), icon = Icons.Rounded.Payments, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.weight(1f))
                    DorrTextField(rateMax, { rateMax = it }, label = stringResource(R.string.availability_rate_to, currency), icon = Icons.Rounded.Payments, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.weight(1f))
                }
            }
        }

        // The weekly hours.
        Card {
            FieldLabel(stringResource(R.string.availability_hours))
            days.forEach { d ->
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    RedToggle(on = d.on, onClick = { d.on = !d.on })
                    Text(stringResource(DAY_NAMES[d.day]), color = settingsInk(), fontSize = 13.sp, modifier = Modifier.weight(1f))
                    if (d.on) {
                        DorrTextField(d.from, { d.from = it.take(5) }, error = !TIME_SHAPE.matches(d.from), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.weight(1f))
                        DorrTextField(d.to, { d.to = it.take(5) }, error = !TIME_SHAPE.matches(d.to) || d.to <= d.from, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.weight(1f))
                    }
                }
            }
        }

        SettingsPrimaryButton(text = stringResource(R.string.profile_save), enabled = loaded, loading = busy, onClick = ::save)
        MessageToast(notice)

        // Time off: added and removed at once.
        Card {
            FieldLabel(stringResource(R.string.availability_time_off))
            if (timeOff.isEmpty()) Text(stringResource(R.string.availability_time_off_none), color = settingsMut(), fontSize = 12.sp)
            timeOff.forEach { t ->
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    Column(Modifier.weight(1f)) {
                        Text("‎${t.startsOn}  →  ${t.endsOn}", color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                        t.reason?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
                    }
                    Text(
                        stringResource(R.string.common_delete), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clickable { removeTimeOff(t) }.padding(6.dp),
                    )
                }
            }
            DateField(newStart, { newStart = it }, label = stringResource(R.string.availability_from), modifier = Modifier.fillMaxWidth())
            DateField(newEnd, { newEnd = it }, label = stringResource(R.string.availability_to), modifier = Modifier.fillMaxWidth())
            DorrTextField(newReason, { newReason = it.take(200) }, label = stringResource(R.string.availability_pause_reason), modifier = Modifier.fillMaxWidth())
            SettingsPrimaryButton(text = stringResource(R.string.availability_add_time_off), enabled = newStart.isNotEmpty() && newEnd.isNotEmpty() && newEnd >= newStart, onClick = ::addTimeOff)
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}
