package com.dorr.app.ui.screens.freelance

import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.EventAvailable
import androidx.compose.material.icons.rounded.EventBusy
import androidx.compose.material.icons.rounded.FormatQuote
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.ReportProblem
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.ThumbUp
import androidx.compose.material.icons.rounded.Timer
import androidx.compose.material.icons.rounded.VideoChat
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
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
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ConsultBookRequest
import com.dorr.app.network.ConsultCardDto
import com.dorr.app.network.ConsultComplaintRequest
import com.dorr.app.network.ConsultReviewDto
import com.dorr.app.network.ConsultReviewRequest
import com.dorr.app.network.ConsultDto
import com.dorr.app.network.ConsultOfferDto
import com.dorr.app.network.ConsultReasonRequest
import com.dorr.app.network.ConsultTeaserDto
import com.dorr.app.network.ClientSpecialOfferDto
import com.dorr.app.network.SpecialOfferAcceptRequest
import com.dorr.app.network.apiFailure
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.ConfirmDialog
import com.dorr.app.ui.screens.profile.SettingsPrimaryButton
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import java.time.Duration
import java.time.Instant
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import java.util.UUID

/*
 * FL-L07, paid consultations on the client's side, listed like the ready-made services: the list of consultations on offer, booking one
 * (a length, a day, a time, what it is about — then a last look at the money before it is held), my consultations in three lists, and one
 * consultation (when, cancelling with the refund said plainly, saying it went well, or reporting a problem). The session itself is a video
 * call (FL-L16, FreelanceConsultationSession.kt): its card on the consultation and the full-screen room. Times come from the server as UTC instants and are shown on the phone's own clock. Each page reads
 * once when it opens and when the connection returns; countdowns read the phone's clock.
 */

private val C_GREEN = Color(0xFF16A34A)
private val C_AMBER = Color(0xFFD97706)
private val C_RED = Color(0xFFDC2626)
private val C_SLATE = Color(0xFF64748B)

private fun instantOf(iso: String?): Instant? = runCatching { Instant.parse(iso) }.getOrNull() ?: runCatching { OffsetDateTime.parse(iso).toInstant() }.getOrNull()

private fun hm(iso: String?): String = instantOf(iso)?.let { DateTimeFormatter.ofPattern("HH:mm", Locale.US).format(it.atZone(ZoneId.systemDefault())) }.orEmpty()

private fun appLocale(): Locale = Locale.getDefault()

private fun longDay(iso: String?): String = instantOf(iso)?.let {
    val z = it.atZone(ZoneId.systemDefault())
    "${DateTimeFormatter.ofPattern("EEEE", appLocale()).format(z)} ${z.dayOfMonth} ${DateTimeFormatter.ofPattern("MMMM", appLocale()).format(z)}"
}.orEmpty()

@Composable
private fun until(iso: String?): String? {
    val at = instantOf(iso) ?: return null
    val left = Duration.between(Instant.now(), at)
    return when {
        left.isNegative -> null
        left.toDays() >= 1 -> stringResource(R.string.cs_in_days, left.toDays().toInt())
        left.toHours() >= 1 -> stringResource(R.string.cs_in_hours, left.toHours().toInt())
        else -> stringResource(R.string.cs_in_minutes, left.toMinutes().coerceAtLeast(1).toInt())
    }
}

@Composable
private fun phase(p: String): Pair<String, Color> = when (p) {
    "pending" -> stringResource(R.string.cs_phase_pending) to C_AMBER
    "declined" -> stringResource(R.string.cs_phase_declined) to C_SLATE
    "upcoming" -> stringResource(R.string.cs_phase_upcoming) to settingsAccent()
    "live" -> stringResource(R.string.cs_phase_live) to C_GREEN
    "ended" -> stringResource(R.string.cs_phase_ended) to C_AMBER
    "completed" -> stringResource(R.string.cs_phase_completed) to C_GREEN
    "disputed" -> stringResource(R.string.cs_phase_disputed) to C_AMBER
    "refunded" -> stringResource(R.string.cs_phase_refunded) to C_RED
    else -> stringResource(R.string.cs_phase_cancelled) to C_SLATE
}

@Composable
private fun CsCard(title: String? = null, icon: ImageVector? = null, content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        if (title != null) Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            if (icon != null) Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
            Text(title, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
        }
        content()
    }
}

@Composable
private fun CsLine(label: String, value: String, strong: Boolean = false) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = if (strong) settingsInk() else settingsMut(), fontSize = if (strong) 15.sp else 13.sp, fontWeight = if (strong) FontWeight.ExtraBold else FontWeight.Medium, modifier = Modifier.weight(1f))
        Text(value, color = if (strong) settingsAccent() else settingsInk(), fontSize = if (strong) 17.sp else 13.sp, fontWeight = if (strong) FontWeight.ExtraBold else FontWeight.SemiBold)
    }
}

/** A numbered step heading on the booking page. */
@Composable
private fun StepHead(n: Int, title: String, done: Boolean) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        Box(Modifier.size(26.dp).clip(CircleShape).background(if (done) C_GREEN else settingsAccent()), contentAlignment = Alignment.Center) {
            if (done) Icon(Icons.Rounded.CheckCircle, null, tint = Color.White, modifier = Modifier.size(16.dp))
            else Text("$n", color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold)
        }
        Text(title, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
    }
}

