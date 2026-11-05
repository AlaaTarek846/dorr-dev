@file:OptIn(ExperimentalLayoutApi::class)

package com.dorr.app.ui.screens.moving

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import android.widget.Toast
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AddPhotoAlternate
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.PublishedWithChanges
import androidx.compose.material.icons.rounded.Receipt
import androidx.compose.material.icons.rounded.SupportAgent
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MovingChangeDto
import com.dorr.app.network.MovingInventoryItemDto
import com.dorr.app.network.MovingInvoiceDto
import com.dorr.app.network.MovingRequestDto
import com.dorr.app.network.MovingTicketDto
import com.dorr.app.network.apiFailure
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.components.PullToRefreshHost
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.rides.AccentButton
import com.dorr.app.ui.screens.rides.RoundButton
import com.dorr.app.ui.screens.rides.parseTime
import com.dorr.app.ui.screens.rides.rememberWhenLabels
import com.dorr.app.ui.screens.rides.staggerIn
import com.dorr.app.ui.screens.rides.whenText
import com.dorr.app.ui.screens.wallet.CheckoutLauncher
import com.dorr.app.ui.screens.wallet.CheckoutRequest
import com.dorr.app.ui.theme.AppColors
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.ByteArrayOutputStream
import java.time.ZonedDateTime

/*
 * After the move and around it: the establishment's changes and new times (nothing is done or charged before a yes), the
 * report with the amount claimed, the inventory photos, the invoice and the tickets. Every refusal shows the server's
 * own words.
 */

// ---------------------------------------------------------------- changes and new times

/** A value of an applied change's `before` / `after` map. */
private fun JsonElement?.minor(key: String): Long? =
    this?.takeIf { it.isJsonObject }?.asJsonObject?.get(key)?.takeIf { it.isJsonPrimitive }?.let { runCatching { it.asLong }.getOrNull() }

private fun JsonElement?.text(key: String): String? =
    this?.takeIf { it.isJsonObject }?.asJsonObject?.get(key)?.takeIf { it.isJsonPrimitive }?.asString

