@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.dorr.app.ui.screens.marketing

import androidx.annotation.StringRes
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowLeft
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Campaign
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.EventAvailable
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material.icons.rounded.Link
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.Icon
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MkCalendarRequest
import com.dorr.app.network.MkClaimDto
import com.dorr.app.network.MkContentDto
import com.dorr.app.network.MkDoneRequest
import com.dorr.app.network.MkJobDto
import com.dorr.app.network.MkLockRequest
import com.dorr.app.network.MkNoteRequest
import com.dorr.app.network.MkOutcomeRequest
import com.dorr.app.network.MkProjectDto
import com.dorr.app.network.MkScheduleRequest
import com.dorr.app.network.MkStudioRequest
import com.dorr.app.network.MkVariantDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.calendar.CalDatePickerDialog
import com.dorr.app.ui.screens.calendar.CalTimePickerDialog
import com.dorr.app.ui.screens.chat.ChSheet
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaIconWell
import com.dorr.app.ui.screens.wallet.WaNote
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise
import com.dorr.app.ui.screens.watchearn.WeChip
import com.dorr.app.ui.screens.watchearn.WeConfirmDialog
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.google.gson.JsonPrimitive
import kotlinx.coroutines.launch
import java.time.DayOfWeek
import java.time.LocalDate
import java.time.LocalTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.TextStyle
import java.time.temporal.TemporalAdjusters
import java.util.Locale

// =============================================================================== shared labels

internal val MK_CHANNELS = listOf("instagram", "tiktok", "snapchat", "x", "facebook", "youtube", "google", "linkedin", "whatsapp", "email", "sms", "website", "offline", "other")
internal val MK_FORMATS = listOf("image", "video", "story", "reel", "carousel", "text")

@StringRes
internal fun mkChannelLabel(channel: String): Int = when (channel) {
    "instagram" -> R.string.mktg_ch_instagram
    "tiktok" -> R.string.mktg_ch_tiktok
    "snapchat" -> R.string.mktg_ch_snapchat
    "x" -> R.string.mktg_ch_x
    "facebook" -> R.string.mktg_ch_facebook
    "youtube" -> R.string.mktg_ch_youtube
    "google" -> R.string.mktg_ch_google
    "linkedin" -> R.string.mktg_ch_linkedin
    "whatsapp" -> R.string.mktg_ch_whatsapp
    "email" -> R.string.mktg_ch_email
    "sms" -> R.string.mktg_ch_sms
    "website" -> R.string.mktg_ch_website
    "offline" -> R.string.mktg_ch_offline
    else -> R.string.mktg_ch_other
}

@StringRes
internal fun mkFormatLabel(format: String?): Int = when (format) {
    "video" -> R.string.mktg_fmt_video
    "story" -> R.string.mktg_fmt_story
    "reel" -> R.string.mktg_fmt_reel
    "carousel" -> R.string.mktg_fmt_carousel
    "text" -> R.string.mktg_fmt_text
    else -> R.string.mktg_fmt_image
}

@StringRes
internal fun mkContentKindLabel(kind: String): Int = when (kind) {
    "ad" -> R.string.mktg_kind_ad
    "task" -> R.string.mktg_kind_task
    else -> R.string.mktg_kind_post
}

@StringRes
internal fun mkClaimLabel(code: String): Int = when (code) {
    "guaranteed_result" -> R.string.mktg_claim_guaranteed_result
    "absolute_claim" -> R.string.mktg_claim_absolute_claim
    "number_needs_proof" -> R.string.mktg_claim_number_needs_proof
    "price_claim" -> R.string.mktg_claim_price_claim
    "too_long_for_channel" -> R.string.mktg_claim_too_long_for_channel
    "headline_too_long" -> R.string.mktg_claim_headline_too_long
    else -> R.string.mktg_claim_empty_text
}

/** The local date-time of a server ISO time. */
internal fun mkLocal(iso: String?): java.time.LocalDateTime? =
    iso?.let { runCatching { OffsetDateTime.parse(it).atZoneSameInstant(ZoneId.systemDefault()).toLocalDateTime() }.getOrNull() }

internal fun mkTime(iso: String?): String = mkLocal(iso)?.toLocalTime()?.format(DateTimeFormatter.ofPattern("HH:mm")) ?: ""

internal fun mkDateTime(iso: String?): String {
    val local = mkLocal(iso) ?: return ""
    return local.toLocalDate().format(DateTimeFormatter.ofPattern("EEE d MMM", Locale.getDefault())) + " · " + local.toLocalTime().format(DateTimeFormatter.ofPattern("HH:mm"))
}

/** A server ISO time from a local date and time. */
internal fun mkIso(date: LocalDate, time: LocalTime): String =
    date.atTime(time).atZone(ZoneId.systemDefault()).toOffsetDateTime().format(DateTimeFormatter.ISO_OFFSET_DATE_TIME)

internal val MkAmber = Color(0xFFD97706)
internal val MkBlue = Color(0xFF2563EB)

@Composable
internal fun MkChannelTag(channel: String) {
    MkSmallTag(stringResource(mkChannelLabel(channel)), MkBlue)
}

private val CONTENT_STATUS = mapOf(
    "DRAFT" to (R.string.mktg_cs_DRAFT to Color(0xFF6B7280)),
    "POLICY_REVIEW" to (R.string.mktg_cs_POLICY_REVIEW to Color(0xFFD97706)),
    "APPROVED" to (R.string.mktg_cs_APPROVED to Color(0xFF16A34A)),
    "SCHEDULED" to (R.string.mktg_cs_SCHEDULED to Color(0xFF2563EB)),
    "PUBLISHED" to (R.string.mktg_cs_PUBLISHED to Color(0xFF16A34A)),
    "FAILED" to (R.string.mktg_cs_FAILED to Color(0xFFDC2626)),
    "REJECTED" to (R.string.mktg_cs_REJECTED to Color(0xFFDC2626)),
    "OPEN" to (R.string.mktg_cs_OPEN to Color(0xFF6B7280)),
    "DONE" to (R.string.mktg_cs_DONE to Color(0xFF16A34A)),
)

