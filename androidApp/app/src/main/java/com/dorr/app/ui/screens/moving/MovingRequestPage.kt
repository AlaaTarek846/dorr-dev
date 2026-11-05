@file:OptIn(ExperimentalLayoutApi::class)

package com.dorr.app.ui.screens.moving

import android.widget.Toast
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.PublishedWithChanges
import androidx.compose.material.icons.rounded.Receipt
import androidx.compose.material.icons.rounded.ReportProblem
import androidx.compose.material.icons.rounded.SupportAgent
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.Text
import androidx.compose.material3.Icon
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.map.DorrCamera
import com.dorr.app.map.DorrMap
import com.dorr.app.map.MapColors
import com.dorr.app.map.MapLine
import com.dorr.app.map.MapPin
import com.dorr.app.map.PinKind
import com.dorr.app.map.dpPx
import com.dorr.app.map.rememberDorrCamera
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MovingChangeDto
import com.dorr.app.network.MovingEndDto
import com.dorr.app.network.MovingRequestDto
import com.dorr.app.network.MovingShowDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.components.PullToRefreshHost
import com.dorr.app.ui.screens.chat.qrImage
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.rides.AccentButton
import com.dorr.app.ui.screens.rides.PanelCard
import com.dorr.app.ui.screens.rides.Plate
import com.dorr.app.ui.screens.rides.RoundButton
import com.dorr.app.ui.screens.rides.TripLine
import com.dorr.app.ui.screens.rides.parseTime
import com.dorr.app.ui.screens.rides.rememberWhenLabels
import com.dorr.app.ui.screens.rides.whenText
import com.dorr.app.ui.theme.AppColors
import com.google.android.gms.maps.model.LatLng
import com.google.gson.JsonObject
import kotlinx.coroutines.launch

/*
 * The request page, the hub of everything after publishing: it switches by status. Waiting for offers: how many came,
 * and taking it back. The job: the timeline, the driver and the plate, the delivery code and, once the driver moves,
 * the rides map with the truck. After delivery: the objection time on the device clock, «received intact», the report
 * and the rating. Changes, a new time, cancelling, the invoice and the tickets are one tap away from here.
 */

/** The order the customer follows; anything else is a side state. */
private val TIMELINE = listOf("confirmed", "assigned", "driver_on_way", "arrived", "loading", "in_transit", "unloading", "delivered", "completed")
private val MAP_STATUSES = setOf("driver_on_way", "arrived", "loading", "in_transit", "unloading")
private val AFTER_ACCEPT = setOf("confirmed", "assigned", "driver_on_way", "arrived", "loading", "in_transit", "unloading", "delivered", "completed", "disputed", "cancelled")
private val CAN_CANCEL = setOf("confirmed", "assigned", "driver_on_way", "arrived")
private val EXCEPTION_ONLY = setOf("loading", "in_transit", "unloading")
private val CAN_RATE = setOf("delivered", "completed", "disputed")
private val HAS_INVOICE = setOf("confirmed", "assigned", "driver_on_way", "arrived", "loading", "in_transit", "unloading", "delivered", "completed", "disputed", "cancelled")

