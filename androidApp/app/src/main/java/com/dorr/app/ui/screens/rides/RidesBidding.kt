package com.dorr.app.ui.screens.rides

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateContentSize
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.foundation.BorderStroke
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
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Remove
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
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
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.RideBidBoardDto
import com.dorr.app.network.RideBidDto
import com.dorr.app.network.RideCurrencyDto
import com.dorr.app.network.RideDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import com.google.gson.JsonObject
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.util.UUID

/**
 * "Name your price" on the booking sheet (§9.1): typed straight in, or − / + by the server's step
 * (5 by default — never one unit per tap, O-43). − stops at the floor (the suggested price less the
 * admin's percentage); + has no ceiling worth showing. A typed price under the floor shows in red.
 */
@Composable
internal fun BidPriceEditor(price: Long, floor: Long, suggested: Long, step: Long, currency: RideCurrencyDto?, onChange: (Long) -> Unit) {
    val accent = settingsAccent()
    val places = currency?.decimals ?: 2
    val unit = remember(places) { java.math.BigDecimal.TEN.pow(places) }
    fun shown(minor: Long): String {
        val major = java.math.BigDecimal.valueOf(minor).divide(unit)
        return if (major.stripTrailingZeros().scale() <= 0) major.toBigInteger().toString() else String.format(java.util.Locale.US, "%.${places}f", major)
    }
    var text by remember { mutableStateOf(shown(price)) }
    var editing by remember { mutableStateOf(false) }
    LaunchedEffect(price) { if (!editing) text = shown(price) }
    val focus = androidx.compose.ui.platform.LocalFocusManager.current
    val below = price < floor

    Column(Modifier.fillMaxWidth().padding(top = 8.dp).clip(RoundedCornerShape(18.dp)).background(accent.copy(alpha = 0.06f)).padding(14.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Text(stringResource(R.string.rd_bid_your_price), color = settingsMut(), fontSize = 12.sp)
        Row(verticalAlignment = Alignment.CenterVertically) {
            StepButton(Icons.Rounded.Remove, enabled = price - step >= floor) { focus.clearFocus(); onChange((price - step).coerceAtLeast(floor)) }
            Row(
                Modifier.padding(horizontal = 12.dp).clip(RoundedCornerShape(14.dp))
                    .border(if (editing) 2.dp else 1.dp, if (below) AppColors.danger else if (editing) accent else accent.copy(alpha = 0.25f), RoundedCornerShape(14.dp))
                    .padding(horizontal = 10.dp, vertical = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                androidx.compose.foundation.text.BasicTextField(
                    value = text,
                    onValueChange = { raw ->
                        val clean = raw.replace(',', '.').filter { it.isDigit() || it == '.' }.take(9)
                        if (clean.count { it == '.' } > 1 || (places == 0 && clean.contains('.'))) return@BasicTextField
                        text = clean
                        clean.toBigDecimalOrNull()?.let { onChange(it.multiply(unit).toLong()) }
                    },
                    singleLine = true,
                    keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(
                        keyboardType = if (places > 0) androidx.compose.ui.text.input.KeyboardType.Decimal else androidx.compose.ui.text.input.KeyboardType.Number,
                        imeAction = androidx.compose.ui.text.input.ImeAction.Done,
                    ),
                    keyboardActions = androidx.compose.foundation.text.KeyboardActions(onDone = { focus.clearFocus() }),
                    textStyle = androidx.compose.ui.text.TextStyle(color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 28.sp, textAlign = androidx.compose.ui.text.style.TextAlign.Center),
                    cursorBrush = androidx.compose.ui.graphics.SolidColor(accent),
                    modifier = Modifier.width(110.dp).onFocusChanged { s ->
                        editing = s.isFocused
                        if (!s.isFocused) text = shown(price)
                    },
                )
                currency?.symbol?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontWeight = FontWeight.SemiBold, fontSize = 14.sp) }
            }
            StepButton(Icons.Rounded.Add, enabled = true) { focus.clearFocus(); onChange((price + step).coerceAtLeast(floor)) }
        }
        Text(
            stringResource(R.string.rd_bid_hint, money(suggested, currency), money(floor, currency)),
            color = if (below) AppColors.danger else if (price < suggested) AppColors.warning else settingsMut(), fontSize = 12.sp,
        )
    }
}

