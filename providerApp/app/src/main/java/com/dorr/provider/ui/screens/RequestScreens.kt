package com.dorr.provider.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Badge
import androidx.compose.material.icons.rounded.Category
import androidx.compose.material.icons.rounded.ExpandLess
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.LocalShipping
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.PhotoLibrary
import androidx.compose.material.icons.rounded.Repeat
import androidx.compose.material.icons.rounded.RequestQuote
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Straighten
import androidx.compose.material.icons.rounded.Timer
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.MovingOfferDto
import com.dorr.provider.net.MovingRequestDto
import com.dorr.provider.net.PagedRows
import com.dorr.provider.net.Realtime
import com.dorr.provider.net.Session
import com.dorr.provider.net.apiDone
import com.dorr.provider.net.can
import com.dorr.provider.net.collectReconnectTick
import com.dorr.provider.net.errorText
import com.dorr.provider.net.included
import com.dorr.provider.net.jsonBody
import com.dorr.provider.ui.components.DateField
import com.dorr.provider.ui.components.DorrTextField
import com.dorr.provider.ui.components.EmptyState
import com.dorr.provider.ui.components.ErrorBanner
import com.dorr.provider.ui.components.Page
import com.dorr.provider.ui.components.PagedFooter
import com.dorr.provider.ui.components.SkeletonList
import com.dorr.provider.ui.components.TimeField
import com.dorr.provider.ui.components.isoAt
import com.dorr.provider.ui.kit.ConfirmDialog
import com.dorr.provider.ui.kit.SettingsPrimaryButton
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsCard
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import com.dorr.provider.ui.kit.settingsSurface
import com.google.gson.JsonObject
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.ZonedDateTime
import java.util.Locale
import kotlinx.coroutines.launch

/**
 * The requests that reached my branches (C-04, `requests.view`), newest first, page by page: the route between the two
 * neighbourhoods (the server never sends the exact address before the job is ours), the time, distance and when offers close,
 * the vehicle types the customer accepts, and «عرضي» with its price when the establishment already answered. Read on open, when
 * the connection returns and on every notice (a new request is one) — never on a timer.
 */
@Composable
fun RequestsScreen(onBack: () -> Unit, onOpenRequest: (String) -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val paged = remember { PagedRows<MovingRequestDto>(20, { it.uuid }) { page -> apiCall { ApiClient.moving.requests(page) } } }
    LaunchedEffect(reconnect) { paged.reload() }
    LaunchedEffect(Unit) { Realtime.changed.collect { paged.reload() } }

    Page(title = stringResource(R.string.mv_requests_title), onBack = onBack, onNearEnd = { scope.launch { paged.loadMore() } }) {
        when {
            !paged.loaded -> SkeletonList(3, Modifier.fillMaxWidth())
            paged.rows.isEmpty() -> EmptyState(Icons.Rounded.Inventory2, stringResource(R.string.mv_requests_empty_title), stringResource(R.string.mv_requests_empty_body), Modifier.padding(top = 30.dp))
            else -> paged.rows.forEach { move -> RequestCard(move) { onOpenRequest(move.uuid) } }
        }
        PagedFooter(paged, onRetry = { paged.retry(); scope.launch { paged.loadMore() } })
        paged.failure?.let { ErrorBanner(it.errorText(offlineText), Modifier.fillMaxWidth()) }
    }
}

