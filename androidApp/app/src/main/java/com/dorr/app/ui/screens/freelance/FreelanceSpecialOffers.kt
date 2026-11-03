package com.dorr.app.ui.screens.freelance

import android.widget.Toast
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
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
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.FormatQuote
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Inbox
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.NotificationsOff
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Replay
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.VideoChat
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CheckboxDefaults
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
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ClientSpecialOfferDto
import com.dorr.app.network.SpecialOfferAcceptRequest
import com.dorr.app.network.SpecialOfferDeclineRequest
import com.dorr.app.network.SpecialOfferMuteRequest
import com.dorr.app.network.apiFailure
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
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
import java.time.OffsetDateTime
import java.util.UUID

/*
 * FL-L09, the client's side: special offers from freelancers I finished work with. A banner on the freelance home while some wait, the
 * inbox (waiting / accepted / past), and one offer with what it is, the price before → after, the time left by the phone's clock, and
 * Accept (custom work and a service start at once; a consultation goes to the booking page at its discounted prices) / Decline (with a
 * reason, and "stop offers from this freelancer"). Read on open, on a live notice and when the connection returns — never on a timer.
 */

private val SPC_GREEN = Color(0xFF16A34A)
private val SPC_AMBER = Color(0xFFD97706)
private val SPC_RED = Color(0xFFDC2626)
private val SPC_SLATE = Color(0xFF64748B)

private fun spcInstant(iso: String?): Instant? = runCatching { Instant.parse(iso) }.getOrNull() ?: runCatching { OffsetDateTime.parse(iso).toInstant() }.getOrNull()

/** "Ends in 2 days" / "5 h" / "12 min" by the phone's clock, or null past the end. */
@Composable
private fun spcLeft(iso: String?): String? {
    val left = spcInstant(iso)?.let { Duration.between(Instant.now(), it) } ?: return null
    if (left.isNegative || left.isZero) return null
    return when {
        left.toDays() >= 1 -> stringResource(R.string.spc_left_days, left.toDays().toInt())
        left.toHours() >= 1 -> stringResource(R.string.spc_left_hours, left.toHours().toInt())
        else -> stringResource(R.string.spc_left_minutes, left.toMinutes().coerceAtLeast(1).toInt())
    }
}

private fun spcKindIcon(kind: String): ImageVector = when (kind) {
    "service" -> Icons.Rounded.Storefront
    "consultation" -> Icons.Rounded.VideoChat
    else -> Icons.Rounded.Work
}

@Composable
private fun spcKindLabel(kind: String): String = stringResource(
    when (kind) { "service" -> R.string.spc_kind_service; "consultation" -> R.string.spc_kind_consultation; else -> R.string.spc_kind_custom },
)

@Composable
private fun spcStatus(status: String): Pair<String, Color> = when (status) {
    "accepted" -> stringResource(R.string.spc_status_accepted) to SPC_GREEN
    "declined" -> stringResource(R.string.spc_status_declined) to SPC_RED
    "withdrawn" -> stringResource(R.string.spc_status_withdrawn) to SPC_SLATE
    "expired" -> stringResource(R.string.spc_status_expired) to SPC_SLATE
    else -> stringResource(R.string.spc_status_sent) to SPC_AMBER
}

/** The headline price: the offered price, or "25% off" on a consultation. */
@Composable
private fun spcPrice(o: ClientSpecialOfferDto): String = when {
    o.kind == "consultation" -> stringResource(R.string.spc_off, (o.discountBp ?: 0) / 100)
    o.priceMinor != null -> mkMoney(o.priceMinor, o.decimals, o.currency)
    else -> ""
}

@Composable
private fun SpcPill(text: String, color: Color, solid: Boolean = false) {
    Text(
        text, color = if (solid) Color.White else color, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1,
        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(if (solid) color else color.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp),
    )
}

// ==================================================================== the door on the freelance home

/** Shown on the freelance hub only while offers wait: a soft-glowing card with how many, opening the inbox. */
@Composable
internal fun SpecialOffersBanner(count: Int, onOpen: () -> Unit) {
    if (count <= 0) return
    val glow by rememberInfiniteTransition(label = "spc-glow").animateFloat(1f, 1.12f, infiniteRepeatable(tween(900), RepeatMode.Reverse), label = "spc-glow-scale")
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp))
            .background(Brush.linearGradient(listOf(Color(0xFF16A34A), Color(0xFF0EA5E9)))).clickable(onClick = onOpen).padding(16.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.size(46.dp).scale(glow).clip(RoundedCornerShape(15.dp)).background(Color.White.copy(alpha = 0.22f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.LocalOffer, null, tint = Color.White, modifier = Modifier.size(24.dp))
        }
        Column(Modifier.weight(1f)) {
            Text(pluralStringResource(R.plurals.spc_banner_title, count, count), color = Color.White, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.spc_banner_body), color = Color.White.copy(alpha = 0.9f), fontSize = 12.sp, lineHeight = 17.sp)
        }
        Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = Color.White, modifier = Modifier.size(22.dp))
    }
}

