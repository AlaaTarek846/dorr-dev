package com.dorr.freelance.ui.screens

import android.widget.Toast
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
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
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.EventAvailable
import androidx.compose.material.icons.rounded.FormatQuote
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.PersonSearch
import androidx.compose.material.icons.rounded.Replay
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material.icons.rounded.VideoChat
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.PagedRows
import com.dorr.freelance.net.PastClientDto
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.SpecialOfferComposeDto
import com.dorr.freelance.net.SpecialOfferDto
import com.dorr.freelance.net.SpecialOfferQuoteDto
import com.dorr.freelance.net.SpecialOfferRequest
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.AvatarImage
import com.dorr.freelance.ui.components.ChipRow
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.components.PagedFooter
import com.dorr.freelance.ui.components.SkeletonDetail
import com.dorr.freelance.ui.components.SkeletonList
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.ConfirmDialog
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.kit.settingsSurface
import com.dorr.freelance.ui.wallet.longDate
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.util.UUID

/*
 * FL-L09: special offers to clients I finished work with. The list (with how my offers did and today's room), one offer, and the
 * four-step composer: the client → what I offer → the details with a live "you get" → a preview as the client will see it. The client's
 * yes starts the work at once. Lists reload on open, on a live notice and when the connection returns — never on a timer.
 */

private val SPO_TABS = listOf("open", "accepted", "past")

@Composable
private fun spoKindIcon(kind: String): ImageVector = when (kind) {
    "service" -> Icons.Rounded.Storefront
    "consultation" -> Icons.Rounded.VideoChat
    else -> Icons.Rounded.Work
}

@Composable
private fun spoKindLabel(kind: String): String = when (kind) {
    "service" -> stringResource(R.string.spo_kind_service)
    "consultation" -> stringResource(R.string.spo_kind_consultation)
    else -> stringResource(R.string.spo_kind_custom)
}

@Composable
private fun spoStatusLabel(status: String): String = when (status) {
    "accepted" -> stringResource(R.string.spo_status_accepted)
    "declined" -> stringResource(R.string.spo_status_declined)
    "withdrawn" -> stringResource(R.string.spo_status_withdrawn)
    "expired" -> stringResource(R.string.spo_status_expired)
    else -> stringResource(R.string.spo_status_sent)
}

@Composable
private fun spoTone(status: String): SvcTone = serviceTone(
    when (status) { "accepted" -> "active"; "declined" -> "declined"; "sent" -> "awaiting"; else -> "paused" },
)

/** "Ends in 2 days" / "Ends in 5 h" by the phone's clock, or null past the end. */
@Composable
private fun spoLeft(iso: String?): String? {
    val left = timeLeft(iso) ?: return null
    if (left.isNegative || left.isZero) return null
    return when {
        left.toDays() >= 1 -> stringResource(R.string.spo_left_days, left.toDays().toInt())
        left.toHours() >= 1 -> stringResource(R.string.spo_left_hours, left.toHours().toInt())
        else -> stringResource(R.string.spo_left_minutes, left.toMinutes().coerceAtLeast(1).toInt())
    }
}

/** The price as the client reads it: the offered price, or "−25%" on a consultation. */
@Composable
private fun spoPrice(o: SpecialOfferDto): String = when {
    o.kind == "consultation" -> stringResource(R.string.spo_discount_off, (o.discountBp ?: 0) / 100)
    o.priceMinor != null -> svcMoney(o.priceMinor, o.decimals, o.currency)
    else -> ""
}

// ======================================================================== the list