/** One request in a list: the route card, then what is moved, the vehicle types asked, the extras — and my offer's price. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun RequestCard(move: MovingRequestDto, statusPill: Boolean = false, onClick: () -> Unit) {
    MvRouteCard(
        move,
        compact = true,
        onClick = onClick,
        badge = {
            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(4.dp)) {
                if (statusPill) StatusPill(moveStatusLabel(move.status), moveStatusColor(move.status))
                move.myOffer?.let { mine ->
                    StatusPill(stringResource(R.string.mv_my_offer) + " · " + offerStatusLabel(mine.status), offerStatusColor(mine.status))
                    if (mine.priceMinor > 0) Text(formatMinor(mine.priceMinor, move.currencyCode), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Black)
                }
            }
        },
        footer = {
            Box(Modifier.fillMaxWidth().height(1.dp).background(settingsMut().copy(alpha = 0.15f)))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                loadTypeLabel(move.loadType).takeIf { it.isNotBlank() }?.let { MvChip(Icons.Rounded.Inventory2, it, Slate) }
                move.askedTypes().forEach { type -> type.name?.let { MvChip(Icons.Rounded.LocalShipping, it, settingsAccent()) } }
                move.workersCount?.takeIf { it > 0 }?.let { MvChip(Icons.Rounded.Groups, stringResource(R.string.mv_workers_n, it), Slate) }
                move.photos.size.takeIf { it > 0 }?.let { MvChip(Icons.Rounded.PhotoLibrary, stringResource(R.string.mv_photos_n, it), Slate) }
            }
            ExtrasRow(move)
        },
    )
}

/** The details of a request (and of a job, which is the same request once ours) shown on its page. */
@Composable
internal fun RequestDetails(move: MovingRequestDto) {
    MvRouteCard(move, badge = { StatusPill(moveStatusLabel(move.status), moveStatusColor(move.status)) })

    val types = move.askedTypes()
    if (types.isNotEmpty()) {
        MvSection(Icons.Rounded.LocalShipping, stringResource(R.string.mv_sec_vehicles_asked), stringResource(if (types.size > 1) R.string.mv_sec_vehicles_asked_many else R.string.mv_sec_vehicles_asked_one))
        MvAskedTypes(types)
    }

    MvSection(Icons.Rounded.Inventory2, stringResource(R.string.mv_sec_load))
    MvFactGrid(
        listOfNotNull(
            loadTypeLabel(move.loadType).takeIf { it.isNotBlank() }?.let { Triple(Icons.Rounded.Category, stringResource(R.string.mv_load), it) },
            move.piecesCount?.takeIf { it.isNotBlank() }?.let { Triple(Icons.Rounded.Inventory2, stringResource(R.string.mv_pieces), it) },
            move.workersCount?.takeIf { it > 0 }?.let { Triple(Icons.Rounded.Groups, stringResource(R.string.mv_workers), it.toString()) },
            move.dimensions?.takeIf { it.isNotBlank() }?.let { Triple(Icons.Rounded.Straighten, stringResource(R.string.mv_dimensions), it) },
        ),
    )
    move.description?.takeIf { it.isNotBlank() }?.let { text ->
        Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).padding(14.dp)) {
            Text(text, color = settingsInk(), fontSize = 14.sp, lineHeight = 22.sp)
        }
    }
    move.voice?.takeIf { it.url != null }?.let { MvCustomerVoice(it) }
    MvPhotos(move.photos.mapNotNull { it.url })
    if (move.extras?.let { it.packing || it.disassembly || it.crane || it.technician } == true) {
        Text(stringResource(R.string.mv_sec_extras), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.fillMaxWidth())
        ExtrasRow(move)
    }

    MvSection(Icons.Rounded.LocationOn, stringResource(R.string.mv_sec_places))
    MvEndsCard(move)
    move.notes?.takeIf { it.isNotBlank() }?.let { note ->
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Amber.copy(alpha = 0.10f)).padding(12.dp),
            verticalAlignment = Alignment.Top,
        ) {
            Icon(Icons.Rounded.Notes, null, tint = Amber, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.mv_notes_line, note), color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp)
        }
    }
}

/**
 * A request's page: its details; then my offer (changed or taken back while the customer hasn't chosen) or «قدّم عرضك» for a
 * seat with `offers.send`. The move's own live channel reloads it once on each change while the page is open (no timer).
 */
