@file:OptIn(ExperimentalLayoutApi::class)

package com.dorr.app.ui.screens.moving

import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.LocalShipping
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.CheckoutDto
import com.dorr.app.network.MovingCancellationDto
import com.dorr.app.network.MovingOfferDto
import com.dorr.app.network.MovingOffersPageDto
import com.dorr.app.network.MovingShowDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.PullToRefreshHost
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.rides.AccentButton
import com.dorr.app.ui.screens.rides.parseTime
import com.dorr.app.ui.screens.rides.rememberWhenLabels
import com.dorr.app.ui.screens.rides.staggerIn
import com.dorr.app.ui.screens.rides.whenText
import com.dorr.app.ui.screens.wallet.CheckoutLauncher
import com.dorr.app.ui.screens.wallet.CheckoutRequest
import com.dorr.app.ui.theme.AppColors
import com.google.gson.JsonElement

/*
 * The offers (REQ-096, owner's call): three at a time with «عرض المزيد», the number that came above them, each with the
 * reasons for its place said in words. Accepting shows the price, the tax, the total and the cancellation fees first
 * (REQ-043, REQ-115), then opens the app's one payment screen.
 */

/** The ticked parts of an offer's `includes` (an empty one may come as `[]`). */
internal fun JsonElement?.includedKeys(): List<String> =
    this?.takeIf { it.isJsonObject }?.asJsonObject?.entrySet()
        ?.filter { (_, v) -> v.isJsonPrimitive && (v.asString == "true" || v.asString == "1") }
        ?.map { it.key }.orEmpty()

@Composable
internal fun MovingOffersPage(uuid: String, decimals: Int, nav: MovingNav) {
    var total by remember { mutableIntStateOf(0) }
    var pages by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var offers by remember { mutableStateOf<List<MovingOfferDto>?>(null) }
    var deadline by remember { mutableStateOf<String?>(null) }
    var status by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var loadingMore by remember { mutableStateOf(false) }
    var accepting by remember { mutableStateOf<MovingOfferDto?>(null) }
    val failed = stringResource(R.string.rd_error)

    /** The pages already on screen, read again: a new or withdrawn offer changes the order. */
    suspend fun reload() {
        var collected = emptyList<MovingOfferDto>()
        var last: MovingOffersPageDto? = null
        runCatching {
            for (p in 1..pages) {
                val page = ApiClient.moving.offers(mvAuth(), uuid, p).data ?: break
                collected = collected + page.offers
                last = page
            }
        }.onFailure { error = it.apiFailure().message ?: failed }
        last?.let {
            total = it.total
            hasMore = it.hasMore
            offers = collected
            error = null
        }
        if (offers == null) offers = emptyList()
        runCatching { ApiClient.moving.show(mvAuth(), uuid).data }.getOrNull()?.let { show: MovingShowDto ->
            deadline = show.request.offersDeadlineAt
            status = show.request.status
        }
    }

    MovingLiveEffect(uuid = uuid, onChanged = { reload() })
    // The request moved on (expired, an offer taken on another phone): the hub says what happened.
    LaunchedEffect(status) { if (status != null && status != "open") nav.backToRequest(uuid) }
    LaunchedEffect(pages) { if (pages > 1) { reload(); loadingMore = false } }

    val offersLeft = rememberSecondsLeft(deadline)
    MvPage(title = stringResource(R.string.mv_offers_title), onBack = { nav.pop() }) {
        PullToRefreshHost(Modifier.fillMaxSize()) {
            val list = offers
            if (list == null) {
                MvLoading()
                return@PullToRefreshHost
            }
            if (list.isEmpty()) {
                OffersWaiting(error, offersLeft)
                return@PullToRefreshHost
            }
            MvScroll {
                Text(offersCountText(total), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 22.sp)
                Text(stringResource(R.string.mv_offers_order_hint), color = settingsMut(), fontSize = 12.5.sp)
                offersLeft?.takeIf { it > 0 }?.let { Text(stringResource(R.string.mv_offers_left, countdownText(it)), color = settingsAccent(), fontWeight = FontWeight.SemiBold, fontSize = 13.sp) }
                list.forEachIndexed { i, offer ->
                    Box(Modifier.staggerIn(i)) {
                        OfferCard(
                            offer,
                            decimals,
                            onOpen = { nav.push(MvRoute.OfferDetails(uuid, offer, decimals)) },
                            onAccept = { accepting = offer },
                        )
                    }
                }
                if (hasMore) {
                    AccentButton(stringResource(R.string.mv_see_more), busy = loadingMore) {
                        loadingMore = true
                        pages += 1
                    }
                }
                MvError(error)
            }
        }
    }

    accepting?.let { offer ->
        AcceptOfferSheet(uuid, offer, decimals, onClose = { accepting = null }, onPaid = { nav.backToRequest(uuid) })
    }
}