@Composable
internal fun MkContentStatus(status: String) {
    val (label, color) = CONTENT_STATUS[status] ?: (R.string.mktg_cs_DRAFT to Color(0xFF6B7280))
    MkBadge(stringResource(label), color)
}

// =============================================================================== calendar (U06)

/**
 * The daily calendar: a week strip, the chosen day's posts, ads and tasks, and the AI that fills the next days from
 * the approved plan. Reloads once per `marketing.job.changed` of this project and on reconnect — never on a timer.
 */
@Composable
fun MarketingCalendar(projectId: String) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val reconnect = collectReconnectTick()
    val firstDay = remember { java.time.temporal.WeekFields.of(Locale.getDefault()).firstDayOfWeek }

    var day by remember { mutableStateOf(LocalDate.now()) }
    val weekStart = day.with(TemporalAdjusters.previousOrSame(firstDay))
    var items by remember { mutableStateOf<List<MkContentDto>?>(null) }
    var unplanned by remember { mutableIntStateOf(0) }
    var project by remember { mutableStateOf<MkProjectDto?>(null) }
    var jobs by remember { mutableStateOf<List<MkJobDto>>(emptyList()) }
    var reload by remember { mutableIntStateOf(0) }
    var generating by remember { mutableStateOf(false) }
    var adding by remember { mutableStateOf(false) }
    val relevantChange = if (host.changedProject == null || host.changedProject == projectId) host.changes else -1

    LaunchedEffect(projectId, weekStart, reload, reconnect, relevantChange) {
        runCatching {
            val api = ApiClient.marketing
            if (project == null) project = api.project(mkAuth(), projectId).data
            val cal = api.calendar(mkAuth(), projectId, weekStart.toString(), weekStart.plusDays(6).toString()).data
            items = cal?.items.orEmpty()
            unplanned = cal?.unplanned ?: 0
            jobs = api.jobs(mkAuth(), projectId).data.orEmpty()
        }.onFailure { host.fail(it, generic) }
    }

    val canEdit = project?.abilities?.edit == true && project?.status == "active"
    val running = jobs.firstOrNull { it.type == "calendar" && !it.finished }
    val lastFailed = jobs.firstOrNull { it.type == "calendar" }?.takeIf { it.status == "FAILED" }

    WaPage2(title = stringResource(R.string.mktg_calendar)) {
        // Week switcher + day strip.
        WeekStrip(weekStart, day, items.orEmpty(), onPick = { day = it }, onWeek = { delta -> day = day.plusWeeks(delta.toLong()) })

        if (running != null) {
            Spacer(Modifier.height(12.dp))
            WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
                Text(stringResource(R.string.mktg_calendar_writing), color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(8.dp))
                MkBar(running.progressPct / 100f, MkBlue)
            }
        } else if (lastFailed != null) {
            Spacer(Modifier.height(12.dp))
            WaNote(stringResource(R.string.mktg_job_failed, lastFailed.errorCode.orEmpty()))
        }

        if (canEdit) {
            Spacer(Modifier.height(12.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
                WaButton(stringResource(R.string.mktg_calendar_generate), { generating = true }, icon = Icons.Rounded.AutoAwesome, compact = true, enabled = running == null, modifier = Modifier.weight(1f))
                WaButton(stringResource(R.string.mktg_add), { adding = true }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Add, compact = true)
            }
        }

        val dayItems = items.orEmpty().filter { mkLocal(it.plannedAt)?.toLocalDate() == day }
        WaSectionTitle(day.format(DateTimeFormatter.ofPattern("EEEE d MMMM", Locale.getDefault())))
        when {
            items == null -> Column(verticalArrangement = Arrangement.spacedBy(10.dp)) { repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(76.dp), RoundedCornerShape(20.dp)) } }
            dayItems.isEmpty() -> WaEmpty(Icons.Rounded.EventAvailable, Tone.Gray, stringResource(R.string.mktg_calendar_empty), stringResource(if (canEdit) R.string.mktg_calendar_empty_sub else R.string.mktg_calendar_empty_view))
            else -> Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                dayItems.forEachIndexed { i, item ->
                    ContentRow(item, Modifier.waRise(i), canTick = canEdit, onTick = { done ->
                        scope.launch {
                            runCatching { ApiClient.marketing.taskDone(mkAuth(), item.id, MkDoneRequest(done)) }
                                .onSuccess { reload++ }
                                .onFailure { host.fail(it, generic) }
                        }
                    }) { host.push(MkRoute.Content(projectId, item.id)) }
                }
            }
        }
        if (unplanned > 0) {
            Spacer(Modifier.height(12.dp))
            Text(stringResource(R.string.mktg_unplanned, unplanned), color = Wa.Mut, fontSize = 12.5.sp, modifier = Modifier.padding(horizontal = 4.dp))
        }
    }

    if (generating) {
        GenerateCalendarSheet(projectId, onDismiss = { generating = false }) { generating = false; reload++ }
    }
    if (adding) {
        AddItemSheet(projectId, day, onDismiss = { adding = false }) { created ->
            adding = false
            reload++
            if (!created.isTask) host.push(MkRoute.Content(projectId, created.id))
        }
    }
}