@Composable
internal fun MovingRequestPage(uuid: String, nav: MovingNav) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var show by remember(uuid) { mutableStateOf<MovingShowDto?>(null) }
    var changes by remember(uuid) { mutableStateOf<List<MovingChangeDto>>(emptyList()) }
    var loadError by remember(uuid) { mutableStateOf<String?>(null) }
    var truck by remember(uuid) { mutableStateOf<LatLng?>(null) }
    var heading by remember(uuid) { mutableFloatStateOf(0f) }
    var cancelOpen by remember { mutableStateOf(false) }
    var withdrawOpen by remember { mutableStateOf(false) }
    val failed = stringResource(R.string.rd_error)

    suspend fun reload() {
        runCatching { ApiClient.moving.show(mvAuth(), uuid).data }
            .onSuccess { fresh ->
                if (fresh != null) {
                    show = fresh
                    loadError = null
                    val at = fresh.driverLocation
                    val lat = at?.lat
                    val lng = at?.lng
                    if (lat != null && lng != null) truck = LatLng(lat, lng)
                    if (fresh.request.status in AFTER_ACCEPT) {
                        changes = runCatching { ApiClient.moving.changes(mvAuth(), uuid).data.orEmpty() }.getOrDefault(changes)
                    }
                }
            }
            .onFailure { if (show == null) loadError = it.apiFailure().message ?: failed }
    }

    MovingLiveEffect(
        uuid = uuid,
        onChanged = { reload() },
        onDriver = { lat, lng, h ->
            truck = LatLng(lat, lng)
            if (h != null) heading = h
        },
    )

    val current = show
    val status = current?.request?.status

    // A draft continues in the steps.
    LaunchedEffect(status) { if (status == "draft") nav.replace(MvRoute.Wizard(uuid)) }

    val onReload: () -> Unit = { scope.launch { reload() } }

    val title = stringResource(R.string.mv_request_title)
    when {
        current == null -> MvPage(title = title, onBack = { nav.pop() }) {
            if (loadError != null) MvEmpty(Icons.Rounded.ReportProblem, loadError.orEmpty()) else MvLoading()
        }
        status in MAP_STATUSES -> {
            val camera = rememberDorrCamera(LatLng(24.7136, 46.6753), 13f)
            val panelMax = (LocalConfiguration.current.screenHeightDp * 0.6f).dp
            Box(Modifier.fillMaxSize().background(settingsBackground())) {
                JobMap(current.request, truck, heading, camera)
                Box(Modifier.fillMaxWidth().statusBarsPadding().padding(16.dp)) {
                    RoundButton(Icons.AutoMirrored.Rounded.ArrowBack, onClick = { nav.pop() })
                }
                Box(Modifier.align(Alignment.BottomCenter).fillMaxWidth()) {
                    PanelCard {
                        CompositionLocalProvider(LocalMvOnPanel provides true) {
                            Column(Modifier.heightIn(max = panelMax).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                                RequestSections(current, changes, nav, onReload, onCancel = { cancelOpen = true }, onWithdraw = { withdrawOpen = true })
                            }
                        }
                    }
                }
            }
        }
        else -> MvPage(title = title, onBack = { nav.pop() }) {
            PullToRefreshHost(Modifier.fillMaxSize()) {
                MvScroll {
                    RequestSections(current, changes, nav, onReload, onCancel = { cancelOpen = true }, onWithdraw = { withdrawOpen = true })
                }
            }
        }
    }

    if (cancelOpen && current != null) MovingCancelSheet(
        show = current,
        onClose = { cancelOpen = false },
        onDone = { message ->
            cancelOpen = false
            message?.let { Toast.makeText(context, it, Toast.LENGTH_LONG).show() }
            onReload()
        },
    )
    if (withdrawOpen && current != null) MovingWithdrawSheet(
        uuid = uuid,
        title = stringResource(R.string.mv_withdraw_title),
        body = stringResource(R.string.mv_withdraw_body),
        action = stringResource(R.string.mv_withdraw),
        onClose = { withdrawOpen = false },
        onDone = { message ->
            withdrawOpen = false
            message?.let { Toast.makeText(context, it, Toast.LENGTH_LONG).show() }
            onReload()
        },
    )
}

/** The rides map: the two ends joined, and the truck sliding as its place arrives. */
@Composable
private fun JobMap(request: MovingRequestDto, truck: LatLng?, heading: Float, camera: DorrCamera) {
    val pickup = request.pickup.latLng()
    val dropoff = request.dropoff.latLng()
    val pins = buildList {
        pickup?.let { add(MapPin(it, PinKind.Pickup)) }
        dropoff?.let { add(MapPin(it, PinKind.Dropoff)) }
        truck?.let { add(MapPin(it, PinKind.Car, heading, id = "moving-truck")) }
    }
    val route = listOfNotNull(pickup, dropoff)
    val width = dpPx(5f)
    DorrMap(
        camera = camera,
        modifier = Modifier.fillMaxSize(),
        pins = pins,
        lines = if (route.size > 1) listOf(MapLine(route, MapColors.route, width)) else emptyList(),
        padding = PaddingValues(bottom = 300.dp),
    )
    val fitPadding = dpPx(48f).toInt()
    LaunchedEffect(route.size, truck != null) { camera.fit(listOfNotNull(pickup, dropoff, truck), fitPadding) }
}

