package com.dorr.app.ui.screens.events

import android.content.Intent
import android.net.Uri
import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Explore
import androidx.compose.material.icons.rounded.Favorite
import androidx.compose.material.icons.rounded.FavoriteBorder
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.OpenInNew
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Share
import androidx.compose.material.icons.rounded.Update
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.DiscCategoryDto
import com.dorr.app.network.DiscCityDto
import com.dorr.app.network.DiscEventDto
import com.dorr.app.network.MessageDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.moments.momentColor
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaNote
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.rememberPressScale
import com.dorr.app.ui.screens.wallet.waRise
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.time.format.TextStyle
import java.util.Locale
import kotlin.math.max
import kotlin.math.roundToInt
import com.dorr.app.network.AppLocale
import com.dorr.app.ui.screens.chat.ChSheet
import com.dorr.app.ui.screens.chat.bool

// =============================================================================== looks

@Composable
internal fun categoryColor(c: DiscCategoryDto?): Color = momentColor(c?.color, EvPurple)

@Composable
internal fun categoryBrush(c: DiscCategoryDto?): Brush {
    val base = categoryColor(c)
    return Brush.linearGradient(listOf(base, Color(base.red * 0.6f, base.green * 0.6f, base.blue * 0.6f)))
}

@Composable
internal fun statusLabel(status: String): String? = when (status) {
    "postponed" -> stringResource(R.string.ev_st_postponed)
    "cancelled" -> stringResource(R.string.ev_st_cancelled)
    "sold_out" -> stringResource(R.string.ev_st_sold_out)
    "ended" -> stringResource(R.string.ev_st_ended)
    else -> null
}

internal fun statusColor(status: String): Color = when (status) {
    "postponed" -> Color(0xFFD97706)
    "cancelled" -> Color(0xFFDC2626)
    "sold_out" -> Color(0xFF2563EB)
    "ended" -> Color(0xFF6B7280)
    else -> Color(0xFF16A34A)
}

private fun localDate(e: DiscEventDto): LocalDate? = e.localDate?.let { runCatching { LocalDate.parse(it) }.getOrNull() }

/** "Thu 5 Nov · 20:00" in the event's own place. */
internal fun whenText(e: DiscEventDto): String =
    listOfNotNull(localDate(e)?.let { dayText(it) }, e.localTime).joinToString(" · ")

/** "21:00 your time" when I'm in another zone. */
@Composable
internal fun myTimeText(e: DiscEventDto): String? = e.myTime?.let { t ->
    runCatching { LocalDateTime.parse(t.replace(' ', 'T')) }.getOrNull()?.let {
        stringResource(R.string.ev_my_time, it.format(DateTimeFormatter.ofPattern("EEE d MMM · HH:mm", AppLocale.java())))
    }
}

@Composable
private fun priceText(e: DiscEventDto): String {
    if (e.isFree) return stringResource(R.string.ev_free)
    e.priceDisplay?.trim()?.takeIf { it.isNotEmpty() }?.let { return it }
    val raw = e.priceText?.trim().orEmpty()
    val symbol = e.currency?.symbol?.takeIf { it.isNotBlank() } ?: e.currency?.code
    val compact = raw.replace(" ", "").replace(",", "")
    if (compact.isNotEmpty() && compact.all { it.isDigit() || it == '.' } && !symbol.isNullOrBlank()) {
        return "$compact $symbol"
    }
    return raw.ifBlank { stringResource(R.string.ev_paid) }
}

@Composable
internal fun distanceText(km: Double): String = when {
    km < 1.0 -> stringResource(R.string.ev_m, max(1, (km * 1000).roundToInt()))
    km < 10.0 -> stringResource(R.string.ev_km, String.format(Locale.getDefault(), "%.1f", km))
    else -> stringResource(R.string.ev_km, km.roundToInt().toString())
}

/** Hide a description that is only the booking or official URL — those become buttons. */
private fun visibleDescription(e: DiscEventDto): String? {
    val text = e.description?.trim().orEmpty()
    if (text.isBlank()) return null
    val links = listOfNotNull(e.bookingUrl, e.sourceUrl).map { it.trim().trimEnd('/') }
    val compact = text.trimEnd('/')
    if (links.any { it.equals(compact, ignoreCase = true) }) return null
    val onlyUrl = (text.startsWith("http://") || text.startsWith("https://")) && ' ' !in text && '\n' !in text
    if (onlyUrl && links.isNotEmpty()) return null
    return e.description
}

// =============================================================================== cards

