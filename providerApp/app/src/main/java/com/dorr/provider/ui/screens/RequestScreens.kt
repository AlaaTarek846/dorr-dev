package com.dorr.provider.ui.screens

import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckBox
import androidx.compose.material.icons.rounded.CheckBoxOutlineBlank
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.LocalShipping
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.RadioButtonChecked
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Repeat
import androidx.compose.material.icons.rounded.RequestQuote
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
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.MovingOfferDto
import com.dorr.provider.net.MovingRequestDto
import com.dorr.provider.net.PagedRows
import com.dorr.provider.net.Realtime
import com.dorr.provider.net.VehicleDto
import com.dorr.provider.net.collectReconnectTick
import com.dorr.provider.net.errorText
import com.dorr.provider.net.included
import com.dorr.provider.net.jsonBody
import com.dorr.provider.net.plate
import com.dorr.provider.ui.components.Card
import com.dorr.provider.ui.components.DateField
import com.dorr.provider.ui.components.DorrTextField
import com.dorr.provider.ui.components.EmptyState
import com.dorr.provider.ui.components.ErrorBanner
import com.dorr.provider.ui.components.FieldLabel
import com.dorr.provider.ui.components.FormGroup
import com.dorr.provider.ui.components.FormIntro
import com.dorr.provider.ui.components.Page
import com.dorr.provider.ui.components.PagedFooter
import com.dorr.provider.ui.components.TimeField
import com.dorr.provider.ui.components.isoAt
import com.dorr.provider.ui.kit.SettingsPrimaryButton
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import com.google.gson.JsonObject
import java.time.OffsetDateTime
import java.time.ZoneId
import kotlinx.coroutines.launch

/**
 * The requests that reached my branches (C-04, `requests.view`), newest first, page by page: the neighbourhoods only (the server
 * never sends the exact address before the job is ours), distance, time, load, workers, extras, vehicle type, photos, and
 * «عرضي» when the establishment already answered. Read on open, when the connection returns and on every notice (a new request
 * is one) — never on a timer.
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
            !paged.loaded -> CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.padding(top = 40.dp).size(28.dp))
            paged.rows.isEmpty() -> EmptyState(Icons.Rounded.Inventory2, stringResource(R.string.mv_requests_empty_title), stringResource(R.string.mv_requests_empty_body), Modifier.padding(top = 30.dp))
            else -> paged.rows.forEach { move -> RequestCard(move) { onOpenRequest(move.uuid) } }
        }
        PagedFooter(paged, onRetry = { paged.retry(); scope.launch { paged.loadMore() } })
        paged.failure?.let { ErrorBanner(it.errorText(offlineText), Modifier.fillMaxWidth()) }
    }
}

/** One request in a list: the route, when, what, and the pills. */
@Composable
internal fun RequestCard(move: MovingRequestDto, statusPill: Boolean = false, onClick: () -> Unit) {
    Card {
        Column(Modifier.fillMaxWidth().clickable(onClick = onClick), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(routeText(move), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                move.myOffer?.let { StatusPill(stringResource(R.string.mv_my_offer), offerStatusColor(it.status)) }
                if (statusPill) StatusPill(moveStatusLabel(move.status), moveStatusColor(move.status))
            }
            Text(dateTimeText(move.scheduledAt), color = settingsMut(), fontSize = 12.sp)
            val facts = listOfNotNull(
                move.distanceKm?.let { stringResource(R.string.mv_distance, formatKm(it)) },
                loadTypeLabel(move.loadType).takeIf { it.isNotBlank() },
                move.workersCount?.takeIf { it > 0 }?.let { stringResource(R.string.mv_workers_n, it) },
                move.vehicleType?.name,
                move.photos.size.takeIf { it > 0 }?.let { stringResource(R.string.mv_photos_n, it) },
            )
            if (facts.isNotEmpty()) Text(facts.joinToString(" · "), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
            ExtrasRow(move)
        }
    }
}

/** The details of a request (and of a job, which is the same request once ours) shown on its page. */
@Composable
internal fun RequestDetails(move: MovingRequestDto) {
    Card {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(routeText(move), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
            StatusPill(moveStatusLabel(move.status), moveStatusColor(move.status))
        }
        InfoLine(stringResource(R.string.mv_when), dateTimeText(move.scheduledAt))
        InfoLine(stringResource(R.string.mv_distance_label), move.distanceKm?.let { stringResource(R.string.mv_distance, formatKm(it)) })
        InfoLine(stringResource(R.string.mv_load), loadTypeLabel(move.loadType))
        InfoLine(stringResource(R.string.mv_pieces), move.piecesCount)
        InfoLine(stringResource(R.string.mv_dimensions), move.dimensions)
        InfoLine(stringResource(R.string.mv_workers), move.workersCount?.toString())
        InfoLine(stringResource(R.string.mv_vehicle_type), move.vehicleType?.name)
        InfoLine(stringResource(R.string.mv_deadline), dateTimeText(move.offersDeadlineAt))
        ExtrasRow(move)
        move.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsInk(), fontSize = 13.sp, lineHeight = 20.sp) }
        move.notes?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.mv_notes_line, it), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp) }
    }
    EndCard(stringResource(R.string.mv_pickup), move.pickup)
    EndCard(stringResource(R.string.mv_dropoff), move.dropoff)
    if (move.photos.isNotEmpty()) {
        FieldLabel(stringResource(R.string.mv_photos))
        Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            move.photos.forEach { photo ->
                AsyncImage(
                    model = ApiClient.mediaUrl(photo.url), contentDescription = null, contentScale = ContentScale.Crop,
                    modifier = Modifier.size(110.dp).clip(RoundedCornerShape(14.dp)),
                )
            }
        }
    }
}