/** On the booking page: the deal the freelancer made me, its discount and their word. */
@Composable
internal fun SpecialDealBanner(o: ClientSpecialOfferDto) {
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(SPC_GREEN.copy(alpha = if (settingsNight()) 0.2f else 0.10f))
            .border(1.dp, SPC_GREEN.copy(alpha = 0.35f), RoundedCornerShape(20.dp)).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(Icons.Rounded.LocalOffer, null, tint = SPC_GREEN, modifier = Modifier.size(18.dp))
            Text(stringResource(R.string.spc_deal_title, (o.discountBp ?: 0) / 100), color = SPC_GREEN, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
            spcLeft(o.expiresAt)?.let { Text(it, color = SPC_AMBER, fontSize = 11.sp, fontWeight = FontWeight.Bold) }
        }
        o.message?.takeIf { it.isNotBlank() }?.let { Text("“$it”", color = settingsInk(), fontSize = 12.5.sp, lineHeight = 18.sp) }
        Text(stringResource(R.string.spc_deal_instant), color = settingsMut(), fontSize = 11.5.sp)
    }
}

// ==================================================================== the inbox

private val SPC_TABS = listOf("open", "accepted", "past")

@Composable
internal fun SpecialOffersPage(onBack: () -> Unit, onOpen: (String) -> Unit, embedded: Boolean = false) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var tab by rememberSaveable { mutableStateOf(0) }
    val listState = rememberLazyListState()
    val paged = remember(tab) { PagedRows<ClientSpecialOfferDto>({ it.uuid }, { page -> ApiClient.freelanceSpecialOffers.list(auth(), tab = SPC_TABS[tab], page = page) }) }
    LaunchedEffect(paged, reconnect) { paged.reload(generic) }
    listState.NearEnd(paged.rows.size) { scope.launch { paged.loadMore(generic) } }

    Column(Modifier.fillMaxSize()) {
        // Inside «My activity» the head is the hub's and the statuses are chips.
        if (!embedded) FlPageHeader(stringResource(R.string.spc_title), onBack)
        FlPartTabs(
            listOf(
                FlTab(Icons.Rounded.Inbox, stringResource(R.string.spc_tab_open)),
                FlTab(Icons.Rounded.TaskAlt, stringResource(R.string.spc_tab_accepted)),
                FlTab(Icons.Rounded.History, stringResource(R.string.spc_tab_past)),
            ),
            tab, compact = embedded,
        ) { tab = it }
        if (!paged.loaded && paged.error == null) {
            Box(Modifier.weight(1f)) { FlSkeletonList() }
        } else LazyColumn(
            Modifier.weight(1f).fillMaxWidth(), state = listState,
            contentPadding = PaddingValues(horizontal = 16.dp, vertical = 10.dp), verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            if (paged.rows.isEmpty() && paged.error == null) item {
                com.dorr.app.ui.screens.wallet.WaEmpty(
                    Icons.Rounded.LocalOffer, com.dorr.app.ui.screens.wallet.Tone.Green,
                    stringResource(if (tab == 0) R.string.spc_empty_open else R.string.spc_empty_other), stringResource(R.string.spc_empty_body),
                )
            }
            items(paged.rows, key = { it.uuid }) { o -> SpcCard(o) { onOpen(o.uuid) } }
            item { PagedSpinner(paged) }
            paged.error?.let { item { TextButton(onClick = { paged.clearError(); scope.launch { paged.loadMore(generic) } }, modifier = Modifier.fillMaxWidth()) { Text(it, color = SPC_RED, fontSize = 13.sp) } } }
        }
    }
}

