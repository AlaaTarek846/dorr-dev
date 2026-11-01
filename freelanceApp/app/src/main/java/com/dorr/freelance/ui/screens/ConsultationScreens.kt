package com.dorr.freelance.ui.screens

import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.EventAvailable
import androidx.compose.material.icons.rounded.EventBusy
import androidx.compose.material.icons.rounded.FormatQuote
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.NotificationsActive
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.Timer
import androidx.compose.material.icons.rounded.VideoChat
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TimePicker
import androidx.compose.material3.TimePickerDefaults
import androidx.compose.material3.rememberTimePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.AppLocale
import com.dorr.freelance.net.ConsultationCancelRequest
import com.dorr.freelance.net.ConsultationDto
import com.dorr.freelance.net.ConsultationHoursDto
import com.dorr.freelance.net.ConsultationInfoDto
import com.dorr.freelance.net.ConsultationOfferDto
import com.dorr.freelance.net.ConsultationOfferRequest
import com.dorr.freelance.net.ConsultationOptionRequest
import com.dorr.freelance.net.PagedRows
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.components.PagedFooter
import com.dorr.freelance.ui.components.SkeletonDetail
import com.dorr.freelance.ui.components.SkeletonList
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.RedToggle
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.kit.settingsSurface
import kotlinx.coroutines.launch
import java.time.Duration
import java.time.Instant
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/*
 * FL-L07, paid consultations on the freelancer's side, listed like ready-made services: the hub (my consultations with their state, my
 * numbers, the bookings in three lists), the editor of one consultation (title, lengths with a price, weekly hours in my phone's time
 * zone, sent to the staff for review), and one booking (when, who, what about, the money, answering or cancelling). The session itself is
 * a video call (FL-L16), so there is no room. Everything is read when a page opens, when the connection returns and when a notice arrives — never on a timer; the
 * countdowns read the phone's clock.
 */

private val CONS_TABS = listOf("upcoming", "past", "cancelled")
private val DAYS = listOf(R.string.day_sun, R.string.day_mon, R.string.day_tue, R.string.day_wed, R.string.day_thu, R.string.day_fri, R.string.day_sat)
private val HHMM = Regex("""^([01]\d|2[0-3]):[0-5]\d$""")

private fun consInstant(iso: String?): Instant? = runCatching { Instant.parse(iso) }.getOrNull() ?: runCatching { OffsetDateTime.parse(iso).toInstant() }.getOrNull()

private fun hm(iso: String?): String = consInstant(iso)?.let { DateTimeFormatter.ofPattern("HH:mm", Locale.US).format(it.atZone(ZoneId.systemDefault())) }.orEmpty()

/** "Mon 9 Nov" in the app language, digits Latin. */
private fun dayLine(iso: String?): String = consInstant(iso)?.let {
    val z = it.atZone(ZoneId.systemDefault())
    "${DateTimeFormatter.ofPattern("EEEE", AppLocale.java()).format(z)} ${z.dayOfMonth} ${DateTimeFormatter.ofPattern("MMMM", AppLocale.java()).format(z)}"
}.orEmpty()

@Composable
private fun phaseLabel(phase: String): String = when (phase) {
    "pending" -> stringResource(R.string.cns_phase_pending)
    "declined" -> stringResource(R.string.cns_phase_declined)
    "upcoming" -> stringResource(R.string.cns_phase_upcoming)
    "live" -> stringResource(R.string.cns_phase_live)
    "ended" -> stringResource(R.string.cns_phase_ended)
    "completed" -> stringResource(R.string.cns_phase_completed)
    "cancelled" -> stringResource(R.string.cns_phase_cancelled)
    "disputed" -> stringResource(R.string.cns_phase_disputed)
    "refunded" -> stringResource(R.string.cns_phase_refunded)
    else -> phase
}

@Composable
private fun phaseTone(phase: String): SvcTone {
    val c = when (phase) {
        "upcoming" -> settingsAccent()
        "live" -> Color(0xFF16A34A)
        "ended", "disputed", "pending" -> Color(0xFFD97706)
        "completed" -> Color(0xFF16A34A)
        "refunded" -> Color(0xFFDC2626)
        else -> Color(0xFF64748B)
    }
    return SvcTone(c, c.copy(alpha = if (settingsNight()) 0.22f else 0.12f))
}

/** "in 3 h", "in 2 days", "now" — from the phone's clock. */
@Composable
private fun untilText(iso: String?): String? {
    val at = consInstant(iso) ?: return null
    val left = Duration.between(Instant.now(), at)
    return when {
        left.isNegative -> null
        left.toDays() >= 1 -> stringResource(R.string.cns_in_days, left.toDays().toInt())
        left.toHours() >= 1 -> stringResource(R.string.cns_in_hours, left.toHours().toInt())
        else -> stringResource(R.string.cns_in_minutes, left.toMinutes().coerceAtLeast(1).toInt())
    }
}

// ================================================================== the hub

private val HUB_TABS = listOf("offers", "upcoming", "past", "cancelled")