/** One end of the move: the neighbourhood, floor and lift; the exact address only once the job is ours (the server decides). */
@Composable
private fun EndCard(title: String, end: com.dorr.provider.net.MovingEndDto?) {
    if (end == null) return
    Card {
        Text(title, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
        InfoLine(stringResource(R.string.mv_area), end.area)
        InfoLine(stringResource(R.string.mv_floor), end.floor)
        end.elevator?.let { InfoLine(stringResource(R.string.mv_elevator), stringResource(if (it) R.string.common_yes else R.string.mv_no)) }
        InfoLine(stringResource(R.string.mv_address), end.address)
        InfoLine(stringResource(R.string.mv_entrance), end.entrance)
        if (end.address == null) Text(stringResource(R.string.mv_address_hidden), color = settingsMut(), fontSize = 11.sp, lineHeight = 16.sp)
    }
}

/**
 * A request's page: its details, and «إرسال عرض» for a seat with `offers.send`. The move's own live channel reloads it once on
 * each change while the page is open (no timer).
 */
@Composable
fun RequestScreen(uuid: String, canOffer: Boolean, onBack: () -> Unit) {
    val reconnect = collectReconnectTick()
    val connected by Realtime.connected.collectAsState()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var move by remember { mutableStateOf<MovingRequestDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var offering by rememberSaveable { mutableStateOf(false) }
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
    if (offering && current != null) {
        androidx.activity.compose.BackHandler { offering = false }
        OfferForm(
            move = current, offer = null,
            onBack = { offering = false },
            onDone = { message -> sentMessage = message; offering = false },
        )
        return
    }

    Page(title = stringResource(R.string.mv_request_title), onBack = onBack) {
        if (current == null) {
            if (error == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.padding(top = 40.dp).size(28.dp))
        } else {
            RequestDetails(current)
            sentMessage?.let { Text(it, color = Green, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.fillMaxWidth()) }
            if (canOffer && current.status == "open") SettingsPrimaryButton(text = stringResource(R.string.mv_send_offer), onClick = { offering = true })
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

/**
 * Sending an offer on [move] ([offer] null) or changing one: the vehicle (approved ones only — the others say why), the price,
 * workers and trips, what it includes, arrival and how long it holds (new offers only: the server keeps them), terms and the
 * promise after the move. The server's refusal is shown as it is (e.g. «لا يمكن إرسال عرض: …»). An offer above the sender's limit
 * goes to the approver first. [onDone] gets the sentence to show.
 */
@Composable
internal fun OfferForm(move: MovingRequestDto?, offer: MovingOfferDto?, onBack: () -> Unit, onDone: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val sentText = stringResource(R.string.mv_offer_sent_ok)
    val toApproverText = stringResource(R.string.mv_offer_to_approver)
    val updatedText = stringResource(R.string.mv_offer_updated)
    val creating = offer == null
    val currency = offer?.currencyCode ?: move?.currencyCode

    var vehicles by remember { mutableStateOf<List<VehicleDto>?>(null) }
    var vehicleUuid by rememberSaveable { mutableStateOf<String?>(null) }
    var price by rememberSaveable { mutableStateOf(offer?.priceMinor?.let { formatMinor(it, null).replace("‎", "") }.orEmpty()) }
    var workers by rememberSaveable { mutableStateOf((offer?.workersCount ?: move?.workersCount)?.toString().orEmpty()) }
    var trips by rememberSaveable { mutableStateOf((offer?.tripsCount ?: 1).toString()) }
    val includes = remember {
        mutableStateListOf<String>().apply {
            if (offer != null) addAll(offer.included())
            else move?.extras?.let { e ->
                if (e.packing) add("packing"); if (e.disassembly) add("disassembly"); if (e.crane) add("crane"); if (e.technician) add("technician")
            }
        }
    }
    var technicianNote by rememberSaveable { mutableStateOf(offer?.technicianNote.orEmpty()) }
    val scheduled = remember(move?.scheduledAt) { runCatching { OffsetDateTime.parse(move?.scheduledAt).atZoneSameInstant(ZoneId.systemDefault()) }.getOrNull() }
    var arrivalDate by rememberSaveable { mutableStateOf(scheduled?.toLocalDate()?.toString().orEmpty()) }
    var arrivalTime by rememberSaveable { mutableStateOf(scheduled?.toLocalTime()?.let { String.format(java.util.Locale.US, "%02d:%02d", it.hour, it.minute) }.orEmpty()) }
    var validDate by rememberSaveable { mutableStateOf("") }
    var validTime by rememberSaveable { mutableStateOf("") }
    var terms by rememberSaveable { mutableStateOf(offer?.terms.orEmpty()) }
    var afterService by rememberSaveable { mutableStateOf(offer?.afterService.orEmpty()) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) {
        (apiCall { ApiClient.manage.vehicles() } as? ApiResult.Ok)?.data?.let { list ->
            vehicles = list
            if (vehicleUuid == null && creating) {
                // The approved vehicle of the requested type first, else any approved one.
                vehicleUuid = (list.firstOrNull { it.status == "approved" && it.vehicleTypeId == move?.vehicleTypeId } ?: list.firstOrNull { it.status == "approved" })?.uuid
            }
        }
    }

    val priceMinor = parseMinor(price)
    val workersValue = workers.toIntOrNull()
    val tripsValue = trips.toIntOrNull()
    val arrivalIso = isoAt(arrivalDate, arrivalTime)
    val validIso = isoAt(validDate, validTime)
    val ready = priceMinor != null && (workers.isBlank() || (workersValue != null && workersValue in 0..50)) && (tripsValue != null && tripsValue in 1..50) &&
        (!creating || (vehicleUuid != null && arrivalIso != null && validIso != null))

    fun send() {
        busy = true
        error = null
        val body = JsonObject().apply {
            vehicleUuid?.let { addProperty("vehicle_uuid", it) }
            addProperty("price_minor", priceMinor)
            workersValue?.let { addProperty("workers_count", it) }
            addProperty("trips_count", tripsValue)
            add("includes", JsonObject().apply { listOf("packing", "disassembly", "crane", "technician", "tax").forEach { addProperty(it, it in includes) } })
            addProperty("technician_note", technicianNote.trim().ifEmpty { null })
            if (creating) {
                addProperty("arrival_at", arrivalIso)
                addProperty("valid_until", validIso)
            }
            addProperty("terms", terms.trim().ifEmpty { null })
            addProperty("after_service", afterService.trim().ifEmpty { null })
        }
        scope.launch {
            val result = if (offer == null) apiCall { ApiClient.moving.sendOffer(move?.uuid.orEmpty(), jsonBody(body)) } else apiCall { ApiClient.moving.updateOffer(offer.uuid, jsonBody(body)) }
            busy = false
            when {
                result is ApiResult.Ok && offer != null -> onDone(updatedText)
                result is ApiResult.Ok -> onDone(if (result.data.status == "awaiting_approval") toApproverText else sentText)
                else -> error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    Page(title = stringResource(if (creating) R.string.mv_send_offer else R.string.mv_edit_offer), onBack = onBack) {
        FormIntro(Icons.Rounded.RequestQuote, stringResource(if (creating) R.string.mv_send_offer else R.string.mv_edit_offer), stringResource(R.string.mv_offer_form_body))

        // The vehicle: only an approved one can carry an offer; the others say why not.
        FieldLabel(stringResource(R.string.mv_offer_vehicle))
        val list = vehicles
        when {
            list == null -> CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.size(24.dp))
            list.isEmpty() -> Text(stringResource(R.string.mv_no_vehicles), color = Red, fontSize = 13.sp, modifier = Modifier.fillMaxWidth())
            else -> Card {
                list.forEach { vehicle ->
                    val usable = vehicle.status == "approved"
                    Row(
                        Modifier.fillMaxWidth().clickable(enabled = usable) { vehicleUuid = vehicle.uuid }.padding(vertical = 4.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(
                            if (vehicleUuid == vehicle.uuid) Icons.Rounded.RadioButtonChecked else Icons.Rounded.RadioButtonUnchecked, contentDescription = null,
                            tint = if (usable) settingsAccent() else settingsMut().copy(alpha = 0.4f), modifier = Modifier.size(22.dp),
                        )
                        Spacer(Modifier.width(8.dp))
                        Column(Modifier.weight(1f)) {
                            Text(
                                (vehicle.vehicleType?.name ?: stringResource(R.string.vh_vehicle)) + " · ‎" + vehicle.plate(),
                                color = settingsInk().copy(alpha = if (usable) 1f else 0.5f), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                            )
                            if (!usable) Text(stringResource(R.string.mv_vehicle_unusable, vehicleStatusLabel(vehicle.status)), color = settingsMut(), fontSize = 11.sp)
                            else if (move?.vehicleTypeId?.let { wanted -> vehicle.vehicleTypeId != wanted } == true) Text(stringResource(R.string.mv_vehicle_other_type), color = Amber, fontSize = 11.sp)
                        }
                    }
                }
                if (!creating) Text(stringResource(R.string.mv_vehicle_keep), color = settingsMut(), fontSize = 11.sp)
            }
        }

        FormGroup {
            DorrTextField(
                price, { price = it.filter { c -> c.isDigit() || c == '.' || c == ',' }.take(12) },
                label = stringResource(R.string.mv_offer_price, currency.orEmpty()), icon = Icons.Rounded.Payments,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), error = price.isNotBlank() && priceMinor == null, modifier = Modifier.fillMaxWidth(),
            )
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                DorrTextField(workers, { workers = it.filter(Char::isDigit).take(2) }, label = stringResource(R.string.mv_workers), icon = Icons.Rounded.Groups, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.weight(1f))
                DorrTextField(trips, { trips = it.filter(Char::isDigit).take(2) }, label = stringResource(R.string.mv_trips), icon = Icons.Rounded.Repeat, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.weight(1f))
            }
        }

        FieldLabel(stringResource(R.string.mv_offer_includes))
        Card {
            listOf("packing", "disassembly", "crane", "technician", "tax").forEach { key ->
                Row(Modifier.fillMaxWidth().clickable { if (key in includes) includes.remove(key) else includes.add(key) }.padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(if (key in includes) Icons.Rounded.CheckBox else Icons.Rounded.CheckBoxOutlineBlank, contentDescription = null, tint = if (key in includes) settingsAccent() else settingsMut(), modifier = Modifier.size(22.dp))
                    Spacer(Modifier.width(8.dp))
                    Text(includeLabel(key), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                }
            }
            if ("technician" in includes) {
                DorrTextField(technicianNote, { technicianNote = it.take(255) }, label = stringResource(R.string.mv_technician_note), placeholder = stringResource(R.string.onb_optional), modifier = Modifier.fillMaxWidth())
            }
        }

        if (creating) {
            FieldLabel(stringResource(R.string.mv_arrival))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                DateField(arrivalDate, { arrivalDate = it }, label = stringResource(R.string.mv_date), modifier = Modifier.weight(1f))
                TimeField(arrivalTime, { arrivalTime = it }, label = stringResource(R.string.mv_time), modifier = Modifier.weight(1f))
            }
            FieldLabel(stringResource(R.string.mv_valid_until))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                DateField(validDate, { validDate = it }, label = stringResource(R.string.mv_date), modifier = Modifier.weight(1f))
                TimeField(validTime, { validTime = it }, label = stringResource(R.string.mv_time), modifier = Modifier.weight(1f))
            }
            Text(stringResource(R.string.mv_valid_until_hint), color = settingsMut(), fontSize = 11.sp, lineHeight = 16.sp, modifier = Modifier.fillMaxWidth())
        } else {
            Card {
                InfoLine(stringResource(R.string.mv_arrival), dateTimeText(offer?.arrivalAt))
                InfoLine(stringResource(R.string.mv_valid_until), dateTimeText(offer?.validUntil))
            }
        }

        FormGroup {
            DorrTextField(terms, { terms = it.take(3000) }, label = stringResource(R.string.mv_terms), placeholder = stringResource(R.string.onb_optional), icon = Icons.Rounded.Notes, singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
            DorrTextField(afterService, { afterService = it.take(2000) }, label = stringResource(R.string.mv_after_service), placeholder = stringResource(R.string.mv_after_service_hint), icon = Icons.Rounded.LocalShipping, singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
        }
        SettingsPrimaryButton(text = stringResource(if (creating) R.string.mv_send_offer else R.string.common_save), enabled = ready, loading = busy, onClick = ::send)
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}