@Composable
private fun WeekStrip(weekStart: LocalDate, selected: LocalDate, items: List<MkContentDto>, onPick: (LocalDate) -> Unit, onWeek: (Int) -> Unit) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val busyDays = items.mapNotNull { mkLocal(it.plannedAt)?.toLocalDate() }.groupingBy { it }.eachCount()
    val today = LocalDate.now()
    WaCard(Modifier.fillMaxWidth().waRise(0), padding = 12.dp) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(36.dp).clip(CircleShape).clickable { onWeek(-1) }, contentAlignment = Alignment.Center) {
                Icon(if (rtl) Icons.AutoMirrored.Rounded.KeyboardArrowRight else Icons.AutoMirrored.Rounded.KeyboardArrowLeft, stringResource(R.string.mktg_prev_week), tint = Wa.Ink)
            }
            val end = weekStart.plusDays(6)
            Text(
                weekStart.format(DateTimeFormatter.ofPattern("d MMM", Locale.getDefault())) + " – " + end.format(DateTimeFormatter.ofPattern("d MMM yyyy", Locale.getDefault())),
                color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center, modifier = Modifier.weight(1f),
            )
            Box(Modifier.size(36.dp).clip(CircleShape).clickable { onWeek(1) }, contentAlignment = Alignment.Center) {
                Icon(if (rtl) Icons.AutoMirrored.Rounded.KeyboardArrowLeft else Icons.AutoMirrored.Rounded.KeyboardArrowRight, stringResource(R.string.mktg_next_week), tint = Wa.Ink)
            }
        }
        Spacer(Modifier.height(10.dp))
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
            (0..6).forEach { offset ->
                val date = weekStart.plusDays(offset.toLong())
                val isSel = date == selected
                val bg by animateColorAsState(if (isSel) Wa.Red else Color.Transparent, tween(200), label = "day")
                Column(
                    Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(bg)
                        .then(if (date == today && !isSel) Modifier.border(1.dp, Wa.Red.copy(alpha = 0.4f), RoundedCornerShape(14.dp)) else Modifier)
                        .clickable { onPick(date) }.padding(vertical = 8.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Text(date.dayOfWeek.getDisplayName(TextStyle.SHORT, Locale.getDefault()), color = if (isSel) Color.White else Wa.Mut, fontSize = 10.5.sp, maxLines = 1)
                    Text(date.dayOfMonth.toString(), color = if (isSel) Color.White else Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                    Box(Modifier.padding(top = 3.dp).size(5.dp).clip(CircleShape).background(if ((busyDays[date] ?: 0) > 0) (if (isSel) Color.White else Wa.Red) else Color.Transparent))
                }
            }
        }
    }
}

/** One item of the calendar or a list: kind icon, time, channel, title, status; tasks get a tick box. */
@Composable
internal fun ContentRow(item: MkContentDto, modifier: Modifier = Modifier, canTick: Boolean = false, onTick: (Boolean) -> Unit = {}, onClick: () -> Unit) {
    WaCard(modifier.fillMaxWidth().clickable(onClick = onClick), padding = 14.dp) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            if (item.isTask) {
                val done = item.status == "DONE"
                Icon(
                    if (done) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked,
                    stringResource(if (done) R.string.mktg_task_reopen else R.string.mktg_task_done),
                    tint = if (done) Wa.Green else Wa.Soft,
                    modifier = Modifier.size(30.dp).clip(CircleShape).clickable(enabled = canTick) { onTick(!done) }.padding(3.dp),
                )
            } else {
                WaIconWell(if (item.kind == "ad") Icons.Rounded.Campaign else Icons.Rounded.Image, if (item.kind == "ad") Tone.Pink else Tone.Blue, size = 40.dp, iconSize = 20.dp)
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(
                    item.title, color = if (item.status == "DONE") Wa.Mut else Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis,
                    textDecoration = if (item.status == "DONE") androidx.compose.ui.text.style.TextDecoration.LineThrough else null,
                )
                Spacer(Modifier.height(4.dp))
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    val time = mkTime(item.plannedAt)
                    if (time.isNotBlank()) Text(time, color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    MkChannelTag(item.channel)
                    if (item.hasBlockingIssues) Icon(Icons.Rounded.WarningAmber, stringResource(R.string.mktg_has_blocking), tint = Wa.Danger, modifier = Modifier.size(16.dp))
                }
            }
            if (!item.isTask) {
                Spacer(Modifier.width(8.dp))
                MkContentStatus(item.status)
            }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun GenerateCalendarSheet(projectId: String, onDismiss: () -> Unit, onStarted: () -> Unit) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val started = stringResource(R.string.mktg_calendar_started)
    var days by remember { mutableIntStateOf(7) }
    val channels = remember { mutableStateListOf<String>() }
    var replace by remember { mutableStateOf(true) }
    var busy by remember { mutableStateOf(false) }
    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).navigationBarsPadding().padding(horizontal = 20.dp).padding(bottom = 20.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(stringResource(R.string.mktg_calendar_generate), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.mktg_calendar_generate_sub), color = Wa.Mut, fontSize = 13.sp, lineHeight = 19.sp)
            Text(stringResource(R.string.mktg_days), color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf(7, 14, 30).forEach { d -> WeChip(stringResource(R.string.mktg_n_days, d), selected = days == d, onClick = { days = d }) }
            }
            Text(stringResource(R.string.mktg_channels_optional), color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                MK_CHANNELS.filter { it != "offline" && it != "other" }.forEach { ch ->
                    WeChip(stringResource(mkChannelLabel(ch)), selected = ch in channels, onClick = { if (ch in channels) channels.remove(ch) else if (channels.size < 8) channels.add(ch) })
                }
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.mktg_replace_drafts), color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold)
                    Text(stringResource(R.string.mktg_replace_drafts_sub), color = Wa.Mut, fontSize = 12.sp, lineHeight = 17.sp)
                }
                Switch(replace, { replace = it }, colors = SwitchDefaults.colors(checkedTrackColor = Wa.Red))
            }
            WaButton(
                stringResource(R.string.mktg_calendar_generate),
                onClick = {
                    busy = true
                    scope.launch {
                        runCatching {
                            ApiClient.marketing.generateCalendar(
                                mkAuth(), projectId,
                                MkCalendarRequest(days = days, channels = channels.toList().ifEmpty { null }, replaceDrafts = replace, timezone = ZoneId.systemDefault().id),
                            )
                        }.onSuccess { host.showToast(started); onStarted() }
                            .onFailure { host.fail(it, generic) }
                        busy = false
                    }
                },
                icon = Icons.Rounded.AutoAwesome, loading = busy,
            )
        }
    }
}