@Composable
private fun StepButton(icon: androidx.compose.ui.graphics.vector.ImageVector, enabled: Boolean, onClick: () -> Unit) {
    Box(
        Modifier.size(44.dp).clip(CircleShape).background(if (enabled) settingsAccent() else settingsMut().copy(alpha = 0.3f)).clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, null, tint = Color.White) }
}

/**
 * The bidding screen after booking (§9.2): my price (and raising it), and every driver's offer as a
 * card — cheapest first, its timer as a draining bar — with take / decline.
 */
@Composable
internal fun BiddingPanel(state: RidesState, onCancel: () -> Unit) {
    val ride = state.ride ?: return
    val scope = rememberCoroutineScope()
    var board by remember { mutableStateOf(RideBidBoardDto()) }
    var busy by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val myPrice = board.riderPriceMinor ?: ride.bidding?.riderPriceMinor ?: 0

    // The offers arrive as they are made (`ride.changed` on the ride's channel); an offer whose timer ends
    // is reloaded once at its own deadline — by the clock, not by asking.
    suspend fun reload() { runCatching { ApiClient.rides.bids(ridesAuth(), ride.uuid).data }.getOrNull()?.let { board = it } }
    LaunchedEffect(ride.uuid) {
        reload()
        com.dorr.app.chat.ChatRealtime.events.collect { e -> if (e.name == "ride.changed") reload() }
    }
    LaunchedEffect(board.bids.map { it.uuid to it.secondsLeft }) {
        val next = board.bids.map { it.secondsLeft }.filter { it > 0 }.minOrNull() ?: return@LaunchedEffect
        delay(next * 1000L + 1000L)
        reload()
    }

    fun raise(by: Long) {
        busy = "raise"
        error = null
        scope.launch {
            runCatching { ApiClient.rides.raise(ridesAuth(), ride.uuid, JsonObject().apply { addProperty("price_minor", myPrice + by) }).data }
                .onSuccess { it?.let { fresh -> state.ride = fresh }; board = board.copy(riderPriceMinor = myPrice + by) }
                .onFailure { error = it.apiFailure().message }
            busy = null
        }
    }

    fun take(bid: RideBidDto) {
        busy = bid.uuid
        error = null
        scope.launch {
            runCatching { ApiClient.rides.acceptBid(ridesAuth(), UUID.randomUUID().toString(), ride.uuid, bid.uuid).data }
                .onSuccess { it?.let { fresh -> state.ride = fresh } }
                .onFailure { error = it.apiFailure().message }
            busy = null
        }
    }

    fun decline(bid: RideBidDto) {
        board = board.copy(bids = board.bids.filterNot { it.uuid == bid.uuid })
        scope.launch { runCatching { ApiClient.rides.declineBid(ridesAuth(), ride.uuid, bid.uuid) } }
    }

    Row(verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(stringResource(if (board.bids.isEmpty()) R.string.rd_bid_waiting else R.string.rd_bid_offers), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
            Text(stringResource(R.string.rd_bid_my_price, money(myPrice, ride.currency)), color = settingsMut(), fontSize = 13.sp)
        }
        listOf(500L, 1000L).forEach { by ->
            Text(
                "+" + money(by, ride.currency.let { it?.copy(symbol = "") }), color = settingsAccent(), fontWeight = FontWeight.Bold, fontSize = 13.sp,
                modifier = Modifier.padding(start = 6.dp).clip(CircleShape).border(1.dp, settingsAccent(), CircleShape).clickable(enabled = busy == null) { raise(by) }.padding(horizontal = 10.dp, vertical = 6.dp),
            )
        }
    }
    if (board.bids.isEmpty()) {
        Spacer(Modifier.height(10.dp))
        LinearProgressIndicator(modifier = Modifier.fillMaxWidth().clip(CircleShape), color = settingsAccent())
        Text(stringResource(R.string.rd_bid_raise_hint), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.padding(top = 8.dp))
    }
    Spacer(Modifier.height(8.dp))
    LazyColumn(Modifier.fillMaxWidth().heightIn(max = 330.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        items(board.bids, key = { it.uuid }) { bid ->
            AnimatedVisibility(visible = true, enter = fadeIn() + expandVertically()) {
                BidCard(bid, ride, busy = busy == bid.uuid, onTake = { take(bid) }, onDecline = { decline(bid) })
            }
        }
    }
    if (board.queued > 0) Text(stringResource(R.string.rd_bid_queued, board.queued), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
    error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp)) }
    Text(
        stringResource(R.string.rd_cancel), color = AppColors.danger, fontWeight = FontWeight.Bold,
        modifier = Modifier.padding(top = 8.dp).clip(RoundedCornerShape(10.dp)).clickable(onClick = onCancel).padding(8.dp),
    )
}