/** No offer yet: the rides' calm radar, and how long the offers can still come. */
@Composable
private fun OffersWaiting(error: String?, left: Long?) {
    val wave by rememberInfiniteTransition(label = "mv-radar").animateFloat(0f, 1f, infiniteRepeatable(tween(1800, easing = LinearEasing), RepeatMode.Restart), label = "wave")
    val accent = settingsAccent()
    MvScroll {
        Spacer(Modifier.height(40.dp))
        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
            Box(Modifier.size(110.dp), contentAlignment = Alignment.Center) {
                listOf(0f, 0.33f, 0.66f).forEach { offset ->
                    val p = (wave + offset) % 1f
                    Box(Modifier.size(110.dp).scale(0.3f + p * 0.7f).clip(CircleShape).background(accent.copy(alpha = (1f - p) * 0.25f)))
                }
                Box(Modifier.size(46.dp).clip(CircleShape).background(accent), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.LocalShipping, null, tint = Color.White)
                }
            }
            Text(stringResource(R.string.mv_offers_waiting), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
            left?.takeIf { it > 0 }?.let { Text(stringResource(R.string.mv_offers_left, countdownText(it)), color = settingsMut(), fontSize = 13.sp) }
            error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp)) }
        }
    }
}

@Composable
private fun OfferCard(offer: MovingOfferDto, decimals: Int, onOpen: () -> Unit, onAccept: () -> Unit) {
    val cheapest = "cheapest" in offer.reasons
    val shape = RoundedCornerShape(20.dp)
    Column(
        Modifier.fillMaxWidth().clip(shape).background(settingsCard())
            .border(if (cheapest) 2.dp else 1.dp, if (cheapest) AppColors.success else settingsInk().copy(alpha = 0.08f), shape)
            .padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        OfferHead(offer, decimals)
        if (offer.reasons.isNotEmpty()) {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                offer.reasons.forEach { OfferReasonChip(it) }
            }
        }
        OfferVehicle(offer)
        OfferFacts(offer)
        OfferValidity(offer)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Box(Modifier.weight(1f)) { MvOutlineButton(stringResource(R.string.mv_offer_details), onClick = onOpen) }
            Box(Modifier.weight(1f)) { AccentButton(stringResource(R.string.mv_accept), onClick = onAccept) }
        }
    }
}

/** The price, and who offers it with their stars and finished moves. */
@Composable
private fun OfferHead(offer: MovingOfferDto, decimals: Int) {
    val e = offer.establishment
    Row(verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(46.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            if (e?.logo != null) AsyncImage(ApiClient.mediaUrl(e.logo), null, contentScale = ContentScale.Crop, modifier = Modifier.size(46.dp).clip(CircleShape))
            else Text(e?.name?.take(1).orEmpty(), color = settingsAccent(), fontWeight = FontWeight.Bold)
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(e?.name.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Bold)
            offer.branch?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Star, null, tint = AppColors.warning, modifier = Modifier.size(14.dp))
                Text(
                    e?.average?.let { String.format(java.util.Locale.getDefault(), "%.1f", it) } ?: stringResource(R.string.rd_new_rating),
                    color = settingsInk(), fontSize = 12.sp,
                )
                if ((e?.count ?: 0) > 0) Text(" (${e?.count})", color = settingsMut(), fontSize = 12.sp)
                Text(" · " + stringResource(R.string.mv_finished_moves, e?.jobs ?: 0), color = settingsMut(), fontSize = 12.sp)
            }
        }
        Text(mvMoney(offer.priceMinor, offer.currencyCode, decimals), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
    }
}