@Composable
internal fun MovingChangesPage(uuid: String, nav: MovingNav) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var changes by remember { mutableStateOf<List<MovingChangeDto>?>(null) }
    var request by remember { mutableStateOf<MovingRequestDto?>(null) }
    var busy by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var timeOpen by remember { mutableStateOf(false) }
    var newTime by remember { mutableStateOf<ZonedDateTime?>(null) }
    var reason by remember { mutableStateOf("") }
    var askReason by remember { mutableStateOf(false) }
    val failed = stringResource(R.string.rd_error)

    suspend fun reload() {
        // The request first: the cards need its currency and decimals.
        runCatching { ApiClient.moving.show(mvAuth(), uuid).data?.request }.getOrNull()?.let { request = it }
        runCatching { ApiClient.moving.changes(mvAuth(), uuid).data.orEmpty() }
            .onSuccess { changes = it }
            .onFailure {
                error = it.apiFailure().message ?: failed
                if (changes == null) changes = emptyList()
            }
    }
    MovingLiveEffect(uuid = uuid, onChanged = { reload() })

    fun pay(change: MovingChangeDto, checkoutId: String?) {
        CheckoutLauncher.open(CheckoutRequest("moving_change", mapOf("change_uuid" to change.uuid), checkoutId)) { scope.launch { reload() } }
    }

    fun respond(change: MovingChangeDto, approve: Boolean) {
        busy = change.uuid
        error = null
        scope.launch {
            val body = JsonObject().apply { addProperty("approve", approve) }
            runCatching { ApiClient.moving.respondChange(mvAuth(), uuid, change.uuid, body) }
                .onSuccess { envelope ->
                    // A yes that costs more: the difference is paid on the payment screen, and only then counts.
                    val checkout = envelope.data?.checkout
                    if (checkout != null) pay(change, checkout.id) else Toast.makeText(context, envelope.message, Toast.LENGTH_SHORT).show()
                    reload()
                }
                .onFailure { error = it.apiFailure().message ?: failed }
            busy = null
        }
    }

    val status = request?.status
    val canAskTime = status == "confirmed" || status == "assigned"
    val open = changes.orEmpty().any { it.status == "pending" || it.status == "awaiting_payment" }
    MvPage(
        title = stringResource(R.string.mv_changes_title),
        onBack = { nav.pop() },
    ) {
        val list = changes
        if (list == null) {
            MvLoading()
            return@MvPage
        }
        PullToRefreshHost(Modifier.fillMaxSize()) {
            MvScroll {
                MvError(error)
                if (canAskTime && !open) {
                    MvAction(Icons.Rounded.Event, stringResource(R.string.mv_new_time), stringResource(R.string.mv_new_time_hint)) { timeOpen = true }
                }
                if (list.isEmpty()) {
                    Box(Modifier.fillMaxWidth().height(260.dp)) { MvEmpty(Icons.Rounded.PublishedWithChanges, stringResource(R.string.mv_changes_empty)) }
                }
                list.forEachIndexed { i, change ->
                    Box(Modifier.staggerIn(i)) {
                        ChangeCard(
                            change, request,
                            busy = busy == change.uuid,
                            onRespond = { approve -> respond(change, approve) },
                            onPay = { pay(change, null) },
                        )
                    }
                }
            }
        }
    }

    if (timeOpen) MovingTimeSheet(
        initial = parseTime(request?.scheduledAt),
        title = stringResource(R.string.mv_new_time),
        onClose = { timeOpen = false },
        onPick = {
            newTime = it
            timeOpen = false
            askReason = true
        },
    )
    if (askReason) {
        val (today, tomorrow) = rememberWhenLabels()
        MvSheet(onClose = { askReason = false }) {
            Text(stringResource(R.string.mv_new_time), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 20.sp)
            newTime?.let { Text(whenText(it, today, tomorrow), color = settingsAccent(), fontWeight = FontWeight.Bold) }
            Text(stringResource(R.string.mv_new_time_needs_yes), color = settingsMut(), fontSize = 12.5.sp)
            DorrTextField(value = reason, onValueChange = { reason = it.take(500) }, label = stringResource(R.string.mv_new_time_reason), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
            MvError(error)
            AccentButton(stringResource(R.string.mv_send), enabled = reason.isNotBlank() && newTime != null, busy = busy == "time") {
                val at = newTime ?: return@AccentButton
                busy = "time"
                error = null
                scope.launch {
                    val body = JsonObject().apply {
                        addProperty("scheduled_at", at.toOffsetDateTime().toString())
                        addProperty("reason", reason.trim())
                    }
                    runCatching { ApiClient.moving.reschedule(mvAuth(), uuid, body) }
                        .onSuccess {
                            askReason = false
                            reason = ""
                            reload()
                        }
                        .onFailure { error = it.apiFailure().message ?: failed }
                    busy = null
                }
            }
        }
    }
}