private fun MovingEndDto?.latLng(): LatLng? {
    val lat = this?.lat
    val lng = this?.lng
    return if (lat != null && lng != null) LatLng(lat, lng) else null
}

/** The cards of the page, in the order the customer needs them for this status. */
@Composable
private fun ColumnScope.RequestSections(
    show: MovingShowDto,
    changes: List<MovingChangeDto>,
    nav: MovingNav,
    onReload: () -> Unit,
    onCancel: () -> Unit,
    onWithdraw: () -> Unit,
) {
    val r = show.request
    val status = r.status
    StatusHeader(r)
    when (status) {
        "open" -> OpenCard(show, nav, onReload)
        "expired" -> MvNote(stringResource(R.string.mv_expired_note), AppColors.warning)
        "withdrawn" -> MvNote(stringResource(R.string.mv_withdrawn_note))
        "cancelled" -> MvNote(stringResource(R.string.mv_cancelled_note), AppColors.danger)
    }
    if (status in TIMELINE || status == "disputed") TimelineCard(status)
    val pending = changes.firstOrNull { it.status == "pending" && it.proposedBy == "provider" }
    val unpaid = changes.firstOrNull { it.status == "awaiting_payment" }
    if (pending != null || unpaid != null) {
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(AppColors.warning.copy(alpha = 0.12f)).clickable { nav.push(MvRoute.Changes(r.uuid)) }.padding(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.WarningAmber, null, tint = AppColors.warning, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(8.dp))
            Text(stringResource(if (unpaid != null) R.string.mv_change_pay_banner else R.string.mv_change_banner), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.weight(1f))
        }
    }
    if (r.driver != null || r.vehicle != null) DriverCard(r)
    show.deliveryCode?.let { code -> DeliveryCodeCard(code, show.deliveryQr) }
    if (status == "delivered") DeliveredCard(show, nav, onReload)
    CasesCard(show)
    if (status in CAN_RATE && !show.rated) RatingCard(r, onReload)
    r.money?.let { MoneyCard(r) }
    if (status !in MAP_STATUSES) RequestSummary(r)
    Actions(show, nav, onCancel, onWithdraw)
}

@Composable
private fun StatusHeader(r: MovingRequestDto) {
    val (label, color) = movingStatusLook(r.status)
    val (today, tomorrow) = rememberWhenLabels()
    Row(verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(loadTypeLabel(r.loadType), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
            parseTime(r.scheduledAt)?.let { Text(whenText(it, today, tomorrow), color = settingsMut(), fontSize = 13.sp) }
        }
        MvChip(label, color)
    }
}

/** Waiting for offers: how many came, how long is left, and taking the request back. */
@Composable
private fun OpenCard(show: MovingShowDto, nav: MovingNav, onReload: () -> Unit) {
    val r = show.request
    val left = rememberSecondsLeft(r.offersDeadlineAt, onEnd = onReload)
    MvCard {
        Text(
            if (show.offersCount > 0) offersCountText(show.offersCount) else stringResource(R.string.mv_offers_waiting),
            color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp,
        )
        left?.takeIf { it > 0 }?.let { Text(stringResource(R.string.mv_offers_left, countdownText(it)), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(top = 2.dp)) }
        if (show.offersCount > 0) {
            Spacer(Modifier.height(12.dp))
            AccentButton(stringResource(R.string.mv_see_offers), icon = Icons.Rounded.LocalOffer) { nav.push(MvRoute.Offers(r.uuid, r.currencyDecimals)) }
        }
    }
}

