package com.dorr.app.ui.screens.rides

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.CreditCard
import androidx.compose.material.icons.rounded.LocalTaxi
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.Map
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Speed
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
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
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.RideQuoteDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.wallet.CheckoutLauncher
import com.dorr.app.ui.screens.wallet.CheckoutRequest
import com.dorr.app.ui.theme.AppColors
import com.google.android.gms.maps.CameraUpdateFactory
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.google.maps.android.compose.CameraPositionState
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.util.UUID

private fun RidePoint.json(): JsonObject = JsonObject().apply {
    addProperty("lat", lat)
    addProperty("lng", lng)
    addProperty("address", address.take(255))
}

/** The first card: where am I picked up, and "Where to?". */
@Composable
internal fun WhereToPanel(state: RidesState, hasLocation: Boolean, onAskLocation: () -> Unit) {
    PanelCard {
        if (!hasLocation) {
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(AppColors.brandOrange.copy(alpha = 0.1f)).clickable(onClick = onAskLocation).padding(12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.LocationOn, null, tint = AppColors.brandOrange)
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.rd_location_needed), color = settingsInk(), fontSize = 13.sp, modifier = Modifier.weight(1f))
                Text(stringResource(R.string.rd_allow), color = AppColors.brandOrange, fontWeight = FontWeight.Bold)
            }
            Spacer(Modifier.height(10.dp))
        }
        PointRow(Icons.Rounded.LocationOn, AppColors.brandNavy, stringResource(R.string.rd_pickup), state.pickup?.address ?: stringResource(R.string.rd_moving_pin)) {
            state.editing = "pickup"
            state.step = RideStep.Search
        }
        Spacer(Modifier.height(10.dp))
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(settingsBackground()).clickable {
                state.editing = "destination"
                state.step = RideStep.Search
            }.padding(horizontal = 16.dp, vertical = 16.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.Search, null, tint = settingsAccent())
            Spacer(Modifier.width(10.dp))
            Text(stringResource(R.string.rd_where_to), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 18.sp)
        }
        if (state.feature("saved_places")) SavedPlacesRow(state)
        if (state.feature("scheduled")) {
            Spacer(Modifier.height(10.dp))
            WhenChips(state)
        }
        if (state.feature("open_destination")) {
            Spacer(Modifier.height(10.dp))
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable {
                    state.openDestination = true
                    state.destination = null
                    state.stops.clear()
                    loadQuotes(state)
                }.padding(vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(Modifier.size(36.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Speed, null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
                }
                Spacer(Modifier.width(10.dp))
                Column {
                    Text(stringResource(R.string.rd_no_destination), color = settingsInk(), fontWeight = FontWeight.SemiBold)
                    Text(stringResource(R.string.rd_no_destination_hint), color = settingsMut(), fontSize = 12.sp)
                }
            }
        }
        state.error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp)) }
    }
}