@Composable
fun RequestScreen(uuid: String, canOffer: Boolean, onBack: () -> Unit) {
    val reconnect = collectReconnectTick()
    val connected by Realtime.connected.collectAsState()
    val seat by Session.membership.collectAsState()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val withdrawnText = stringResource(R.string.mv_offer_withdrawn_ok)
    var move by remember { mutableStateOf<MovingRequestDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var offering by rememberSaveable { mutableStateOf(false) }
    var editing by rememberSaveable { mutableStateOf(false) }
    var withdrawing by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var sentMessage by remember { mutableStateOf<String?>(null) }

    suspend fun reload() {
        when (val result = apiCall { ApiClient.moving.request(uuid) }) {
            is ApiResult.Ok -> {
                move = result.data
                error = null
            }
            else -> error = result.errorText(offlineText) ?: genericError
        }
    }
    LaunchedEffect(reconnect) { reload() }
    LaunchedEffect(uuid, connected) { Realtime.watchMove(uuid).collect { if (it.name == "moving.changed") reload() } }

    val current = move
    val mine = current?.myOffer?.takeIf { it.status in setOf("sent", "awaiting_approval", "accepted") }
    if ((offering || editing) && current != null) {
        BackHandler { offering = false; editing = false }
        OfferForm(
            move = current, offer = if (editing) mine else null,
            onBack = { offering = false; editing = false },
            onDone = { message ->
                sentMessage = message
                offering = false
                editing = false
                scope.launch { reload() }
            },
        )
        return
    }

    fun withdraw(offer: MovingOfferDto) {
        busy = true
        scope.launch {
            val failed = apiDone(offlineText, genericError) { ApiClient.moving.withdrawOffer(offer.uuid) }
            busy = false
            withdrawing = false
            if (failed != null) error = failed else {
                sentMessage = withdrawnText
                reload()
            }
        }
    }

    Page(title = stringResource(R.string.mv_request_title), onBack = onBack) {
        if (current == null) {
            if (error == null) SkeletonList(3, Modifier.fillMaxWidth())
        } else {
            MvDoneBanner(sentMessage)
            if (mine != null) {
                MvMyOfferCard(mine, canManage = seat.can("offers.manage"), busy = busy, onEdit = { editing = true }, onWithdraw = { withdrawing = true })
            }
            RequestDetails(current)
            if (mine == null && canOffer && current.status == "open") {
                Spacer(Modifier.height(4.dp))
                SettingsPrimaryButton(text = stringResource(R.string.mv_make_offer), onClick = { sentMessage = null; offering = true })
                Text(stringResource(R.string.mv_make_offer_hint), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp, modifier = Modifier.fillMaxWidth())
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }

    if (withdrawing && mine != null) {
        ConfirmDialog(
            icon = Icons.Rounded.Undo,
            title = stringResource(R.string.mv_withdraw_title),
            message = stringResource(R.string.mv_withdraw_message),
            confirmLabel = stringResource(R.string.mv_withdraw),
            loading = busy,
            onConfirm = { withdraw(mine) },
            onDismiss = { if (!busy) withdrawing = false },
        )
    }
}

/** "2026-11-24" and "14:30" of an ISO time in the phone's zone. */
private fun localParts(iso: String?): Pair<String, String>? = runCatching {
    val at: ZonedDateTime = OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault())
    at.toLocalDate().toString() to String.format(Locale.US, "%02d:%02d", at.hour, at.minute)
}.getOrNull()

/**
 * Sending an offer on [move] ([offer] null) or changing one, in clear blocks: the vehicle (my vehicles, the ones of a type the
 * customer asked for first; the others say why they can't), the price with what the customer pays and what reaches the
 * establishment, the team (workers and trips with − and +), what it includes (what the customer asked for is ticked already),
 * the arrival (the asked time, or another) and how long it holds (until offers close, or another time), and — folded — terms and
 * the promise after the move. With [move] null (changing from «العروض»), the request is read first. The server's refusal is shown
 * as it is; an offer above the sender's limit goes to the approver first. [onDone] gets the sentence to show.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun OfferForm(move: MovingRequestDto?, offer: MovingOfferDto?, onBack: () -> Unit, onDone: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val sentText = stringResource(R.string.mv_offer_sent_ok)
    val toApproverText = stringResource(R.string.mv_offer_to_approver)
    val updatedText = stringResource(R.string.mv_offer_updated)
    val creating = offer == null

    var request by remember { mutableStateOf(move) }
    LaunchedEffect(offer?.requestUuid) {
        if (request == null) offer?.requestUuid?.let { uuid -> (apiCall { ApiClient.moving.request(uuid) } as? ApiResult.Ok)?.data?.let { request = it } }
    }
    val req = request
    val currency = offer?.currencyCode ?: req?.currencyCode

    var vehicleUuid by rememberSaveable { mutableStateOf<String?>(null) }
    LaunchedEffect(req?.uuid) {
        // A new offer starts on the first vehicle that can answer (the server lists those first).
        if (creating && vehicleUuid == null) vehicleUuid = req?.myVehicles?.firstOrNull { it.usable }?.uuid
    }
    var price by rememberSaveable { mutableStateOf(offer?.priceMinor?.takeIf { it > 0 }?.let { formatMinor(it, null).replace("‎", "") }.orEmpty()) }
    var workers by rememberSaveable { mutableStateOf(offer?.workersCount ?: move?.workersCount?.takeIf { it > 0 } ?: 2) }
    var trips by rememberSaveable { mutableStateOf(offer?.tripsCount ?: 1) }
    val includes = remember {
        mutableStateListOf<String>().apply {
            if (offer != null) addAll(offer.included())
            else move?.extras?.let { e ->
                if (e.packing) add("packing"); if (e.disassembly) add("disassembly"); if (e.crane) add("crane"); if (e.technician) add("technician")
            }
        }
    }
    var technicianNote by rememberSaveable { mutableStateOf(offer?.technicianNote.orEmpty()) }
    val asked = localParts(move?.scheduledAt)
    var arrivalAsAsked by rememberSaveable { mutableStateOf(true) }
    var arrivalDate by rememberSaveable { mutableStateOf(asked?.first.orEmpty()) }
    var arrivalTime by rememberSaveable { mutableStateOf(asked?.second.orEmpty()) }
    val deadline = move?.offersDeadlineAt ?: move?.scheduledAt
    var validToDeadline by rememberSaveable { mutableStateOf(true) }
    var validDate by rememberSaveable { mutableStateOf(localParts(deadline)?.first.orEmpty()) }
    var validTime by rememberSaveable { mutableStateOf(localParts(deadline)?.second.orEmpty()) }
    var terms by rememberSaveable { mutableStateOf(offer?.terms.orEmpty()) }
    var afterService by rememberSaveable { mutableStateOf(offer?.afterService.orEmpty()) }
    var moreOpen by rememberSaveable { mutableStateOf(!offer?.terms.isNullOrBlank() || !offer?.afterService.isNullOrBlank()) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    val priceMinor = parseMinor(price)
    val arrivalIso = if (arrivalAsAsked) move?.scheduledAt else isoAt(arrivalDate, arrivalTime)
    val validIso = if (validToDeadline) deadline else isoAt(validDate, validTime)
    val ready = priceMinor != null && (!creating || (vehicleUuid != null && arrivalIso != null && validIso != null))

    fun send() {
        busy = true
        error = null
        val body = JsonObject().apply {
            vehicleUuid?.let { addProperty("vehicle_uuid", it) }
            addProperty("price_minor", priceMinor)
            addProperty("workers_count", workers)
            addProperty("trips_count", trips)
            // The tax is always added on top by the server: it isn't something an offer includes or not.
            add("includes", JsonObject().apply { listOf("packing", "disassembly", "crane", "technician").forEach { addProperty(it, it in includes) } })
            addProperty("technician_note", technicianNote.trim().ifEmpty { null }.takeIf { "technician" in includes })
            if (creating) {
                addProperty("arrival_at", arrivalIso)
                addProperty("valid_until", validIso)
            }
            addProperty("terms", terms.trim().ifEmpty { null })
            addProperty("after_service", afterService.trim().ifEmpty { null })
        }
        scope.launch {
            val result = if (offer == null) apiCall { ApiClient.moving.sendOffer(req?.uuid.orEmpty(), jsonBody(body)) } else apiCall { ApiClient.moving.updateOffer(offer.uuid, jsonBody(body)) }
            busy = false
            when {
                result is ApiResult.Ok && offer != null -> onDone(updatedText)
                result is ApiResult.Ok -> onDone(if (result.data.status == "awaiting_approval") toApproverText else sentText)
                else -> error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    Page(title = stringResource(if (creating) R.string.mv_make_offer else R.string.mv_edit_offer), onBack = onBack) {
        if (req == null) {
            SkeletonList(3, Modifier.fillMaxWidth())
            return@Page
        }
        MvRouteCard(req, compact = true, footer = { MvAskedTypes(req.askedTypes()) })

        // 1 · The vehicle.
        MvSection(Icons.Rounded.LocalShipping, stringResource(R.string.mv_sec_offer_vehicle), stringResource(if (creating) R.string.mv_sec_offer_vehicle_hint else R.string.mv_vehicle_keep))
        val vehicles = req.myVehicles
        when {
            vehicles.isEmpty() -> MvWarning(stringResource(R.string.mv_no_vehicles))
            vehicles.none { it.usable } -> MvWarning(stringResource(R.string.mv_no_fitting_vehicle, req.askedTypes().mapNotNull { it.name }.joinToString(" / ")))
        }
        vehicles.forEach { vehicle ->
            MvVehicleOption(vehicle, selected = vehicleUuid == vehicle.uuid) { vehicleUuid = if (!creating && vehicleUuid == vehicle.uuid) null else vehicle.uuid }
        }

        // 2 · The price, and what it means for both sides.
        MvSection(Icons.Rounded.Payments, stringResource(R.string.mv_sec_price), stringResource(R.string.mv_sec_price_hint))
        DorrTextField(
            price, { price = it.filter { c -> c.isDigit() || c == '.' || c == ',' }.take(12) },
            label = stringResource(R.string.mv_offer_price, currency.orEmpty()), icon = Icons.Rounded.Payments,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), error = price.isNotBlank() && priceMinor == null,
            textStyle = androidx.compose.material3.MaterialTheme.typography.titleLarge.copy(fontWeight = FontWeight.Black),
            minHeight = 56.dp, modifier = Modifier.fillMaxWidth(),
        )
        AnimatedVisibility(priceMinor != null && req.pricing != null, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            req.pricing?.let { pricing -> priceMinor?.let { MvPriceBreakdown(pricing.breakdown(it), currency) } }
        }

        // 3 · The team.
        MvSection(Icons.Rounded.Groups, stringResource(R.string.mv_sec_team))
        MvStepper(Icons.Rounded.Groups, stringResource(R.string.mv_workers), workers, 0..50, req.workersCount?.takeIf { it > 0 }?.let { stringResource(R.string.mv_customer_asked_n, it) }) { workers = it }
        MvStepper(Icons.Rounded.Repeat, stringResource(R.string.mv_trips), trips, 1..20, stringResource(R.string.mv_trips_hint)) { trips = it }

        // 4 · What it includes.
        MvSection(Icons.Rounded.Badge, stringResource(R.string.mv_sec_includes), stringResource(R.string.mv_sec_includes_hint))
        val extras = req.extras
        val keys = listOf("packing", "disassembly", "crane", "technician")
        Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            keys.chunked(2).forEach { row ->
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    row.forEach { key ->
                        val askedFor = when (key) {
                            "packing" -> extras?.packing; "disassembly" -> extras?.disassembly; "crane" -> extras?.crane; else -> extras?.technician
                        } == true
                        MvIncludeTile(key, key in includes, askedFor, Modifier.weight(1f)) { if (key in includes) includes.remove(key) else includes.add(key) }
                    }
                }
            }
        }
        AnimatedVisibility("technician" in includes, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            DorrTextField(technicianNote, { technicianNote = it.take(255) }, label = stringResource(R.string.mv_technician_note), placeholder = stringResource(R.string.onb_optional), modifier = Modifier.fillMaxWidth())
        }

        if (creating) {
            // 5 · When the truck arrives.
            MvSection(Icons.Rounded.Schedule, stringResource(R.string.mv_sec_arrival))
            MvChoice(
                first = stringResource(R.string.mv_arrival_as_asked, dateTimeText(move?.scheduledAt)), second = stringResource(R.string.mv_other_time),
                firstOn = arrivalAsAsked, onChange = { arrivalAsAsked = it },
            )
            AnimatedVisibility(!arrivalAsAsked, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    DateField(arrivalDate, { arrivalDate = it }, label = stringResource(R.string.mv_date), modifier = Modifier.weight(1f))
                    TimeField(arrivalTime, { arrivalTime = it }, label = stringResource(R.string.mv_time), modifier = Modifier.weight(1f))
                }
            }

            // 6 · How long the offer holds.
            MvSection(Icons.Rounded.Timer, stringResource(R.string.mv_sec_validity), stringResource(R.string.mv_valid_until_hint))
            MvChoice(
                first = stringResource(R.string.mv_valid_to_deadline, dateTimeText(deadline)), second = stringResource(R.string.mv_other_time),
                firstOn = validToDeadline, onChange = { validToDeadline = it },
            )
            AnimatedVisibility(!validToDeadline, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    DateField(validDate, { validDate = it }, label = stringResource(R.string.mv_date), modifier = Modifier.weight(1f))
                    TimeField(validTime, { validTime = it }, label = stringResource(R.string.mv_time), modifier = Modifier.weight(1f))
                }
            }
        } else {
            Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                InfoLine(stringResource(R.string.mv_arrival), dateTimeText(offer?.arrivalAt))
                InfoLine(stringResource(R.string.mv_valid_until), dateTimeText(offer?.validUntil))
            }
        }

        // 7 · Terms and the promise after the move, folded.
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(mvWell()).clickable { moreOpen = !moreOpen }.padding(horizontal = 14.dp, vertical = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.Notes, null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.mv_more_details), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
            Icon(if (moreOpen) Icons.Rounded.ExpandLess else Icons.Rounded.ExpandMore, null, tint = settingsMut())
        }
        AnimatedVisibility(moreOpen, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                DorrTextField(terms, { terms = it.take(3000) }, label = stringResource(R.string.mv_terms), placeholder = stringResource(R.string.mv_terms_hint), icon = Icons.Rounded.Notes, singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
                DorrTextField(afterService, { afterService = it.take(2000) }, label = stringResource(R.string.mv_after_service), placeholder = stringResource(R.string.mv_after_service_hint), icon = Icons.Rounded.LocalShipping, singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
            }
        }

        SettingsPrimaryButton(text = stringResource(if (creating) R.string.mv_send_offer else R.string.common_save), enabled = ready, loading = busy, onClick = ::send)
        if (creating) Text(stringResource(R.string.mv_offer_form_body), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp, modifier = Modifier.fillMaxWidth())
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

/** Two choices side by side (the asked time / another time…), the picked one filled. */
@Composable
private fun MvChoice(first: String, second: String, firstOn: Boolean, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        listOf(true to first, false to second).forEach { (value, label) ->
            val on = firstOn == value
            Box(
                Modifier.weight(if (value) 1.6f else 1f).clip(RoundedCornerShape(14.dp))
                    .background(if (on) settingsAccent().copy(alpha = 0.10f) else settingsCard())
                    .border(if (on) 1.5.dp else 1.dp, if (on) settingsAccent() else settingsMut().copy(alpha = 0.25f), RoundedCornerShape(14.dp))
                    .clickable { onChange(value) }.padding(horizontal = 10.dp, vertical = 11.dp),
                contentAlignment = Alignment.Center,
            ) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(10.dp).clip(CircleShape).background(if (on) settingsAccent() else Color.Transparent).border(1.5.dp, if (on) settingsAccent() else settingsMut(), CircleShape))
                    Spacer(Modifier.width(6.dp))
                    Text(label, color = if (on) settingsAccent() else settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                }
            }
        }
    }
}

/** A warning line on a soft amber tile. */
@Composable
private fun MvWarning(text: String) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Amber.copy(alpha = 0.10f)).padding(12.dp),
        verticalAlignment = Alignment.Top,
    ) {
        Icon(Icons.Rounded.LocalShipping, null, tint = Amber, modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(8.dp))
        Text(text, color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp)
    }
}