/** The status as steps: done with a tick, the current one pulsing, the rest waiting. */
@Composable
private fun TimelineCard(status: String) {
    val at = if (status == "disputed") TIMELINE.indexOf("delivered") else TIMELINE.indexOf(status)
    val pulse by rememberInfiniteTransition(label = "mv-now").animateFloat(0.55f, 1f, infiniteRepeatable(tween(800), RepeatMode.Reverse), label = "a")
    MvCard {
        MvCardTitle(stringResource(R.string.mv_timeline))
        Spacer(Modifier.height(10.dp))
        TIMELINE.forEachIndexed { i, step ->
            val done = i < at
            val now = i == at
            Row(verticalAlignment = Alignment.Top) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Box(
                        Modifier.size(20.dp).alpha(if (now) pulse else 1f).clip(CircleShape).background(
                            when {
                                done -> AppColors.success
                                now -> settingsAccent()
                                else -> settingsInk().copy(alpha = 0.10f)
                            },
                        ),
                        contentAlignment = Alignment.Center,
                    ) {
                        if (done) Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(13.dp))
                        if (now) Box(Modifier.size(7.dp).clip(CircleShape).background(Color.White))
                    }
                    if (i < TIMELINE.lastIndex) Box(Modifier.width(2.dp).height(16.dp).background(if (done) AppColors.success else settingsInk().copy(alpha = 0.10f)))
                }
                Spacer(Modifier.width(10.dp))
                Text(
                    movingStatusLook(step).first,
                    color = if (done || now) settingsInk() else settingsMut(),
                    fontWeight = if (now) FontWeight.ExtraBold else FontWeight.Medium, fontSize = 13.5.sp,
                )
            }
        }
    }
}

/** Who is coming and in what: the driver's name and photo, the vehicle and the drawn plate (the rides captain card). */
@Composable
private fun DriverCard(r: MovingRequestDto) {
    val driver = r.driver
    val vehicle = r.vehicle
    MvCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(54.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                if (driver?.avatar != null) AsyncImage(ApiClient.mediaUrl(driver.avatar), null, contentScale = ContentScale.Crop, modifier = Modifier.size(54.dp).clip(CircleShape))
                else Text(driver?.name?.take(1).orEmpty(), color = settingsAccent(), fontWeight = FontWeight.Bold, fontSize = 20.sp)
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(driver?.name ?: stringResource(R.string.mv_driver_soon), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 16.sp)
                r.establishment?.name?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
                vehicle?.let { v -> Text(listOfNotNull(v.make, v.model, v.color).joinToString(" "), color = settingsMut(), fontSize = 12.sp) }
            }
            vehicle?.let { v -> Plate(v.plateLetters, v.plateNumber, null) }
        }
    }
}

/** The code at the door: big, one digit a box, with its QR. Only the customer sees it. */
@Composable
private fun DeliveryCodeCard(code: String, qr: String?) {
    val qrBitmap = remember(qr) { qr?.let { runCatching { qrImage(it) }.getOrNull() } }
    MvCard {
        MvCardTitle(stringResource(R.string.mv_code_title))
        Text(stringResource(R.string.mv_code_hint), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp))
        Spacer(Modifier.height(12.dp))
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center) {
            code.forEach { digit ->
                Box(Modifier.padding(horizontal = 4.dp).size(width = 52.dp, height = 64.dp).clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.10f)), contentAlignment = Alignment.Center) {
                    Text(digit.toString(), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 32.sp)
                }
            }
        }
        qrBitmap?.let { bmp ->
            Spacer(Modifier.height(12.dp))
            Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
                Image(bmp.asImageBitmap(), null, modifier = Modifier.size(180.dp).clip(RoundedCornerShape(14.dp)).background(Color.White).padding(8.dp))
            }
        }
    }
}