/** Why it sits here, in words: the order is explained, never a choice made for the customer. */
@Composable
private fun OfferReasonChip(reason: String) {
    val (text, color) = when (reason) {
        "cheapest" -> stringResource(R.string.mv_reason_cheapest) to AppColors.success
        "earliest" -> stringResource(R.string.mv_reason_earliest) to AppColors.info
        "vehicle_fit" -> stringResource(R.string.mv_reason_vehicle_fit) to settingsAccent()
        else -> reason to settingsMut()
    }
    MvChip(text, color)
}

@Composable
private fun OfferVehicle(offer: MovingOfferDto) {
    val vehicle = offer.vehicle ?: return
    vehicle.type?.name?.let { MvLine(stringResource(R.string.mv_vehicle_title), it) }
    if (vehicle.photos.isNotEmpty()) {
        LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            items(vehicle.photos) { url ->
                AsyncImage(ApiClient.mediaUrl(url), null, contentScale = ContentScale.Crop, modifier = Modifier.size(width = 120.dp, height = 84.dp).clip(RoundedCornerShape(14.dp)).background(mvInsetColor()))
            }
        }
    }
}

@Composable
private fun OfferFacts(offer: MovingOfferDto) {
    val (today, tomorrow) = rememberWhenLabels()
    offer.workersCount?.let { MvLine(stringResource(R.string.mv_workers), it.toString()) }
    offer.tripsCount?.let { MvLine(stringResource(R.string.mv_trips), it.toString()) }
    parseTime(offer.arrivalAt)?.let { MvLine(stringResource(R.string.mv_arrival), whenText(it, today, tomorrow)) }
    val included = offer.includes.includedKeys()
    if (included.isNotEmpty()) {
        Text(stringResource(R.string.mv_included), color = settingsMut(), fontSize = 12.sp)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            included.forEach { MvChip(extraLabel(it), settingsAccent()) }
        }
    }
}

/** How long the offer holds, on the device clock. */
@Composable
private fun OfferValidity(offer: MovingOfferDto) {
    val left = rememberSecondsLeft(offer.validUntil)
    left?.let {
        val over = it <= 0
        Text(
            if (over) stringResource(R.string.mv_offer_expired) else stringResource(R.string.mv_valid_for, countdownText(it)),
            color = if (over) AppColors.danger else if (it < 600) AppColors.warning else settingsMut(), fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold,
        )
    }
}

/** The offer in full: everything on the card, its terms and what is promised after the move. */
@Composable
internal fun MovingOfferDetailsPage(uuid: String, offer: MovingOfferDto, decimals: Int, nav: MovingNav) {
    var accepting by remember { mutableStateOf(false) }
    MvPage(
        title = stringResource(R.string.mv_offer_details),
        onBack = { nav.pop() },
        bottom = {
            AccentButton(stringResource(R.string.mv_accept_price, mvMoney(offer.priceMinor, offer.currencyCode, decimals))) { accepting = true }
        },
    ) {
        MvScroll {
            MvCard(Modifier.padding(top = 2.dp)) {
                OfferHead(offer, decimals)
                if (offer.reasons.isNotEmpty()) {
                    Spacer(Modifier.height(8.dp))
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        offer.reasons.forEach { OfferReasonChip(it) }
                    }
                }
            }
            MvCard { OfferVehicle(offer); OfferFacts(offer); OfferValidity(offer) }
            // Knowing the company: its rating by criteria and where its branches are.
            offer.establishment?.let { MvCompanyRatingCard(it) }
            MvCompanyBranchesCard(offer.branches)
            offer.technicianNote?.takeIf { it.isNotBlank() }?.let { note ->
                MvCard {
                    MvCardTitle(stringResource(R.string.mv_extra_technician))
                    Spacer(Modifier.height(4.dp))
                    Text(note, color = settingsInk(), fontSize = 14.sp)
                }
            }
            offer.terms?.takeIf { it.isNotBlank() }?.let { terms ->
                MvCard {
                    MvCardTitle(stringResource(R.string.mv_terms))
                    Spacer(Modifier.height(4.dp))
                    Text(terms, color = settingsInk(), fontSize = 14.sp)
                }
            }
            offer.afterService?.takeIf { it.isNotBlank() }?.let { after ->
                MvCard {
                    MvCardTitle(stringResource(R.string.mv_after_service))
                    Spacer(Modifier.height(4.dp))
                    Text(after, color = settingsInk(), fontSize = 14.sp)
                }
            }
        }
    }
    if (accepting) AcceptOfferSheet(uuid, offer, decimals, onClose = { accepting = false }, onPaid = { nav.backToRequest(uuid) })
}