@Composable
private fun PointRow(icon: androidx.compose.ui.graphics.vector.ImageVector, tint: Color, label: String, value: String, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable(onClick = onClick).padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = tint, modifier = Modifier.size(22.dp))
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(label, color = settingsMut(), fontSize = 11.sp)
            Text(value, color = settingsInk(), fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

/** Typing a place: pickup, destination and stops, with the phone geocoder's suggestions. */
@Composable
internal fun PlaceSearchPage(state: RidesState, camera: CameraPositionState) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var query by remember(state.editing) { mutableStateOf("") }
    var results by remember { mutableStateOf<List<RidePoint>>(emptyList()) }
    var searching by remember { mutableStateOf(false) }
    val focus = remember { FocusRequester() }
    val maxStops = 3

    LaunchedEffect(query) {
        if (query.trim().length < 3) { results = emptyList(); return@LaunchedEffect }
        delay(350)
        searching = true
        results = searchPlaces(context, query, state.me ?: state.pickup?.latLng)
        searching = false
    }
    LaunchedEffect(state.editing) { runCatching { focus.requestFocus() } }

    fun picked(point: RidePoint) {
        // R-5: the search is choosing Home or Work to keep, not a trip.
        state.savingPlace?.let { type ->
            savePlace(state, point, type)
            state.savingPlace = null
            state.step = RideStep.Map
            return
        }
        state.set(point)
        if (state.destination != null && state.pickup != null) loadQuotes(state) else state.step = RideStep.Map
    }

    Column(Modifier.fillMaxSize().background(settingsBackground()).statusBarsPadding().imePadding()) {
        Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            RoundButton(Icons.AutoMirrored.Rounded.ArrowBack) {
                state.savingPlace = null
                state.step = if (state.quotes.isNotEmpty()) RideStep.Quotes else RideStep.Map
            }
            Spacer(Modifier.width(10.dp))
            Text(
                stringResource(when (state.savingPlace) { "home" -> R.string.rd_place_pick_home; "work" -> R.string.rd_place_pick_work; else -> R.string.rd_where_to }),
                color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 20.sp,
            )
        }
        if (state.savingPlace == null) Column(Modifier.padding(horizontal = 16.dp).clip(RoundedCornerShape(18.dp)).background(settingsCard()).padding(12.dp)) {
            FieldRow(AppColors.brandNavy, stringResource(R.string.rd_pickup), state.pickup?.address, state.editing == "pickup") { state.editing = "pickup" }
            state.stops.forEachIndexed { i, stop ->
                FieldRow(AppColors.warning, stringResource(R.string.rd_stop, i + 1), stop.address, state.editing == "stop:$i", onRemove = { state.stops.removeAt(i) }) { state.editing = "stop:$i" }
            }
            FieldRow(AppColors.brandOrange, stringResource(R.string.rd_destination), state.destination?.address, state.editing == "destination") { state.editing = "destination" }
            if (state.feature("multi_stop") && state.stops.size < maxStops && state.destination != null) {
                Row(Modifier.clickable { state.editing = "stop:${state.stops.size}" }.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.Add, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                    Text(stringResource(R.string.rd_add_stop), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                }
            }
        }
        Spacer(Modifier.height(10.dp))
        Row(
            Modifier.padding(horizontal = 16.dp).fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsCard()).padding(horizontal = 14.dp, vertical = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.Search, null, tint = settingsMut())
            Spacer(Modifier.width(8.dp))
            Box(Modifier.weight(1f)) {
                if (query.isEmpty()) Text(stringResource(R.string.rd_search_hint), color = settingsMut())
                BasicTextField(
                    value = query, onValueChange = { query = it }, singleLine = true,
                    textStyle = TextStyle(color = settingsInk(), fontSize = 16.sp), cursorBrush = SolidColor(settingsAccent()),
                    modifier = Modifier.fillMaxWidth().focusRequester(focus),
                )
            }
            if (searching) androidx.compose.material3.CircularProgressIndicator(strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
        }
        LazyColumn(Modifier.weight(1f).padding(horizontal = 16.dp, vertical = 8.dp)) {
            if (state.savingPlace == null) item {
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable {
                        state.step = RideStep.PickOnMap
                        val start = when (state.editing) { "pickup" -> state.pickup; "destination" -> state.destination; else -> null }?.latLng ?: state.pickup?.latLng
                        start?.let { scope.launch { camera.animate(CameraUpdateFactory.newLatLngZoom(it, 16f)) } }
                    }.padding(vertical = 12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.Map, null, tint = settingsAccent())
                    Spacer(Modifier.width(10.dp))
                    Text(stringResource(R.string.rd_pick_on_map), color = settingsAccent(), fontWeight = FontWeight.SemiBold)
                }
            }
            if (query.trim().length in 1..2) item { Text(stringResource(R.string.rd_search_min), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(vertical = 8.dp)) }
            if (query.trim().length >= 3 && !searching && results.isEmpty()) item { Text(stringResource(R.string.rd_no_results), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(vertical = 8.dp)) }
            items(results) { place ->
                Row(Modifier.fillMaxWidth().clickable { picked(place) }.padding(vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(34.dp).clip(CircleShape).background(settingsCard()), contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.LocationOn, null, tint = settingsMut(), modifier = Modifier.size(18.dp))
                    }
                    Spacer(Modifier.width(10.dp))
                    Text(place.address, color = settingsInk(), maxLines = 2, overflow = TextOverflow.Ellipsis)
                }
            }
        }
    }
}