@Composable
private fun SpcCard(o: ClientSpecialOfferDto, onClick: () -> Unit) {
    val (label, color) = spcStatus(o.status)
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).clickable(onClick = onClick).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            FlAvatar(o.freelancer.avatarUrl, o.freelancer.name, 40)
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(o.freelancer.name.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    if (o.freelancer.verified) Icon(Icons.Rounded.Verified, null, tint = Color(0xFF0EA5E9), modifier = Modifier.size(15.dp))
                }
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    Icon(spcKindIcon(o.kind), null, tint = settingsAccent(), modifier = Modifier.size(13.dp))
                    Text(spcKindLabel(o.kind), color = settingsAccent(), fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
                }
            }
            SpcPill(label, color)
        }
        Text(o.title.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(SPC_GREEN.copy(alpha = 0.08f)).padding(horizontal = 12.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Icon(Icons.Rounded.LocalOffer, null, tint = SPC_GREEN, modifier = Modifier.size(16.dp))
            Text(spcPrice(o), color = SPC_GREEN, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
            o.listPriceMinor?.let { Text(mkMoney(it, o.decimals, o.currency), color = settingsMut(), fontSize = 12.sp, textDecoration = TextDecoration.LineThrough) }
            Spacer(Modifier.weight(1f))
            if (o.status == "sent") spcLeft(o.expiresAt)?.let { Text(it, color = SPC_AMBER, fontSize = 11.5.sp, fontWeight = FontWeight.Bold) }
        }
    }
}

// ==================================================================== one offer