/**
 * Before paying: the price, the tax and the total (the checkout's amount is the total), with the cancellation fees
 * (REQ-043, REQ-115); then the app's one payment screen, as the rides card payment opens it. Paying holds the money;
 * it reaches the establishment only after delivery.
 */
@Composable
private fun AcceptOfferSheet(uuid: String, offer: MovingOfferDto, decimals: Int, onClose: () -> Unit, onPaid: () -> Unit) {
    var checkout by remember { mutableStateOf<CheckoutDto?>(null) }
    var terms by remember { mutableStateOf<MovingCancellationDto?>(null) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.rd_error)
    LaunchedEffect(offer.uuid) {
        runCatching { ApiClient.moving.accept(mvAuth(), uuid, offer.uuid).data }
            .onSuccess { checkout = it }
            .onFailure { error = it.apiFailure().message ?: failed }
        terms = runCatching { ApiClient.moving.cancellation(mvAuth(), uuid).data }.getOrNull()
        loading = false
    }
    val code = offer.currencyCode
    MvSheet(onClose) {
        Text(stringResource(R.string.mv_accept_title), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 20.sp)
        Text(offer.establishment?.name.orEmpty(), color = settingsMut(), fontSize = 13.sp)
        if (loading) {
            Box(Modifier.fillMaxWidth().height(120.dp)) { MvLoading() }
        } else {
            val c = checkout
            if (c != null) {
                val total = c.amountMinor
                Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(mvInsetColor()).padding(12.dp)) {
                    MvLine(stringResource(R.string.mv_price), mvMoney(offer.priceMinor, code, decimals))
                    MvLine(stringResource(R.string.mv_tax), mvMoney((total - offer.priceMinor).coerceAtLeast(0), code, decimals))
                    MvLine(stringResource(R.string.mv_total), mvMoney(total, code, decimals), bold = true)
                }
                Text(stringResource(R.string.mv_accept_held), color = settingsMut(), fontSize = 12.5.sp)
            }
            terms?.let { CancellationTable(it) }
            MvError(error)
            if (c != null) {
                AccentButton(stringResource(R.string.mv_go_pay)) {
                    CheckoutLauncher.open(CheckoutRequest(c.purpose, mapOf("request_uuid" to uuid, "offer_uuid" to offer.uuid), c.id)) { onPaid() }
                    onClose()
                }
            }
        }
    }
}

/** The fee table in force for this request; what the country left empty costs nothing. */
@Composable
private fun CancellationTable(t: MovingCancellationDto) {
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(mvInsetColor()).padding(12.dp)) {
        MvCardTitle(stringResource(R.string.mv_fees_title))
        Spacer(Modifier.height(4.dp))
        val hours = t.nearHours?.let { if (it % 1.0 == 0.0) it.toInt().toString() else it.toString() }
        MvLine(if (hours != null) stringResource(R.string.mv_fee_early_hours, hours) else stringResource(R.string.mv_fee_early), feeText(t.earlyBp))
        MvLine(if (hours != null) stringResource(R.string.mv_fee_near_hours, hours) else stringResource(R.string.mv_fee_near), feeText(t.nearBp))
        MvLine(stringResource(R.string.mv_fee_on_way), feeText(t.onWayBp))
        MvLine(stringResource(R.string.mv_fee_arrived), feeText(t.arrivedBp))
        Text(stringResource(R.string.mv_fee_after_loading), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
    }
}

@Composable
private fun feeText(bp: Double?): String {
    val percent = (bp ?: 0.0) / 100.0
    if (percent <= 0) return stringResource(R.string.mv_fee_none)
    return stringResource(R.string.mv_fee_percent, if (percent % 1.0 == 0.0) percent.toInt().toString() else String.format(java.util.Locale.US, "%.2f", percent))
}