@Composable
private fun FieldRow(dot: Color, label: String, value: String?, active: Boolean, onRemove: (() -> Unit)? = null, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp))
            .background(if (active) settingsAccent().copy(alpha = 0.08f) else Color.Transparent)
            .clickable(onClick = onClick).padding(horizontal = 8.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(10.dp).clip(CircleShape).background(dot))
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(label, color = settingsMut(), fontSize = 11.sp)
            Text(value ?: "—", color = settingsInk(), maxLines = 1, overflow = TextOverflow.Ellipsis, fontWeight = if (active) FontWeight.Bold else FontWeight.Normal)
        }
        onRemove?.let { Icon(Icons.Rounded.Close, null, tint = settingsMut(), modifier = Modifier.size(18.dp).clickable(onClick = it)) }
    }
}

/** Picking a point by moving the map: the pin's point is set, then confirmed. */
@Composable
internal fun ConfirmPointPanel(state: RidesState) {
    val current = when {
        state.editing == "pickup" -> state.pickup
        state.editing == "destination" -> state.destination
        else -> state.editing.removePrefix("stop:").toIntOrNull()?.let { state.stops.getOrNull(it) }
    }
    PanelCard {
        Text(stringResource(R.string.rd_moving_pin), color = settingsMut(), fontSize = 12.sp)
        Text(current?.address ?: "…", color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 16.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
        Spacer(Modifier.height(14.dp))
        AccentButton(stringResource(R.string.rd_confirm_point), enabled = current != null) {
            if (state.pickup != null && (state.destination != null || state.openDestination)) loadQuotes(state) else state.step = RideStep.Search
        }
    }
}

/** Ask the server to price the trip for every category open here (`reprice`: the same trip, e.g. a promo code). */
internal fun loadQuotes(state: RidesState, reprice: Boolean = false) {
    val pickup = state.pickup ?: return
    state.step = RideStep.Quotes
    // A promo code re-prices the list in place; a new trip starts from an empty one.
    if (!reprice) {
        state.quotes = emptyList()
        state.selected = null
    }
    state.error = null
    state.busy = true
    kotlinx.coroutines.MainScope().launch {
        // R-16..R-18: in an airport or other sensitive place, the rider picks an approved point first.
        if (!reprice && state.feature("pickup_points") && askForPoint(state, pickup, state.destination?.takeIf { !state.openDestination })) {
            state.busy = false
            return@launch
        }
        val body = JsonObject().apply {
            add("origin", pickup.json().apply { state.pickupPointId?.let { addProperty("point_id", it) } })
            state.destination?.takeIf { !state.openDestination }?.let { add("destination", it.json().apply { state.dropoffPointId?.let { id -> addProperty("point_id", id) } }) }
            if (state.stops.isNotEmpty() && !state.openDestination) add("stops", JsonArray().apply { state.stops.forEach { add(it.json()) } })
            state.couponCode?.takeIf { !state.openDestination }?.let { addProperty("coupon_code", it) }
        }
        val keep = state.selected?.category?.slug
        runCatching { ApiClient.rides.quotes(ridesAuth(), body).data.orEmpty() }
            .onSuccess { list ->
                state.quotes = list
                // The same category stays chosen after a promo code re-prices the list.
                state.selected = list.firstOrNull { it.category.slug == keep } ?: list.firstOrNull()
                state.riderPrice = state.selected?.bid?.suggestedMinor
                if (state.selected?.category?.cashAllowed == false && state.payment == "cash") state.payment = "wallet"
            }
            .onFailure {
                val failure = it.apiFailure()
                if (state.couponCode != null && (failure.errorCode?.startsWith("rides_coupon_") == true || failure.errorCode == "rides_feature_disabled")) {
                    // A refused code never costs the prices: say why under the field and price without it.
                    state.couponError = failure.message
                    state.couponCode = null
                    state.busy = false
                    loadQuotes(state, reprice = true)
                    return@launch
                }
                state.error = failure.message
            }
        state.busy = false
    }
}

/** The prices: one card per category, how to pay, and the button. */
@Composable
internal fun QuotesPanel(state: RidesState, camera: CameraPositionState) {
    val scope = rememberCoroutineScope()
    val minute = stringResource(R.string.rd_minutes)
    val hour = stringResource(R.string.rd_hours)
    val errorText = stringResource(R.string.rd_error)
    var suggestCard by remember { mutableStateOf(false) }

    fun book() {
        val quote = state.selected ?: return
        if (state.scheduledAt != null && quote.scheduling == null) return
        state.busy = true
        state.error = null
        suggestCard = false
        scope.launch {
            val body = JsonObject().apply {
                addProperty("quote_uuid", quote.uuid)
                addProperty("fingerprint", quote.fingerprint)
                addProperty("payment_method", state.payment)
                if (quote.bid != null) addProperty("rider_price_minor", state.riderPrice ?: quote.bid.suggestedMinor)
                state.scheduledAt?.let { addProperty("scheduled_at", it.toOffsetDateTime().toString()) }
                addProperty("passengers", state.passengers)
                state.pickupNote.trim().takeIf { it.isNotEmpty() }?.let { addProperty("pickup_note", it) }
                // R-13: the person the ride is for, with the rider's own consent tick.
                // R-INN-07: the favourite captains first.
                circleBody(state)?.let { add("circle", it) }
                if (state.forOther) add("beneficiary", JsonObject().apply {
                    addProperty("name", state.beneficiaryName.trim())
                    addProperty("phone", state.beneficiaryPhone.trim())
                    addProperty("consent", state.beneficiaryConsent)
                })
            }
            runCatching { ApiClient.rides.book(ridesAuth(), UUID.randomUUID().toString(), body).data }
                .onSuccess { ride ->
                    state.ride = ride
                    // Card: the app's one payment screen; once paid the server holds it and searches.
                    if (ride?.status == "awaiting_payment") openRidePayment(state)
                }
                .onFailure { failure ->
                    val f = failure.apiFailure()
                    if (f.errorCode == "rides_insufficient_balance" && state.feature("card")) suggestCard = true
                    if (f.errorCode == "rides_quote_expired" || f.errorCode == "rides_quote_changed") loadQuotes(state)
                    state.error = f.message ?: errorText
                }
            state.busy = false
        }
    }

    if (state.pointsChooser != null) {
        PickupPointsPanel(state)
        return
    }

    // The promo code, the price details and the cancellation rules make this sheet taller: it scrolls.
    PanelCard { Column(Modifier.heightIn(max = 560.dp).verticalScroll(rememberScrollState())) {
        Text(stringResource(R.string.rd_choose_ride), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
        Spacer(Modifier.height(10.dp))
        if (state.feature("scheduled") && state.quotes.any { it.scheduling != null }) {
            WhenChips(state)
            Spacer(Modifier.height(8.dp))
        }
        if (state.busy && state.quotes.isEmpty()) {
            Box(Modifier.fillMaxWidth().height(90.dp), contentAlignment = Alignment.Center) { androidx.compose.material3.CircularProgressIndicator(color = settingsAccent()) }
        }
        state.quotes.forEach { quote ->
            QuoteCard(quote, selected = quote.uuid == state.selected?.uuid, minute = minute, hour = hour) {
                state.selected = quote
                state.riderPrice = quote.bid?.suggestedMinor
            }
        }
        state.selected?.bid?.let { bid ->
            BidPriceEditor(state.riderPrice ?: bid.suggestedMinor, bid.floorMinor, bid.suggestedMinor, bid.stepMinor, state.selected?.currency) { state.riderPrice = it }
        }

        state.selected?.let { quote ->
            Spacer(Modifier.height(8.dp))
            Text(stringResource(R.string.rd_pay_with), color = settingsMut(), fontSize = 12.sp)
            Spacer(Modifier.height(6.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                val fee = { m: String -> quote.paymentFee(m).takeIf { it > 0 }?.let { "+" + money(it, quote.currency) } }
                if (state.feature("wallet")) PayChip(Icons.Rounded.AccountBalanceWallet, stringResource(R.string.rd_pay_wallet), state.payment == "wallet", Modifier.weight(1f), fee("wallet")) { state.payment = "wallet" }
                if (state.feature("card")) PayChip(Icons.Rounded.CreditCard, stringResource(R.string.rd_pay_card), state.payment == "card", Modifier.weight(1f), fee("card")) { state.payment = "card" }
                if (state.feature("cash") && quote.category.cashAllowed != false) PayChip(Icons.Rounded.Payments, stringResource(R.string.rd_pay_cash), state.payment == "cash", Modifier.weight(1f), fee("cash")) { state.payment = "cash" }
            }
            Spacer(Modifier.height(6.dp))
            // R-5, R-174: keep this destination as Home, Work or a favourite.
            if (state.feature("saved_places")) SaveDestinationRow(state)
            // D-60, D-61: how many ride and a note for the captain.
            TripDetailsRow(state)
            // R-13: the ride is for someone else.
            if (state.feature("book_for_other")) BookForOtherRow(state)
            // R-INN-07: ask my favourite captains first.
            if (state.feature("favorite_circle")) CircleRow(state)
            // Wireflow م11 / م12: the promo code, every line of the price, and the rules it comes with.
            PromoCodeRow(state)
            PriceDetails(quote, state.payment)
            PolicySummary(quote, state.payment)
            // R-179: the terms in force for this booking, one tap away before confirming.
            TermsNotice(quote)
            state.error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp)) }
            if (suggestCard) {
                Text(
                    stringResource(R.string.rd_use_card), color = settingsAccent(), fontWeight = FontWeight.Bold,
                    modifier = Modifier.padding(top = 6.dp).clickable { state.payment = "card"; book() },
                )
            }
            val at = state.scheduledAt
            val (today, tomorrow) = rememberWhenLabels()
            if (at != null) {
                val rules = quote.scheduling
                Text(
                    if (rules == null) stringResource(R.string.rd_schedule_not_for_bid)
                    else stringResource(R.string.rd_scheduled_free_until, whenText(at.minusSeconds(rules.freeCancelBeforeS), today, tomorrow)),
                    color = if (rules == null) AppColors.warning else AppColors.success, fontSize = 12.sp, fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.padding(top = 8.dp),
                )
            }
            Spacer(Modifier.height(12.dp))
            AccentButton(
                if (at != null) stringResource(R.string.rd_confirm_scheduled, quote.category.name ?: "", whenText(at, today, tomorrow))
                else stringResource(R.string.rd_confirm, quote.category.name ?: ""),
                enabled = (at == null || quote.scheduling != null) && state.beneficiaryReady(), busy = state.busy,
            ) { book() }
        } ?: state.error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp) }
    } }
}