/** After delivery: how long is left to object, «received intact», and the report. */
@Composable
private fun DeliveredCard(show: MovingShowDto, nav: MovingNav, onReload: () -> Unit) {
    val r = show.request
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.rd_error)
    val left = rememberSecondsLeft(show.objectionUntil, onEnd = onReload)
    val openReport = show.cases.any { it.kind == "report" && it.status == "open" }
    MvCard {
        MvCardTitle(stringResource(R.string.mv_delivered_title))
        Text(stringResource(R.string.mv_delivered_hint), color = settingsMut(), fontSize = 12.5.sp, modifier = Modifier.padding(top = 2.dp))
        left?.let {
            if (it > 0) Text(stringResource(R.string.mv_objection_left, countdownText(it)), color = settingsAccent(), fontWeight = FontWeight.Bold, fontSize = 14.sp, modifier = Modifier.padding(top = 8.dp))
            else Text(stringResource(R.string.mv_objection_over), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp))
        }
        Spacer(Modifier.height(12.dp))
        AccentButton(stringResource(R.string.mv_received_intact), busy = busy, color = AppColors.success, icon = Icons.Rounded.CheckCircle) {
            busy = true
            error = null
            scope.launch {
                runCatching { ApiClient.moving.confirm(mvAuth(), r.uuid) }
                    .onSuccess { envelope ->
                        Toast.makeText(context, envelope.message, Toast.LENGTH_LONG).show()
                        onReload()
                    }
                    .onFailure { error = it.apiFailure().message ?: failed }
                busy = false
            }
        }
        if (!openReport && (left == null || left > 0)) {
            Spacer(Modifier.height(8.dp))
            MvOutlineButton(stringResource(R.string.mv_report_open), color = AppColors.danger) { nav.push(MvRoute.Report(r.uuid, r.currencyCode, r.currencyDecimals)) }
        }
        error?.let {
            Spacer(Modifier.height(8.dp))
            MvError(it)
        }
    }
}

/** A report or an exception in progress, and what was decided. */
@Composable
private fun CasesCard(show: MovingShowDto) {
    if (show.cases.isEmpty()) return
    MvCard {
        show.cases.forEachIndexed { i, c ->
            if (i > 0) Spacer(Modifier.height(10.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    stringResource(if (c.kind == "exception") R.string.mv_case_exception else R.string.mv_case_report),
                    color = settingsInk(), fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f),
                )
                val isOpen = c.status == "open"
                MvChip(stringResource(if (isOpen) R.string.mv_case_open else R.string.mv_case_decided), if (isOpen) AppColors.warning else AppColors.success)
            }
            if (c.status != "open") c.decision?.let { Text(caseDecision(it), color = settingsMut(), fontSize = 12.5.sp, modifier = Modifier.padding(top = 4.dp)) }
            if (c.kind == "report" && (c.awardMinor ?: 0) > 0) Text(stringResource(R.string.mv_case_award, mvMoney(c.awardMinor, show.request.currencyCode, show.request.currencyDecimals)), color = AppColors.success, fontWeight = FontWeight.SemiBold, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp))
        }
    }
}

@Composable
private fun caseDecision(decision: String): String = when (decision) {
    "accepted" -> stringResource(R.string.mv_decision_accepted)
    "partial" -> stringResource(R.string.mv_decision_partial)
    "rejected" -> stringResource(R.string.mv_decision_rejected)
    else -> decision
}