/** A home-row card: smaller cover, title and place, price and a tappable save heart at the bottom. */
@Composable
internal fun EventCard(e: DiscEventDto, modifier: Modifier = Modifier.width(196.dp), onClick: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val press by rememberPressScale(source, 0.97f)
    Column(
        modifier.scale(press).clip(RoundedCornerShape(18.dp)).background(Wa.Surface)
            .clickable(interactionSource = source, indication = null, onClick = onClick),
    ) {
        Box(Modifier.fillMaxWidth().height(96.dp).background(categoryBrush(e.category))) {
            if (e.cover != null) {
                AsyncImage(model = ApiClient.mediaUrl(e.cover), contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
            } else {
                Text(e.category?.emoji ?: "🎟️", fontSize = 32.sp, modifier = Modifier.align(Alignment.Center))
            }
            DateBadge(e, Modifier.align(Alignment.TopStart).padding(8.dp), compact = true)
            statusLabel(e.status)?.let {
                Text(
                    it, color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.ExtraBold,
                    modifier = Modifier.align(Alignment.TopEnd).padding(8.dp).clip(CircleShape).background(statusColor(e.status)).padding(horizontal = 7.dp, vertical = 2.dp),
                )
            }
        }
        Column(Modifier.padding(horizontal = 10.dp, vertical = 9.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(e.title, color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                if (e.verified) Icon(Icons.Rounded.Verified, stringResource(R.string.ev_verified), tint = Color(0xFF2563EB), modifier = Modifier.padding(start = 4.dp).size(14.dp))
            }
            Text(
                listOfNotNull(e.localTime, e.venue, e.city?.name).joinToString(" · "),
                color = Wa.Mut, fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
                modifier = Modifier.padding(top = 3.dp),
            )
            Row(Modifier.fillMaxWidth().padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(
                    priceText(e), color = Wa.Green, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold,
                    modifier = Modifier.clip(CircleShape).background(Wa.Green.copy(alpha = 0.14f)).padding(horizontal = 8.dp, vertical = 3.dp),
                )
                e.distanceKm?.let {
                    Spacer(Modifier.width(6.dp))
                    Text(distanceText(it), color = Wa.Soft, fontSize = 11.sp, maxLines = 1)
                }
                Spacer(Modifier.weight(1f))
                SaveHeart(e, compact = true)
            }
        }
    }
}

@Composable
private fun DateBadge(e: DiscEventDto, modifier: Modifier = Modifier, compact: Boolean = false) {
    val d = localDate(e) ?: return
    Column(
        modifier.clip(RoundedCornerShape(if (compact) 11.dp else 14.dp)).background(Color.White)
            .padding(horizontal = if (compact) 7.dp else 10.dp, vertical = if (compact) 3.dp else 5.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(d.dayOfMonth.toString(), color = Color(0xFF111928), fontSize = if (compact) 15.sp else 17.sp, fontWeight = FontWeight.ExtraBold)
        Text(d.month.getDisplayName(TextStyle.SHORT, AppLocale.java()), color = categoryColor(e.category), fontSize = if (compact) 9.sp else 10.5.sp, fontWeight = FontWeight.ExtraBold)
    }
}

@Composable
private fun SaveHeart(e: DiscEventDto, compact: Boolean = false, onChanged: ((DiscEventDto) -> Unit)? = null) {
    var ev by remember { mutableStateOf(e) }
    LaunchedEffect(e) { ev = e }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val canSave = ev.status != "cancelled" && ev.status != "ended" && ev.reviewStatus.let { r -> r == null || r == "approved" }
    val size = if (compact) 32.dp else 40.dp
    Box(
        Modifier.size(size).clip(CircleShape)
            .background(if (ev.interested) Wa.Red.copy(alpha = 0.16f) else Wa.Field)
            .clickable(enabled = canSave && !busy) {
                busy = true
                scope.launch {
                    val cur = ev
                    if (cur.interested) {
                        runCatching { ApiClient.events.uninterest(evAuth(), cur.id) }
                            .onSuccess {
                                val next = cur.copy(interested = false, notify = false, interestedCount = (cur.interestedCount - 1).coerceAtLeast(0))
                                ev = next
                                onChanged?.invoke(next)
                                EventsStore.changed()
                            }
                            .onFailure { Toast.makeText(context, it.apiFailure().message, Toast.LENGTH_LONG).show() }
                    } else {
                        runCatching { ApiClient.events.interest(evAuth(), cur.id, JsonObject().apply { addProperty("notify", true) }, evZone()).data }
                            .onSuccess { r ->
                                val next = cur.copy(interested = true, notify = r?.notify ?: true, interestedCount = r?.interestedCount ?: (cur.interestedCount + 1))
                                ev = next
                                onChanged?.invoke(next)
                                EventsStore.changed()
                            }
                            .onFailure { Toast.makeText(context, it.apiFailure().message, Toast.LENGTH_LONG).show() }
                    }
                    busy = false
                }
            },
        contentAlignment = Alignment.Center,
    ) {
        Icon(
            if (ev.interested) Icons.Rounded.Favorite else Icons.Rounded.FavoriteBorder,
            stringResource(if (ev.interested) R.string.ev_interested_on else R.string.ev_interested),
            tint = if (canSave) Wa.Red else Wa.Soft,
            modifier = Modifier.size(if (compact) 16.dp else 20.dp),
        )
    }
}

/** One event in a list: the date block, title and place, then a save heart with the price under it. */
@Composable
internal fun EventRow(e: DiscEventDto, modifier: Modifier = Modifier, review: String? = null, onInterest: ((DiscEventDto) -> Unit)? = null, onClick: () -> Unit) {
    var ev by remember { mutableStateOf(e) }
    LaunchedEffect(e) { ev = e }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val canSave = ev.status != "cancelled" && ev.status != "ended" && ev.reviewStatus.let { r -> r == null || r == "approved" }
    val color = categoryColor(ev.category)
    val d = localDate(ev)
    val priceColor = Wa.Green
    Row(modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Wa.Surface).clickable(onClick = onClick).padding(horizontal = 8.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(56.dp).clip(RoundedCornerShape(14.dp)).background(categoryBrush(ev.category)), contentAlignment = Alignment.Center) {
            if (ev.cover != null) AsyncImage(model = ApiClient.mediaUrl(ev.cover), contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
            Column(Modifier.clip(RoundedCornerShape(12.dp)).background(Color.White.copy(alpha = 0.92f)).padding(horizontal = 8.dp, vertical = 3.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Text(d?.dayOfMonth?.toString() ?: "—", color = Color(0xFF111928), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                Text(d?.month?.getDisplayName(TextStyle.SHORT, AppLocale.java()).orEmpty(), color = color, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold)
            }
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(ev.title, color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                if (ev.verified) Icon(Icons.Rounded.Verified, null, tint = Color(0xFF2563EB), modifier = Modifier.padding(start = 4.dp).size(15.dp))
            }
            Row(Modifier.fillMaxWidth().padding(top = 3.dp), verticalAlignment = Alignment.CenterVertically) {
                ev.localTime?.let {
                    Text(it, color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Medium, maxLines = 1)
                }
                val place = listOfNotNull(ev.venue, ev.city?.name).joinToString(" · ")
                if (place.isNotEmpty()) {
                    if (ev.localTime != null) Text(" · ", color = Wa.Mut, fontSize = 12.sp)
                    Icon(Icons.Rounded.Place, null, tint = Wa.Mut, modifier = Modifier.size(12.dp))
                    Spacer(Modifier.width(3.dp))
                    Text(place, color = Wa.Mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                }
                ev.distanceKm?.let {
                    Text(" · " + distanceText(it), color = Wa.Soft, fontSize = 11.sp, fontWeight = FontWeight.Medium, maxLines = 1)
                }
            }
            val tags = buildList {
                statusLabel(ev.status)?.let { add(it to statusColor(ev.status)) }
                review?.takeIf { it != "approved" }?.let {
                    add(stringResource(if (it == "rejected") R.string.ev_rv_rejected else R.string.ev_rv_pending) to if (it == "rejected") Wa.Danger else Color(0xFFD97706))
                }
            }
            if (tags.isNotEmpty()) {
                Row(Modifier.padding(top = 5.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    tags.forEach { (label, tint) -> MiniTag(label, tint) }
                }
            }
        }
        Spacer(Modifier.width(8.dp))
        Column(
            Modifier.widthIn(min = 58.dp, max = 96.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            Box(
                Modifier.size(40.dp).clip(CircleShape)
                    .background(if (ev.interested) Wa.Red.copy(alpha = 0.16f) else Wa.Field)
                    .clickable(enabled = canSave && !busy) {
                        busy = true
                        scope.launch {
                            val cur = ev
                            if (cur.interested) {
                                runCatching { ApiClient.events.uninterest(evAuth(), cur.id) }
                                    .onSuccess {
                                        val next = cur.copy(interested = false, notify = false, interestedCount = (cur.interestedCount - 1).coerceAtLeast(0))
                                        ev = next
                                        onInterest?.invoke(next)
                                        EventsStore.changed()
                                    }
                                    .onFailure { Toast.makeText(context, it.apiFailure().message, Toast.LENGTH_LONG).show() }
                            } else {
                                runCatching { ApiClient.events.interest(evAuth(), cur.id, JsonObject().apply { addProperty("notify", true) }, evZone()).data }
                                    .onSuccess { r ->
                                        val next = cur.copy(interested = true, notify = r?.notify ?: true, interestedCount = r?.interestedCount ?: (cur.interestedCount + 1))
                                        ev = next
                                        onInterest?.invoke(next)
                                        EventsStore.changed()
                                    }
                                    .onFailure { Toast.makeText(context, it.apiFailure().message, Toast.LENGTH_LONG).show() }
                            }
                            busy = false
                        }
                    },
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    if (ev.interested) Icons.Rounded.Favorite else Icons.Rounded.FavoriteBorder,
                    stringResource(if (ev.interested) R.string.ev_interested_on else R.string.ev_interested),
                    tint = if (canSave) Wa.Red else Wa.Soft,
                    modifier = Modifier.size(20.dp),
                )
            }
            Text(
                priceText(ev),
                color = priceColor,
                fontSize = 11.sp,
                fontWeight = FontWeight.ExtraBold,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                textAlign = TextAlign.Center,
                modifier = Modifier.clip(CircleShape).background(priceColor.copy(alpha = 0.14f)).padding(horizontal = 8.dp, vertical = 4.dp),
            )
        }
    }
}

@Composable
private fun MiniTag(text: String, color: Color) {
    Text(text, color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1, modifier = Modifier.clip(CircleShape).background(color.copy(alpha = 0.12f)).padding(horizontal = 7.dp, vertical = 2.dp))
}

@Composable
internal fun CategoryTile(c: DiscCategoryDto, onClick: () -> Unit) {
    Column(
        Modifier.width(80.dp).height(78.dp).clip(RoundedCornerShape(16.dp)).background(categoryBrush(c)).clickable(onClick = onClick).padding(horizontal = 6.dp, vertical = 8.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Text(c.emoji ?: "🎟️", fontSize = 22.sp)
        Spacer(Modifier.height(4.dp))
        Text(c.name.orEmpty(), color = Color.White, fontSize = 11.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis, textAlign = TextAlign.Center)
    }
}

// =============================================================================== home banner

/** "What's on around you" on the home page — hidden when Discover is off here. */
@Composable
fun EventsBanner(onOpen: () -> Unit, modifier: Modifier = Modifier) {
    LaunchedEffect(Unit) { if (EventsStore.home == null) EventsStore.refresh() }
    if (EventsStore.off) return
    val next = EventsStore.home?.sections?.firstOrNull()?.items?.firstOrNull()
    val source = remember { MutableInteractionSource() }
    val press by rememberPressScale(source, 0.98f)
    Row(
        modifier.fillMaxWidth().scale(press).clip(RoundedCornerShape(24.dp)).background(EvHeroBrush)
            .clickable(interactionSource = source, indication = null, onClick = onOpen).padding(16.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(52.dp).clip(RoundedCornerShape(18.dp)).background(Color.White.copy(alpha = 0.18f)), contentAlignment = Alignment.Center) { Text("🎟️", fontSize = 26.sp) }
        Spacer(Modifier.width(14.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.ev_banner_title), color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
            Text(
                next?.let { "${it.category?.emoji ?: "✨"}  ${it.title} · ${whenText(it)}" } ?: stringResource(R.string.ev_banner_sub),
                color = Color.White.copy(alpha = 0.9f), fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
            )
        }
        Icon(Icons.Rounded.Explore, null, tint = Color.White, modifier = Modifier.size(24.dp))
    }
}

// =============================================================================== the event (174–179)

@Composable
internal fun EventDetail(id: String, onBack: () -> Unit) {
    var e by remember { mutableStateOf<DiscEventDto?>(null) }
    var missing by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var sheet by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val roomMade = stringResource(R.string.ev_room_made)
    val coverSaved = stringResource(R.string.ev_cover_saved)
    val coverReview = stringResource(R.string.ev_cover_review)
    var uploading by remember { mutableStateOf(false) }
    val pickCover = rememberCoverPicker { uri ->
        val ev = e ?: return@rememberCoverPicker
        uploading = true
        scope.launch {
            val part = EventCover.part(context, uri)
            if (part != null) {
                runCatching { ApiClient.events.updateEvent(evAuth(), ev.id, emptyMap(), part, evZone()).data }
                    .onSuccess { r ->
                        r?.let { e = it }
                        EventsStore.changed()
                        // Not a verified organizer: the event waits for review again with its new photo.
                        Toast.makeText(context, if (r?.reviewStatus == "pending") coverReview else coverSaved, Toast.LENGTH_LONG).show()
                    }
                    .onFailure { Toast.makeText(context, it.apiFailure().message, Toast.LENGTH_LONG).show() }
            }
            uploading = false
        }
    }
    LaunchedEffect(sheet) { if (sheet == "cover") { sheet = null; pickCover() } }
    LaunchedEffect(id) {
        runCatching { ApiClient.events.event(evAuth(), id, evZone()).data }.onSuccess { e = it; missing = it == null }.onFailure { missing = true }
    }
    val open: (String?) -> Unit = { url -> url?.let { runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(it))) } } }

    EvFrame(
        title = e?.title ?: stringResource(R.string.ev_title),
        onBack = onBack,
        settingsHeader = true,
        cta = e?.takeIf { it.status != "cancelled" && it.status != "ended" && it.reviewStatus.let { r -> r == null || r == "approved" } }?.let { ev ->
            {
                WaButton(
                    stringResource(if (ev.interested) R.string.ev_interested_on else R.string.ev_interested),
                    {
                        busy = true
                        scope.launch {
                            if (ev.interested) {
                                runCatching { ApiClient.events.uninterest(evAuth(), ev.id) }.onSuccess { e = ev.copy(interested = false, notify = false, interestedCount = (ev.interestedCount - 1).coerceAtLeast(0)) }
                            } else {
                                runCatching { ApiClient.events.interest(evAuth(), ev.id, JsonObject().apply { addProperty("notify", true) }, evZone()).data }
                                    .onSuccess { r -> e = ev.copy(interested = true, notify = r?.notify ?: true, interestedCount = r?.interestedCount ?: (ev.interestedCount + 1)) }
                            }
                            EventsStore.changed()
                            busy = false
                        }
                    },
                    style = if (ev.interested) WaButtonStyle.Ghost else WaButtonStyle.Primary,
                    icon = if (ev.interested) Icons.Rounded.Favorite else Icons.Rounded.FavoriteBorder,
                    loading = busy,
                )
            }
        },
    ) {
        val ev = e
        when {
            missing -> WaCard(Modifier.fillMaxWidth()) { WaEmpty(Icons.Rounded.Explore, Tone.Gray, stringResource(R.string.ev_gone_title), stringResource(R.string.ev_gone_text)) }
            ev == null -> {
                WaSkeleton(Modifier.fillMaxWidth().height(200.dp), RoundedCornerShape(26.dp))
                Spacer(Modifier.height(12.dp))
                WaSkeleton(Modifier.fillMaxWidth().height(140.dp), RoundedCornerShape(22.dp))
            }
            else -> DetailBody(ev, onLink = open, onSheet = { sheet = it }, onNotify = { on ->
                e = ev.copy(notify = on)
                scope.launch { runCatching { ApiClient.events.interest(evAuth(), ev.id, JsonObject().apply { addProperty("notify", on) }, evZone()) } }
            }, uploading = uploading)
        }
        Spacer(Modifier.height(24.dp))
    }

    val ev = e ?: return
    when (sheet) {
        "share" -> ShareSheet(ev, onDismiss = { sheet = null })
        "room" -> com.dorr.app.ui.screens.chat.PeoplePickerSheet(emptySet(), onDismiss = { sheet = null }) { ids ->
            scope.launch {
                runCatching { ApiClient.events.room(evAuth(), ev.id, JsonObject().apply { add("members", JsonArray().apply { ids.forEach { add(it) } }) }).data }
                    .onSuccess { r ->
                        Toast.makeText(context, roomMade, Toast.LENGTH_SHORT).show()
                        r?.getAsJsonObject("conversation")?.get("id")?.asString?.let { conv ->
                            EventsLink.close()
                            com.dorr.app.chat.ChatPush.open(com.dorr.app.chat.ChatDeepLink.Conversation(conv))
                        }
                    }
                    .onFailure { Toast.makeText(context, it.apiFailure().message, Toast.LENGTH_LONG).show() }
            }
        }
        "status" -> StatusSheet(ev, onDismiss = { sheet = null }) { e = it; sheet = null }
    }
}

@Composable
private fun DetailBody(ev: DiscEventDto, onLink: (String?) -> Unit, onSheet: (String) -> Unit, onNotify: (Boolean) -> Unit, uploading: Boolean = false) {
    val context = LocalContext.current
    Box(Modifier.fillMaxWidth().height(168.dp).waRise(0).clip(RoundedCornerShape(20.dp)).background(categoryBrush(ev.category))) {
        if (ev.cover != null) AsyncImage(model = ApiClient.mediaUrl(ev.cover), contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
        else Text(ev.category?.emoji ?: "🎟️", fontSize = 48.sp, modifier = Modifier.align(Alignment.Center))
        Row(Modifier.align(Alignment.TopStart).padding(10.dp), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
            ev.category?.name?.let {
                Text(
                    listOfNotNull(ev.category.emoji, it).joinToString(" "),
                    color = Wa.Ink,
                    fontSize = 11.sp,
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier.clip(RoundedCornerShape(50)).background(Wa.Surface).padding(horizontal = 9.dp, vertical = 4.dp),
                )
            }
            if (ev.familyFriendly) {
                Text("👨‍👩‍👧", fontSize = 12.sp, modifier = Modifier.clip(RoundedCornerShape(50)).background(Wa.Surface).padding(horizontal = 7.dp, vertical = 4.dp))
            }
        }
        if (ev.mine) ChangeCoverPill(uploading, Modifier.align(Alignment.BottomEnd).padding(10.dp)) { onSheet("cover") }
    }

    Row(Modifier.padding(top = 10.dp).waRise(1), verticalAlignment = Alignment.CenterVertically) {
        if (ev.verified) {
            Box(Modifier.size(15.dp).clip(CircleShape).background(Wa.Red), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(10.dp))
            }
        } else {
            Icon(Icons.Rounded.RadioButtonUnchecked, null, tint = Wa.Soft, modifier = Modifier.size(16.dp))
        }
        Spacer(Modifier.width(6.dp))
        Text(
            when {
                ev.source == "admin" -> stringResource(R.string.ev_from_official)
                ev.verified -> stringResource(R.string.ev_from_verified, ev.organizer?.name.orEmpty())
                else -> stringResource(R.string.ev_from_unverified, ev.organizer?.name.orEmpty())
            },
            color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold,
        )
    }

    statusLabel(ev.status)?.let { label ->
        Column(Modifier.fillMaxWidth().padding(top = 10.dp).clip(RoundedCornerShape(16.dp)).background(statusColor(ev.status).copy(alpha = 0.12f)).padding(horizontal = 12.dp, vertical = 10.dp)) {
            Text(label, color = statusColor(ev.status), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            ev.statusNote?.takeIf { it.isNotBlank() }?.let { Text(it, color = Wa.Ink, fontSize = 13.sp, modifier = Modifier.padding(top = 2.dp)) }
            ev.oldStartsAt?.let { old ->
                runCatching { OffsetDateTime.parse(old).atZoneSameInstant(ZoneId.of(ev.timezone ?: "UTC")) }.getOrNull()?.let {
                    Text(stringResource(R.string.ev_was_on, it.format(DateTimeFormatter.ofPattern("EEE d MMM · HH:mm", AppLocale.java()))), color = Wa.Mut, fontSize = 12.sp)
                }
            }
        }
    }
    if (ev.mine && ev.reviewStatus != null && ev.reviewStatus != "approved") {
        WaNote(
            if (ev.reviewStatus == "rejected") stringResource(R.string.ev_rv_rejected_note, ev.reviewNote.orEmpty()) else stringResource(R.string.ev_rv_pending_note),
            Modifier.padding(top = 10.dp),
        )
    }

    val endText = ev.localEnd?.let { end ->
        runCatching { LocalDateTime.parse(end.replace(' ', 'T')) }.getOrNull()?.let {
            stringResource(R.string.ev_ends_at, it.format(DateTimeFormatter.ofLocalizedTime(FormatStyle.SHORT).withLocale(AppLocale.java())))
        }
    }
    val place = listOfNotNull(ev.venue, ev.address, ev.city?.name).joinToString(" · ")
    val checked = ev.lastVerifiedAt?.let { at -> runCatching { OffsetDateTime.parse(at).toLocalDate() }.getOrNull()?.let { dayText(it) } }
    WaCard(Modifier.fillMaxWidth().padding(top = 10.dp).waRise(2), padding = 6.dp) {
        InfoLine(Icons.Rounded.CalendarMonth, whenText(ev) + (ev.city?.name?.let { "  ·  " + stringResource(R.string.ev_local_of, it) } ?: ""), myTimeText(ev))
        endText?.let {
            FactDivider()
            InfoLine(Icons.Rounded.Flag, it)
        }
        if (place.isNotBlank()) {
            FactDivider()
            InfoLine(Icons.Rounded.Place, place, stringResource(R.string.ev_open_map)) {
                val q = Uri.encode(listOfNotNull(ev.venue, ev.address, ev.city?.name).joinToString(", "))
                val uri = if (ev.lat != null && ev.lng != null) "geo:${ev.lat},${ev.lng}?q=${ev.lat},${ev.lng}($q)" else "geo:0,0?q=$q"
                runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(uri))) }
            }
        }
        FactDivider()
        InfoLine(Icons.Rounded.Payments, priceText(ev))
        if (ev.interestedCount > 0) {
            FactDivider()
            InfoLine(Icons.Rounded.Groups, stringResource(R.string.ev_interested_n, ev.interestedCount))
        }
        checked?.let {
            FactDivider()
            InfoLine(Icons.Rounded.Verified, stringResource(R.string.ev_checked_on, it))
        }
        visibleDescription(ev)?.let { desc ->
            FactDivider()
            InfoLine(Icons.Rounded.Notes, desc)
        }
    }

    if (ev.interested) {
        WaCard(Modifier.fillMaxWidth().padding(top = 8.dp), padding = 6.dp) {
            ToggleLine(stringResource(R.string.ev_notify), ev.notify, stringResource(R.string.ev_notify_sub), onNotify)
        }
    }

    if (ev.bookingUrl != null || ev.sourceUrl != null) {
        Row(Modifier.fillMaxWidth().padding(top = 12.dp).waRise(3), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            ev.sourceUrl?.let { url ->
                WaButton(
                    stringResource(R.string.ev_official_page),
                    { onLink(url) },
                    icon = Icons.Rounded.Language,
                    style = WaButtonStyle.Ghost,
                    modifier = Modifier.weight(1f),
                )
            }
            ev.bookingUrl?.let { url ->
                WaButton(
                    stringResource(R.string.ev_book),
                    { onLink(url) },
                    icon = Icons.Rounded.OpenInNew,
                    style = WaButtonStyle.Primary,
                    modifier = Modifier.weight(1f),
                )
            }
        }
        Text(stringResource(R.string.ev_links_note), color = Wa.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 6.dp))
    }

    Row(Modifier.fillMaxWidth().padding(top = 12.dp).waRise(4), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        if (ev.reviewStatus == null || ev.reviewStatus == "approved") {
            ActionTile(Icons.Rounded.Share, stringResource(R.string.ev_share), Modifier.weight(1f)) { onSheet("share") }
            if (ev.status != "cancelled" && ev.status != "ended") ActionTile(Icons.Rounded.Groups, stringResource(R.string.ev_go_together), Modifier.weight(1f)) { onSheet("room") }
        }
        if (ev.mine) ActionTile(Icons.Rounded.Update, stringResource(R.string.ev_change_status), Modifier.weight(1f)) { onSheet("status") }
    }
}

@Composable
private fun FactDivider() {
    Box(Modifier.fillMaxWidth().padding(start = 46.dp).height(1.dp).background(Wa.Line))
}

@Composable
private fun InfoLine(icon: ImageVector, text: String, sub: String? = null, onClick: (() -> Unit)? = null) {
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(12.dp))
            .then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier)
            .padding(horizontal = 6.dp, vertical = 9.dp),
        verticalAlignment = Alignment.Top,
    ) {
        Box(Modifier.size(32.dp).clip(RoundedCornerShape(10.dp)).background(Wa.Field), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = Wa.Red, modifier = Modifier.size(16.dp))
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(text, color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.SemiBold, lineHeight = 18.sp)
            sub?.let {
                Text(it, color = if (onClick != null) Wa.Red else Wa.Mut, fontSize = 12.sp, fontWeight = if (onClick != null) FontWeight.Bold else FontWeight.Normal)
            }
        }
    }
}