// ==================================================================== the door on a freelancer's card

/** The consultations a freelancer lists now, one row each ("Brand advice · from 210 SAR"); nothing when they list none. */
@Composable
internal fun ConsultTeaser(items: List<ConsultTeaserDto>, onBook: (String) -> Unit) {
    if (items.isEmpty()) return
    val accent = settingsAccent()
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        items.forEach { t ->
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp))
                    .background(Brush.linearGradient(listOf(accent.copy(alpha = 0.12f), accent.copy(alpha = 0.04f))))
                    .border(1.dp, accent.copy(alpha = 0.25f), RoundedCornerShape(18.dp))
                    .clickable { onBook(t.uuid) }.padding(14.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                Box(Modifier.size(42.dp).clip(RoundedCornerShape(13.dp)).background(accent), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.VideoChat, null, tint = Color.White, modifier = Modifier.size(22.dp))
                }
                Column(Modifier.weight(1f)) {
                    Text(t.title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.cs_teaser_title), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(
                        stringResource(R.string.cs_teaser_line, mkMoney(t.fromMinor, 2, t.currency), t.minutes.joinToString(" · ")),
                        color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
                    )
                }
                Icon(Icons.Rounded.ChevronRight, null, tint = accent, modifier = Modifier.size(22.dp))
            }
        }
    }
}

// ==================================================================== the list of consultations on offer