/** A post, an ad or a task written by hand for the chosen day. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun AddItemSheet(projectId: String, day: LocalDate, onDismiss: () -> Unit, onCreated: (MkContentDto) -> Unit) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    var kind by remember { mutableStateOf("post") }
    var channel by remember { mutableStateOf("instagram") }
    var title by remember { mutableStateOf("") }
    var body by remember { mutableStateOf("") }
    var time by remember { mutableStateOf(LocalTime.of(19, 0)) }
    var pickTime by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding().padding(horizontal = 20.dp).padding(bottom = 20.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(stringResource(R.string.mktg_add_item), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("post", "ad", "task").forEach { k -> WeChip(stringResource(mkContentKindLabel(k)), selected = kind == k, onClick = { kind = k; if (k == "task") channel = "offline" else if (channel == "offline") channel = "instagram" }) }
            }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                MK_CHANNELS.filter { kind == "task" || it != "offline" }.forEach { ch -> WeChip(stringResource(mkChannelLabel(ch)), selected = channel == ch, onClick = { channel = ch }) }
            }
            DorrTextField(value = title, onValueChange = { title = it.take(160) }, label = stringResource(R.string.mktg_item_title))
            if (kind != "task") {
                DorrTextField(value = body, onValueChange = { body = it.take(2200) }, label = stringResource(R.string.mktg_body), singleLine = false, minLines = 3, minHeight = 96.dp)
            }
            DorrTextField(
                value = day.format(DateTimeFormatter.ofPattern("EEE d MMM", Locale.getDefault())) + " · " + time.format(DateTimeFormatter.ofPattern("HH:mm")),
                onValueChange = {}, label = stringResource(R.string.mktg_when), icon = Icons.Rounded.Schedule, onClick = { pickTime = true },
            )
            WaButton(
                stringResource(R.string.mktg_save),
                onClick = {
                    busy = true
                    scope.launch {
                        val payload = JsonObject().apply {
                            addProperty("kind", kind)
                            addProperty("channel", channel)
                            addProperty("title", title.trim())
                            addProperty("planned_at", mkIso(day, time))
                            if (kind != "task") {
                                add("variants", JsonArray().apply { add(JsonObject().apply { addProperty("body", body.trim()) }) })
                            }
                        }
                        runCatching { ApiClient.marketing.createContent(mkAuth(), projectId, payload).data }
                            .onSuccess { if (it != null) onCreated(it) }
                            .onFailure { host.fail(it, generic) }
                        busy = false
                    }
                },
                enabled = title.trim().length >= 2 && (kind == "task" || body.isNotBlank()), loading = busy,
            )
        }
    }
    if (pickTime) CalTimePickerDialog(time, onDismiss = { pickTime = false }) { time = it; pickTime = false }
}

// =============================================================================== one item (U07 review)

/**
 * One post, ad or task: the A/B variants, the claims review, and the steps its state allows — send for review,
 * approve this exact wording (owner or manager), return with a note, schedule, then "I posted it". DORR never posts.
 */