@Composable
fun SpecialOffersScreen(onBack: () -> Unit, onCompose: () -> Unit, onOpen: (String) -> Unit, onSwitchStore: ((String) -> Unit)? = null) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var tab by rememberSaveable { mutableStateOf("open") }
    var info by remember { mutableStateOf<SpecialOfferComposeDto?>(null) }
    var closed by remember { mutableStateOf(false) }
    val paged = remember(tab) { PagedRows<SpecialOfferDto>(15, { it.uuid }, { page -> apiCall { ApiClient.specialOffers.list(tab = tab, page = page, perPage = 15) } }) }

    suspend fun loadInfo() {
        when (val r = apiCall { ApiClient.specialOffers.compose() }) {
            is ApiResult.Ok -> { info = r.data; closed = false }
            is ApiResult.Failed -> if (r.error.errorCode == "freelance_feature_off") closed = true
            else -> Unit
        }
    }
    LaunchedEffect(tab, reconnect) { paged.reload(); loadInfo() }
    LaunchedEffect(tab) { Realtime.changed.collect { paged.reload(); loadInfo() } }

    Page(title = stringResource(R.string.spo_title), onBack = onBack, onNearEnd = { scope.launch { paged.loadMore() } }) {
        if (closed) {
            EmptyState(icon = Icons.Rounded.Block, title = stringResource(R.string.spo_closed_title), body = stringResource(R.string.spo_closed_body), modifier = Modifier.padding(top = 70.dp))
            return@Page
        }
        onSwitchStore?.let { com.dorr.freelance.ui.kit.StoreSwitcher("special_offers", it) }
        SpoHero(info, onCompose)
        val counts = info?.counts.orEmpty()
        com.dorr.freelance.ui.kit.FilterChips(
            SPO_TABS.map { t -> com.dorr.freelance.ui.kit.FilterOption(t, stringResource(when (t) { "accepted" -> R.string.spo_tab_accepted; "past" -> R.string.spo_tab_past; else -> R.string.spo_tab_open }) + (counts[t]?.takeIf { it > 0 }?.let { "  $it" } ?: "")) },
            tab, { tab = it },
        )
        when {
            !paged.loaded && paged.failure == null -> SkeletonList()
            paged.loaded && paged.rows.isEmpty() && paged.failure == null -> EmptyState(
                icon = Icons.Rounded.LocalOffer,
                title = stringResource(if (tab == "open") R.string.spo_empty_open else R.string.spo_empty_other),
                body = stringResource(R.string.spo_empty_body),
                modifier = Modifier.padding(top = 40.dp),
            )
            else -> paged.rows.forEach { o -> SpoCard(o) { onOpen(o.uuid) } }
        }
        PagedFooter(paged) { scope.launch { paged.loadMore() } }
        ErrorBanner(paged.failure?.let { it.errorText(offlineText) ?: genericError }, Modifier.fillMaxWidth())
    }
}

/** The head: what this is for, how my offers did, today's room and the button to make one. */
@Composable
private fun SpoHero(info: SpecialOfferComposeDto?, onCompose: () -> Unit) {
    val st = info?.stats
    val left = info?.todayLeft ?: 0
    com.dorr.freelance.ui.kit.StoreHero(
        Icons.Rounded.LocalOffer, stringResource(R.string.spo_hero_title), stringResource(R.string.spo_hero_body),
        listOf(
            com.dorr.freelance.ui.kit.HeroNumber("${st?.sent ?: 0}", stringResource(R.string.spo_stat_sent)),
            com.dorr.freelance.ui.kit.HeroNumber("${st?.seen ?: 0}", stringResource(R.string.spo_stat_seen)),
            com.dorr.freelance.ui.kit.HeroNumber("${st?.accepted ?: 0}", stringResource(R.string.spo_stat_accepted)),
            com.dorr.freelance.ui.kit.HeroNumber("${st?.acceptRate ?: 0}%", stringResource(R.string.spo_stat_rate)),
        ),
        action = stringResource(R.string.spo_new), actionIcon = Icons.Rounded.Send, actionEnabled = info != null && left > 0, onAction = onCompose,
        footnote = if (info == null) null else if (left > 0) stringResource(R.string.spo_today_left, left) else stringResource(R.string.spo_today_none),
    )
}