/** The consultations clients can book, a list of their own next to the ready-made services: a search and one card each. */
@Composable
internal fun ConsultationsMarketPage(onBack: () -> Unit, onOpen: (String) -> Unit, onMine: () -> Unit, embedded: Boolean = false) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var typed by rememberSaveable { mutableStateOf("") }
    var search by rememberSaveable { mutableStateOf("") }
    var closed by remember { mutableStateOf(false) }
    val listState = rememberLazyListState()
    // The search goes out once typing pauses.
    LaunchedEffect(typed) { kotlinx.coroutines.delay(400); search = typed.trim() }
    val paged = remember(search) {
        PagedRows<ConsultCardDto>({ it.uuid }, { page ->
            try {
                ApiClient.freelanceConsultations.market(auth(), search = search.ifBlank { null }, page = page)
            } catch (e: retrofit2.HttpException) {
                if (e.code() == 403) closed = true
                throw e
            }
        })
    }
    LaunchedEffect(paged, reconnect) { paged.reload(generic) }
    listState.NearEnd(paged.rows.size) { scope.launch { paged.loadMore(generic) } }

    Column(Modifier.fillMaxSize()) {
        // Inside «Services» the page's head and «My consultations» are the hub's.
        if (!embedded) FlPageHeader(stringResource(R.string.cs_market_title), onBack)
        com.dorr.app.ui.components.PullToRefreshHost(Modifier.weight(1f).fillMaxWidth()) {
        LazyColumn(Modifier.fillMaxSize(), state = listState, contentPadding = PaddingValues(horizontal = 16.dp, vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            item {
                DorrTextField(typed, { typed = it.take(80) }, placeholder = stringResource(R.string.cs_market_search), icon = Icons.Rounded.Search,
                    trailing = if (typed.isNotEmpty()) { { Icon(Icons.Rounded.Close, null, tint = settingsMut(), modifier = Modifier.padding(end = 10.dp).size(18.dp).clickable { typed = "" }) } } else null,
                    modifier = Modifier.fillMaxWidth())
            }
            if (!embedded) item {
                Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(settingsAccent().copy(alpha = 0.08f)).clickable(onClick = onMine).padding(12.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    Icon(Icons.Rounded.EventAvailable, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                    Text(stringResource(R.string.cs_mine_title), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                    Icon(Icons.Rounded.ChevronRight, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                }
            }
            when {
                closed -> item { com.dorr.app.ui.screens.wallet.WaEmpty(Icons.Rounded.VideoChat, com.dorr.app.ui.screens.wallet.Tone.Blue, stringResource(R.string.cs_market_title), stringResource(R.string.cs_market_closed)) }
                !paged.loaded -> item { FlSkeletonList(count = 3, height = 150.dp) }
                paged.rows.isEmpty() && paged.error == null -> item {
                    com.dorr.app.ui.screens.wallet.WaEmpty(Icons.Rounded.VideoChat, com.dorr.app.ui.screens.wallet.Tone.Blue, stringResource(R.string.cs_market_empty_title), stringResource(R.string.cs_market_empty_body))
                }
                else -> items(paged.rows, key = { it.uuid }) { c -> ConsultCard(c) { onOpen(c.uuid) } }
            }
            item { PagedSpinner(paged) }
            paged.error?.takeIf { !closed }?.let { item { TextButton(onClick = { paged.clearError(); scope.launch { paged.loadMore(generic) } }, modifier = Modifier.fillMaxWidth()) { Text(it, color = C_RED, fontSize = 13.sp) } } }
        }
        }
    }
}

@Composable
private fun ConsultCard(c: ConsultCardDto, onClick: () -> Unit) {
    val accent = settingsAccent()
    Column(Modifier.fillMaxWidth().settingsSurface(FlCorners.card).clickable(role = androidx.compose.ui.semantics.Role.Button, onClick = onClick).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            FlAvatar(c.freelancer.avatarUrl, c.freelancer.name, 44)
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(c.freelancer.name.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                c.freelancer.headline?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
            }
            if (c.freelancer.rating != null) {
                Icon(Icons.Rounded.Star, null, tint = Color(0xFFF59E0B), modifier = Modifier.size(15.dp))
                Text(String.format(Locale.US, "%.1f", c.freelancer.rating), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.ExtraBold)
            }
        }
        Text(c.title.orEmpty(), color = settingsInk(), fontSize = 17.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 24.sp)
        c.about?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp, maxLines = 2, overflow = TextOverflow.Ellipsis) }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(Icons.Rounded.Timer, null, tint = accent, modifier = Modifier.size(16.dp))
            Text(c.minutes.map { stringResource(R.string.cs_minutes, it) }.joinToString(" · "), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(stringResource(R.string.cs_from_price, mkMoney(c.fromMinor, c.decimals, c.currency)), color = accent, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
        }
        if (c.nextSlot != null) {
            // The first free time, on this phone's own clock.
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(Icons.Rounded.CalendarMonth, null, tint = C_GREEN, modifier = Modifier.size(14.dp))
                Text(stringResource(R.string.cs_next_free, longDay(c.nextSlot), hm(c.nextSlot)), color = C_GREEN, fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}

// ==================================================================== booking

@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun BookConsultationPage(offerUuid: String, onBack: () -> Unit, onBooked: (String) -> Unit, special: String? = null) {
    val context = LocalContext.current
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var offer by remember { mutableStateOf<ConsultOfferDto?>(null) }
    // FL-L09: booked through a freelancer's special offer — its discounted prices, and the booking is confirmed at once.
    var specialOffer by remember { mutableStateOf<ClientSpecialOfferDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var minutes by rememberSaveable { mutableStateOf(0) }
    var slots by remember { mutableStateOf<List<String>?>(null) }
    var day by rememberSaveable { mutableStateOf<String?>(null) }
    var slot by rememberSaveable { mutableStateOf<String?>(null) }
    var topic by rememberSaveable { mutableStateOf("") }
    var confirming by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var bookError by remember { mutableStateOf<String?>(null) }
    // FL-L16: the session is recorded — booking waits for the client's yes.
    var consent by rememberSaveable { mutableStateOf(false) }
    // One id for this booking: a second tap (or a retry after a weak network) is the same booking.
    val requestId = rememberSaveable { UUID.randomUUID().toString() }

    LaunchedEffect(offerUuid, reconnect) {
        try {
            val o = ApiClient.freelanceConsultations.offer(auth(), offerUuid).data
            if (special != null) specialOffer = ApiClient.freelanceSpecialOffers.show(auth(), special).data
            offer = o
            if (minutes == 0) minutes = o?.options?.firstOrNull()?.minutes ?: 0
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }
    suspend fun loadSlots() {
        if (minutes == 0) return
        slots = null
        try {
            slots = ApiClient.freelanceConsultations.slots(auth(), offerUuid, minutes).data?.slots.orEmpty()
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            slots = emptyList()
            error = e.message(generic)
        }
    }
    LaunchedEffect(minutes, reconnect) { loadSlots() }

    // The free times grouped by the day they fall on, on this phone's clock.
    val byDay = remember(slots) {
        slots.orEmpty().mapNotNull { s -> instantOf(s)?.let { it.atZone(ZoneId.systemDefault()).toLocalDate() to s } }
            .groupBy({ it.first }, { it.second }).toSortedMap()
    }
    LaunchedEffect(byDay) {
        val keys = byDay.keys.map { it.toString() }
        if (day == null || day !in keys) day = keys.firstOrNull()
        if (slot != null && slots?.contains(slot) != true) slot = null
    }
    val option = offer?.options?.firstOrNull { it.minutes == minutes }
    val deal = specialOffer?.consultation?.lengths?.firstOrNull { it.minutes == minutes }
    val ready = option != null && slot != null && topic.trim().length >= 10

    fun book() {
        val o = offer ?: return
        val s = slot ?: return
        busy = true
        bookError = null
        scope.launch {
            try {
                val booked = if (special != null) {
                    ApiClient.freelanceSpecialOffers.accept(auth(), special, SpecialOfferAcceptRequest(requestId, minutes, s, topic.trim(), recordingConsent = true)).data?.result?.consultation
                } else {
                    ApiClient.freelanceConsultations.book(auth(), ConsultBookRequest(offerUuid, minutes, s, topic.trim(), requestId, recordingConsent = true)).data?.uuid
                }
                confirming = false
                Toast.makeText(context, context.getString(if (special != null) R.string.spc_booked else R.string.cs_booked), Toast.LENGTH_SHORT).show()
                if (booked != null) onBooked(booked)
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                val f = e.apiFailure()
                bookError = f.message ?: generic
                // Someone took this time a moment ago: the free times are read again.
                if (f.errorCode == "freelance_consultation_slot_taken") { slot = null; loadSlots() }
            }
            busy = false
        }
    }

    Column(Modifier.fillMaxSize()) {
        FlPageHeader(stringResource(R.string.cs_book_title), onBack)
        val o = offer
        if (o == null) {
            FlLoading(error)
            return@Column
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp, vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            // Who and what.
            Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(22.dp)).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    FlAvatar(o.freelancer.avatarUrl, o.freelancer.name, 56)
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                        Text(o.freelancer.name.orEmpty(), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        o.freelancer.headline?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            if (o.freelancer.rating != null) {
                                Icon(Icons.Rounded.Star, null, tint = Color(0xFFF59E0B), modifier = Modifier.size(15.dp))
                                Text(String.format(Locale.US, "%.1f", o.freelancer.rating), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.ExtraBold)
                            }
                            if (o.freelancer.consultationsDone > 0) Text(stringResource(R.string.cs_done_n, o.freelancer.consultationsDone), color = C_GREEN, fontSize = 11.sp, fontWeight = FontWeight.Bold)
                        }
                    }
                }
                Text(o.title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.cs_default_title), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 25.sp)
                o.about?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 13.sp, lineHeight = 20.sp) }
            }

            specialOffer?.let { sp -> SpecialDealBanner(sp) }
            // FL-L09: the loyalty program and where I stand (a special offer's price is final, so it stands alone).
            if (special == null) LoyaltyCard(o.loyalty)

            // What clients said, from projects and consultations.
            if (o.freelancer.reviews.isNotEmpty()) CsCard(stringResource(R.string.cs_reviews_title, o.freelancer.reviewsCount), Icons.Rounded.Star) {
                o.freelancer.reviews.forEach { r ->
                    Column(verticalArrangement = Arrangement.spacedBy(3.dp)) {
                        ReviewStars(r.stars)
                        r.comment?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp) }
                        Text(
                            listOfNotNull(if (r.kind == "consultation") stringResource(R.string.cs_review_kind) else null, r.project).joinToString(" · "),
                            color = settingsMut(), fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
                        )
                    }
                }
            }

            // 1. The length.
            CsCard {
                StepHead(1, stringResource(R.string.cs_step_length), option != null)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    o.options.forEach { op ->
                        val on = op.minutes == minutes
                        Column(
                            Modifier.weight(1f).clip(RoundedCornerShape(16.dp))
                                .background(if (on) settingsAccent() else settingsAccent().copy(alpha = 0.06f))
                                .clickable { if (!on) { minutes = op.minutes; slot = null } }.padding(vertical = 12.dp, horizontal = 6.dp),
                            horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(2.dp),
                        ) {
                            Icon(Icons.Rounded.Timer, null, tint = if (on) Color.White else settingsAccent(), modifier = Modifier.size(18.dp))
                            Text(stringResource(R.string.cs_minutes, op.minutes), color = if (on) Color.White else settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
                            val opDeal = specialOffer?.consultation?.lengths?.firstOrNull { it.minutes == op.minutes }
                            if (opDeal != null) Text(mkMoney(op.totalMinor, o.decimals, o.currency), color = if (on) Color.White.copy(alpha = 0.7f) else settingsMut().copy(alpha = 0.7f), fontSize = 10.sp, textDecoration = TextDecoration.LineThrough, maxLines = 1)
                            Text(mkMoney(opDeal?.totalMinor ?: op.totalMinor, o.decimals, o.currency), color = if (on) Color.White.copy(alpha = 0.9f) else if (opDeal != null) C_GREEN else settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1)
                            if (opDeal == null) LoyaltyMini(op.loyaltyBp, on)
                        }
                    }
                }
            }

            // 2. The day and 3. the time.
            CsCard {
                StepHead(2, stringResource(R.string.cs_step_time), slot != null)
                val list = slots
                when {
                    list == null -> FlSkeletonList(count = 1, height = 70.dp)
                    byDay.isEmpty() -> FlNotice(stringResource(R.string.cs_no_slots, o.rules.maxDaysAhead), Icons.Rounded.EventBusy, C_AMBER)
                    else -> {
                        LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            items(byDay.keys.toList(), key = { it.toString() }) { d -> DayChip(d, byDay[d]?.size ?: 0, d.toString() == day) { day = d.toString() } }
                        }
                        val times = day?.let { byDay[LocalDate.parse(it)] }.orEmpty()
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            times.forEach { s ->
                                val on = s == slot
                                Text(
                                    "‎" + hm(s), color = if (on) Color.White else settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center,
                                    modifier = Modifier.width(76.dp).clip(RoundedCornerShape(12.dp))
                                        .background(if (on) settingsAccent() else Color.Transparent)
                                        .border(1.dp, if (on) settingsAccent() else settingsMut().copy(alpha = 0.3f), RoundedCornerShape(12.dp))
                                        .clickable { slot = s }.padding(vertical = 10.dp),
                                )
                            }
                        }
                        Text(stringResource(R.string.cs_local_time), color = settingsMut(), fontSize = 11.sp)
                    }
                }
            }

            // 4. What about.
            CsCard {
                StepHead(3, stringResource(R.string.cs_step_topic), topic.trim().length >= 10)
                DorrTextField(topic, { topic = it.take(1000) }, placeholder = stringResource(R.string.cs_topic_hint), singleLine = false, minLines = 4, modifier = Modifier.fillMaxWidth())
                Text(stringResource(R.string.cs_topic_rule), color = settingsMut(), fontSize = 11.sp)
            }

            // The rules, said before paying.
            CsCard(stringResource(R.string.cs_rules_title), Icons.Rounded.Gavel) {
                listOf(
                    Icons.Rounded.Lock to stringResource(R.string.cs_rule_held),
                    Icons.Rounded.HourglassTop to if (special != null) stringResource(R.string.spc_rule_instant) else stringResource(R.string.cs_rule_accept, o.rules.responseHours),
                    Icons.Rounded.EventBusy to stringResource(R.string.cs_rule_cancel, o.rules.freeCancelHours),
                    Icons.Rounded.ReportProblem to stringResource(R.string.cs_rule_problem, o.rules.complaintHours),
                ).forEach { (icon, line) ->
                    Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(17.dp))
                        Text(line, color = settingsInk(), fontSize = 12.5.sp, lineHeight = 18.sp, modifier = Modifier.weight(1f))
                    }
                }
            }
            Spacer(Modifier.height(4.dp))
        }

        // The bar at the bottom: the total and the button.
        Row(
            Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(topStart = 22.dp, topEnd = 22.dp)).padding(horizontal = 16.dp, vertical = 12.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.cs_total), color = settingsMut(), fontSize = 11.sp)
                Text((deal?.totalMinor ?: option?.totalMinor)?.let { mkMoney(it, o.decimals, o.currency) } ?: "—", color = if (deal != null) C_GREEN else settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                slot?.let { Text("${longDay(it)} · ‎${hm(it)}", color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis) }
            }
            SettingsPrimaryButton(text = stringResource(R.string.cs_book_cta), enabled = ready, modifier = Modifier.width(170.dp), onClick = { bookError = null; confirming = true })
        }
    }

    val o = offer
    if (confirming && o != null && option != null && slot != null) {
        FlSheet(
            Icons.Rounded.EventAvailable, stringResource(R.string.cs_confirm_title), stringResource(R.string.cs_confirm_body), onDismiss = { if (!busy) confirming = false },
            // The send button stays at the bottom while the rest scrolls, so it is never below the fold.
            footer = {
                bookError?.let { FlNotice(it, Icons.Rounded.Close, C_RED) }
                RecordingConsentRow(consent) { consent = it }
                SettingsPrimaryButton(text = stringResource(R.string.cs_confirm_cta), enabled = consent, loading = busy, onClick = ::book)
            },
        ) {
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    FlAvatar(o.freelancer.avatarUrl, o.freelancer.name, 34)
                    Column(Modifier.weight(1f)) {
                        Text(o.freelancer.name.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                        Text("${longDay(slot)} · ‎${hm(slot)}", color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    }
                }
                HorizontalDivider(color = settingsMut().copy(alpha = 0.15f))
                CsLine(stringResource(R.string.cs_minutes, option.minutes), mkMoney(deal?.priceMinor ?: option.listPriceMinor ?: option.priceMinor, o.decimals, o.currency))
                if (deal != null) CsLine(stringResource(R.string.spc_you_save), "− " + mkMoney(deal.listPriceMinor - deal.priceMinor, o.decimals, o.currency))
                // FL-L09: the loyalty discount off the length's usual price.
                else LoyaltyDiscountLine(option.loyaltyBp, mkMoney((option.listPriceMinor ?: option.priceMinor) - option.priceMinor, o.decimals, o.currency))
                val fee = deal?.clientFeeMinor ?: option.clientFeeMinor
                if (fee > 0) CsLine(stringResource(R.string.mk_fee), mkMoney(fee, o.decimals, o.currency))
                CsLine(stringResource(R.string.mk_total), mkMoney(deal?.totalMinor ?: option.totalMinor, o.decimals, o.currency), strong = true)
            }
            FlNotice(if (special != null) stringResource(R.string.spc_confirm_instant) else stringResource(R.string.cs_confirm_safe, o.rules.responseHours), Icons.Rounded.Lock, C_GREEN)
        }
    }
}