@Composable
fun MarketingContentPage(projectId: String, itemId: String) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val clipboard = LocalClipboardManager.current
    val copied = stringResource(R.string.mktg_copied)
    var item by remember { mutableStateOf<MkContentDto?>(null) }
    var project by remember { mutableStateOf<MkProjectDto?>(null) }
    var variant by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf<String?>(null) }
    var editing by remember { mutableStateOf(false) }
    var returning by remember { mutableStateOf(false) }
    var posting by remember { mutableStateOf<Boolean?>(null) }
    var scheduling by remember { mutableStateOf(false) }
    var deleting by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }

    LaunchedEffect(itemId, reload) {
        runCatching {
            if (project == null) project = ApiClient.marketing.project(mkAuth(), projectId).data
            item = ApiClient.marketing.content(mkAuth(), itemId).data
        }.onFailure { host.fail(it, generic) }
    }

    fun act(key: String, ok: String? = null, call: suspend () -> MkContentDto?) {
        busy = key
        scope.launch {
            runCatching { call() }
                .onSuccess { if (it != null) item = it; if (ok != null) host.showToast(ok) }
                .onFailure { host.fail(it, generic); reload++ }
            busy = null
        }
    }

    val c = item
    val abilities = project?.abilities
    val canEdit = abilities?.edit == true
    val sent = stringResource(R.string.mktg_sent_review)
    val approved = stringResource(R.string.mktg_approved_toast)
    val saved = stringResource(R.string.mktg_saved)

    WaPage2(title = c?.title ?: stringResource(mkContentKindLabel("post"))) {
        if (c == null) {
            repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(96.dp).padding(bottom = 12.dp), RoundedCornerShape(22.dp)) }
            return@WaPage2
        }

        // Header.
        WaCard(Modifier.fillMaxWidth().waRise(0), padding = 16.dp) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                WaIconWell(if (c.isTask) Icons.Rounded.TaskAlt else if (c.kind == "ad") Icons.Rounded.Campaign else Icons.Rounded.Image, if (c.kind == "ad") Tone.Pink else Tone.Blue, size = 44.dp, iconSize = 22.dp)
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(c.title, color = Wa.Ink, fontSize = 15.5.sp, fontWeight = FontWeight.ExtraBold)
                    val meta = listOfNotNull(
                        stringResource(mkContentKindLabel(c.kind)),
                        stringResource(mkChannelLabel(c.channel)),
                        c.format?.let { stringResource(mkFormatLabel(it)) },
                    ).joinToString(" · ")
                    Text(meta, color = Wa.Mut, fontSize = 12.5.sp)
                }
                MkContentStatus(c.status)
            }
            if (!c.plannedAt.isNullOrBlank()) {
                Spacer(Modifier.height(10.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.Schedule, null, tint = Wa.Mut, modifier = Modifier.size(16.dp))
                    Spacer(Modifier.width(6.dp))
                    Text(mkDateTime(c.plannedAt), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                }
            }
            if (!c.objective.isNullOrBlank()) {
                Spacer(Modifier.height(8.dp))
                Text(c.objective, color = Wa.Mut, fontSize = 13.sp, lineHeight = 19.sp)
            }
            if (c.costEstimateMinor != null) {
                Spacer(Modifier.height(6.dp))
                Text(stringResource(R.string.mktg_cost_estimate, mkMoney(c.costEstimateMinor, project?.currency.orEmpty())), color = Wa.Mut, fontSize = 12.5.sp)
            }
        }

        if (c.status == "REJECTED" && !c.reviewNote.isNullOrBlank()) {
            Spacer(Modifier.height(10.dp))
            WaNote(stringResource(R.string.mktg_returned_note, c.reviewNote))
        }
        if (c.status == "PUBLISHED" && !c.publishedUrl.isNullOrBlank()) {
            Spacer(Modifier.height(10.dp))
            WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.Link, null, tint = Wa.Green, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.width(8.dp))
                    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                        Text(c.publishedUrl, color = Wa.Ink, fontSize = 12.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
        }

        // Variants.
        if (!c.isTask && c.variants.isNotEmpty()) {
            val shown = c.variants.firstOrNull { it.key == (variant ?: c.selected) } ?: c.variants.first()
            WaSectionTitle(stringResource(R.string.mktg_variants), trailing = {
                Icon(
                    Icons.Rounded.ContentCopy, stringResource(R.string.mktg_copy), tint = Wa.Red,
                    modifier = Modifier.size(34.dp).clip(CircleShape).clickable {
                        clipboard.setText(AnnotatedString(variantText(shown)))
                        host.showToast(copied)
                    }.padding(7.dp),
                )
            })
            if (c.variants.size > 1) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.padding(bottom = 10.dp)) {
                    c.variants.forEach { v ->
                        WeChip(
                            stringResource(R.string.mktg_variant, v.key) + if (v.key == c.selected) " ✓" else "",
                            selected = v.key == shown.key, onClick = { variant = v.key },
                        )
                    }
                }
            }
            VariantCard(shown, c.claims.filter { it.variant == shown.key })
            if (canEdit && c.variants.size > 1 && shown.key != c.selected && c.status != "PUBLISHED") {
                Spacer(Modifier.height(8.dp))
                WaButton(
                    stringResource(R.string.mktg_use_variant, shown.key),
                    {
                        act("select", saved) {
                            ApiClient.marketing.updateContent(mkAuth(), c.id, JsonObject().apply { addProperty("lock_version", c.lockVersion); addProperty("selected", shown.key) }).data
                        }
                    },
                    style = WaButtonStyle.Ghost, compact = true, loading = busy == "select",
                )
            }
        }

        // Claims review.
        if (!c.isTask) {
            WaSectionTitle(stringResource(R.string.mktg_claims))
            ClaimsBlock(c.claims)
        }

        // Steps.
        Spacer(Modifier.height(18.dp))
        Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
            when {
                c.isTask -> if (canEdit) {
                    val done = c.status == "DONE"
                    WaButton(
                        stringResource(if (done) R.string.mktg_task_reopen else R.string.mktg_task_done),
                        { act("done") { ApiClient.marketing.taskDone(mkAuth(), c.id, MkDoneRequest(!done)).data } },
                        style = if (done) WaButtonStyle.Ghost else WaButtonStyle.Primary, icon = Icons.Rounded.TaskAlt, loading = busy == "done",
                    )
                }
                c.status in setOf("DRAFT", "REJECTED") && canEdit -> {
                    WaButton(
                        stringResource(R.string.mktg_send_review),
                        { act("submit", sent) { ApiClient.marketing.submitContent(mkAuth(), c.id).data } },
                        icon = Icons.AutoMirrored.Rounded.Send, enabled = !c.hasBlockingIssues, loading = busy == "submit",
                    )
                    if (c.hasBlockingIssues) Text(stringResource(R.string.mktg_fix_blocking), color = Wa.Danger, fontSize = 12.5.sp)
                }
                c.status == "POLICY_REVIEW" && abilities?.approve == true -> {
                    WaButton(
                        stringResource(R.string.mktg_approve_content),
                        { act("approve", approved) { ApiClient.marketing.approveContent(mkAuth(), c.id, MkLockRequest(c.lockVersion)).data } },
                        icon = Icons.Rounded.Verified, loading = busy == "approve",
                    )
                    WaButton(stringResource(R.string.mktg_return), { returning = true }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Undo)
                }
                c.status == "POLICY_REVIEW" -> WaNote(stringResource(R.string.mktg_waiting_review))
                c.status in setOf("APPROVED", "SCHEDULED") && canEdit -> {
                    WaButton(stringResource(R.string.mktg_mark_posted), { posting = true }, icon = Icons.Rounded.CheckCircle)
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        WaButton(stringResource(if (c.status == "SCHEDULED") R.string.mktg_reschedule else R.string.mktg_schedule), { scheduling = true }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Schedule, compact = true, modifier = Modifier.weight(1f))
                        WaButton(stringResource(R.string.mktg_not_posted), { posting = false }, style = WaButtonStyle.Ghost, compact = true, modifier = Modifier.weight(1f))
                    }
                    Text(stringResource(R.string.mktg_post_yourself), color = Wa.Mut, fontSize = 12.sp, lineHeight = 17.sp)
                }
            }
            if (canEdit && c.status != "PUBLISHED") {
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    WaButton(stringResource(R.string.mktg_edit), { editing = true }, style = WaButtonStyle.Quiet, icon = Icons.Rounded.Edit, compact = true, modifier = Modifier.weight(1f))
                    WaButton(stringResource(R.string.mktg_delete), { deleting = true }, style = WaButtonStyle.Quiet, icon = Icons.Rounded.Delete, compact = true, modifier = Modifier.weight(1f))
                }
                if (c.status in setOf("APPROVED", "SCHEDULED", "POLICY_REVIEW")) {
                    Text(stringResource(R.string.mktg_edit_resets), color = Wa.Soft, fontSize = 11.5.sp, lineHeight = 16.sp)
                }
            }
        }
    }

    val current = item
    if (editing && current != null) {
        EditContentSheet(current, onDismiss = { editing = false }) { updated -> editing = false; item = updated; host.showToast(saved) }
    }
    if (returning && current != null) {
        NoteSheet(stringResource(R.string.mktg_return), onDismiss = { returning = false }) { note ->
            returning = false
            act("return") { ApiClient.marketing.rejectContent(mkAuth(), current.id, MkNoteRequest(note.ifBlank { null })).data }
        }
    }
    posting?.let { published ->
        PostedSheet(published, onDismiss = { posting = null }) { url, note ->
            posting = null
            act("outcome", saved) { ApiClient.marketing.contentOutcome(mkAuth(), current!!.id, MkOutcomeRequest(published, url, note)).data }
        }
    }
    if (scheduling && current != null) {
        ScheduleFlow(mkLocal(current.plannedAt), onDismiss = { scheduling = false }) { iso ->
            scheduling = false
            act("schedule", saved) { ApiClient.marketing.scheduleContent(mkAuth(), current.id, MkScheduleRequest(iso)).data }
        }
    }
    if (deleting && current != null) {
        val deleted = stringResource(R.string.mktg_deleted)
        WeConfirmDialog(
            title = stringResource(R.string.mktg_delete), text = current.title, confirm = stringResource(R.string.mktg_delete), danger = true,
            onDismiss = { deleting = false },
            onConfirm = {
                deleting = false
                scope.launch {
                    runCatching { ApiClient.marketing.deleteContent(mkAuth(), current.id) }
                        .onSuccess { host.showToast(deleted); host.changed(projectId); host.pop() }
                        .onFailure { host.fail(it, generic) }
                }
            },
        )
    }
}

