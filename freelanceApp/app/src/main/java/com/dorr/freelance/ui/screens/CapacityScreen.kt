package com.dorr.freelance.ui.screens

import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.People
import androidx.compose.material.icons.rounded.Remove
import androidx.compose.material.icons.rounded.Speed
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.CapacityDto
import com.dorr.freelance.net.CapacityRequest
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.WaitlistEntryDto
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.components.SkeletonDetail
import com.dorr.freelance.ui.kit.RedToggle
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsSurface
import kotlinx.coroutines.launch
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

private fun whenText(iso: String?): String = runCatching {
    OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("EEE d MMM, HH:mm"))
}.getOrDefault("")

/**
 * FL-L10, «my capacity»: how many projects I take at once (only running work counts), how full I am now, and who waits in line for my
 * services. Read when the page opens, when the connection returns and when a notice arrives (someone joins the line, work ends) — never
 * on a timer.
 */
@Composable
fun CapacityScreen(onBack: () -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var data by remember { mutableStateOf<CapacityDto?>(null) }
    var failure by remember { mutableStateOf<String?>(null) }
    var limited by remember { mutableStateOf(false) }
    var draft by remember { mutableIntStateOf(3) }
    var saving by remember { mutableStateOf(false) }
    var saved by remember { mutableStateOf(false) }

    fun apply(d: CapacityDto) {
        data = d
        limited = d.max != null
        draft = d.max ?: draft.coerceAtLeast(maxOf(1, d.running))
    }

    suspend fun load() {
        when (val r = apiCall { ApiClient.capacity.show() }) {
            is ApiResult.Ok -> { apply(r.data); failure = null }
            else -> if (data == null) failure = r.errorText(offlineText) ?: genericError
        }
    }
    LaunchedEffect(reconnect) { load() }
    LaunchedEffect(Unit) { Realtime.changed.collect { load() } }

    Page(title = stringResource(R.string.cap_title), onBack = onBack) {
        val d = data
        when {
            d == null && failure != null -> EmptyState(Icons.Rounded.Speed, stringResource(R.string.cap_title), failure.orEmpty(), Modifier.padding(top = 70.dp))
            d == null -> SkeletonDetail()
            else -> {
                Gauge(d)
                Column(
                    Modifier.fillMaxWidth().widthIn(max = 520.dp).settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp),
                    verticalArrangement = Arrangement.spacedBy(14.dp),
                ) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(stringResource(R.string.cap_limit_title), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                            Text(stringResource(R.string.cap_limit_sub), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
                        }
                        RedToggle(on = limited, onClick = { limited = !limited; saved = false })
                    }
                    if (limited) {
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.Center) {
                            StepButton(Icons.Rounded.Remove, enabled = draft > 1) { draft -= 1; saved = false }
                            Column(Modifier.padding(horizontal = 26.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                                Text("$draft", color = settingsAccent(), fontSize = 40.sp, fontWeight = FontWeight.ExtraBold)
                                Text(stringResource(R.string.cap_projects_at_once), color = settingsMut(), fontSize = 12.sp)
                            }
                            StepButton(Icons.Rounded.Add, enabled = draft < d.maxChoice) { draft += 1; saved = false }
                        }
                        if (draft < d.running) Text(stringResource(R.string.cap_below_running, d.running), color = Color(0xFFD97706), fontSize = 12.sp, lineHeight = 17.sp)
                    }
                    SettingsPrimaryButton(
                        text = stringResource(if (saved) R.string.cap_saved else R.string.cap_save), loading = saving,
                        enabled = !saved && (limited != (d.max != null) || (limited && draft != d.max)),
                        onClick = {
                            saving = true
                            scope.launch {
                                when (val r = apiCall { ApiClient.capacity.update(CapacityRequest(if (limited) draft else null)) }) {
                                    is ApiResult.Ok -> { apply(r.data); saved = true; failure = null }
                                    else -> failure = r.errorText(offlineText) ?: genericError
                                }
                                saving = false
                            }
                        },
                    )
                }
                Line(d)
            }
        }
        ErrorBanner(if (data != null) failure else null, Modifier.fillMaxWidth())
    }
}

/** How full I am: a bar of the running projects and the places kept, against my cap. */
@Composable
private fun Gauge(d: CapacityDto) {
    val accent = settingsAccent()
    val max = d.max
    val used = d.running + d.holds
    val fill by animateFloatAsState(if (max == null || max == 0) 0f else (used.toFloat() / max).coerceIn(0f, 1f), tween(800), label = "capGauge")
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).clip(RoundedCornerShape(24.dp)).background(Brush.linearGradient(listOf(accent, accent.copy(alpha = 0.72f)))).padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Text(
            when {
                max == null -> stringResource(R.string.cap_state_open)
                d.full -> stringResource(R.string.cap_state_full)
                else -> stringResource(R.string.cap_state_room, max - used)
            },
            color = Color.White, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold,
        )
        Text(stringResource(R.string.cap_running_line, d.running), color = Color.White.copy(alpha = 0.9f), fontSize = 13.sp)
        if (max != null) {
            Box(Modifier.fillMaxWidth().height(10.dp).clip(RoundedCornerShape(99.dp)).background(Color.White.copy(alpha = 0.25f))) {
                Box(Modifier.fillMaxWidth(fill).fillMaxHeight().clip(RoundedCornerShape(99.dp)).background(Color.White))
            }
            Text(stringResource(R.string.cap_used_of, used, max), color = Color.White.copy(alpha = 0.9f), fontSize = 12.sp)
        }
    }
}

@Composable
private fun StepButton(icon: androidx.compose.ui.graphics.vector.ImageVector, enabled: Boolean, onClick: () -> Unit) {
    val tone = if (enabled) settingsAccent() else settingsMut().copy(alpha = 0.4f)
    Box(
        Modifier.size(48.dp).clip(CircleShape).border(1.5.dp, tone, CircleShape).clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, null, tint = tone, modifier = Modifier.size(24.dp)) }
}

/** The clients in line for my services: those holding a freed place first, then the rest by when they joined. */
@Composable
private fun Line(d: CapacityDto) {
    Row(Modifier.fillMaxWidth().widthIn(max = 520.dp).padding(top = 4.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Icon(Icons.Rounded.People, null, tint = settingsInk(), modifier = Modifier.size(20.dp))
        Text(stringResource(R.string.cap_line_title), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
        if (d.line.isNotEmpty()) SvcPill("${d.line.size}", serviceTone("awaiting"))
    }
    if (d.line.isEmpty()) {
        Text(stringResource(R.string.cap_line_empty), color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp, modifier = Modifier.fillMaxWidth().widthIn(max = 520.dp))
        return
    }
    d.line.forEach { LineRow(it) }
}

@Composable
private fun LineRow(e: WaitlistEntryDto) {
    val holding = e.status == "offered"
    Row(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).clip(RoundedCornerShape(18.dp)).settingsSurface(RoundedCornerShape(18.dp)).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.size(40.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            if (holding) Icon(Icons.Rounded.HourglassTop, null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
            else Text("${e.position ?: ""}", color = settingsAccent(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(e.client.name.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
            Text(e.service.title.orEmpty(), color = settingsMut(), fontSize = 12.sp, maxLines = 1)
            if (holding) Text(stringResource(R.string.cap_holding_until, whenText(e.holdUntil)), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        }
        if (holding) SvcPill(stringResource(R.string.cap_holding), serviceTone("active"))
    }
}