@Composable
private fun BidCard(bid: RideBidDto, ride: RideDto, busy: Boolean, onTake: () -> Unit, onDecline: () -> Unit) {
    var left by remember(bid.uuid, bid.secondsLeft) { mutableIntStateOf(bid.secondsLeft) }
    LaunchedEffect(bid.uuid, bid.secondsLeft) { while (left > 0) { delay(1000); left -= 1 } }
    val progress by animateFloatAsState(left.toFloat() / bid.ttlS.coerceAtLeast(1), tween(900), label = "bid-timer")
    val accent = settingsAccent()
    val minute = stringResource(R.string.rd_minutes)

    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp))
            .border(BorderStroke(if (bid.cheapest) 2.dp else 1.dp, if (bid.cheapest) AppColors.success else settingsInk().copy(alpha = 0.08f)), RoundedCornerShape(16.dp))
            .background(settingsBackground()).padding(12.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(44.dp).clip(CircleShape).background(accent.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Text(bid.driver.name?.take(1).orEmpty(), color = accent, fontWeight = FontWeight.Bold)
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(bid.driver.name.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Bold)
                    Icon(Icons.Rounded.Star, null, tint = AppColors.warning, modifier = Modifier.padding(start = 4.dp).size(14.dp))
                    Text(bid.driver.rating?.let { String.format("%.1f", it) } ?: stringResource(R.string.rd_new_rating), color = settingsInk(), fontSize = 12.sp)
                }
                Text(
                    listOfNotNull(bid.driver.vehicle?.let { "${it.make.orEmpty()} ${it.model.orEmpty()}".trim() }, "${(bid.etaS + 59) / 60} $minute").joinToString(" · "),
                    color = settingsMut(), fontSize = 12.sp,
                )
                Row(horizontalArrangement = Arrangement.spacedBy(4.dp), modifier = Modifier.padding(top = 2.dp)) {
                    if (bid.cheapest) Badge(stringResource(R.string.rd_bid_cheapest), AppColors.success)
                    if (bid.nearest) Badge(stringResource(R.string.rd_bid_nearest), AppColors.info)
                }
            }
            Text(money(bid.priceMinor, ride.currency), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
        }
        LinearProgressIndicator(progress = { progress.coerceIn(0f, 1f) }, modifier = Modifier.fillMaxWidth().padding(vertical = 8.dp).height(4.dp).clip(CircleShape), color = if (left <= 5) AppColors.danger else accent, trackColor = accent.copy(alpha = 0.12f))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Box(
                Modifier.weight(1f).height(42.dp).clip(RoundedCornerShape(12.dp)).border(1.dp, settingsInk().copy(alpha = 0.15f), RoundedCornerShape(12.dp)).clickable(onClick = onDecline),
                contentAlignment = Alignment.Center,
            ) { Text(stringResource(R.string.rd_bid_decline), color = settingsInk(), fontWeight = FontWeight.SemiBold) }
            Box(
                Modifier.weight(2f).height(42.dp).clip(RoundedCornerShape(12.dp)).background(AppColors.success).clickable(enabled = !busy && left > 0, onClick = onTake),
                contentAlignment = Alignment.Center,
            ) {
                if (busy) androidx.compose.material3.CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
                else Text(stringResource(R.string.rd_bid_take, money(bid.priceMinor, ride.currency)), color = Color.White, fontWeight = FontWeight.Bold)
            }
        }
    }
}

@Composable
private fun Badge(text: String, color: Color) {
    Text(text, color = color, fontSize = 10.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).background(color.copy(alpha = 0.12f)).padding(horizontal = 6.dp, vertical = 2.dp))
}