/** Stars for the establishment and the driver. Once rated (`rated` in the show) the page no longer draws this card. */
@Composable
private fun RatingCard(r: MovingRequestDto, onReload: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var establishment by remember { mutableIntStateOf(0) }
    var driver by remember { mutableIntStateOf(0) }
    var comment by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.rd_error)
    MvCard {
        MvCardTitle(stringResource(R.string.mv_rate_title))
        Spacer(Modifier.height(8.dp))
        Text(r.establishment?.name ?: stringResource(R.string.mv_rate_establishment), color = settingsMut(), fontSize = 13.sp)
        MvStars(establishment) { establishment = it }
        r.driver?.let { d ->
            Spacer(Modifier.height(6.dp))
            Text(d.name ?: stringResource(R.string.mv_rate_driver), color = settingsMut(), fontSize = 13.sp)
            MvStars(driver) { driver = it }
        }
        if (establishment > 0) {
            Spacer(Modifier.height(8.dp))
            DorrTextField(value = comment, onValueChange = { comment = it.take(1000) }, placeholder = stringResource(R.string.rd_rate_comment), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
            Spacer(Modifier.height(10.dp))
            AccentButton(stringResource(R.string.rd_rate_send), busy = busy) {
                busy = true
                error = null
                scope.launch {
                    val body = JsonObject().apply {
                        addProperty("establishment_stars", establishment)
                        if (driver > 0) addProperty("driver_stars", driver)
                        if (comment.isNotBlank()) addProperty("comment", comment.trim())
                    }
                    runCatching { ApiClient.moving.rate(mvAuth(), r.uuid, body) }
                        .onSuccess { envelope ->
                            Toast.makeText(context, envelope.message, Toast.LENGTH_SHORT).show()
                            onReload()
                        }
                        .onFailure {
                            val failure = it.apiFailure()
                            if (failure.errorCode == "moving_already_rated") onReload() else error = failure.message ?: failed
                        }
                    busy = false
                }
            }
        }
        error?.let {
            Spacer(Modifier.height(6.dp))
            MvError(it)
        }
    }
}

@Composable
private fun MoneyCard(r: MovingRequestDto) {
    val money = r.money ?: return
    val code = r.currencyCode
    val places = r.currencyDecimals
    MvCard {
        money.priceMinor?.let { MvLine(stringResource(R.string.mv_price), mvMoney(it, code, places)) }
        money.taxMinor?.takeIf { it > 0 }?.let { MvLine(stringResource(R.string.mv_tax), mvMoney(it, code, places)) }
        money.totalMinor?.let { MvLine(stringResource(R.string.mv_total), mvMoney(it, code, places), bold = true) }
        if (money.paymentStatus == "held") Text(stringResource(R.string.mv_money_held), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
    }
}

/** What was asked for. */
@Composable
private fun RequestSummary(r: MovingRequestDto) {
    MvCard {
        MvCardTitle(stringResource(R.string.mv_summary))
        Spacer(Modifier.height(6.dp))
        r.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsInk(), fontSize = 14.sp, modifier = Modifier.padding(bottom = 6.dp)) }
        if (r.photos.isNotEmpty()) {
            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.padding(bottom = 8.dp)) {
                items(r.photos, key = { it.id }) { p ->
                    AsyncImage(ApiClient.mediaUrl(p.url), null, contentScale = ContentScale.Crop, modifier = Modifier.size(84.dp).clip(RoundedCornerShape(14.dp)).background(mvInsetColor()))
                }
            }
        }
        SummaryEnd(AppColors.brandNavy, r.pickup)
        SummaryEnd(AppColors.brandOrange, r.dropoff)
        r.distanceKm?.let { MvLine(stringResource(R.string.mv_distance), stringResource(R.string.mv_km, String.format(java.util.Locale.US, "%.1f", it))) }
        r.piecesCount?.let { MvLine(stringResource(R.string.mv_pieces), it.toString()) }
        r.dimensions?.takeIf { it.isNotBlank() }?.let { MvLine(stringResource(R.string.mv_dimensions), it) }
        r.vehicleType?.name?.let { MvLine(stringResource(R.string.mv_vehicle_title), it) }
        r.workersCount?.let { MvLine(stringResource(R.string.mv_workers), it.toString()) }
        val extras = r.extras
        val chosen = listOfNotNull(
            "packing".takeIf { extras?.packing == true },
            "disassembly".takeIf { extras?.disassembly == true },
            "crane".takeIf { extras?.crane == true },
            "technician".takeIf { extras?.technician == true },
        )
        if (chosen.isNotEmpty()) {
            Spacer(Modifier.height(4.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                chosen.forEach { MvChip(extraLabel(it), settingsAccent()) }
            }
        }
    }
}