@Composable
internal fun SpecialOfferPage(
    uuid: String, onBack: () -> Unit, onOpenWork: (String) -> Unit, onOpenOrder: (String) -> Unit, onOpenConsultation: (String) -> Unit,
    onBook: (special: String, consultationOffer: String) -> Unit,
) {
    val context = LocalContext.current
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var offer by remember { mutableStateOf<ClientSpecialOfferDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var accepting by remember { mutableStateOf(false) }
    var declining by remember { mutableStateOf(false) }
    var reason by rememberSaveable { mutableStateOf("") }
    var mute by rememberSaveable { mutableStateOf(false) }
    var actionError by remember { mutableStateOf<String?>(null) }
    // One id for this acceptance: a second tap (or a retry on a weak network) is the same one.
    val requestId = rememberSaveable { UUID.randomUUID().toString() }

    LaunchedEffect(uuid, reconnect) {
        try {
            offer = ApiClient.freelanceSpecialOffers.show(auth(), uuid).data
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    fun act(block: suspend () -> ClientSpecialOfferDto?, done: Int, after: (ClientSpecialOfferDto) -> Unit = {}) {
        busy = true
        actionError = null
        scope.launch {
            try {
                val o = block()
                if (o != null) offer = o
                accepting = false
                declining = false
                Toast.makeText(context, context.getString(done), Toast.LENGTH_SHORT).show()
                if (o != null) after(o)
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                actionError = e.apiFailure().message ?: generic
            }
            busy = false
        }
    }

    Column(Modifier.fillMaxSize()) {
        FlPageHeader(stringResource(R.string.spc_detail_title), onBack)
        val o = offer
        if (o == null) {
            FlLoading(error)
            return@Column
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp, vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            SpcHero(o)
            o.message?.takeIf { it.isNotBlank() }?.let { m ->
                Row(
                    Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).padding(14.dp),
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    Icon(Icons.Rounded.FormatQuote, null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
                    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(m, color = settingsInk(), fontSize = 14.sp, lineHeight = 21.sp)
                        Text("— " + o.freelancer.name.orEmpty(), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                    }
                }
            }
            SpcWhat(o)
            o.money?.let { m ->
                SpcSection(stringResource(R.string.spc_money), Icons.Rounded.Payments) {
                    SpcLine(stringResource(R.string.spc_price), mkMoney(m.priceMinor, o.decimals, o.currency))
                    o.savingMinor?.takeIf { it > 0 }?.let { SpcLine(stringResource(R.string.spc_you_save), "− " + mkMoney(it, o.decimals, o.currency), color = SPC_GREEN) }
                    if (m.clientFeeMinor > 0) SpcLine(stringResource(R.string.mk_fee), mkMoney(m.clientFeeMinor, o.decimals, o.currency))
                    HorizontalDivider(color = settingsMut().copy(alpha = 0.15f))
                    SpcLine(stringResource(R.string.mk_total), mkMoney(m.totalMinor, o.decimals, o.currency), strong = true)
                }
            }
            if (o.status == "sent") FlNotice(stringResource(if (o.kind == "consultation") R.string.spc_how_consult else R.string.spc_how_work), Icons.Rounded.CheckCircle, SPC_GREEN)
            if (o.muted) FlNotice(stringResource(R.string.spc_muted_note), Icons.Rounded.NotificationsOff, SPC_SLATE)
            Spacer(Modifier.height(4.dp))
        }

        // The bar at the bottom: what can be done now.
        val result = o.result
        Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(topStart = 22.dp, topEnd = 22.dp)).padding(horizontal = 16.dp, vertical = 12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            when {
                o.can.accept -> {
                    SettingsPrimaryButton(
                        text = stringResource(if (o.kind == "consultation") R.string.spc_pick_time else R.string.spc_accept),
                        onClick = {
                            val c = o.consultation?.uuid
                            if (o.kind == "consultation" && c != null) onBook(o.uuid, c) else accepting = true
                        },
                    )
                    FlAction(stringResource(R.string.spc_decline), Icons.Rounded.Close, FlTone.Danger) { declining = true }
                }
                o.status == "accepted" && result?.consultation != null -> SettingsPrimaryButton(text = stringResource(R.string.spc_open_consultation), onClick = { onOpenConsultation(result.consultation) })
                o.status == "accepted" && result?.serviceOrder != null -> SettingsPrimaryButton(text = stringResource(R.string.spc_open_order), onClick = { onOpenOrder(result.serviceOrder) })
                o.status == "accepted" && result?.project != null -> SettingsPrimaryButton(text = stringResource(R.string.spc_open_work), onClick = { onOpenWork(result.project) })
                else -> FlAction(
                    stringResource(if (o.muted) R.string.spc_unmute else R.string.spc_mute), Icons.Rounded.NotificationsOff, FlTone.Soft, loading = busy,
                ) {
                    val f = o.freelancer.uuid ?: return@FlAction
                    act({ ApiClient.freelanceSpecialOffers.mute(auth(), f, SpecialOfferMuteRequest(!o.muted)); o.copy(muted = !o.muted) }, if (o.muted) R.string.spc_unmuted else R.string.spc_muted)
                }
            }
        }
    }

    val o = offer
    if (accepting && o != null) {
        FlSheet(
            Icons.Rounded.TaskAlt, stringResource(R.string.spc_accept_title), stringResource(R.string.spc_accept_body, o.freelancer.name.orEmpty()), onDismiss = { if (!busy) accepting = false },
            footer = {
                actionError?.let { FlNotice(it, Icons.Rounded.Close, SPC_RED) }
                SettingsPrimaryButton(text = stringResource(R.string.spc_accept_cta), loading = busy, onClick = {
                    act({ ApiClient.freelanceSpecialOffers.accept(auth(), o.uuid, SpecialOfferAcceptRequest(requestId)).data }, R.string.spc_accepted) { done ->
                        done.result?.serviceOrder?.let(onOpenOrder) ?: done.result?.project?.let(onOpenWork)
                    }
                })
            },
        ) {
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
                Text(o.title.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                o.money?.let { m ->
                    SpcLine(stringResource(R.string.spc_price), mkMoney(m.priceMinor, o.decimals, o.currency))
                    if (m.clientFeeMinor > 0) SpcLine(stringResource(R.string.mk_fee), mkMoney(m.clientFeeMinor, o.decimals, o.currency))
                    SpcLine(stringResource(R.string.mk_total), mkMoney(m.totalMinor, o.decimals, o.currency), strong = true)
                }
            }
            FlNotice(stringResource(R.string.spc_accept_safe), Icons.Rounded.CheckCircle, SPC_GREEN)
        }
    }
    if (declining && o != null) {
        FlSheet(
            Icons.Rounded.Block, stringResource(R.string.spc_decline_title), stringResource(R.string.spc_decline_body), onDismiss = { if (!busy) declining = false },
            footer = {
                actionError?.let { FlNotice(it, Icons.Rounded.Close, SPC_RED) }
                FlAction(stringResource(R.string.spc_decline_cta), Icons.Rounded.Close, FlTone.Danger, loading = busy) {
                    act({ ApiClient.freelanceSpecialOffers.decline(auth(), o.uuid, SpecialOfferDeclineRequest(reason.trim().ifEmpty { null }, mute)).data }, R.string.spc_declined)
                }
            },
        ) {
            DorrTextField(reason, { reason = it.take(300) }, placeholder = stringResource(R.string.spc_decline_reason), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable { mute = !mute }.padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                Checkbox(checked = mute, onCheckedChange = { mute = it }, colors = CheckboxDefaults.colors(checkedColor = settingsAccent()))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.spc_mute_check, o.freelancer.name.orEmpty()), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                    Text(stringResource(R.string.spc_mute_hint), color = settingsMut(), fontSize = 11.5.sp)
                }
            }
        }
    }
}