@Composable
fun ConsultationsScreen(onBack: () -> Unit, onOpenEditor: (String?) -> Unit, onOpenConsultation: (String) -> Unit) {
    val context = LocalContext.current
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var tab by rememberSaveable { mutableStateOf("offers") }
    var info by remember { mutableStateOf<ConsultationInfoDto?>(null) }
    val offers = remember { mutableStateListOf<ConsultationOfferDto>() }
    var offersLoaded by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var busyUuid by remember { mutableStateOf<String?>(null) }
    var deleting by remember { mutableStateOf<ConsultationOfferDto?>(null) }
    val paged = remember(tab) { PagedRows<ConsultationDto>(15, { it.uuid }, { page -> apiCall { ApiClient.consultations.list(tab = tab, page = page, perPage = 15) } }) }

    suspend fun loadInfo() { (apiCall { ApiClient.consultations.info() } as? ApiResult.Ok)?.data?.let { info = it } }
    suspend fun loadOffers() {
        when (val r = apiCall { ApiClient.consultations.offers() }) {
            is ApiResult.Ok -> { offers.clear(); offers.addAll(r.data); offersLoaded = true; error = null }
            else -> error = r.errorText(offlineText) ?: genericError
        }
    }
    LaunchedEffect(reconnect) { loadInfo(); loadOffers() }
    LaunchedEffect(tab, reconnect) { if (tab != "offers") paged.reload() }
    LaunchedEffect(Unit) { Realtime.changed.collect { loadInfo(); loadOffers(); if (tab != "offers") paged.reload() } }

    fun act(o: ConsultationOfferDto, kind: String) {
        busyUuid = o.uuid
        scope.launch {
            val r = apiCall {
                when (kind) {
                    "submit" -> ApiClient.consultations.submit(o.uuid)
                    "pause" -> ApiClient.consultations.pause(o.uuid)
                    else -> ApiClient.consultations.resume(o.uuid)
                }
            }
            busyUuid = null
            if (r is ApiResult.Ok) {
                Toast.makeText(context, context.getString(if (kind == "submit") R.string.cns_offer_sent else R.string.cns_offer_updated), Toast.LENGTH_SHORT).show()
                loadOffers(); loadInfo()
            } else error = r.errorText(offlineText) ?: genericError
        }
    }

    Page(title = stringResource(R.string.cns_title), onBack = onBack, onNearEnd = { if (tab != "offers") scope.launch { paged.loadMore() } }) {
        val s = info
        if (s != null) {
            HubHero(s, offers.count { it.live }, onNew = { onOpenEditor(null) })
            if (s.stats.pending > 0) Notice(Icons.Rounded.NotificationsActive, stringResource(R.string.cns_pending_banner, s.stats.pending), phaseTone("pending"))
            if (!s.featureOn) Notice(Icons.Rounded.Info, stringResource(R.string.cns_feature_off), serviceTone("pending_review"))
        }
        Segmented(HUB_TABS.map { it to tabLabel(it) }, tab, marks = if ((info?.stats?.pending ?: 0) > 0) setOf("upcoming") else emptySet()) { tab = it }
        if (tab == "offers") {
            when {
                !offersLoaded && error == null -> SkeletonList()
                offersLoaded && offers.isEmpty() -> StartCard { onOpenEditor(null) }
                else -> offers.forEach { o ->
                    OfferCard(o, busy = busyUuid == o.uuid, onEdit = { onOpenEditor(o.uuid) }, onAct = { act(o, it) }, onDelete = { deleting = o })
                }
            }
            if (offersLoaded && offers.isNotEmpty() && info?.review == true) {
                Text(stringResource(R.string.cns_review_note), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp, modifier = Modifier.widthIn(max = 520.dp))
            }
            ErrorBanner(error, Modifier.fillMaxWidth())
        } else {
            when {
                !paged.loaded && paged.failure == null -> SkeletonList()
                paged.loaded && paged.rows.isEmpty() && paged.failure == null -> EmptyState(
                    icon = Icons.Rounded.EventAvailable,
                    title = stringResource(when (tab) { "upcoming" -> R.string.cns_empty_upcoming; "past" -> R.string.cns_empty_past; else -> R.string.cns_empty_cancelled }),
                    body = stringResource(R.string.cns_empty_body),
                    modifier = Modifier.padding(top = 40.dp),
                )
                else -> paged.rows.forEach { c -> BookingCard(c) { onOpenConsultation(c.uuid) } }
            }
            PagedFooter(paged) { scope.launch { paged.loadMore() } }
            ErrorBanner(paged.failure?.let { it.errorText(offlineText) ?: genericError }, Modifier.fillMaxWidth())
        }
    }

    deleting?.let { o ->
        AlertDialog(
            onDismissRequest = { deleting = null },
            title = { Text(stringResource(R.string.cns_delete_title), fontWeight = FontWeight.ExtraBold) },
            text = { Text(stringResource(R.string.cns_delete_body, o.title.orEmpty()), color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp) },
            confirmButton = {
                TextButton(onClick = {
                    deleting = null
                    busyUuid = o.uuid
                    scope.launch {
                        val r = apiCall { ApiClient.consultations.delete(o.uuid) }
                        busyUuid = null
                        if (r is ApiResult.Ok) { loadOffers(); loadInfo() } else error = r.errorText(offlineText) ?: genericError
                    }
                }) { Text(stringResource(R.string.common_delete), color = serviceTone("rejected").ink, fontWeight = FontWeight.Bold) }
            },
            dismissButton = { TextButton(onClick = { deleting = null }) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
        )
    }
}

@Composable
private fun tabLabel(tab: String): String = when (tab) {
    "offers" -> stringResource(R.string.cns_tab_offers)
    "past" -> stringResource(R.string.cns_tab_past)
    "cancelled" -> stringResource(R.string.cns_tab_cancelled)
    else -> stringResource(R.string.cns_tab_upcoming)
}

/** The top of the hub: how many I have open, my numbers, and a new consultation. */
@Composable
private fun HubHero(s: ConsultationInfoDto, openNow: Int, onNew: () -> Unit) {
    val accent = settingsAccent()
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).clip(RoundedCornerShape(24.dp))
            .background(Brush.linearGradient(listOf(accent, accent.copy(alpha = 0.78f))))
            .padding(18.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(Color.White.copy(alpha = 0.18f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.VideoChat, contentDescription = null, tint = Color.White, modifier = Modifier.size(24.dp))
            }
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.cns_hero_title), color = Color.White, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Box(Modifier.size(8.dp).clip(CircleShape).background(if (openNow > 0) Color(0xFF86EFAC) else Color.White.copy(alpha = 0.5f)))
                    Text(
                        if (openNow > 0) stringResource(R.string.cns_open_n, openNow) else stringResource(R.string.cns_open_none),
                        color = Color.White.copy(alpha = 0.92f), fontSize = 12.sp, fontWeight = FontWeight.SemiBold,
                    )
                }
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            HeroStat(s.stats.upcoming.toString(), stringResource(R.string.cns_stat_upcoming), Modifier.weight(1f))
            HeroStat(s.stats.completed.toString(), stringResource(R.string.cns_stat_done), Modifier.weight(1f))
            HeroStat(svcMoney(s.stats.earnedMinor, s.decimals, null), stringResource(R.string.cns_stat_earned, s.currency.orEmpty()), Modifier.weight(1.3f))
        }
        Row(
            Modifier.fillMaxWidth().height(46.dp).clip(RoundedCornerShape(14.dp)).background(Color.White).clickable(onClick = onNew),
            horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.Add, contentDescription = null, tint = accent, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.cns_new_offer), color = accent, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
        }
    }
}