@Composable
private fun SpoCard(o: SpecialOfferDto, onClick: () -> Unit) {
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).settingsSurface(RoundedCornerShape(20.dp)).clickable(onClick = onClick).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            AvatarImage(o.client?.avatarUrl, 38.dp)
            Column(Modifier.weight(1f)) {
                Text(o.client?.name.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    Icon(spoKindIcon(o.kind), contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(13.dp))
                    Text(spoKindLabel(o.kind), color = settingsAccent(), fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
                }
            }
            SvcPill(spoStatusLabel(o.status), spoTone(o.status))
        }
        Text(o.title.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(horizontal = 12.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Text(spoPrice(o), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
            o.listPriceMinor?.let {
                Text(svcMoney(it, o.decimals, o.currency), color = settingsMut(), fontSize = 12.sp, textDecoration = TextDecoration.LineThrough)
            }
            Spacer(Modifier.weight(1f))
            if (o.viewedAt != null) {
                Icon(Icons.Rounded.Visibility, contentDescription = stringResource(R.string.spo_seen), tint = Color(0xFF16A34A), modifier = Modifier.size(16.dp))
            }
        }
        if (o.status == "sent") spoLeft(o.expiresAt)?.let { text ->
            val warn = serviceTone("awaiting")
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(Icons.Rounded.HourglassTop, contentDescription = null, tint = warn.ink, modifier = Modifier.size(15.dp))
                Text(text, color = warn.ink, fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}

// ======================================================================== one offer

@Composable
fun SpecialOfferScreen(uuid: String, onBack: () -> Unit, onOpenWork: (String) -> Unit, onOpenConsultation: (String) -> Unit, onOpenOrder: (String) -> Unit) {
    val context = LocalContext.current
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var offer by remember { mutableStateOf<SpecialOfferDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var asking by remember { mutableStateOf(false) }

    suspend fun load() {
        when (val r = apiCall { ApiClient.specialOffers.show(uuid) }) {
            is ApiResult.Ok -> { offer = r.data; error = null }
            else -> error = r.errorText(offlineText) ?: genericError
        }
    }
    LaunchedEffect(reconnect) { load() }
    LaunchedEffect(Unit) { Realtime.changed.collect { load() } }

    fun withdraw() {
        busy = true
        scope.launch {
            val r = apiCall { ApiClient.specialOffers.withdraw(uuid) }
            busy = false
            asking = false
            if (r is ApiResult.Ok) {
                offer = r.data
                Toast.makeText(context, context.getString(R.string.spo_withdrawn), Toast.LENGTH_SHORT).show()
            } else error = r.errorText(offlineText) ?: genericError
        }
    }

    Page(title = stringResource(R.string.spo_detail_title), onBack = onBack) {
        val o = offer
        if (o == null) {
            if (error == null) SkeletonDetail()
        } else {
            SpoHead(o)
            o.message?.takeIf { it.isNotBlank() }?.let { m ->
                InfoCard(title = stringResource(R.string.spo_your_message), icon = Icons.Rounded.FormatQuote) {
                    Text(m, color = settingsInk(), fontSize = 14.sp, lineHeight = 21.sp,
                        modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(12.dp))
                }
            }
            SpoWhat(o)
            o.money?.let { m ->
                InfoCard(title = stringResource(R.string.svo_money), icon = Icons.Rounded.Payments) {
                    MoneyLine(stringResource(R.string.svo_price), svcMoney(m.priceMinor, o.decimals, o.currency))
                    MoneyLine(stringResource(R.string.svo_commission), "− " + svcMoney(m.commissionMinor, o.decimals, o.currency), muted = true)
                    Box(Modifier.fillMaxWidth().height(1.dp).background(if (settingsNight()) AccountDark.line else Color(0xFFEEF0F3)))
                    MoneyLine(stringResource(R.string.svo_net), svcMoney(m.netMinor, o.decimals, o.currency), strong = true)
                }
            }
            SpoTimeline(o)
            val result = o.result
            when {
                o.can.withdraw -> Row(
                    Modifier.fillMaxWidth().widthIn(max = 520.dp).height(50.dp).clip(RoundedCornerShape(18.dp))
                        .border(1.5.dp, serviceTone("rejected").ink.copy(alpha = 0.4f), RoundedCornerShape(18.dp))
                        .clickable(enabled = !busy) { asking = true },
                    horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.Undo, contentDescription = null, tint = serviceTone("rejected").ink, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.width(8.dp))
                    Text(stringResource(R.string.spo_withdraw), color = serviceTone("rejected").ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                }
                o.status == "accepted" && result?.consultation != null ->
                    SettingsPrimaryButton(text = stringResource(R.string.spo_open_consultation), modifier = Modifier.widthIn(max = 520.dp), onClick = { onOpenConsultation(result.consultation) })
                o.status == "accepted" && result?.serviceOrder != null ->
                    SettingsPrimaryButton(text = stringResource(R.string.spo_open_order), modifier = Modifier.widthIn(max = 520.dp), onClick = { onOpenOrder(result.serviceOrder) })
                o.status == "accepted" && result?.project != null ->
                    SettingsPrimaryButton(text = stringResource(R.string.svo_open_work), modifier = Modifier.widthIn(max = 520.dp), onClick = { onOpenWork(result.project) })
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }

    if (asking) {
        ConfirmDialog(
            icon = Icons.Rounded.Undo, title = stringResource(R.string.spo_withdraw_title), message = stringResource(R.string.spo_withdraw_body),
            confirmLabel = stringResource(R.string.spo_withdraw), loading = busy, onConfirm = { withdraw() }, onDismiss = { asking = false },
        )
    }
}

@Composable
private fun SpoHead(o: SpecialOfferDto) {
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).settingsSurface(RoundedCornerShape(22.dp)).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            SvcPill(spoStatusLabel(o.status), spoTone(o.status), solid = true)
            SvcPill(spoKindLabel(o.kind), SvcTone(settingsAccent(), settingsAccent().copy(alpha = 0.10f)))
        }
        Text(o.title.orEmpty(), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 25.sp)
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            AvatarImage(o.client?.avatarUrl, 36.dp)
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.svo_client), color = settingsMut(), fontSize = 11.sp)
                Text(o.client?.name.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
            }
            Text(longDate(o.sentAt), color = settingsMut(), fontSize = 11.sp)
        }
        if (o.status == "sent") spoLeft(o.expiresAt)?.let { text ->
            val warn = serviceTone("awaiting")
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(warn.fill).padding(12.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                Icon(Icons.Rounded.HourglassTop, contentDescription = null, tint = warn.ink, modifier = Modifier.size(20.dp))
                Column {
                    Text(text, color = warn.ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.spo_ends_on, longDate(o.expiresAt)), color = warn.ink.copy(alpha = 0.85f), fontSize = 12.sp)
                }
            }
        }
    }
}