@Composable
private fun ActionTile(icon: ImageVector, label: String, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Column(
        modifier.clip(RoundedCornerShape(16.dp)).background(Wa.Surface).clickable(onClick = onClick).padding(vertical = 10.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(Modifier.size(36.dp).clip(RoundedCornerShape(12.dp)).background(Wa.Red.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = Wa.Red, modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.height(6.dp))
        Text(label, color = Wa.Ink, fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

// =============================================================================== sheets

/** Share in a chat as a card, maybe with "who's going?" (178). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ShareSheet(ev: DiscEventDto, onDismiss: () -> Unit) {
    var chats by remember { mutableStateOf<List<ConversationDto>?>(null) }
    var picked by remember { mutableStateOf<String?>(null) }
    var poll by remember { mutableStateOf(true) }
    var comment by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val sent = stringResource(R.string.ev_shared)
    LaunchedEffect(Unit) { chats = runCatching { ApiClient.chat.conversations(evAuth(), perPage = 60).data }.getOrNull().orEmpty().filter { it.canSend } }

    ChSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 24.dp)) {
            Text(stringResource(R.string.ev_share_title), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(ev.title, color = Wa.Mut, fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Spacer(Modifier.height(10.dp))
            LazyColumn(Modifier.heightIn(max = 320.dp)) {
                items(chats.orEmpty(), key = { it.id }) { c ->
                    val on = picked == c.id
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable { picked = c.id }.padding(vertical = 8.dp, horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        com.dorr.app.ui.screens.chat.ChAvatar(c.avatar, c.title, c.peer?.key ?: c.id, size = 40.dp, isGroup = c.isGroup)
                        Spacer(Modifier.width(10.dp))
                        Text(c.title.orEmpty(), color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                        Icon(if (on) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (on) Wa.Red else Wa.Soft, modifier = Modifier.size(24.dp))
                    }
                }
            }
            if (chats == null) WaSkeleton(Modifier.fillMaxWidth().height(120.dp), RoundedCornerShape(16.dp))
            Spacer(Modifier.height(8.dp))
            DorrTextField(comment, { comment = it.take(1000) }, placeholder = stringResource(R.string.ev_share_comment), modifier = Modifier.fillMaxWidth())
            ToggleLine(stringResource(R.string.ev_share_poll), poll, stringResource(R.string.ev_share_poll_sub)) { poll = it }
            WaButton(stringResource(R.string.ev_share_send), {
                val target = picked ?: return@WaButton
                busy = true
                scope.launch {
                    val body = JsonObject().apply {
                        addProperty("conversation_id", target)
                        addProperty("poll", poll)
                        if (comment.isNotBlank()) addProperty("comment", comment.trim())
                    }
                    runCatching { ApiClient.events.share(evAuth(), ev.id, body) }
                        .onSuccess { Toast.makeText(context, sent, Toast.LENGTH_SHORT).show(); onDismiss() }
                        .onFailure { Toast.makeText(context, it.apiFailure().message, Toast.LENGTH_LONG).show() }
                    busy = false
                }
            }, enabled = picked != null, loading = busy, icon = Icons.AutoMirrored.Rounded.Send, modifier = Modifier.fillMaxWidth().padding(top = 10.dp))
        }
    }
}

/** The organizer keeps it up to date (175): the interested who asked are told. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun StatusSheet(ev: DiscEventDto, onDismiss: () -> Unit, onSaved: (DiscEventDto) -> Unit) {
    var status by remember { mutableStateOf(ev.status) }
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    ChSheet(
        onDismissRequest = onDismiss,
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
        containerColor = Wa.Surface,
        shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp),
    ) {
        Column(
            Modifier
                .fillMaxWidth()
                .navigationBarsPadding()
                .imePadding()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp)
                .padding(bottom = 24.dp),
        ) {
            Text(stringResource(R.string.ev_change_status), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(10.dp))
            listOf("confirmed" to R.string.ev_st_confirmed, "postponed" to R.string.ev_st_postponed, "sold_out" to R.string.ev_st_sold_out, "cancelled" to R.string.ev_st_cancelled)
                .forEach { (key, label) ->
                    val on = status == key
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable { status = key }.padding(vertical = 10.dp, horizontal = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(10.dp).clip(CircleShape).background(statusColor(key)))
                        Spacer(Modifier.width(10.dp))
                        Text(stringResource(label), color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                        Icon(if (on) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (on) Wa.Red else Wa.Soft, modifier = Modifier.size(22.dp))
                    }
                }
            DorrTextField(note, { note = it.take(300) }, placeholder = stringResource(R.string.ev_status_note), modifier = Modifier.fillMaxWidth().padding(top = 6.dp))
            WaNote(stringResource(R.string.ev_status_tells, ev.interestedCount), Modifier.padding(top = 10.dp))
            error?.let { Text(it, color = Wa.Danger, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp)) }
            WaButton(stringResource(R.string.ev_save), {
                busy = true
                scope.launch {
                    val body = JsonObject().apply { addProperty("status", status); if (note.isNotBlank()) addProperty("note", note.trim()) }
                    runCatching { ApiClient.events.organizerStatus(evAuth(), ev.id, body, evZone()).data }
                        .onSuccess { it?.let(onSaved); EventsStore.changed() }
                        .onFailure { error = it.apiFailure().message }
                    busy = false
                }
            }, enabled = status != ev.status || note.isNotBlank(), loading = busy, modifier = Modifier.fillMaxWidth().padding(top = 12.dp))
        }
    }
}

/** Every city Discover covers, by country, searchable. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun CityPickerSheet(onDismiss: () -> Unit, onPick: (DiscCityDto) -> Unit) {
    var cities by remember { mutableStateOf<List<DiscCityDto>?>(null) }
    var query by remember { mutableStateOf("") }
    LaunchedEffect(Unit) { cities = EventsStore.allCities() }
    ChSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 20.dp)) {
            Text(stringResource(R.string.ev_pick_city), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(8.dp))
            DorrTextField(query, { query = it.take(60) }, placeholder = stringResource(R.string.ev_search_city), icon = Icons.Rounded.Search, modifier = Modifier.fillMaxWidth())
            Spacer(Modifier.height(8.dp))
            val shown = cities.orEmpty().filter { query.isBlank() || it.name.orEmpty().contains(query.trim(), ignoreCase = true) }
            LazyColumn(Modifier.height(420.dp)) {
                items(shown, key = { it.id }) { c ->
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable { onPick(c) }.padding(vertical = 11.dp, horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        com.dorr.app.ui.screens.wallet.WalletFlag(c.country)
                        Spacer(Modifier.width(12.dp))
                        Text(c.name.orEmpty(), color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                        Text(c.timezone.orEmpty().substringAfterLast('/').replace('_', ' '), color = Wa.Soft, fontSize = 11.5.sp)
                    }
                }
            }
            if (cities == null) WaSkeleton(Modifier.fillMaxWidth().height(100.dp), RoundedCornerShape(16.dp))
        }
    }
}

// =============================================================================== the card in a chat (178)

/** An event shared in a chat: a snapshot card; "View" opens the live event in Discover. */
@Composable
fun EventCardBubble(dto: MessageDto, @Suppress("UNUSED_PARAMETER") mine: Boolean, footer: @Composable () -> Unit) {
    val ev = dto.meta?.get("event")?.takeIf { it.isJsonObject }?.asJsonObject
    fun s(key: String): String? = ev?.get(key)?.takeIf { it.isJsonPrimitive }?.asString
    val id = s("id")
    val color = momentColor(s("color"), EvPurple)
    Column(Modifier.width(262.dp)) {
        Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(Wa.Surface).clickable(enabled = id != null) { EventsLink.show(id) }) {
            Box(Modifier.fillMaxWidth().height(112.dp).background(Brush.linearGradient(listOf(color, Color(color.red * 0.6f, color.green * 0.6f, color.blue * 0.6f))))) {
                s("cover")?.let { AsyncImage(model = ApiClient.mediaUrl(it), contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize()) }
                    ?: Text(s("emoji") ?: "🎟️", fontSize = 40.sp, modifier = Modifier.align(Alignment.Center))
                s("local_date")?.let { d ->
                    runCatching { LocalDate.parse(d) }.getOrNull()?.let { date ->
                        Column(Modifier.align(Alignment.TopStart).padding(8.dp).clip(RoundedCornerShape(12.dp)).background(Color.White).padding(horizontal = 9.dp, vertical = 4.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                            Text(date.dayOfMonth.toString(), color = Color(0xFF111928), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                            Text(date.month.getDisplayName(TextStyle.SHORT, AppLocale.java()), color = color, fontSize = 10.sp, fontWeight = FontWeight.ExtraBold)
                        }
                    }
                }
                val status = s("status") ?: "confirmed"
                statusLabel(status)?.let {
                    Text(it, color = Color.White, fontSize = 10.5.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.align(Alignment.TopEnd).padding(8.dp).clip(CircleShape).background(statusColor(status)).padding(horizontal = 8.dp, vertical = 3.dp))
                }
            }
            Column(Modifier.padding(12.dp)) {
                Text(listOfNotNull(s("category")?.let { (s("emoji")?.let { e -> "$e " } ?: "") + it }).joinToString(), color = color, fontSize = 11.5.sp, fontWeight = FontWeight.ExtraBold)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(s("title").orEmpty(), color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                    if (ev?.bool("verified") == true) Icon(Icons.Rounded.Verified, null, tint = Color(0xFF2563EB), modifier = Modifier.padding(start = 4.dp).size(15.dp))
                }
                Text(listOfNotNull(s("local_time"), s("venue"), s("city")).joinToString(" · "), color = Wa.Mut, fontSize = 12.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                dto.body?.takeIf { it.isNotBlank() }?.let { Text(it, color = Wa.Ink, fontSize = 14.sp, modifier = Modifier.padding(top = 6.dp)) }
                Text(
                    stringResource(R.string.ev_view), color = Wa.Red, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold,
                    modifier = Modifier.padding(top = 8.dp).fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(Wa.Red.copy(alpha = 0.08f)).padding(vertical = 8.dp),
                    textAlign = androidx.compose.ui.text.style.TextAlign.Center,
                )
            }
        }
        Box(Modifier.fillMaxWidth().padding(horizontal = 6.dp)) { footer() }
    }
}