private fun variantText(v: MkVariantDto): String = listOfNotNull(v.headline, v.body, v.cta, v.hashtags.joinToString(" ").takeIf { it.isNotBlank() })
    .filter { it.isNotBlank() }.joinToString("\n\n")

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun VariantCard(v: MkVariantDto, claims: List<MkClaimDto>) {
    WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
        if (!v.headline.isNullOrBlank()) {
            Text(v.headline, color = Wa.Ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 22.sp)
            Spacer(Modifier.height(8.dp))
        }
        if (!v.body.isNullOrBlank()) {
            Text(v.body, color = Wa.Ink, fontSize = 14.sp, lineHeight = 22.sp)
        }
        if (!v.cta.isNullOrBlank()) {
            Spacer(Modifier.height(12.dp))
            Text(
                v.cta, color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.clip(RoundedCornerShape(99.dp)).background(Wa.Red).padding(horizontal = 16.dp, vertical = 8.dp),
            )
        }
        if (v.hashtags.isNotEmpty()) {
            Spacer(Modifier.height(10.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                v.hashtags.forEach { MkSmallTag(it, MkBlue) }
            }
        }
        if (!v.visualBrief.isNullOrBlank()) {
            Spacer(Modifier.height(12.dp))
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Wa.Field).padding(12.dp)) {
                Icon(Icons.Rounded.Image, null, tint = Wa.Mut, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Column {
                    Text(stringResource(R.string.mktg_visual_brief), color = Wa.Mut, fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
                    Text(v.visualBrief, color = Wa.Ink, fontSize = 13.sp, lineHeight = 19.sp)
                }
            }
        }
        if (claims.any { it.severity == "blocking" }) {
            Spacer(Modifier.height(10.dp))
            Text(stringResource(R.string.mktg_variant_blocked), color = Wa.Danger, fontSize = 12.sp, fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
internal fun ClaimsBlock(claims: List<MkClaimDto>) {
    if (claims.isEmpty()) {
        WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Verified, null, tint = Wa.Green, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.mktg_claims_clear), color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold)
            }
        }
        return
    }
    WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
        Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
            claims.forEach { claim ->
                val blocking = claim.severity == "blocking"
                Row(verticalAlignment = Alignment.Top) {
                    Icon(Icons.Rounded.WarningAmber, null, tint = if (blocking) Wa.Danger else MkAmber, modifier = Modifier.size(18.dp).padding(top = 1.dp))
                    Spacer(Modifier.width(8.dp))
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(mkClaimLabel(claim.code)), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, lineHeight = 19.sp)
                        val where = listOfNotNull(claim.variant?.let { stringResource(R.string.mktg_variant, it) }, claim.detail?.let { "«$it»" }).joinToString(" · ")
                        if (where.isNotBlank()) Text(where, color = Wa.Mut, fontSize = 12.sp)
                    }
                    MkSmallTag(stringResource(if (blocking) R.string.mktg_blocking else R.string.mktg_warning), if (blocking) Wa.Danger else MkAmber)
                }
            }
        }
    }
}