@Composable
private fun ChangeCard(change: MovingChangeDto, request: MovingRequestDto?, busy: Boolean, onRespond: (Boolean) -> Unit, onPay: () -> Unit) {
    val code = request?.currencyCode
    val places = request?.currencyDecimals ?: 2
    val (today, tomorrow) = rememberWhenLabels()
    val waitingMinutes = change.reason?.takeIf { it.startsWith("waiting:") }?.removePrefix("waiting:")
    MvCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            MvCardTitle(
                stringResource(
                    when (change.kind) {
                        "waiting" -> R.string.mv_change_waiting
                        "reschedule" -> R.string.mv_change_time
                        else -> R.string.mv_change_price
                    },
                ),
                Modifier.weight(1f),
            )
            val (label, color) = when (change.status) {
                "pending" -> stringResource(R.string.mv_change_pending) to AppColors.warning
                "awaiting_payment" -> stringResource(R.string.mv_change_awaiting_payment) to AppColors.warning
                "approved" -> stringResource(R.string.mv_change_approved) to AppColors.success
                else -> stringResource(R.string.mv_change_rejected) to AppColors.danger
            }
            MvChip(label, color)
        }
        Spacer(Modifier.height(6.dp))
        when {
            waitingMinutes != null -> Text(stringResource(R.string.mv_waiting_minutes, waitingMinutes), color = settingsInk(), fontSize = 14.sp)
            !change.reason.isNullOrBlank() -> Text(change.reason, color = settingsInk(), fontSize = 14.sp)
        }

        if (change.kind == "reschedule") {
            // Before and after: the time.
            val before = parseTime(change.before.text("scheduled_at")) ?: parseTime(request?.scheduledAt)
            MvLine(stringResource(R.string.mv_before), before?.let { whenText(it, today, tomorrow) } ?: "—")
            MvLine(stringResource(R.string.mv_after), parseTime(change.newScheduledAt)?.let { whenText(it, today, tomorrow) } ?: "—")
        } else {
            // Before and after: the price (kept with an applied change; worked out from the difference until then).
            val before = change.before.minor("price_minor") ?: request?.money?.priceMinor
            val after = change.after.minor("price_minor") ?: before?.let { it + change.priceDeltaMinor }
            MvLine(stringResource(R.string.mv_before), mvMoney(before, code, places))
            MvLine(stringResource(R.string.mv_after), mvMoney(after, code, places))
            MvLine(stringResource(R.string.mv_change_difference), mvMoney(change.priceDeltaMinor, code, places))
            change.extraTotalMinor?.takeIf { it > 0 }?.let { MvLine(stringResource(R.string.mv_change_to_pay), mvMoney(it, code, places), bold = true) }
            change.workersCount?.let { MvLine(stringResource(R.string.mv_workers), it.toString()) }
            change.tripsCount?.let { MvLine(stringResource(R.string.mv_trips), it.toString()) }
        }

        if (change.status == "pending" && change.proposedBy == "provider") {
            Spacer(Modifier.height(10.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Box(Modifier.weight(1f)) { MvOutlineButton(stringResource(R.string.mv_refuse), color = AppColors.danger, enabled = !busy) { onRespond(false) } }
                Box(Modifier.weight(1f)) { AccentButton(stringResource(R.string.mv_approve), busy = busy) { onRespond(true) } }
            }
        }
        if (change.status == "pending" && change.proposedBy == "user") {
            Text(stringResource(R.string.mv_change_wait_establishment), color = settingsMut(), fontSize = 12.5.sp, modifier = Modifier.padding(top = 6.dp))
        }
        if (change.status == "awaiting_payment") {
            Spacer(Modifier.height(10.dp))
            AccentButton(stringResource(R.string.mv_pay_difference), onClick = onPay)
        }
    }
}

// ---------------------------------------------------------------- the report

/** The picked photo as a `photos[]` part: shrunk so it always fits the server's limit (the events cover way). */
private suspend fun reportPhotoPart(context: Context, uri: Uri): MultipartBody.Part? = withContext(Dispatchers.IO) {
    runCatching {
        val maxSide = 1600
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        context.contentResolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, bounds) }
        var sample = 1
        while (maxOf(bounds.outWidth, bounds.outHeight) / (sample * 2) >= maxSide) sample *= 2
        val decoded = context.contentResolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, BitmapFactory.Options().apply { inSampleSize = sample }) } ?: return@runCatching null
        val scale = maxSide.toFloat() / maxOf(decoded.width, decoded.height)
        val bitmap = if (scale < 1f) Bitmap.createScaledBitmap(decoded, (decoded.width * scale).toInt(), (decoded.height * scale).toInt(), true) else decoded
        val bytes = ByteArrayOutputStream().also { bitmap.compress(Bitmap.CompressFormat.JPEG, 85, it) }.toByteArray()
        MultipartBody.Part.createFormData("photos[]", "photo_${System.nanoTime()}.jpg", bytes.toRequestBody("image/jpeg".toMediaTypeOrNull()))
    }.getOrNull()
}