@Composable
private fun QuoteCard(quote: RideQuoteDto, selected: Boolean, minute: String, hour: String, onClick: () -> Unit) {
    val accent = settingsAccent()
    Row(
        Modifier.fillMaxWidth().padding(vertical = 4.dp).clip(RoundedCornerShape(16.dp))
            .border(BorderStroke(if (selected) 2.dp else 1.dp, if (selected) accent else settingsInk().copy(alpha = 0.08f)), RoundedCornerShape(16.dp))
            .background(if (selected) accent.copy(alpha = 0.05f) else Color.Transparent)
            .clickable(onClick = onClick).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(48.dp).clip(RoundedCornerShape(14.dp)).background(accent.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.LocalTaxi, null, tint = accent, modifier = Modifier.size(28.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(quote.category.name ?: quote.category.slug.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 16.sp)
            val sub = listOfNotNull(
                quote.route?.durationS?.let { minutesText(it, minute, hour) },
                quote.route?.distanceM?.let { stringResource(R.string.rd_km, String.format("%.1f", it / 1000.0)) },
                quote.category.capacity?.let { stringResource(R.string.rd_seats, it) },
            ).joinToString(" · ")
            if (sub.isNotEmpty()) Text(sub, color = settingsMut(), fontSize = 12.sp)
            quote.category.tagline?.let { Text(it, color = settingsMut(), fontSize = 11.sp, maxLines = 1) }
        }
        Column(horizontalAlignment = Alignment.End) {
            val currency = quote.currency
            when {
                quote.bid != null -> {
                    Text(money(quote.bid.suggestedMinor, currency), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 17.sp)
                    Text(stringResource(R.string.rd_bid_name_price), color = AppColors.brandOrange, fontSize = 11.sp, fontWeight = FontWeight.Bold)
                }
                quote.totalMinor != null -> {
                    StruckPrice(quote)
                    Text(money(quote.totalMinor, currency), color = if (quote.coupon?.applied == true) AppColors.success else settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 17.sp)
                    Text(stringResource(R.string.rd_fixed_price), color = AppColors.success, fontSize = 11.sp)
                }
                quote.estimate != null -> {
                    Text("${money(quote.estimate.minMinor, currency)}–${money(quote.estimate.maxMinor, currency)}", color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
                    Text(stringResource(R.string.rd_estimate), color = settingsMut(), fontSize = 11.sp)
                }
                else -> Text(stringResource(R.string.rd_rate_card), color = settingsInk(), fontWeight = FontWeight.Bold)
            }
        }
    }
}

@Composable
private fun PayChip(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, selected: Boolean, modifier: Modifier, fee: String? = null, onClick: () -> Unit) {
    val accent = settingsAccent()
    Column(
        modifier.clip(RoundedCornerShape(12.dp))
            .border(BorderStroke(if (selected) 2.dp else 1.dp, if (selected) accent else settingsInk().copy(alpha = 0.1f)), RoundedCornerShape(12.dp))
            .background(if (selected) accent.copy(alpha = 0.06f) else Color.Transparent)
            .clickable(onClick = onClick).padding(vertical = 10.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(icon, null, tint = if (selected) accent else settingsMut(), modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(6.dp))
            Text(label, color = settingsInk(), fontWeight = if (selected) FontWeight.Bold else FontWeight.Normal, fontSize = 13.sp)
        }
        // R-48: this way of paying adds a fee — shown on the chip before it is chosen.
        fee?.let { Text(it, color = AppColors.warning, fontSize = 10.sp, fontWeight = FontWeight.Bold) }
    }
}

/** A card ride's payment, on the app's one payment screen (purpose `ride`). */
internal fun openRidePayment(state: RidesState) {
    val uuid = state.ride?.uuid ?: return
    CheckoutLauncher.open(CheckoutRequest("ride", mapOf("ride_uuid" to uuid))) {
        kotlinx.coroutines.MainScope().launch {
            runCatching { ApiClient.rides.ride(ridesAuth(), uuid).data }.getOrNull()?.let { state.ride = it }
        }
    }
}