@Composable
private fun EditContentSheet(item: MkContentDto, onDismiss: () -> Unit, onSaved: (MkContentDto) -> Unit) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    var title by remember { mutableStateOf(item.title) }
    var objective by remember { mutableStateOf(item.objective.orEmpty()) }
    val drafts = remember { item.variants.map { mutableStateListOf(it.headline.orEmpty(), it.body.orEmpty(), it.cta.orEmpty()) } }
    var tab by remember { mutableIntStateOf(item.variants.indexOfFirst { it.key == item.selected }.coerceAtLeast(0)) }
    var busy by remember { mutableStateOf(false) }
    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding().padding(horizontal = 20.dp).padding(bottom = 20.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(stringResource(R.string.mktg_edit), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            DorrTextField(value = title, onValueChange = { title = it.take(160) }, label = stringResource(R.string.mktg_item_title))
            DorrTextField(value = objective, onValueChange = { objective = it.take(300) }, label = stringResource(R.string.mktg_objective))
            if (drafts.isNotEmpty()) {
                if (drafts.size > 1) {
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        item.variants.forEachIndexed { i, v -> WeChip(stringResource(R.string.mktg_variant, v.key), selected = tab == i, onClick = { tab = i }) }
                    }
                }
                val d = drafts[tab]
                DorrTextField(value = d[0], onValueChange = { d[0] = it.take(120) }, label = stringResource(R.string.mktg_headline))
                DorrTextField(value = d[1], onValueChange = { d[1] = it.take(2200) }, label = stringResource(R.string.mktg_body), singleLine = false, minLines = 4, minHeight = 120.dp)
                DorrTextField(value = d[2], onValueChange = { d[2] = it.take(60) }, label = stringResource(R.string.mktg_cta))
            }
            if (!item.isTask && item.status in setOf("APPROVED", "SCHEDULED", "POLICY_REVIEW")) WaNote(stringResource(R.string.mktg_edit_resets))
            WaButton(
                stringResource(R.string.mktg_save),
                onClick = {
                    busy = true
                    scope.launch {
                        val body = JsonObject().apply {
                            addProperty("lock_version", item.lockVersion)
                            addProperty("title", title.trim())
                            addProperty("objective", objective.trim().ifBlank { null })
                            if (!item.isTask && drafts.isNotEmpty()) {
                                add("variants", JsonArray().apply {
                                    item.variants.forEachIndexed { i, v ->
                                        add(JsonObject().apply {
                                            addProperty("headline", drafts[i][0].trim())
                                            addProperty("body", drafts[i][1].trim())
                                            addProperty("cta", drafts[i][2].trim())
                                            add("hashtags", JsonArray().apply { v.hashtags.forEach { add(JsonPrimitive(it)) } })
                                            v.visualBrief?.let { addProperty("visual_brief", it) }
                                        })
                                    }
                                })
                                item.selected?.let { addProperty("selected", it) }
                            }
                        }
                        runCatching { ApiClient.marketing.updateContent(mkAuth(), item.id, body).data }
                            .onSuccess { if (it != null) onSaved(it) }
                            .onFailure { host.fail(it, generic) }
                        busy = false
                    }
                },
                enabled = title.trim().length >= 2, loading = busy,
            )
        }
    }
}

@Composable
internal fun NoteSheet(title: String, onDismiss: () -> Unit, onSend: (String) -> Unit) {
    var note by remember { mutableStateOf("") }
    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().navigationBarsPadding().padding(horizontal = 20.dp).padding(bottom = 20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(title, color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            DorrTextField(value = note, onValueChange = { note = it.take(500) }, label = stringResource(R.string.mktg_note_optional), singleLine = false, minLines = 3, minHeight = 96.dp)
            WaButton(title, { onSend(note.trim()) })
        }
    }
}

@Composable
private fun PostedSheet(published: Boolean, onDismiss: () -> Unit, onSave: (String?, String?) -> Unit) {
    var url by remember { mutableStateOf("") }
    var note by remember { mutableStateOf("") }
    val validUrl = url.isBlank() || url.trim().startsWith("http")
    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().navigationBarsPadding().padding(horizontal = 20.dp).padding(bottom = 20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(stringResource(if (published) R.string.mktg_mark_posted else R.string.mktg_not_posted), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            if (published) {
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    DorrTextField(
                        value = url, onValueChange = { url = it.trim().take(500) }, label = stringResource(R.string.mktg_post_link), placeholder = "https://",
                        icon = Icons.Rounded.Link, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri), error = !validUrl,
                    )
                }
            }
            DorrTextField(value = note, onValueChange = { note = it.take(500) }, label = stringResource(R.string.mktg_note_optional), singleLine = false, minLines = 2, minHeight = 76.dp)
            WaButton(stringResource(R.string.mktg_save), { onSave(url.trim().ifBlank { null }, note.trim().ifBlank { null }) }, enabled = validUrl)
        }
    }
}