@Composable
private fun SummaryEnd(dot: Color, end: MovingEndDto?) {
    TripLine(dot, end?.address ?: end?.area)
    val details = listOfNotNull(
        end?.area?.takeIf { it != end?.address },
        end?.floor?.let { stringResource(R.string.mv_floor_value, it.toString()) },
        end?.let { stringResource(if (it.elevator == true) R.string.mv_has_elevator else R.string.mv_no_elevator) },
        end?.entrance?.takeIf { it.isNotBlank() },
    )
    if (details.isNotEmpty()) Text(details.joinToString(" · "), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.padding(start = 19.dp, bottom = 4.dp))
}

/** One tap away: changes, the inventory, the invoice, the tickets, and leaving. */
@Composable
private fun Actions(show: MovingShowDto, nav: MovingNav, onCancel: () -> Unit, onWithdraw: () -> Unit) {
    val r = show.request
    val status = r.status
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        if (status in AFTER_ACCEPT && status != "cancelled") MvAction(Icons.Rounded.PublishedWithChanges, stringResource(R.string.mv_changes_title)) { nav.push(MvRoute.Changes(r.uuid)) }
        if (show.inventory.isNotEmpty()) MvAction(Icons.Rounded.Inventory2, stringResource(R.string.mv_inventory_title), stringResource(R.string.mv_inventory_count, show.inventory.size)) { nav.push(MvRoute.Inventory(r.uuid, show.inventory)) }
        if (status in HAS_INVOICE) MvAction(Icons.Rounded.Receipt, stringResource(R.string.mv_invoice_title)) { nav.push(MvRoute.Invoice(r.uuid, r.currencyDecimals)) }
        MvAction(Icons.Rounded.SupportAgent, stringResource(R.string.mv_tickets_title)) { nav.push(MvRoute.Tickets(r.uuid)) }
        if (status == "expired" || status == "withdrawn") RepublishButton(r.uuid, nav)
        if (status == "open") MvOutlineButton(stringResource(R.string.mv_withdraw), color = AppColors.danger, onClick = onWithdraw)
        if (status in CAN_CANCEL) MvOutlineButton(stringResource(R.string.mv_cancel), color = AppColors.danger, onClick = onCancel)
        if (status in EXCEPTION_ONLY) MvOutlineButton(stringResource(R.string.mv_exception), color = AppColors.danger, onClick = onCancel)
    }
}

/**
 * Cancelling: the fee shown first as a badge, the reasons as cards, the three that blame the other side said to go to
 * Dorr as an exception. Once loading has begun only those are offered (the server refuses the rest).
 */