/** A report: why, the photos, and how much is claimed. It says plainly that only that amount stays held. */
@Composable
internal fun MovingReportPage(uuid: String, currency: String?, decimals: Int, nav: MovingNav) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var reason by remember { mutableStateOf<String?>(null) }
    var details by remember { mutableStateOf("") }
    var claim by remember { mutableStateOf("") }
    val photos = remember { mutableStateListOf<Uri>() }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.rd_error)
    val pick = rememberLauncherForActivityResult(ActivityResultContracts.PickMultipleVisualMedia(10)) { uris ->
        photos.addAll(uris.filter { it !in photos })
        while (photos.size > 10) photos.removeAt(photos.lastIndex)
    }
    // The amount is typed in the currency; the server takes minor units, with this request's decimals.
    val claimMinor = parseMinor(claim, decimals)
    val ready = reason != null && claimMinor != null && claimMinor > 0 && photos.isNotEmpty()

    MvPage(
        title = stringResource(R.string.mv_report_title),
        onBack = { nav.pop() },
        bottom = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                MvError(error)
                AccentButton(stringResource(R.string.mv_report_send), enabled = ready, busy = busy) {
                    val why = reason ?: return@AccentButton
                    val amount = claimMinor ?: return@AccentButton
                    busy = true
                    error = null
                    scope.launch {
                        val text = "text/plain".toMediaTypeOrNull()
                        val fields = buildMap<String, RequestBody> {
                            put("reason", why.toRequestBody(text))
                            put("claim_minor", amount.toString().toRequestBody(text))
                            if (details.isNotBlank()) put("details", details.trim().toRequestBody(text))
                        }
                        val parts = photos.toList().mapNotNull { reportPhotoPart(context, it) }
                        if (parts.isEmpty()) {
                            error = failed
                        } else {
                            runCatching { ApiClient.moving.report(mvAuth(), uuid, fields, parts) }
                                .onSuccess { envelope ->
                                    Toast.makeText(context, envelope.message, Toast.LENGTH_LONG).show()
                                    nav.pop()
                                }
                                .onFailure { error = it.apiFailure().message ?: failed }
                        }
                        busy = false
                    }
                }
            }
        },
    ) {
        MvScroll {
            MvNote(stringResource(R.string.mv_report_hold_note), AppColors.warning)
            MvCard {
                MvCardTitle(stringResource(R.string.mv_report_reason))
                Spacer(Modifier.height(8.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    listOf(
                        "damage" to R.string.mv_report_damage,
                        "missing_item" to R.string.mv_report_missing,
                        "incomplete_service" to R.string.mv_report_incomplete,
                        "other" to R.string.mv_reason_other,
                    ).forEach { (key, label) -> MvPill(stringResource(label), reason == key) { reason = key } }
                }
            }
            MvCard {
                DorrTextField(
                    value = claim, onValueChange = { typed -> claim = limitAmount(typed, decimals) },
                    label = stringResource(R.string.mv_report_claim, currency.orEmpty()),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(10.dp))
                DorrTextField(value = details, onValueChange = { details = it.take(2000) }, label = stringResource(R.string.mv_report_details), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
            }
            MvCard {
                MvCardTitle(stringResource(R.string.mv_report_photos))
                Text(stringResource(R.string.mv_report_photos_hint), color = settingsMut(), fontSize = 12.sp)
                Spacer(Modifier.height(10.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    val tile = RoundedCornerShape(16.dp)
                    photos.forEach { uri ->
                        Box(Modifier.size(92.dp).clip(tile).background(settingsBackground())) {
                            AsyncImage(uri, null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                            Box(Modifier.align(Alignment.TopEnd).padding(4.dp).size(26.dp).clip(CircleShape).background(Color.Black.copy(alpha = 0.55f)).clickable { photos.remove(uri) }, contentAlignment = Alignment.Center) {
                                Icon(Icons.Rounded.Close, null, tint = Color.White, modifier = Modifier.size(15.dp))
                            }
                        }
                    }
                    if (photos.size < 10) {
                        Box(
                            Modifier.size(92.dp).clip(tile).background(settingsBackground()).border(1.5.dp, settingsInk().copy(alpha = 0.12f), tile)
                                .clickable { pick.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) },
                            contentAlignment = Alignment.Center,
                        ) { Icon(Icons.Rounded.AddPhotoAlternate, null, tint = settingsAccent(), modifier = Modifier.size(30.dp)) }
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------------- the inventory

/** The numbered pieces with their photo before loading and after delivery. */
@Composable
internal fun MovingInventoryPage(items: List<MovingInventoryItemDto>, nav: MovingNav) {
    MvPage(title = stringResource(R.string.mv_inventory_title), onBack = { nav.pop() }) {
        if (items.isEmpty()) {
            MvEmpty(Icons.Rounded.Inventory2, stringResource(R.string.mv_inventory_empty))
            return@MvPage
        }
        MvScroll {
            items.forEachIndexed { i, item ->
                Box(Modifier.staggerIn(i)) {
                    MvCard {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Box(Modifier.size(32.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                                Text(item.number?.toString().orEmpty(), color = settingsAccent(), fontWeight = FontWeight.Bold, fontSize = 13.sp)
                            }
                            Spacer(Modifier.width(10.dp))
                            Text(item.label.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                            item.tripNo?.let { MvChip(stringResource(R.string.mv_trip_no, it), settingsMut()) }
                        }
                        Spacer(Modifier.height(10.dp))
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            InventoryPhoto(stringResource(R.string.mv_before), item.before, item.conditionNote, Modifier.weight(1f))
                            InventoryPhoto(stringResource(R.string.mv_after), item.after, item.deliveredNote, Modifier.weight(1f))
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun InventoryPhoto(title: String, url: String?, note: String?, modifier: Modifier) {
    Column(modifier) {
        Text(title, color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
        Spacer(Modifier.height(4.dp))
        Box(Modifier.fillMaxWidth().aspectRatio(1f).clip(RoundedCornerShape(14.dp)).background(settingsBackground()), contentAlignment = Alignment.Center) {
            if (url != null) AsyncImage(ApiClient.mediaUrl(url), null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
            else Text("—", color = settingsMut())
        }
        note?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsInk(), fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp)) }
    }
}

// ---------------------------------------------------------------- the invoice

@Composable
internal fun MovingInvoicePage(uuid: String, decimals: Int, nav: MovingNav) {
    var invoice by remember { mutableStateOf<MovingInvoiceDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.rd_error)
    val tick = collectReconnectTick()
    LaunchedEffect(uuid, tick) {
        runCatching { ApiClient.moving.invoice(mvAuth(), uuid).data }
            .onSuccess { invoice = it; error = null }
            .onFailure { error = it.apiFailure().message ?: failed }
    }
    val (today, tomorrow) = rememberWhenLabels()
    MvPage(title = stringResource(R.string.mv_invoice_title), onBack = { nav.pop() }) {
        val i = invoice
        when {
            i != null -> PullToRefreshHost(Modifier.fillMaxSize()) {
                MvScroll {
                    MvCard {
                        i.number?.let { MvLine(stringResource(R.string.mv_invoice_number), it) }
                        parseTime(i.issuedAt)?.let { MvLine(stringResource(R.string.mv_invoice_date), whenText(it, today, tomorrow)) }
                        i.establishment?.name?.let { MvLine(stringResource(R.string.mv_invoice_establishment), it) }
                        i.establishment?.taxNumber?.takeIf { it.isNotBlank() }?.let { MvLine(stringResource(R.string.mv_invoice_tax_number), it) }
                        i.workersCount?.let { MvLine(stringResource(R.string.mv_workers), it.toString()) }
                        i.tripsCount?.let { MvLine(stringResource(R.string.mv_trips), it.toString()) }
                    }
                    val included = i.includes.includedKeys()
                    if (included.isNotEmpty()) {
                        MvCard {
                            MvCardTitle(stringResource(R.string.mv_included))
                            Spacer(Modifier.height(6.dp))
                            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                                included.forEach { MvChip(extraLabel(it), settingsAccent()) }
                            }
                        }
                    }
                    MvCard {
                        i.lines.forEach { line ->
                            val minutes = line.reason?.takeIf { it.startsWith("waiting:") }?.removePrefix("waiting:")
                            MvLine(
                                when (line.key) {
                                    "waiting" -> if (minutes != null) stringResource(R.string.mv_invoice_waiting_minutes, minutes) else stringResource(R.string.mv_change_waiting)
                                    "change" -> line.reason?.takeIf { it.isNotBlank() } ?: stringResource(R.string.mv_change_price)
                                    else -> stringResource(R.string.mv_invoice_moving)
                                },
                                mvMoney(line.amountMinor, i.currencyCode, decimals),
                            )
                        }
                        MvLine(stringResource(R.string.mv_price), mvMoney(i.priceMinor, i.currencyCode, decimals))
                        MvLine(stringResource(R.string.mv_tax), mvMoney(i.taxMinor, i.currencyCode, decimals))
                        MvLine(stringResource(R.string.mv_total), mvMoney(i.totalMinor, i.currencyCode, decimals), bold = true)
                        if (i.refundedMinor > 0) MvLine(stringResource(R.string.mv_invoice_refunded), mvMoney(i.refundedMinor, i.currencyCode, decimals), valueColor = AppColors.success)
                    }
                }
            }
            error != null -> MvEmpty(Icons.Rounded.Receipt, error.orEmpty())
            else -> MvLoading()
        }
    }
}

// ---------------------------------------------------------------- tickets

@Composable
private fun ticketTypeLabel(type: String?): String = stringResource(
    when (type) {
        "suggestion" -> R.string.mv_ticket_suggestion
        "inquiry" -> R.string.mv_ticket_inquiry
        "complaint" -> R.string.mv_ticket_complaint
        "safety" -> R.string.mv_ticket_safety
        "inventory" -> R.string.mv_ticket_inventory
        "delay" -> R.string.mv_ticket_delay
        "financial" -> R.string.mv_ticket_financial
        else -> R.string.mv_ticket_note
    },
)

@Composable
private fun ticketStatusLook(status: String?): Pair<String, Color> = when (status) {
    "awaiting_company" -> stringResource(R.string.mv_ticket_st_awaiting_company) to AppColors.warning
    "company_replied" -> stringResource(R.string.mv_ticket_st_company_replied) to settingsAccent()
    "escalated" -> stringResource(R.string.mv_ticket_st_escalated) to AppColors.brandNavy
    "awaiting_customer" -> stringResource(R.string.mv_ticket_st_awaiting_customer) to AppColors.warning
    "resolved" -> stringResource(R.string.mv_ticket_st_resolved) to AppColors.success
    else -> stringResource(R.string.mv_ticket_st_unresolved) to AppColors.danger
}

@Composable
internal fun MovingTicketsPage(uuid: String, nav: MovingNav) {
    var tickets by remember { mutableStateOf<List<MovingTicketDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.rd_error)
    val tick = collectReconnectTick()
    LaunchedEffect(uuid, tick) {
        runCatching { ApiClient.moving.tickets(mvAuth(), uuid).data.orEmpty() }
            .onSuccess { tickets = it; error = null }
            .onFailure {
                error = it.apiFailure().message ?: failed
                if (tickets == null) tickets = emptyList()
            }
    }
    MvPage(
        title = stringResource(R.string.mv_tickets_title),
        onBack = { nav.pop() },
        trailing = { RoundButton(Icons.Rounded.Add, onClick = { nav.push(MvRoute.NewTicket(uuid)) }) },
    ) {
        val list = tickets
        if (list == null) {
            MvLoading()
            return@MvPage
        }
        PullToRefreshHost(Modifier.fillMaxSize()) {
            MvScroll {
                MvNote(stringResource(R.string.mv_tickets_hint))
                MvError(error)
                if (list.isEmpty()) {
                    Box(Modifier.fillMaxWidth().height(260.dp)) { MvEmpty(Icons.Rounded.SupportAgent, stringResource(R.string.mv_tickets_empty)) }
                }
                list.forEachIndexed { i, t ->
                    val (label, color) = ticketStatusLook(t.status)
                    Column(Modifier.staggerIn(i).fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(mvCardColor()).clickable { nav.push(MvRoute.Ticket(uuid, t.uuid)) }.padding(14.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(t.subject.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Bold)
                                Text(ticketTypeLabel(t.type), color = settingsMut(), fontSize = 12.sp)
                            }
                            MvChip(label, color)
                        }
                    }
                }
            }
        }
    }
}

@Composable
internal fun MovingNewTicketPage(uuid: String, nav: MovingNav) {
    val scope = rememberCoroutineScope()
    val types = listOf("complaint", "inquiry", "delay", "inventory", "financial", "safety", "suggestion", "note")
    var type by remember { mutableStateOf<String?>(null) }
    var subject by remember { mutableStateOf("") }
    var body by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.rd_error)
    MvPage(
        title = stringResource(R.string.mv_ticket_new),
        onBack = { nav.pop() },
        bottom = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                MvError(error)
                AccentButton(stringResource(R.string.mv_send), enabled = type != null && subject.isNotBlank() && body.isNotBlank(), busy = busy) {
                    val kind = type ?: return@AccentButton
                    busy = true
                    error = null
                    scope.launch {
                        val json = JsonObject().apply {
                            addProperty("type", kind)
                            addProperty("subject", subject.trim())
                            addProperty("body", body.trim())
                        }
                        runCatching { ApiClient.moving.openTicket(mvAuth(), uuid, json).data }
                            .onSuccess { ticket -> if (ticket != null) nav.replace(MvRoute.Ticket(uuid, ticket.uuid)) else nav.pop() }
                            .onFailure { error = it.apiFailure().message ?: failed }
                        busy = false
                    }
                }
            }
        },
    ) {
        MvScroll {
            MvNote(stringResource(R.string.mv_tickets_hint))
            MvCard {
                MvCardTitle(stringResource(R.string.mv_ticket_type))
                Spacer(Modifier.height(8.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    types.forEach { key -> MvPill(ticketTypeLabel(key), type == key) { type = key } }
                }
            }
            MvCard {
                DorrTextField(value = subject, onValueChange = { subject = it.take(200) }, label = stringResource(R.string.mv_ticket_subject), modifier = Modifier.fillMaxWidth())
                Spacer(Modifier.height(10.dp))
                DorrTextField(value = body, onValueChange = { body = it.take(3000) }, label = stringResource(R.string.mv_ticket_body), singleLine = false, minLines = 4, modifier = Modifier.fillMaxWidth())
            }
        }
    }
}

@Composable
internal fun MovingTicketPage(ticketUuid: String, nav: MovingNav) {
    val scope = rememberCoroutineScope()
    var ticket by remember { mutableStateOf<MovingTicketDto?>(null) }
    var text by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.rd_error)
    val tick = collectReconnectTick()
    LaunchedEffect(ticketUuid, tick) {
        runCatching { ApiClient.moving.ticket(mvAuth(), ticketUuid).data }
            .onSuccess { ticket = it; error = null }
            .onFailure { error = it.apiFailure().message ?: failed }
    }

    fun act(action: String) {
        busy = true
        error = null
        scope.launch {
            val json = JsonObject().apply {
                addProperty("action", action)
                if (text.isNotBlank()) addProperty("body", text.trim())
            }
            runCatching { ApiClient.moving.actOnTicket(mvAuth(), ticketUuid, json).data }
                .onSuccess { fresh ->
                    if (fresh != null) ticket = fresh
                    text = ""
                }
                .onFailure { error = it.apiFailure().message ?: failed }
            busy = false
        }
    }

    MvPage(title = ticket?.subject ?: stringResource(R.string.mv_tickets_title), onBack = { nav.pop() }) {
        val t = ticket
        if (t == null) {
            if (error != null) MvEmpty(Icons.Rounded.SupportAgent, error.orEmpty()) else MvLoading()
            return@MvPage
        }
        val closed = t.status == "resolved" || t.status == "unresolved"
        val (label, color) = ticketStatusLook(t.status)
        PullToRefreshHost(Modifier.fillMaxSize()) {
            MvScroll {
                MvCard {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(ticketTypeLabel(t.type), color = settingsInk(), fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                        MvChip(label, color)
                    }
                    t.closingReason?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.5.sp, modifier = Modifier.padding(top = 6.dp)) }
                    t.escalationReason?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.mv_ticket_escalation_reason, it), color = settingsMut(), fontSize = 12.5.sp, modifier = Modifier.padding(top = 6.dp)) }
                }
                MvNote(stringResource(R.string.mv_tickets_hint))
                MvCard {
                    MvCardTitle(stringResource(R.string.rd_cases_conversation))
                    t.messages.forEach { m ->
                        val mine = m.author == "user"
                        Column(Modifier.fillMaxWidth().padding(top = 8.dp), horizontalAlignment = if (mine) Alignment.End else Alignment.Start) {
                            Text(
                                m.body.orEmpty(), color = settingsInk(), fontSize = 13.sp,
                                modifier = Modifier.clip(RoundedCornerShape(14.dp)).background(if (mine) settingsAccent().copy(alpha = 0.12f) else settingsBackground()).padding(horizontal = 12.dp, vertical = 8.dp),
                            )
                            if (!mine) Text(stringResource(if (m.author == "admin") R.string.mv_ticket_from_dorr else R.string.mv_ticket_from_company), color = settingsMut(), fontSize = 10.sp)
                        }
                    }
                }
                if (!closed) {
                    MvCard {
                        DorrTextField(value = text, onValueChange = { text = it.take(3000) }, label = stringResource(R.string.mv_ticket_write), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
                        Spacer(Modifier.height(10.dp))
                        AccentButton(stringResource(R.string.mv_ticket_reply), enabled = text.isNotBlank(), busy = busy) { act("reply") }
                        Spacer(Modifier.height(8.dp))
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Box(Modifier.weight(1f)) { MvOutlineButton(stringResource(R.string.mv_ticket_resolve), color = AppColors.success, enabled = !busy) { act("resolve") } }
                            if (t.status != "escalated") {
                                Box(Modifier.weight(1f)) { MvOutlineButton(stringResource(R.string.mv_ticket_escalate), color = AppColors.warning, enabled = !busy) { act("escalate") } }
                            }
                        }
                        Text(stringResource(R.string.mv_ticket_escalate_hint), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp))
                    }
                }
                MvError(error)
            }
        }
    }
}