/** The head: the freelancer, the badge, the title, the big price with the old one struck through, and the time left. */
@Composable
private fun SpcHero(o: ClientSpecialOfferDto) {
    val (label, color) = spcStatus(o.status)
    val accent = settingsAccent()
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(24.dp)).background(Brush.linearGradient(listOf(accent, accent.copy(alpha = 0.78f)))).padding(18.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(Modifier.size(52.dp).clip(CircleShape).border(2.dp, Color.White.copy(alpha = 0.7f), CircleShape), contentAlignment = Alignment.Center) { FlAvatar(o.freelancer.avatarUrl, o.freelancer.name, 48) }
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(o.freelancer.name.orEmpty(), color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    if (o.freelancer.verified) Icon(Icons.Rounded.Verified, null, tint = Color.White, modifier = Modifier.size(16.dp))
                }
                o.freelancer.headline?.takeIf { it.isNotBlank() }?.let { Text(it, color = Color.White.copy(alpha = 0.85f), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
            }
            Text(
                label, color = if (o.status == "sent") accent else Color.White, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(if (o.status == "sent") Color.White else color.copy(alpha = 0.9f)).padding(horizontal = 10.dp, vertical = 4.dp),
            )
        }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Icon(Icons.Rounded.LocalOffer, null, tint = Color.White, modifier = Modifier.size(15.dp))
            Text(stringResource(R.string.spc_badge, spcKindLabel(o.kind)), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
        }
        Text(o.title.orEmpty(), color = Color.White, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 27.sp)
        Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(spcPrice(o), color = Color.White, fontSize = 26.sp, fontWeight = FontWeight.Black)
            o.listPriceMinor?.let { Text(mkMoney(it, o.decimals, o.currency), color = Color.White.copy(alpha = 0.7f), fontSize = 14.sp, textDecoration = TextDecoration.LineThrough, modifier = Modifier.padding(bottom = 3.dp)) }
        }
        if (o.status == "sent") spcLeft(o.expiresAt)?.let { left ->
            Row(
                Modifier.clip(RoundedCornerShape(12.dp)).background(Color.White.copy(alpha = 0.18f)).padding(horizontal = 10.dp, vertical = 7.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                Icon(Icons.Rounded.HourglassTop, null, tint = Color.White, modifier = Modifier.size(15.dp))
                Text(left, color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}

/** What is on offer: the work, the service level with what it includes, or the consultation's lengths before → after. */
@Composable
private fun SpcWhat(o: ClientSpecialOfferDto) {
    SpcSection(stringResource(R.string.spc_what), spcKindIcon(o.kind)) {
        when (o.kind) {
            "service" -> {
                val s = o.service
                Text(s?.title.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                Text(listOfNotNull(s?.levelLabel, s?.tierName?.takeIf { it.isNotBlank() }).joinToString(" · "), color = settingsAccent(), fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                s?.features?.forEach { f ->
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Icon(Icons.Rounded.CheckCircle, null, tint = SPC_GREEN, modifier = Modifier.size(16.dp))
                        Text(f, color = settingsInk(), fontSize = 13.sp)
                    }
                }
            }
            "consultation" -> {
                Text(o.consultation?.title.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                o.consultation?.lengths?.forEach { l ->
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Icon(Icons.Rounded.Schedule, null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
                        Text(stringResource(R.string.cs_minutes, l.minutes), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                        Text(mkMoney(l.listPriceMinor, o.decimals, o.currency), color = settingsMut(), fontSize = 12.sp, textDecoration = TextDecoration.LineThrough)
                        Text(mkMoney(l.priceMinor, o.decimals, o.currency), color = SPC_GREEN, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                    }
                }
            }
            else -> {
                o.description?.let { Text(it, color = settingsInk(), fontSize = 14.sp, lineHeight = 21.sp) }
                o.specialty?.name?.let { SpcPill(it, settingsAccent()) }
            }
        }
        if (o.kind != "consultation") {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FlStat(Icons.Rounded.Schedule, stringResource(R.string.spc_days_label), stringResource(R.string.mk_days, o.deliveryDays ?: 0), Modifier.weight(1f))
                FlStat(Icons.Rounded.Replay, stringResource(R.string.spc_revisions_label), "${o.revisions ?: 0}", Modifier.weight(1f))
            }
        }
    }
}

@Composable
private fun SpcSection(title: String, icon: ImageVector, content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
            Text(title, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
        }
        content()
    }
}

@Composable
private fun SpcLine(label: String, value: String, strong: Boolean = false, color: Color? = null) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = settingsMut(), fontSize = if (strong) 14.sp else 13.sp, fontWeight = if (strong) FontWeight.ExtraBold else FontWeight.Medium, modifier = Modifier.weight(1f))
        Text("‎$value", color = color ?: if (strong) settingsAccent() else settingsInk(), fontSize = if (strong) 16.sp else 13.sp, fontWeight = if (strong) FontWeight.ExtraBold else FontWeight.SemiBold)
    }
}