@Composable
private fun DayChip(d: LocalDate, count: Int, on: Boolean, onClick: () -> Unit) {
    val accent = settingsAccent()
    Column(
        Modifier.width(66.dp).clip(RoundedCornerShape(16.dp)).background(if (on) accent else accent.copy(alpha = 0.06f))
            .border(1.dp, if (on) accent else Color.Transparent, RoundedCornerShape(16.dp)).clickable(onClick = onClick).padding(vertical = 10.dp),
        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(1.dp),
    ) {
        Text(DateTimeFormatter.ofPattern("EEE", appLocale()).format(d), color = if (on) Color.White.copy(alpha = 0.9f) else settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1)
        Text("${d.dayOfMonth}", color = if (on) Color.White else settingsInk(), fontSize = 20.sp, fontWeight = FontWeight.ExtraBold)
        Text(DateTimeFormatter.ofPattern("MMM", appLocale()).format(d), color = if (on) Color.White.copy(alpha = 0.9f) else settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1)
        Text(stringResource(R.string.cs_times_n, count), color = if (on) Color.White else C_GREEN, fontSize = 10.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

// ==================================================================== my consultations

private val TABS = listOf("upcoming", "past", "cancelled")

@Composable
internal fun MyConsultationsPage(onBack: () -> Unit, onOpen: (String) -> Unit, embedded: Boolean = false) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var tab by rememberSaveable { mutableStateOf(0) }
    val listState = rememberLazyListState()
    val paged = remember(tab) { PagedRows<ConsultDto>({ it.uuid }, { page -> ApiClient.freelanceConsultations.list(auth(), tab = TABS[tab], page = page) }) }
    LaunchedEffect(paged, reconnect) { paged.reload(generic) }
    listState.NearEnd(paged.rows.size) { scope.launch { paged.loadMore(generic) } }

    Column(Modifier.fillMaxSize()) {
        // Inside «My activity» the head is the hub's and the statuses are chips.
        if (!embedded) FlPageHeader(stringResource(R.string.cs_mine_title), onBack)
        FlPartTabs(
            listOf(
                FlTab(Icons.Rounded.EventAvailable, stringResource(R.string.cs_tab_upcoming)),
                FlTab(Icons.Rounded.CheckCircle, stringResource(R.string.cs_tab_past)),
                FlTab(Icons.Rounded.EventBusy, stringResource(R.string.cs_tab_cancelled)),
            ),
            tab, compact = embedded,
        ) { tab = it }
        LazyColumn(Modifier.weight(1f).fillMaxWidth(), state = listState, contentPadding = PaddingValues(horizontal = 16.dp, vertical = 10.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            when {
                !paged.loaded -> item { FlSkeletonList(count = 3, height = 110.dp) }
                paged.rows.isEmpty() && paged.error == null -> item {
                    com.dorr.app.ui.screens.wallet.WaEmpty(
                        Icons.Rounded.VideoChat, com.dorr.app.ui.screens.wallet.Tone.Blue, stringResource(R.string.cs_empty_title), stringResource(R.string.cs_empty_body),
                    )
                }
                else -> items(paged.rows, key = { it.uuid }) { c -> ConsultTile(c) { onOpen(c.uuid) } }
            }
            item { PagedSpinner(paged) }
            paged.error?.let { item { TextButton(onClick = { paged.clearError(); scope.launch { paged.loadMore(generic) } }, modifier = Modifier.fillMaxWidth()) { Text(it, color = C_RED, fontSize = 13.sp) } } }
        }
    }
}

@Composable
private fun ConsultTile(c: ConsultDto, onClick: () -> Unit) {
    val (label, color) = phase(c.phase)
    val at = instantOf(c.startsAt)?.atZone(ZoneId.systemDefault())
    Row(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).clickable(onClick = onClick).padding(12.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        Column(
            Modifier.width(58.dp).clip(RoundedCornerShape(16.dp)).background(color.copy(alpha = if (settingsNight()) 0.22f else 0.12f)).padding(vertical = 10.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(at?.let { DateTimeFormatter.ofPattern("EEE", appLocale()).format(it) }.orEmpty(), color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1)
            Text(at?.dayOfMonth?.toString().orEmpty(), color = color, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold)
            Text(at?.let { DateTimeFormatter.ofPattern("MMM", appLocale()).format(it) }.orEmpty(), color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1)
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("‎${hm(c.startsAt)} – ${hm(c.endsAt)}", color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                FlDotPill(label, color)
            }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                FlAvatar(c.freelancer?.avatarUrl, c.freelancer?.name, 20)
                Text(stringResource(R.string.cs_with, c.freelancer?.name.orEmpty(), c.minutes), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            c.topic?.let { Text(it, color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(mkMoney(c.money.totalMinor, c.decimals, c.currency), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                if (c.phase == "upcoming") until(c.startsAt)?.let {
                    Icon(Icons.Rounded.Timer, null, tint = settingsMut(), modifier = Modifier.size(14.dp))
                    Text(it, color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
                }
                if (c.can.confirm) FlDotPill(stringResource(R.string.cs_needs_you), C_AMBER)
                else if (c.review.canRate) FlDotPill(stringResource(R.string.cs_rate_needs), C_AMBER)
            }
        }
    }
}

// ==================================================================== one consultation

@Composable
internal fun ConsultationPage(uuid: String, onBack: () -> Unit) {
    val context = LocalContext.current
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var c by remember(uuid) { mutableStateOf<ConsultDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var cancelling by remember { mutableStateOf(false) }
    var confirming by remember { mutableStateOf(false) }
    var complaining by remember { mutableStateOf(false) }
    var complaint by rememberSaveable { mutableStateOf("") }
    var rating by remember { mutableStateOf(false) }
    var stars by rememberSaveable { mutableStateOf(0) }
    var opinion by rememberSaveable { mutableStateOf("") }
    // FL-L16: one more read when the session card asks (the room opened by the clock, a no-show sent) or the session screen closed.
    var reloads by remember { mutableStateOf(0) }

    LaunchedEffect(uuid, reconnect, reloads, ConsultSession.closedCount) {
        try {
            c = ApiClient.freelanceConsultations.show(auth(), uuid).data
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    /** Runs one step; [then] gets the new state (after it went well it opens the rating, when it can be rated). */
    fun act(done: Int, then: (ConsultDto) -> Unit = {}, call: suspend () -> ConsultDto?) {
        busy = true
        scope.launch {
            var fresh: ConsultDto? = null
            try {
                fresh = call()?.also { c = it }
                error = null
                Toast.makeText(context, context.getString(done), Toast.LENGTH_SHORT).show()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.apiFailure().message ?: generic
            }
            busy = false
            cancelling = false
            confirming = false
            complaining = false
            rating = false
            fresh?.let(then)
        }
    }

    Column(Modifier.fillMaxSize()) {
        FlPageHeader(stringResource(R.string.cs_detail_title), onBack)
        val x = c
        if (x == null) {
            FlLoading(error)
            return@Column
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp, vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            Head(x)
            error?.let { FlNotice(it, Icons.Rounded.Close, C_RED) }
            Notes(x)
            x.session?.let { s -> ConsultSessionCard(uuid, x, s, onChanged = { fresh -> if (fresh != null) c = fresh else reloads++ }) }
            if (x.can.confirm || x.can.complain) Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                if (x.can.confirm) FlAction(stringResource(R.string.cs_confirm_ok), Icons.Rounded.ThumbUp, FlTone.Primary, modifier = Modifier.weight(1f)) { confirming = true }
                if (x.can.complain) FlAction(stringResource(R.string.cs_complain), Icons.Rounded.ReportProblem, modifier = Modifier.weight(1f)) { complaining = true }
            }
            ReviewBox(x.review) { rating = true }
            x.topic?.takeIf { it.isNotBlank() }?.let { topic ->
                CsCard(stringResource(R.string.cs_topic), Icons.Rounded.FormatQuote) {
                    Text(topic, color = settingsInk(), fontSize = 14.sp, lineHeight = 21.sp, modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(12.dp))
                }
            }
            CsCard(stringResource(R.string.cs_money), Icons.Rounded.Payments) {
                CsLine(stringResource(R.string.cs_minutes, x.minutes), mkMoney(x.listPriceMinor ?: x.money.priceMinor, x.decimals, x.currency))
                LoyaltyDiscountLine(x.loyaltyBp, mkMoney(x.savingMinor ?: 0, x.decimals, x.currency))
                if (x.money.clientFeeMinor > 0) CsLine(stringResource(R.string.mk_fee), mkMoney(x.money.clientFeeMinor, x.decimals, x.currency))
                CsLine(stringResource(R.string.mk_total), mkMoney(x.money.totalMinor, x.decimals, x.currency), strong = true)
            }
            if (x.can.cancel) {
                Text(
                    stringResource(R.string.cs_cancel), color = C_RED, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).clickable(enabled = !busy) { cancelling = true }.padding(14.dp),
                )
            }
            Spacer(Modifier.height(8.dp))
        }
    }

    // FL-L16: the video session, full screen over the page while I am in it.
    if (ConsultSession.uuid == uuid && ConsultSession.phase != null) ConsultSessionScreen()
    val x = c ?: return
    if (cancelling) {
        ConfirmDialog(
            icon = Icons.Rounded.EventBusy, title = stringResource(R.string.cs_cancel_title),
            message = if (x.can.cancelFree) stringResource(R.string.cs_cancel_free, mkMoney(x.money.totalMinor, x.decimals, x.currency))
            else stringResource(R.string.cs_cancel_late, x.rules.freeCancelHours),
            confirmLabel = stringResource(R.string.cs_cancel_confirm), loading = busy,
            onConfirm = { act(R.string.cs_cancelled_done) { ApiClient.freelanceConsultations.cancel(auth(), uuid, ConsultReasonRequest()).data } },
            onDismiss = { cancelling = false },
        )
    }
    if (confirming) {
        ConfirmDialog(
            icon = Icons.Rounded.ThumbUp, title = stringResource(R.string.cs_confirm_ok_title), message = stringResource(R.string.cs_confirm_ok_body),
            confirmLabel = stringResource(R.string.cs_confirm_ok), loading = busy,
            onConfirm = { act(R.string.cs_confirm_ok_done, then = { if (it.review.canRate) rating = true }) { ApiClient.freelanceConsultations.confirm(auth(), uuid).data } },
            onDismiss = { confirming = false },
        )
    }
    if (complaining) {
        FlSheet(
            Icons.Rounded.ReportProblem, stringResource(R.string.cs_complain_title), stringResource(R.string.cs_complain_body), onDismiss = { if (!busy) complaining = false },
            footer = {
                SettingsPrimaryButton(
                    text = stringResource(R.string.cs_complain_send), enabled = complaint.trim().length >= 10, loading = busy,
                    onClick = { act(R.string.cs_complain_done) { ApiClient.freelanceConsultations.complain(auth(), uuid, ConsultComplaintRequest(complaint.trim())).data } },
                )
            },
        ) {
            DorrTextField(complaint, { complaint = it.take(2000) }, placeholder = stringResource(R.string.cs_complain_hint), singleLine = false, minLines = 4, modifier = Modifier.fillMaxWidth())
            FlNotice(stringResource(R.string.cs_complain_safe), Icons.Rounded.Lock, C_GREEN)
        }
    }
    if (rating) {
        FlSheet(
            Icons.Rounded.Star, stringResource(R.string.cs_rate_title), stringResource(R.string.cs_rate_body, x.freelancer?.name.orEmpty()), onDismiss = { if (!busy) rating = false },
            footer = {
                SettingsPrimaryButton(
                    text = stringResource(R.string.cs_rate_send), enabled = stars in 1..5, loading = busy,
                    onClick = { act(R.string.cs_rate_done) { ApiClient.freelanceConsultations.review(auth(), uuid, ConsultReviewRequest(stars, opinion.trim().ifBlank { null })).data } },
                )
            },
        ) {
            Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Stars(stars, 40) { stars = it }
                if (stars > 0) Text(stringResource(starWord(stars)), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            }
            DorrTextField(opinion, { opinion = it.take(1000) }, placeholder = stringResource(R.string.cs_rate_hint), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
        }
    }
}

/** Once it is over: ask for the client's stars while they may still give them, or show the ones given. */
@Composable
private fun ReviewBox(r: ConsultReviewDto, onRate: () -> Unit) {
    val given = r.rating
    if (!r.canRate && given == null) return
    CsCard(stringResource(if (given == null) R.string.cs_rate_title else R.string.cs_rate_mine), Icons.Rounded.Star) {
        if (given == null) {
            Text(stringResource(R.string.cs_rate_until, "${longDay(r.rateUntil)} \u200E${hm(r.rateUntil)}"), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
            FlAction(stringResource(R.string.cs_rate_cta), Icons.Rounded.Star, FlTone.Primary, onClick = onRate)
        } else {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Stars(Math.round(given.stars).toInt(), 22)
                Text(stringResource(starWord(Math.round(given.stars).toInt())), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            }
            given.comment?.takeIf { it.isNotBlank() }?.let { Text("“$it”", color = settingsInk(), fontSize = 13.sp, lineHeight = 20.sp) }
            Text(stringResource(R.string.cs_rate_public), color = settingsMut(), fontSize = 11.sp)
        }
    }
}

private fun starWord(n: Int): Int = when (n) {
    1 -> R.string.cs_star_1
    2 -> R.string.cs_star_2
    3 -> R.string.cs_star_3
    4 -> R.string.cs_star_4
    else -> R.string.cs_star_5
}

@Composable
private fun Head(c: ConsultDto) {
    val (label, color) = phase(c.phase)
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(22.dp)).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            FlDotPill(label, color, solid = true)
            Spacer(Modifier.weight(1f))
            Text(stringResource(R.string.cs_minutes, c.minutes), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
        }
        Text(c.title.orEmpty(), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 25.sp)
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(color.copy(alpha = if (settingsNight()) 0.22f else 0.12f)).padding(14.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Icon(Icons.Rounded.CalendarMonth, null, tint = color, modifier = Modifier.size(26.dp))
            Column(Modifier.weight(1f)) {
                Text(longDay(c.startsAt), color = color, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                Text("‎${hm(c.startsAt)} – ${hm(c.endsAt)}", color = color, fontSize = 13.sp, fontWeight = FontWeight.Bold)
            }
            if (c.phase == "upcoming" || c.phase == "pending") until(c.startsAt)?.let {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Icon(Icons.Rounded.HourglassTop, null, tint = color, modifier = Modifier.size(16.dp))
                    Text(it, color = color, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold)
                }
            }
        }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            FlAvatar(c.freelancer?.avatarUrl, c.freelancer?.name, 38)
            Column(Modifier.weight(1f)) {
                Text(c.freelancer?.name.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                c.freelancer?.headline?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
            }
        }
    }
}

/** What happens next, or what happened, plainly. */
@Composable
private fun Notes(c: ConsultDto) {
    when (c.phase) {
        "pending" -> FlNotice(stringResource(R.string.cs_note_pending, c.freelancer?.name.orEmpty(), "${longDay(c.respondBy)} \u200E${hm(c.respondBy)}"), Icons.Rounded.HourglassTop, C_AMBER)
        "declined" -> FlNotice(
            stringResource(when {
                c.cancel?.by == "freelancer" -> R.string.cs_note_declined_freelancer
                c.cancel?.reason == "taken" -> R.string.cs_note_declined_taken
                else -> R.string.cs_note_declined_expired
            }) + (c.cancel?.reason?.takeIf { c.cancel.by == "freelancer" }?.let { "\n“$it”" } ?: ""),
            Icons.Rounded.EventBusy, C_SLATE,
        )
        "upcoming" -> FlNotice(
            if (c.can.cancelFree) stringResource(R.string.cs_note_upcoming_free, "${longDay(c.rules.freeCancelUntil)} ‎${hm(c.rules.freeCancelUntil)}") else stringResource(R.string.cs_note_upcoming_late),
            Icons.Rounded.Info, settingsAccent(),
        )
        "live" -> FlNotice(stringResource(R.string.cs_note_live), Icons.Rounded.VideoChat, C_GREEN)
        "ended" -> FlNotice(stringResource(R.string.cs_note_ended, "${longDay(c.rules.releaseAt)} ‎${hm(c.rules.releaseAt)}"), Icons.Rounded.Schedule, C_AMBER)
        "completed" -> FlNotice(stringResource(R.string.cs_note_completed), Icons.Rounded.CheckCircle, C_GREEN)
        "disputed" -> FlNotice(stringResource(R.string.cs_note_disputed), Icons.Rounded.Gavel, C_AMBER)
        "refunded" -> FlNotice(stringResource(R.string.cs_note_refunded) + (c.resolution?.note?.let { "\n$it" } ?: ""), Icons.Rounded.CheckCircle, C_GREEN)
        else -> FlNotice(
            when {
                c.cancel?.by == "freelancer" -> stringResource(R.string.cs_note_cancelled_freelancer)
                c.cancel?.late == true -> stringResource(R.string.cs_note_cancelled_late)
                else -> stringResource(R.string.cs_note_cancelled_me)
            } + (c.cancel?.reason?.let { "\n“$it”" } ?: ""),
            Icons.Rounded.EventBusy, C_SLATE,
        )
    }
}