/** What is offered: the work, the service level, or the consultation's lengths before → after. */
@Composable
private fun SpoWhat(o: SpecialOfferDto) {
    InfoCard(title = stringResource(R.string.spo_what), icon = spoKindIcon(o.kind)) {
        when (o.kind) {
            "service" -> {
                val s = o.service
                Text(s?.title.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    SvcPill(levelLabel(s?.level ?: "basic"), SvcTone(settingsAccent(), settingsAccent().copy(alpha = 0.10f)))
                    s?.tierName?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold) }
                }
                s?.features?.forEach { f ->
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = Color(0xFF16A34A), modifier = Modifier.size(16.dp))
                        Text(f, color = settingsInk(), fontSize = 13.sp)
                    }
                }
                PriceCompare(o)
            }
            "consultation" -> {
                Text(o.consultation?.title.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                o.consultation?.lengths?.forEach { l ->
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Icon(Icons.Rounded.Schedule, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
                        Text(stringResource(R.string.spo_minutes, l.minutes), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                        l.listPriceMinor?.let { Text(svcMoney(it, o.decimals, o.currency), color = settingsMut(), fontSize = 12.sp, textDecoration = TextDecoration.LineThrough) }
                        Text(svcMoney(l.priceMinor, o.decimals, o.currency), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                    }
                }
                SavingBadge(stringResource(R.string.spo_discount_off, (o.discountBp ?: 0) / 100))
            }
            else -> {
                o.description?.let { Text(it, color = settingsInk(), fontSize = 14.sp, lineHeight = 21.sp) }
                o.specialty?.name?.let { SvcPill(it, SvcTone(settingsAccent(), settingsAccent().copy(alpha = 0.10f))) }
            }
        }
        if (o.kind != "consultation") {
            Row(Modifier.padding(top = 2.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                SpoFact(Icons.Rounded.Schedule, daysLabel(o.deliveryDays ?: 0), Modifier.weight(1f))
                val r = o.revisions ?: 0
                SpoFact(Icons.Rounded.Replay, if (r > 0) stringResource(R.string.svc_revisions, r) else stringResource(R.string.svc_no_revisions), Modifier.weight(1f))
            }
        }
    }
}

@Composable
private fun PriceCompare(o: SpecialOfferDto) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        Text(spoPrice(o), color = settingsAccent(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
        o.listPriceMinor?.let { Text(svcMoney(it, o.decimals, o.currency), color = settingsMut(), fontSize = 13.sp, textDecoration = TextDecoration.LineThrough) }
        o.savingMinor?.takeIf { it > 0 }?.let { SavingBadge(stringResource(R.string.spo_saving, svcMoney(it, o.decimals, o.currency))) }
    }
}

@Composable
private fun SavingBadge(text: String) {
    val green = Color(0xFF16A34A)
    Row(
        Modifier.clip(RoundedCornerShape(999.dp)).background(green.copy(alpha = if (settingsNight()) 0.22f else 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Icon(Icons.Rounded.LocalOffer, contentDescription = null, tint = green, modifier = Modifier.size(13.dp))
        Text(text, color = green, fontSize = 11.5.sp, fontWeight = FontWeight.ExtraBold)
    }
}

@Composable
private fun SpoFact(icon: ImageVector, text: String, modifier: Modifier) {
    Row(
        modifier.clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(horizontal = 10.dp, vertical = 9.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
        Text(text, color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

/** Sent → seen → answered, each with its day when it happened. */
@Composable
private fun SpoTimeline(o: SpecialOfferDto) {
    val steps = listOf(
        Triple(Icons.Rounded.Send, stringResource(R.string.spo_step_sent), o.sentAt),
        Triple(Icons.Rounded.Visibility, stringResource(R.string.spo_step_seen), o.viewedAt),
        Triple(
            if (o.status == "accepted") Icons.Rounded.TaskAlt else Icons.Rounded.Block,
            when (o.status) { "sent" -> stringResource(R.string.spo_step_answer); else -> spoStatusLabel(o.status) },
            o.answeredAt ?: o.expiresAt.takeIf { o.status == "expired" },
        ),
    )
    InfoCard(title = stringResource(R.string.spo_timeline), icon = Icons.Rounded.EventAvailable) {
        steps.forEachIndexed { i, (icon, label, at) ->
            val done = at != null
            Row(verticalAlignment = Alignment.Top) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Box(Modifier.size(28.dp).clip(CircleShape).background(if (done) settingsAccent() else settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                        Icon(icon, contentDescription = null, tint = if (done) Color.White else settingsAccent(), modifier = Modifier.size(15.dp))
                    }
                    if (i < steps.lastIndex) Box(Modifier.width(2.dp).height(16.dp).background(settingsAccent().copy(alpha = 0.18f)))
                }
                Spacer(Modifier.width(12.dp))
                Column(Modifier.padding(top = 4.dp)) {
                    Text(label, color = if (done) settingsInk() else settingsMut(), fontSize = 13.sp, fontWeight = if (done) FontWeight.Bold else FontWeight.Medium)
                    at?.let { Text(longDate(it), color = settingsMut(), fontSize = 11.sp) }
                }
            }
        }
        o.declineReason?.takeIf { it.isNotBlank() }?.let {
            Text(stringResource(R.string.spo_reason, it), color = serviceTone("declined").ink, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        }
    }
}

// ======================================================================== the composer

/**
 * Four steps, one at a time, with a bar of where I am and the system back stepping back: (1) the client — my past clients with what we
 * did together, the ones I can't offer now greyed with why; (2) what I offer; (3) the details with "you get" worked out by the server as I
 * type; (4) the offer as the client will read it, then Send. A double tap sends one offer (`request_id`).
 */
@Composable
fun SpecialOfferComposeScreen(onBack: () -> Unit, onSent: (String) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var step by rememberSaveable { mutableStateOf(1) }
    var info by remember { mutableStateOf<SpecialOfferComposeDto?>(null) }
    var clients by remember { mutableStateOf<List<PastClientDto>?>(null) }
    var search by rememberSaveable { mutableStateOf("") }
    var clientKey by rememberSaveable { mutableStateOf<String?>(null) }
    var kind by rememberSaveable { mutableStateOf("custom") }
    // custom
    var title by rememberSaveable { mutableStateOf("") }
    var description by rememberSaveable { mutableStateOf("") }
    var specialtyId by rememberSaveable { mutableStateOf<Int?>(null) }
    var price by rememberSaveable { mutableStateOf("") }
    var days by rememberSaveable { mutableStateOf(5) }
    var revisions by rememberSaveable { mutableStateOf(2) }
    // service / consultation
    var serviceUuid by rememberSaveable { mutableStateOf<String?>(null) }
    var level by rememberSaveable { mutableStateOf("standard") }
    var consultationUuid by rememberSaveable { mutableStateOf<String?>(null) }
    var discount by rememberSaveable { mutableStateOf(15) }
    // both
    var message by rememberSaveable { mutableStateOf("") }
    var validDays by rememberSaveable { mutableStateOf(0) }
    var requestId by rememberSaveable { mutableStateOf(UUID.randomUUID().toString()) }
    var quote by remember { mutableStateOf<SpecialOfferQuoteDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) {
        when (val r = apiCall { ApiClient.specialOffers.compose() }) {
            is ApiResult.Ok -> {
                val d = r.data
                info = d
                if (validDays == 0) validDays = d.rules.defaultValidDays
                if (specialtyId == null) specialtyId = d.specialties.firstOrNull { it.isPrimary }?.id ?: d.specialties.firstOrNull()?.id
            }
            else -> error = r.errorText(offlineText) ?: genericError
        }
    }
    LaunchedEffect(search) {
        delay(if (search.isEmpty()) 0 else 350)
        when (val r = apiCall { ApiClient.specialOffers.clients(search.trim().ifEmpty { null }) }) {
            is ApiResult.Ok -> clients = r.data
            else -> error = r.errorText(offlineText) ?: genericError
        }
    }

    val d = info
    val decimals = d?.decimals ?: 2
    val currency = d?.currency
    val service = d?.services?.firstOrNull { it.uuid == serviceUuid }
    val tier = service?.levels?.firstOrNull { it.level == level } ?: service?.levels?.firstOrNull()
    val consultation = d?.consultations?.firstOrNull { it.uuid == consultationUuid }
    val priceMinor = inputToMinor(price, decimals)

    // "You get" for the price typed (custom work and a service price), worked out by the server a moment after typing stops.
    LaunchedEffect(priceMinor, kind, specialtyId) {
        quote = null
        val p = priceMinor ?: return@LaunchedEffect
        if (kind == "consultation") return@LaunchedEffect
        delay(400)
        (apiCall { ApiClient.specialOffers.quote(p, if (kind == "custom") specialtyId else null) } as? ApiResult.Ok)?.let { quote = it.data }
    }

    val detailsProblem: String? = when (kind) {
        "custom" -> when {
            title.trim().length < 5 -> stringResource(R.string.spo_need_title)
            description.trim().length < 30 -> stringResource(R.string.spo_need_description)
            priceMinor == null -> stringResource(R.string.spo_need_price)
            d?.minPriceMinor != null && priceMinor < d.minPriceMinor -> stringResource(R.string.spo_min_price, svcMoney(d.minPriceMinor, decimals, currency))
            else -> null
        }
        "service" -> when {
            service == null || tier == null -> stringResource(R.string.spo_need_service)
            priceMinor == null -> stringResource(R.string.spo_need_price)
            priceMinor >= tier.priceMinor -> stringResource(R.string.spo_price_not_lower, svcMoney(tier.priceMinor, decimals, currency))
            else -> null
        }
        else -> if (consultation == null) stringResource(R.string.spo_need_consultation) else null
    }

    fun back() { if (step > 1) step-- else onBack() }
    BackHandler(enabled = step > 1) { back() }

    fun send() {
        val key = clientKey ?: return
        busy = true
        error = null
        scope.launch {
            val body = SpecialOfferRequest(
                client = key, kind = kind, message = message.trim().ifEmpty { null }, validDays = validDays, requestId = requestId,
                title = if (kind == "custom") title.trim() else null,
                description = if (kind == "custom") description.trim() else null,
                specialtyId = if (kind == "custom") specialtyId else null,
                service = if (kind == "service") service?.uuid else null,
                level = if (kind == "service") tier?.level else null,
                consultation = if (kind == "consultation") consultation?.uuid else null,
                priceMinor = if (kind == "consultation") null else priceMinor,
                discountBp = if (kind == "consultation") discount * 100 else null,
                deliveryDays = if (kind == "custom") days else null,
                revisions = if (kind == "custom") revisions else null,
            )
            val r = apiCall { ApiClient.specialOffers.send(body) }
            busy = false
            if (r is ApiResult.Ok) {
                Toast.makeText(context, context.getString(R.string.spo_sent), Toast.LENGTH_SHORT).show()
                onSent(r.data.uuid)
            } else {
                error = r.errorText(offlineText) ?: genericError
                // A refusal is final for this tap: the next try is a new offer.
                requestId = UUID.randomUUID().toString()
            }
        }
    }

    Page(title = stringResource(R.string.spo_compose_title), onBack = { back() }) {
        StepsBar(step)
        if (d == null) {
            if (error == null) SkeletonDetail()
        } else {
            AnimatedContent(targetState = step, transitionSpec = { fadeIn() togetherWith fadeOut() }, label = "spo-step") { s ->
                Column(verticalArrangement = Arrangement.spacedBy(14.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    when (s) {
                        1 -> ClientStep(clients, search, clientKey, onSearch = { search = it }, onPick = { clientKey = it; step = 2 })
                        2 -> KindStep(d, kind) { kind = it; price = ""; step = 3 }
                        3 -> {
                            when (kind) {
                                "custom" -> StepCard(1, stringResource(R.string.spo_custom_title), stringResource(R.string.spo_custom_sub)) {
                                    DorrTextField(title, { title = it.take(120) }, label = stringResource(R.string.spo_field_title), modifier = Modifier.fillMaxWidth())
                                    DorrTextField(description, { description = it.take(5000) }, label = stringResource(R.string.spo_field_description), singleLine = false, minLines = 4, modifier = Modifier.fillMaxWidth())
                                    if (d.specialties.size > 1) {
                                        ChipRow(d.specialties.map { "${it.id}" to it.name.orEmpty() }, setOfNotNull(specialtyId?.toString()), { specialtyId = it.toIntOrNull() })
                                    }
                                    PriceField(price, currency) { price = it }
                                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                        Stepper(stringResource(R.string.spo_field_days), days, 1..365, modifier = Modifier.weight(1f)) { days = it }
                                        Stepper(stringResource(R.string.spo_field_revisions), revisions, 0..20, modifier = Modifier.weight(1f)) { revisions = it }
                                    }
                                }
                                "service" -> StepCard(1, stringResource(R.string.spo_service_title), stringResource(R.string.spo_service_sub)) {
                                    ChipRow(d.services.map { it.uuid to it.title.orEmpty() }, setOfNotNull(serviceUuid), { serviceUuid = it; price = "" })
                                    if (service != null) {
                                        Segmented(service.levels.map { it.level to levelLabel(it.level) }, tier?.level ?: "") { level = it; price = "" }
                                        tier?.let { t ->
                                            MoneyLine(stringResource(R.string.spo_usual_price), svcMoney(t.priceMinor, decimals, currency), muted = true)
                                            QuickPercents { pct -> price = minorToInput(t.priceMinor * (100 - pct) / 100, decimals) }
                                        }
                                        PriceField(price, currency, label = stringResource(R.string.spo_field_special_price)) { price = it }
                                    }
                                }
                                else -> StepCard(1, stringResource(R.string.spo_consult_title), stringResource(R.string.spo_consult_sub)) {
                                    ChipRow(d.consultations.map { it.uuid to it.title.orEmpty() }, setOfNotNull(consultationUuid), { consultationUuid = it })
                                    val min = d.rules.minDiscountBp / 100
                                    val max = d.rules.maxDiscountBp / 100
                                    Stepper(stringResource(R.string.spo_field_discount), discount, min..max) { discount = it }
                                    QuickPercents(listOf(10, 15, 20, 25, 30).filter { it in min..max }) { discount = it }
                                    consultation?.lengths?.forEach { l ->
                                        val after = l.priceMinor * (100 - discount) / 100
                                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                            Text(stringResource(R.string.spo_minutes, l.minutes), color = settingsInk(), fontSize = 13.sp, modifier = Modifier.weight(1f))
                                            Text(svcMoney(l.priceMinor, decimals, currency), color = settingsMut(), fontSize = 12.sp, textDecoration = TextDecoration.LineThrough)
                                            Text(svcMoney(after, decimals, currency), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                                        }
                                    }
                                }
                            }
                            quote?.let { q ->
                                InfoCard(title = stringResource(R.string.svo_money), icon = Icons.Rounded.Payments) {
                                    MoneyLine(stringResource(R.string.svo_price), svcMoney(q.priceMinor, decimals, currency))
                                    MoneyLine(stringResource(R.string.svo_commission), "− " + svcMoney(q.commissionMinor, decimals, currency), muted = true)
                                    MoneyLine(stringResource(R.string.svo_net), svcMoney(q.netMinor, decimals, currency), strong = true)
                                    Text(stringResource(R.string.spo_client_pays, svcMoney(q.clientTotalMinor, decimals, currency)), color = settingsMut(), fontSize = 11.5.sp)
                                }
                            }
                            StepCard(2, stringResource(R.string.spo_personal_title), stringResource(R.string.spo_personal_sub)) {
                                DorrTextField(message, { message = it.take(1000) }, label = stringResource(R.string.spo_field_message), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
                                Stepper(stringResource(R.string.spo_field_valid), validDays, 1..d.rules.maxValidDays) { validDays = it }
                            }
                            detailsProblem?.let { Text(it, color = serviceTone("awaiting").ink, fontSize = 12.sp, fontWeight = FontWeight.SemiBold) }
                            SettingsPrimaryButton(text = stringResource(R.string.spo_preview), enabled = detailsProblem == null, modifier = Modifier.widthIn(max = 520.dp), onClick = { step = 4 })
                        }
                        else -> {
                            val client = clients?.firstOrNull { it.key == clientKey }
                            ReviewCard(
                                clientName = client?.name, clientAvatar = client?.avatarUrl, kind = kind,
                                title = when (kind) { "service" -> service?.title; "consultation" -> consultation?.title; else -> title.trim() }.orEmpty(),
                                subtitle = when (kind) { "service" -> listOfNotNull(tier?.let { levelLabel(it.level) }, tier?.name).joinToString(" · "); "custom" -> daysLabel(days); else -> null },
                                price = when (kind) {
                                    "consultation" -> stringResource(R.string.spo_discount_off, discount)
                                    else -> priceMinor?.let { svcMoney(it, decimals, currency) }.orEmpty()
                                },
                                listPrice = if (kind == "service") tier?.let { svcMoney(it.priceMinor, decimals, currency) } else null,
                                message = message.trim(), validDays = validDays,
                            )
                            SettingsPrimaryButton(text = stringResource(R.string.spo_send), loading = busy, modifier = Modifier.widthIn(max = 520.dp), onClick = { send() })
                            Text(stringResource(R.string.spo_send_hint), color = settingsMut(), fontSize = 11.5.sp, lineHeight = 17.sp)
                        }
                    }
                }
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

/** 1 Client · 2 What · 3 Details · 4 Review — a bar that fills as I go. */
@Composable
private fun StepsBar(step: Int) {
    val labels = listOf(R.string.spo_step_client, R.string.spo_step_kind, R.string.spo_step_details, R.string.spo_step_review)
    val fill by animateFloatAsState(step / 4f, label = "spo-bar")
    Column(Modifier.fillMaxWidth().widthIn(max = 520.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Box(Modifier.fillMaxWidth().height(6.dp).clip(RoundedCornerShape(99.dp)).background(settingsAccent().copy(alpha = 0.12f))) {
            Box(Modifier.fillMaxWidth(fill).height(6.dp).clip(RoundedCornerShape(99.dp)).background(settingsAccent()))
        }
        Row(Modifier.fillMaxWidth()) {
            labels.forEachIndexed { i, l ->
                Text(stringResource(l), color = if (i + 1 <= step) settingsAccent() else settingsMut(), fontSize = 11.5.sp,
                    fontWeight = if (i + 1 == step) FontWeight.ExtraBold else FontWeight.SemiBold, modifier = Modifier.weight(1f), maxLines = 1)
            }
        }
    }
}

@Composable
private fun ClientStep(clients: List<PastClientDto>?, search: String, picked: String?, onSearch: (String) -> Unit, onPick: (String) -> Unit) {
    DorrTextField(search, onSearch, placeholder = stringResource(R.string.spo_search_client), icon = Icons.Rounded.Search, modifier = Modifier.fillMaxWidth().widthIn(max = 520.dp))
    when {
        clients == null -> SkeletonList()
        clients.isEmpty() -> EmptyState(
            icon = Icons.Rounded.PersonSearch,
            title = stringResource(if (search.isBlank()) R.string.spo_no_clients else R.string.spo_no_match),
            body = stringResource(R.string.spo_no_clients_body),
            modifier = Modifier.padding(top = 30.dp),
        )
        else -> clients.forEach { c ->
            val on = c.key == picked
            Row(
                Modifier.fillMaxWidth().widthIn(max = 520.dp).settingsSurface(RoundedCornerShape(18.dp))
                    .then(if (on) Modifier.border(2.dp, settingsAccent(), RoundedCornerShape(18.dp)) else Modifier)
                    .clickable(enabled = c.canSend) { onPick(c.key) }.padding(12.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                Box { AvatarImage(c.avatarUrl, 44.dp) }
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(c.name.orEmpty(), color = if (c.canSend) settingsInk() else settingsMut(), fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(stringResource(R.string.spo_client_works, c.works), color = settingsAccent(), fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
                    c.lastTitle?.let { Text(stringResource(R.string.spo_client_last, it), color = settingsMut(), fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
                }
                when (c.blockedReason) {
                    "has_open" -> SvcPill(stringResource(R.string.spo_client_has_open), serviceTone("awaiting"))
                    "cooldown" -> SvcPill(stringResource(R.string.spo_client_cooldown, longDate(c.blockedUntil)), serviceTone("paused"))
                }
            }
        }
    }
}

@Composable
private fun KindStep(d: SpecialOfferComposeDto, kind: String, onPick: (String) -> Unit) {
    listOf(
        Triple("custom", R.string.spo_kind_custom, R.string.spo_kind_custom_body),
        Triple("service", R.string.spo_kind_service, R.string.spo_kind_service_body),
        Triple("consultation", R.string.spo_kind_consultation, R.string.spo_kind_consultation_body),
    ).forEach { (k, title, body) ->
        val enabled = d.kinds[k] == true
        val on = k == kind
        Row(
            Modifier.fillMaxWidth().widthIn(max = 520.dp).settingsSurface(RoundedCornerShape(20.dp))
                .then(if (on && enabled) Modifier.border(2.dp, settingsAccent(), RoundedCornerShape(20.dp)) else Modifier)
                .clickable(enabled = enabled) { onPick(k) }.padding(16.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            Box(Modifier.size(46.dp).clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = if (enabled) 0.12f else 0.05f)), contentAlignment = Alignment.Center) {
                Icon(spoKindIcon(k), contentDescription = null, tint = if (enabled) settingsAccent() else settingsMut(), modifier = Modifier.size(24.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(stringResource(title), color = if (enabled) settingsInk() else settingsMut(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                Text(
                    if (enabled) stringResource(body) else stringResource(if (k == "custom") R.string.spo_kind_off else R.string.spo_kind_none),
                    color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp,
                )
            }
        }
    }
}

@Composable
private fun PriceField(value: String, currency: String?, label: String = stringResource(R.string.spo_field_price), onChange: (String) -> Unit) {
    DorrTextField(
        value, { onChange(it.filter { ch -> ch.isDigit() || ch == '.' || ch == ',' || ch == '٫' }.take(14)) },
        label = label, icon = Icons.Rounded.Payments, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
        trailing = currency?.let { c -> { Text(c, color = settingsMut(), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(end = 12.dp)) } },
        modifier = Modifier.fillMaxWidth(),
    )
}

@Composable
private fun QuickPercents(values: List<Int> = listOf(10, 15, 20, 25), onPick: (Int) -> Unit) {
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        values.forEach { pct ->
            Text(
                "−$pct%", color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.10f)).clickable { onPick(pct) }.padding(horizontal = 12.dp, vertical = 7.dp),
            )
        }
    }
}

/** The offer as the client will see it in the DORR app. */
@Composable
private fun ReviewCard(clientName: String?, clientAvatar: String?, kind: String, title: String, subtitle: String?, price: String, listPrice: String?, message: String, validDays: Int) {
    InfoCard(title = stringResource(R.string.spo_review_title), icon = Icons.Rounded.Visibility) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            AvatarImage(clientAvatar, 34.dp)
            Text(stringResource(R.string.spo_review_to, clientName.orEmpty()), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
        }
        Column(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp))
                .background(Brush.linearGradient(listOf(settingsAccent().copy(alpha = 0.12f), settingsAccent().copy(alpha = 0.03f)))).padding(14.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(Icons.Rounded.LocalOffer, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
                Text(stringResource(R.string.spo_review_badge), color = settingsAccent(), fontSize = 11.5.sp, fontWeight = FontWeight.ExtraBold)
                Spacer(Modifier.weight(1f))
                Text(spoKindLabel(kind), color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.Bold)
            }
            Text(title, color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
            subtitle?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(price, color = settingsAccent(), fontSize = 20.sp, fontWeight = FontWeight.ExtraBold)
                listPrice?.let { Text(it, color = settingsMut(), fontSize = 13.sp, textDecoration = TextDecoration.LineThrough) }
            }
            if (message.isNotBlank()) Text("“$message”", color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp)
            Text(stringResource(R.string.spo_review_valid, validDays), color = serviceTone("awaiting").ink, fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
        }
    }
}