/** Date, then time; the time must be in the future (the server refuses a past one). */
@Composable
private fun ScheduleFlow(initial: java.time.LocalDateTime?, onDismiss: () -> Unit, onPick: (String) -> Unit) {
    val start = initial?.takeIf { it.isAfter(java.time.LocalDateTime.now()) } ?: java.time.LocalDateTime.now().plusHours(1).withMinute(0)
    var date by remember { mutableStateOf<LocalDate?>(null) }
    if (date == null) {
        CalDatePickerDialog(start.toLocalDate(), onDismiss = onDismiss) { picked ->
            date = if (picked.isBefore(LocalDate.now())) LocalDate.now() else picked
        }
    } else {
        CalTimePickerDialog(start.toLocalTime(), onDismiss = onDismiss) { time ->
            val chosen = date!!.atTime(time)
            val safe = if (chosen.isAfter(java.time.LocalDateTime.now().plusMinutes(1))) chosen else java.time.LocalDateTime.now().plusMinutes(5)
            onPick(mkIso(safe.toLocalDate(), safe.toLocalTime()))
        }
    }
}

// =============================================================================== ad studio (U07)

/** The ad studio: a brief in, A/B variants out (the AI writes, the person reviews). The new draft opens when ready. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun MarketingStudio(projectId: String) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val started = stringResource(R.string.mktg_studio_started)
    val reconnect = collectReconnectTick()
    var channel by remember { mutableStateOf("instagram") }
    var format by remember { mutableStateOf("image") }
    var kind by remember { mutableStateOf("ad") }
    var variants by remember { mutableIntStateOf(2) }
    var objective by remember { mutableStateOf("") }
    var brief by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var waiting by remember { mutableStateOf<String?>(null) }
    var jobs by remember { mutableStateOf<List<MkJobDto>>(emptyList()) }
    var drafts by remember { mutableStateOf<List<MkContentDto>?>(null) }
    var reload by remember { mutableIntStateOf(0) }
    val relevantChange = if (host.changedProject == null || host.changedProject == projectId) host.changes else -1

    LaunchedEffect(projectId, reload, reconnect, relevantChange) {
        runCatching {
            jobs = ApiClient.marketing.jobs(mkAuth(), projectId).data.orEmpty()
            drafts = ApiClient.marketing.contentList(mkAuth(), projectId, status = "DRAFT,POLICY_REVIEW,REJECTED", kind = null).data.orEmpty().filter { !it.isTask }
            val mine = waiting?.let { id -> jobs.firstOrNull { it.id == id } }
            if (mine != null && mine.finished) {
                waiting = null
                val created = mine.created?.firstOrNull()
                if (mine.status == "COMPLETED" && created != null) host.push(MkRoute.Content(projectId, created))
                else host.showToast(generic, error = true)
            }
        }.onFailure { host.fail(it, generic) }
    }
    val running = jobs.firstOrNull { it.type == "content" && !it.finished }

    WaPage2(
        title = stringResource(R.string.mktg_studio),
        cta = {
            WaButton(
                stringResource(R.string.mktg_studio_write),
                onClick = {
                    busy = true
                    scope.launch {
                        runCatching {
                            ApiClient.marketing.generateContent(mkAuth(), projectId, MkStudioRequest(channel, brief.trim(), kind, format, objective.trim().ifBlank { null }, variants)).data
                        }.onSuccess { r -> waiting = r?.job?.id; host.showToast(started); reload++ }
                            .onFailure { host.fail(it, generic) }
                        busy = false
                    }
                },
                icon = Icons.Rounded.AutoAwesome, enabled = brief.trim().length >= 5 && running == null, loading = busy || waiting != null,
            )
        },
    ) {
        WaNote(stringResource(R.string.mktg_studio_note))
        Spacer(Modifier.height(12.dp))
        WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(stringResource(R.string.mktg_channel), color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    MK_CHANNELS.filter { it !in setOf("offline", "other", "website") }.forEach { ch -> WeChip(stringResource(mkChannelLabel(ch)), selected = channel == ch, onClick = { channel = ch }) }
                }
                Text(stringResource(R.string.mktg_format), color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    MK_FORMATS.forEach { f -> WeChip(stringResource(mkFormatLabel(f)), selected = format == f, onClick = { format = f }) }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    listOf("ad", "post").forEach { k -> WeChip(stringResource(mkContentKindLabel(k)), selected = kind == k, onClick = { kind = k }) }
                    Spacer(Modifier.weight(1f))
                    listOf(2, 3).forEach { n -> WeChip(stringResource(R.string.mktg_n_variants, n), selected = variants == n, onClick = { variants = n }) }
                }
                DorrTextField(value = objective, onValueChange = { objective = it.take(300) }, label = stringResource(R.string.mktg_objective), placeholder = stringResource(R.string.mktg_objective_hint))
                DorrTextField(
                    value = brief, onValueChange = { brief = it.take(1000) }, label = stringResource(R.string.mktg_brief),
                    placeholder = stringResource(R.string.mktg_brief_hint), singleLine = false, minLines = 4, minHeight = 120.dp,
                )
                Text("${brief.length}/1000", color = Wa.Soft, fontSize = 11.sp, modifier = Modifier.fillMaxWidth(), textAlign = TextAlign.End)
            }
        }
        if (running != null || waiting != null) {
            Spacer(Modifier.height(12.dp))
            WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
                Text(stringResource(R.string.mktg_studio_writing), color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(8.dp))
                MkBar((running?.progressPct ?: 10) / 100f, MkBlue)
            }
        }
        WaSectionTitle(stringResource(R.string.mktg_drafts))
        val list = drafts
        when {
            list == null -> WaSkeleton(Modifier.fillMaxWidth().height(76.dp), RoundedCornerShape(20.dp))
            list.isEmpty() -> Text(stringResource(R.string.mktg_no_drafts), color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.padding(horizontal = 4.dp))
            else -> Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                list.forEach { item -> ContentRow(item) { host.push(MkRoute.Content(projectId, item.id)) } }
            }
        }
    }
}