@Composable
private fun HeroStat(value: String, label: String, modifier: Modifier) {
    Column(modifier.clip(RoundedCornerShape(14.dp)).background(Color.White.copy(alpha = 0.14f)).padding(vertical = 10.dp, horizontal = 8.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Text(value, color = Color.White, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
        Text(label, color = Color.White.copy(alpha = 0.85f), fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/** No consultation listed yet: how it works in three lines. */
@Composable
private fun StartCard(onNew: () -> Unit) {
    InfoCard(title = stringResource(R.string.cns_start_title), icon = Icons.Rounded.EventAvailable) {
        listOf(R.string.cns_start_1, R.string.cns_start_2, R.string.cns_start_3).forEachIndexed { i, res ->
            Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Box(Modifier.size(22.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                    Text("${i + 1}", color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.ExtraBold)
                }
                Text(stringResource(res), color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp, modifier = Modifier.weight(1f))
            }
        }
        SettingsPrimaryButton(text = stringResource(R.string.cns_start_cta), onClick = onNew)
    }
}

/** One consultation I list: its title, state, lengths from a price, the bookings on it and what I can do next. */
@Composable
private fun OfferCard(o: ConsultationOfferDto, busy: Boolean, onEdit: () -> Unit, onAct: (String) -> Unit, onDelete: () -> Unit) {
    val tone = serviceTone(if (o.status == "draft") "draft" else o.status)
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).settingsSurface(RoundedCornerShape(20.dp)).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(o.title.orEmpty().ifBlank { stringResource(R.string.cns_untitled) }, color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 22.sp, modifier = Modifier.weight(1f))
            SvcPill(serviceStatusLabel(o.status), tone)
        }
        Text(
            o.options.map { stringResource(R.string.cns_len_price, it.minutes, svcMoney(it.priceMinor, o.decimals, o.currency)) }.joinToString(" · ").ifBlank { stringResource(R.string.cns_no_lengths) },
            color = settingsAccent(), fontSize = 12.5.sp, fontWeight = FontWeight.Bold, lineHeight = 18.sp,
        )
        if (o.bookings.pending + o.bookings.upcoming + o.bookings.completed > 0) {
            Text(stringResource(R.string.cns_offer_counts, o.bookings.pending, o.bookings.upcoming, o.bookings.completed), color = settingsMut(), fontSize = 12.sp)
        }
        o.statusReason?.takeIf { it.isNotBlank() && (o.status == "rejected" || o.status == "suspended") }?.let {
            Notice(Icons.Rounded.Info, it, serviceTone(if (o.status == "rejected") "rejected" else "suspended"))
        }
        if (o.status == "pending_review") Text(stringResource(R.string.cns_offer_waiting), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            if (o.can.edit) OfferAction(Icons.Rounded.Edit, stringResource(R.string.cns_edit), Modifier.weight(1f), enabled = !busy, onClick = onEdit)
            if (o.can.submit) OfferAction(Icons.Rounded.Send, stringResource(R.string.cns_send_review), Modifier.weight(1.3f), enabled = !busy, strong = true) { onAct("submit") }
            if (o.can.pause) OfferAction(Icons.Rounded.Pause, stringResource(R.string.cns_pause), Modifier.weight(1f), enabled = !busy) { onAct("pause") }
            if (o.can.resume) OfferAction(Icons.Rounded.PlayArrow, stringResource(R.string.cns_resume), Modifier.weight(1f), enabled = !busy, strong = true) { onAct("resume") }
            if (o.can.delete) {
                Box(Modifier.size(42.dp).clip(RoundedCornerShape(12.dp)).clickable(enabled = !busy, onClick = onDelete), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Delete, contentDescription = stringResource(R.string.common_delete), tint = serviceTone("rejected").ink, modifier = Modifier.size(20.dp))
                }
            }
        }
    }
}

@Composable
private fun OfferAction(icon: ImageVector, text: String, modifier: Modifier, enabled: Boolean, strong: Boolean = false, onClick: () -> Unit) {
    val accent = settingsAccent()
    Row(
        modifier.height(42.dp).clip(RoundedCornerShape(12.dp))
            .background(if (strong) accent else accent.copy(alpha = 0.10f)).clickable(enabled = enabled, onClick = onClick).padding(horizontal = 8.dp),
        horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, contentDescription = null, tint = if (strong) Color.White else accent, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(6.dp))
        Text(text, color = if (strong) Color.White else accent, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/** One booking in a list: the day on a coloured tile, the time, the client and the topic, what I get and the state. */
@Composable
private fun BookingCard(c: ConsultationDto, onClick: () -> Unit) {
    val tone = phaseTone(c.phase)
    val at = consInstant(c.startsAt)?.atZone(ZoneId.systemDefault())
    Row(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).settingsSurface(RoundedCornerShape(20.dp)).clickable(onClick = onClick).padding(12.dp),
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Column(
            Modifier.width(58.dp).clip(RoundedCornerShape(16.dp)).background(tone.fill).padding(vertical = 10.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(at?.let { DateTimeFormatter.ofPattern("EEE", AppLocale.java()).format(it) }.orEmpty(), color = tone.ink, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1)
            Text(at?.dayOfMonth?.toString().orEmpty(), color = tone.ink, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold)
            Text(at?.let { DateTimeFormatter.ofPattern("MMM", AppLocale.java()).format(it) }.orEmpty(), color = tone.ink, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1)
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("‎${hm(c.startsAt)} – ${hm(c.endsAt)}", color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                SvcPill(phaseLabel(c.phase), tone)
            }
            Text(stringResource(R.string.cns_with, c.client?.name.orEmpty(), c.minutes), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 1)
            c.topic?.let { Text(it, color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp, maxLines = 2, overflow = TextOverflow.Ellipsis) }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(stringResource(R.string.svo_you_get, svcMoney(c.money.netMinor, c.decimals, c.currency)), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                if (c.phase == "upcoming" || c.phase == "pending") untilText(c.startsAt)?.let {
                    Icon(Icons.Rounded.Timer, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(14.dp))
                    Text(it, color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
                }
            }
        }
    }
}

@Composable
private fun Notice(icon: ImageVector, text: String, tone: SvcTone) {
    Row(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).clip(RoundedCornerShape(16.dp)).background(tone.fill).padding(12.dp),
        verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Icon(icon, contentDescription = null, tint = tone.ink, modifier = Modifier.size(18.dp))
        Text(text, color = tone.ink, fontSize = 13.sp, lineHeight = 19.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
    }
}

// ================================================================== the editor

/** One range of a day in the editor. */
private class Range(from: String, to: String) {
    var from by mutableStateOf(from)
    var to by mutableStateOf(to)
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ConsultationEditorScreen(uuid: String?, onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    // The times are told in this phone's zone and kept with the listing; each client sees them in their own.
    val zone = remember { ZoneId.systemDefault().id }

    var info by remember { mutableStateOf<ConsultationInfoDto?>(null) }
    var offer by remember { mutableStateOf<ConsultationOfferDto?>(null) }
    var ready by remember { mutableStateOf(false) }
    var title by rememberSaveable { mutableStateOf("") }
    var about by rememberSaveable { mutableStateOf("") }
    // minutes → the typed price ("" = not sold)
    val prices = remember { mutableStateMapOf<Int, String>() }
    val chosen = remember { mutableStateListOf<Int>() }
    val week = remember { (0..6).map { mutableStateListOf<Range>() } }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var picking by remember { mutableStateOf<Triple<Range, Boolean, String>?>(null) }

    fun fill(o: ConsultationOfferDto, decimals: Int) {
        offer = o
        title = o.title.orEmpty()
        about = o.about.orEmpty()
        chosen.clear()
        prices.clear()
        o.options.forEach { x -> chosen.add(x.minutes); prices[x.minutes] = minorToInput(x.priceMinor, decimals) }
        week.forEach { it.clear() }
        o.weekly.forEach { h -> if (h.day in 0..6) week[h.day].add(Range(h.from, h.to)) }
    }

    LaunchedEffect(reconnect) {
        if (ready) return@LaunchedEffect
        val i = apiCall { ApiClient.consultations.info() }
        if (i !is ApiResult.Ok) { error = i.errorText(offlineText) ?: genericError; return@LaunchedEffect }
        info = i.data
        if (uuid != null) {
            val o = apiCall { ApiClient.consultations.offer(uuid) }
            if (o is ApiResult.Ok) fill(o.data, i.data.decimals) else { error = o.errorText(offlineText) ?: genericError; return@LaunchedEffect }
        } else if (week.all { it.isEmpty() } && i.data.suggestedWeekly.isNotEmpty()) {
            i.data.suggestedWeekly.forEach { h -> if (h.day in 0..6) week[h.day].add(Range(h.from, h.to)) }
        }
        ready = true
    }

    val textBadPrice = stringResource(R.string.cns_err_price)
    val textNoTitle = stringResource(R.string.cns_err_no_title)
    val textNoLength = stringResource(R.string.cns_err_no_length)
    val textNoHours = stringResource(R.string.cns_err_no_hours)
    val textBadHours = stringResource(R.string.cns_err_hours)
    val textSaved = stringResource(R.string.cns_saved)
    val textSent = stringResource(R.string.cns_offer_sent)

    /** Saves it; with [send] the listing also goes to the staff (or live where they don't look first). */
    fun save(send: Boolean) {
        val s = info ?: return
        error = null
        val options = chosen.sorted().map { m -> m to inputToMinor(prices[m].orEmpty(), s.decimals) }
        if (title.trim().length < 3) { error = textNoTitle; return }
        if (options.isEmpty()) { error = textNoLength; return }
        if (options.any { it.second == null || it.second!! < 100 }) { error = textBadPrice; return }
        val hours = week.flatMapIndexed { day, list -> list.map { ConsultationHoursDto(day, it.from, it.to) } }
        if (hours.isEmpty()) { error = textNoHours; return }
        val bad = week.any { list ->
            val sorted = list.sortedBy { it.from }
            sorted.any { !HHMM.matches(it.from) || !HHMM.matches(it.to) || it.to <= it.from } || sorted.zipWithNext().any { (a, b) -> b.from < a.to }
        }
        if (bad) { error = textBadHours; return }
        busy = true
        scope.launch {
            val body = ConsultationOfferRequest(
                title = title.trim(), about = about.trim().ifEmpty { null },
                options = options.map { ConsultationOptionRequest(it.first, it.second!!) }, weekly = hours, timezone = zone,
            )
            val current = offer
            val first = apiCall { if (current == null) ApiClient.consultations.create(body) else ApiClient.consultations.update(current.uuid, body) }
            var r = first
            if (first is ApiResult.Ok && send && first.data.can.submit) {
                val id = first.data.uuid
                r = apiCall { ApiClient.consultations.submit(id) }
            }
            busy = false
            if (r is ApiResult.Ok) {
                fill(r.data, s.decimals)
                Toast.makeText(context, if (send) textSent else textSaved, Toast.LENGTH_SHORT).show()
                if (send) onBack()
            } else error = r.errorText(offlineText) ?: genericError
        }
    }

    Page(title = stringResource(if (uuid == null) R.string.cns_new_title else R.string.cns_edit_title), onBack = onBack) {
        val s = info
        if (s == null || !ready) {
            if (error == null) SkeletonDetail()
            ErrorBanner(error, Modifier.fillMaxWidth())
            return@Page
        }
        val o = offer
        if (o != null) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                SvcPill(serviceStatusLabel(o.status), serviceTone(o.status))
            }
            if (o.status == "rejected" || o.status == "suspended") o.statusReason?.takeIf { it.isNotBlank() }?.let {
                Notice(Icons.Rounded.Info, it, serviceTone(o.status))
            }
            if (s.review && (o.status == "published" || o.status == "paused")) Notice(Icons.Rounded.Info, stringResource(R.string.cns_edit_review_note), serviceTone("pending_review"))
        }

        StepCard(1, stringResource(R.string.cns_step_about), stringResource(R.string.cns_step_about_sub)) {
            DorrTextField(title, { title = it.take(120) }, label = stringResource(R.string.cns_field_title), placeholder = stringResource(R.string.cns_field_title_hint), modifier = Modifier.fillMaxWidth())
            DorrTextField(about, { about = it.take(1000) }, label = stringResource(R.string.cns_field_about), placeholder = stringResource(R.string.cns_field_about_hint), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
        }

        StepCard(2, stringResource(R.string.cns_step_lengths), stringResource(R.string.cns_step_lengths_sub, s.maxOptions)) {
            s.lengths.forEach { m ->
                val on = m in chosen
                val saved = offer?.options?.firstOrNull { it.minutes == m }
                LengthRow(
                    minutes = m, on = on, price = prices[m].orEmpty(), currency = s.currency,
                    net = saved?.takeIf { minorToInput(it.priceMinor, s.decimals) == prices[m].orEmpty() }?.let { svcMoney(it.netMinor, s.decimals, s.currency) },
                    canAdd = chosen.size < s.maxOptions,
                    onToggle = { if (on) chosen.remove(m) else if (chosen.size < s.maxOptions) chosen.add(m) },
                    onPrice = { prices[m] = it.take(10) },
                )
            }
        }

        StepCard(3, stringResource(R.string.cns_step_hours), stringResource(R.string.cns_step_hours_sub)) {
            (0..6).forEach { day -> DayHours(day, week[day], onPick = { r, isFrom -> picking = Triple(r, isFrom, if (isFrom) r.from else r.to) }) }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(Icons.Rounded.Public, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(14.dp))
                Text(stringResource(R.string.cns_zone, zone), color = settingsMut(), fontSize = 11.sp)
            }
        }

        InfoCard(title = stringResource(R.string.cns_rules_title), icon = Icons.Rounded.Gavel) {
            listOf(
                stringResource(R.string.cns_rule_notice, s.rules.minNoticeHours, s.rules.maxDaysAhead),
                stringResource(R.string.cns_rule_paid_first, s.rules.responseHours),
                stringResource(R.string.cns_rule_cancel, s.rules.freeCancelHours),
                stringResource(R.string.cns_rule_after, s.rules.complaintHours),
            ).forEach { line ->
                Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = Color(0xFF16A34A), modifier = Modifier.size(16.dp).padding(top = 1.dp))
                    Text(line, color = settingsInk(), fontSize = 12.5.sp, lineHeight = 18.sp, modifier = Modifier.weight(1f))
                }
            }
        }

        ErrorBanner(error, Modifier.fillMaxWidth())
        val sendable = offer == null || offer?.can?.submit == true
        if (sendable) {
            SettingsPrimaryButton(text = stringResource(if (s.review) R.string.cns_save_send else R.string.cns_save_publish), loading = busy, modifier = Modifier.widthIn(max = 520.dp)) { save(true) }
            Row(
                Modifier.fillMaxWidth().widthIn(max = 520.dp).height(50.dp).clip(RoundedCornerShape(18.dp))
                    .border(1.5.dp, if (settingsNight()) AccountDark.line else settingsAccent().copy(alpha = 0.3f), RoundedCornerShape(18.dp))
                    .clickable(enabled = !busy) { save(false) },
                horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
            ) { Text(stringResource(R.string.cns_save_draft), color = settingsAccent(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold) }
        } else {
            SettingsPrimaryButton(text = stringResource(R.string.cns_save), loading = busy, modifier = Modifier.widthIn(max = 520.dp)) { save(false) }
        }
    }

    picking?.let { (range, isFrom, current) ->
        val parts = current.split(":")
        val state = rememberTimePickerState(initialHour = parts.getOrNull(0)?.toIntOrNull() ?: 9, initialMinute = parts.getOrNull(1)?.toIntOrNull() ?: 0, is24Hour = true)
        AlertDialog(
            onDismissRequest = { picking = null },
            title = { Text(stringResource(if (isFrom) R.string.cns_pick_from else R.string.cns_pick_to), fontWeight = FontWeight.ExtraBold) },
            text = {
                TimePicker(
                    state = state,
                    colors = TimePickerDefaults.colors(selectorColor = settingsAccent(), timeSelectorSelectedContainerColor = settingsAccent().copy(alpha = 0.16f), timeSelectorSelectedContentColor = settingsAccent()),
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    val v = String.format(Locale.US, "%02d:%02d", state.hour, state.minute)
                    if (isFrom) range.from = v else range.to = v
                    picking = null
                }) { Text(stringResource(R.string.common_ok), color = settingsAccent(), fontWeight = FontWeight.Bold) }
            },
            dismissButton = { TextButton(onClick = { picking = null }) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
        )
    }
}

/** A length sold or not, its price, and what I keep of it (once saved). */
@Composable
private fun LengthRow(minutes: Int, on: Boolean, price: String, currency: String?, net: String?, canAdd: Boolean, onToggle: () -> Unit, onPrice: (String) -> Unit) {
    val night = settingsNight()
    val line = if (on) settingsAccent() else if (night) AccountDark.line else Color(0xFFE5E7EB)
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).border(if (on) 1.5.dp else 1.dp, line, RoundedCornerShape(16.dp))
            .background(if (on) settingsAccent().copy(alpha = 0.05f) else Color.Transparent).padding(12.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(Modifier.clickable(enabled = on || canAdd, onClick = onToggle), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Box(
                Modifier.size(22.dp).clip(RoundedCornerShape(7.dp)).background(if (on) settingsAccent() else Color.Transparent).border(1.5.dp, if (on) settingsAccent() else settingsMut().copy(alpha = 0.5f), RoundedCornerShape(7.dp)),
                contentAlignment = Alignment.Center,
            ) { if (on) Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = Color.White, modifier = Modifier.size(14.dp)) }
            Icon(Icons.Rounded.Timer, contentDescription = null, tint = if (on) settingsAccent() else settingsMut(), modifier = Modifier.size(18.dp))
            Text(stringResource(R.string.cns_minutes, minutes), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
            if (!on && !canAdd) Text(stringResource(R.string.cns_max_reached), color = settingsMut(), fontSize = 11.sp)
        }
        if (on) {
            DorrTextField(
                price, onPrice, label = stringResource(R.string.cns_price, currency.orEmpty()), icon = Icons.Rounded.Payments,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth(),
            )
            net?.let { Text(stringResource(R.string.cns_you_keep, it), color = Color(0xFF16A34A), fontSize = 12.sp, fontWeight = FontWeight.Bold) }
        }
    }
}

/** A weekday: on with one or more ranges (each opens a clock), or off. */
@Composable
private fun DayHours(day: Int, ranges: MutableList<Range>, onPick: (Range, Boolean) -> Unit) {
    val on = ranges.isNotEmpty()
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(stringResource(DAYS[day]), color = if (on) settingsInk() else settingsMut(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
            if (!on) Text(stringResource(R.string.cns_day_off), color = settingsMut(), fontSize = 12.sp)
            RedToggle(on = on, onClick = { if (on) ranges.clear() else ranges.add(Range("09:00", "12:00")) })
        }
        ranges.toList().forEach { r ->
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                TimeChip(r.from, Modifier.weight(1f)) { onPick(r, true) }
                Text("—", color = settingsMut(), fontSize = 14.sp)
                TimeChip(r.to, Modifier.weight(1f), bad = r.to <= r.from) { onPick(r, false) }
                Box(Modifier.size(36.dp).clip(CircleShape).clickable { ranges.remove(r) }, contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Close, contentDescription = stringResource(R.string.common_delete), tint = settingsMut(), modifier = Modifier.size(18.dp))
                }
            }
        }
        if (on) {
            Row(
                Modifier.clip(RoundedCornerShape(10.dp)).clickable {
                    val last = ranges.maxByOrNull { it.to }
                    val start = last?.to ?: "09:00"
                    val h = (start.substringBefore(":").toIntOrNull() ?: 9) + 1
                    ranges.add(Range(start, String.format(Locale.US, "%02d:%s", h.coerceAtMost(23), start.substringAfter(":"))))
                }.padding(vertical = 4.dp, horizontal = 2.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                Icon(Icons.Rounded.Add, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
                Text(stringResource(R.string.cns_add_range), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
        }
        Box(Modifier.fillMaxWidth().height(1.dp).background(if (settingsNight()) AccountDark.line else Color(0xFFF1F2F4)))
    }
}

@Composable
private fun TimeChip(value: String, modifier: Modifier, bad: Boolean = false, onClick: () -> Unit) {
    val line = if (bad) Color(0xFFDC2626) else if (settingsNight()) AccountDark.line else Color(0xFFE5E7EB)
    Row(
        modifier.height(42.dp).clip(RoundedCornerShape(12.dp)).border(1.dp, line, RoundedCornerShape(12.dp)).clickable(onClick = onClick).padding(horizontal = 12.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Icon(Icons.Rounded.Schedule, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
        Text("‎$value", color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center, modifier = Modifier.weight(1f))
    }
}

// ================================================================== one booking

@Composable
fun ConsultationScreen(uuid: String, onBack: () -> Unit) {
    val context = LocalContext.current
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var c by remember { mutableStateOf<ConsultationDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var cancelling by remember { mutableStateOf(false) }
    var declining by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var reason by rememberSaveable { mutableStateOf("") }

    suspend fun load() {
        when (val r = apiCall { ApiClient.consultations.show(uuid) }) {
            is ApiResult.Ok -> { c = r.data; error = null }
            else -> error = r.errorText(offlineText) ?: genericError
        }
    }
    LaunchedEffect(reconnect) { load() }
    LaunchedEffect(Unit) { Realtime.changed.collect { load() } }

    Page(title = stringResource(R.string.cns_detail_title), onBack = onBack) {
        val x = c
        if (x == null) {
            if (error == null) SkeletonDetail()
        } else {
            DetailHead(x)
            x.topic?.takeIf { it.isNotBlank() }?.let { topic ->
                InfoCard(title = stringResource(R.string.cns_topic), icon = Icons.Rounded.FormatQuote) {
                    Text(topic, color = settingsInk(), fontSize = 14.sp, lineHeight = 21.sp,
                        modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(12.dp))
                }
            }
            InfoCard(title = stringResource(R.string.svo_money), icon = Icons.Rounded.Payments) {
                MoneyLine(stringResource(R.string.svo_price), svcMoney(x.money.priceMinor, x.decimals, x.currency))
                MoneyLine(stringResource(R.string.svo_commission), "− " + svcMoney(x.money.commissionMinor, x.decimals, x.currency), muted = true)
                Box(Modifier.fillMaxWidth().height(1.dp).background(if (settingsNight()) AccountDark.line else Color(0xFFEEF0F3)))
                MoneyLine(stringResource(R.string.svo_net), svcMoney(x.money.netMinor, x.decimals, x.currency), strong = true)
            }
            StateNotes(x)
            ClientRating(x)
            if (x.can.accept) {
                SettingsPrimaryButton(
                    text = stringResource(R.string.cns_accept), modifier = Modifier.widthIn(max = 520.dp), enabled = !busy,
                    onClick = {
                        busy = true
                        scope.launch {
                            val r = apiCall { ApiClient.consultations.accept(uuid) }
                            busy = false
                            if (r is ApiResult.Ok) {
                                c = r.data
                                Toast.makeText(context, context.getString(R.string.cns_accept_done), Toast.LENGTH_SHORT).show()
                            } else error = r.errorText(offlineText) ?: genericError
                        }
                    },
                )
            }
            if (x.can.cancel || x.can.decline) {
                Row(
                    Modifier.fillMaxWidth().widthIn(max = 520.dp).height(50.dp).clip(RoundedCornerShape(18.dp))
                        .border(1.5.dp, if (settingsNight()) AccountDark.line else settingsAccent().copy(alpha = 0.3f), RoundedCornerShape(18.dp))
                        .clickable(enabled = !busy) { if (x.can.decline) declining = true else cancelling = true },
                    horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
                ) { Text(stringResource(if (x.can.decline) R.string.cns_decline else R.string.cns_cancel), color = settingsAccent(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold) }
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }

    if (cancelling || declining) {
        AlertDialog(
            onDismissRequest = { if (!busy) { cancelling = false; declining = false } },
            title = { Text(stringResource(if (declining) R.string.cns_decline_title else R.string.cns_cancel_title), fontWeight = FontWeight.ExtraBold) },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text(stringResource(if (declining) R.string.cns_decline_body else R.string.cns_cancel_body), color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp)
                    DorrTextField(reason, { reason = it.take(500) }, label = stringResource(R.string.cns_cancel_reason), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
                }
            },
            confirmButton = {
                TextButton(enabled = !busy, onClick = {
                    busy = true
                    scope.launch {
                        val body = ConsultationCancelRequest(reason.trim().ifEmpty { null })
                        val r = apiCall { if (declining) ApiClient.consultations.decline(uuid, body) else ApiClient.consultations.cancel(uuid, body) }
                        val wasDeclining = declining
                        busy = false
                        cancelling = false
                        declining = false
                        if (r is ApiResult.Ok) {
                            c = r.data
                            Toast.makeText(context, context.getString(if (wasDeclining) R.string.cns_declined_done else R.string.cns_cancelled_done), Toast.LENGTH_SHORT).show()
                        } else error = r.errorText(offlineText) ?: genericError
                    }
                }) { Text(stringResource(if (declining) R.string.cns_decline_confirm else R.string.cns_cancel_confirm), color = serviceTone("rejected").ink, fontWeight = FontWeight.Bold) }
            },
            dismissButton = { TextButton(enabled = !busy, onClick = { cancelling = false; declining = false }) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
        )
    }
}

@Composable
private fun DetailHead(c: ConsultationDto) {
    val tone = phaseTone(c.phase)
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).settingsSurface(RoundedCornerShape(22.dp)).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            SvcPill(phaseLabel(c.phase), tone, solid = true)
            Spacer(Modifier.weight(1f))
            Text(stringResource(R.string.cns_minutes, c.minutes), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
        }
        Text(c.title.orEmpty(), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 25.sp)
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(tone.fill).padding(14.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Icon(Icons.Rounded.CalendarMonth, contentDescription = null, tint = tone.ink, modifier = Modifier.size(26.dp))
            Column(Modifier.weight(1f)) {
                Text(dayLine(c.startsAt), color = tone.ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                Text("‎${hm(c.startsAt)} – ${hm(c.endsAt)}", color = tone.ink.copy(alpha = 0.9f), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            }
            if (c.phase == "upcoming" || c.phase == "pending") untilText(c.startsAt)?.let {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Icon(Icons.Rounded.HourglassTop, contentDescription = null, tint = tone.ink, modifier = Modifier.size(16.dp))
                    Text(it, color = tone.ink, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold)
                }
            }
        }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Box(Modifier.size(34.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.16f)), contentAlignment = Alignment.Center) {
                Text(c.client?.name?.trim()?.firstOrNull()?.uppercase() ?: "?", color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            }
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.svo_client), color = settingsMut(), fontSize = 11.sp)
                Text(c.client?.name.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}

/** Once it is over: the client's stars and words, or until when they may still rate it. */
@Composable
private fun ClientRating(c: ConsultationDto) {
    val given = c.review.rating
    if (given == null && c.review.rateUntil == null) return
    InfoCard(title = stringResource(R.string.cns_rating_title), icon = Icons.Rounded.Star) {
        if (given == null) {
            Text(stringResource(R.string.cns_rating_none, "${dayLine(c.review.rateUntil)} ${hm(c.review.rateUntil)}"), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
        } else {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Stars(Math.round(given.stars).toInt(), 22)
                Text("${given.stars}", color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            }
            given.comment?.takeIf { it.isNotBlank() }?.let { Text("“$it”", color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp) }
            Text(stringResource(R.string.cns_rating_public), color = settingsMut(), fontSize = 11.sp)
        }
    }
}

/** What happens next, or what happened, in one or two plain lines. */
@Composable
private fun StateNotes(c: ConsultationDto) {
    val warn = serviceTone("pending_review")
    val ok = serviceTone("published")
    val info = SvcTone(settingsAccent(), settingsAccent().copy(alpha = 0.08f))
    when (c.phase) {
        "pending" -> Notice(Icons.Rounded.HourglassTop, stringResource(R.string.cns_note_pending, "${dayLine(c.respondBy)} ${hm(c.respondBy)}"), warn)
        "declined" -> {
            val cancel = c.cancel
            val text = when {
                cancel?.by == "freelancer" -> stringResource(R.string.cns_note_declined_me)
                cancel?.reason == "taken" -> stringResource(R.string.cns_note_declined_taken)
                else -> stringResource(R.string.cns_note_declined_expired)
            }
            Notice(Icons.Rounded.EventBusy, text + (cancel?.reason?.takeIf { cancel.by == "freelancer" }?.let { "\n“$it”" } ?: ""), serviceTone("paused"))
        }
        "upcoming" -> Notice(Icons.Rounded.Info, stringResource(R.string.cns_note_upcoming), info)
        "live" -> Notice(Icons.Rounded.VideoChat, stringResource(R.string.cns_note_live), ok)
        "ended" -> Notice(Icons.Rounded.HourglassTop, stringResource(R.string.cns_note_ended, "${dayLine(c.rules.releaseAt)} ${hm(c.rules.releaseAt)}"), warn)
        "completed" -> Notice(Icons.Rounded.CheckCircle, stringResource(R.string.cns_note_completed), ok)
        "disputed" -> {
            Notice(Icons.Rounded.Gavel, stringResource(R.string.cns_note_disputed), warn)
            c.complaint?.text?.let { InfoCard(title = stringResource(R.string.cns_complaint), icon = Icons.Rounded.FormatQuote) { Text(it, color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp) } }
        }
        "refunded" -> Notice(Icons.Rounded.Info, stringResource(R.string.cns_note_refunded) + (c.resolution?.note?.let { "\n$it" } ?: ""), serviceTone("rejected"))
        "cancelled" -> {
            val cancel = c.cancel
            val text = when {
                cancel?.by == "freelancer" -> stringResource(R.string.cns_note_cancelled_me)
                cancel?.late == true -> stringResource(R.string.cns_note_cancelled_late)
                else -> stringResource(R.string.cns_note_cancelled_client)
            }
            Notice(Icons.Rounded.EventBusy, text + (cancel?.reason?.let { "\n“$it”" } ?: ""), serviceTone("paused"))
        }
    }
}