@Composable
private fun MovingCancelSheet(show: MovingShowDto, onClose: () -> Unit, onDone: (String?) -> Unit) {
    val r = show.request
    val scope = rememberCoroutineScope()
    val exceptionOnly = r.status in EXCEPTION_ONLY
    val blaming = listOf("driver_late", "extra_money_asked", "vehicle_mismatch")
    val reasons = if (exceptionOnly) blaming else listOf("changed_plans", "found_another", "other") + blaming
    var reason by remember { mutableStateOf<String?>(null) }
    var details by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.rd_error)
    val isException = reason in blaming

    MvSheet(onClose) {
        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
            Box(Modifier.size(64.dp).clip(CircleShape).background(AppColors.danger.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.WarningAmber, null, tint = AppColors.danger, modifier = Modifier.size(34.dp))
            }
            Spacer(Modifier.height(10.dp))
            Text(stringResource(if (exceptionOnly) R.string.mv_exception else R.string.mv_cancel_title), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 20.sp, textAlign = TextAlign.Center)
            Spacer(Modifier.height(8.dp))
            if (!exceptionOnly) {
                val fee = show.cancelFeeMinor ?: 0
                val free = fee <= 0
                Text(
                    if (free) stringResource(R.string.mv_cancel_free) else stringResource(R.string.mv_cancel_fee, mvMoney(fee, r.currencyCode, r.currencyDecimals)),
                    color = if (free) AppColors.success else AppColors.danger, fontWeight = FontWeight.Bold, fontSize = 13.sp,
                    modifier = Modifier.clip(CircleShape).background((if (free) AppColors.success else AppColors.danger).copy(alpha = 0.1f)).padding(horizontal = 14.dp, vertical = 6.dp),
                )
            }
        }
        Text(stringResource(R.string.mv_cancel_reason), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp)
        reasons.forEach { key ->
            val on = reason == key
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(if (on) settingsAccent().copy(alpha = 0.12f) else settingsBackground())
                    .border(1.5.dp, if (on) settingsAccent() else Color.Transparent, RoundedCornerShape(14.dp))
                    .clickable { reason = key }.padding(horizontal = 12.dp, vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                if (on) {
                    Icon(Icons.Rounded.CheckCircle, null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
                    Spacer(Modifier.width(6.dp))
                }
                Column(Modifier.weight(1f)) {
                    Text(cancelReasonLabel(key), color = settingsInk(), fontSize = 13.sp, fontWeight = if (on) FontWeight.Bold else FontWeight.Medium)
                    if (key in blaming) Text(stringResource(R.string.mv_cancel_goes_to_dorr), color = settingsMut(), fontSize = 11.sp)
                }
            }
        }
        if (isException) MvNote(stringResource(R.string.mv_cancel_exception_note), AppColors.warning)
        DorrTextField(value = details, onValueChange = { details = it.take(1000) }, placeholder = stringResource(R.string.mv_cancel_details), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
        MvError(error)
        AccentButton(stringResource(R.string.mv_keep_request)) { onClose() }
        MvOutlineButton(stringResource(if (isException || exceptionOnly) R.string.mv_send_exception else R.string.mv_cancel_confirm), color = AppColors.danger, enabled = reason != null && !busy) {
            val chosen = reason ?: return@MvOutlineButton
            busy = true
            error = null
            scope.launch {
                val body = JsonObject().apply {
                    addProperty("reason", chosen)
                    if (details.isNotBlank()) addProperty("details", details.trim())
                }
                runCatching { ApiClient.moving.cancel(mvAuth(), r.uuid, body) }
                    .onSuccess { onDone(it.message) }
                    .onFailure { error = it.apiFailure().message ?: failed }
                busy = false
            }
        }
    }
}

@Composable
private fun cancelReasonLabel(key: String): String = stringResource(
    when (key) {
        "changed_plans" -> R.string.mv_reason_changed_plans
        "found_another" -> R.string.mv_reason_found_another
        "driver_late" -> R.string.mv_reason_driver_late
        "extra_money_asked" -> R.string.mv_reason_extra_money
        "vehicle_mismatch" -> R.string.mv_reason_vehicle_mismatch
        else -> R.string.mv_reason_other
    },
)

/** An expired or withdrawn request as a new draft with the same details: the steps open on its review. */
@Composable
private fun RepublishButton(uuid: String, nav: MovingNav) {
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.rd_error)
    AccentButton(stringResource(R.string.mv_republish), busy = busy) {
        busy = true
        error = null
        scope.launch {
            runCatching { ApiClient.moving.republish(mvAuth(), uuid).data }
                .onSuccess { draft -> draft?.let { nav.replace(MvRoute.Wizard(it.uuid, atReview = true)) } }
                .onFailure { error = it.apiFailure().message ?: failed }
            busy = false
        }
    }
    MvError(error)
}

/** Taking a request or a draft back before an offer is chosen: free, and any offers are closed. */
@Composable
internal fun MovingWithdrawSheet(uuid: String, title: String, body: String, action: String, onClose: () -> Unit, onDone: (String?) -> Unit) {
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.rd_error)
    MvSheet(onClose) {
        Text(title, color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 20.sp)
        Text(body, color = settingsMut(), fontSize = 13.sp)
        MvError(error)
        AccentButton(stringResource(R.string.mv_keep_request)) { onClose() }
        MvOutlineButton(action, color = AppColors.danger, enabled = !busy) {
            busy = true
            error = null
            scope.launch {
                runCatching { ApiClient.moving.withdraw(mvAuth(), uuid) }
                    .onSuccess { onDone(it.message) }
                    .onFailure { error = it.apiFailure().message ?: failed }
                busy = false
            }
        }
    }
}
