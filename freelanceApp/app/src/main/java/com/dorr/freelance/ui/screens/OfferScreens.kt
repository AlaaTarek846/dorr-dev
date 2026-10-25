package com.dorr.freelance.ui.screens

import android.content.Context
import android.net.Uri
import android.provider.OpenableColumns
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AttachFile
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.OfferBreakdownDto
import com.dorr.freelance.net.OfferDto
import com.dorr.freelance.net.OfferPreviewDto
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.FieldLabel
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsSurface
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody

private const val MAX_OFFER_FILES = 5
private const val DETAILS_MIN = 30

/** A file picked from the phone, kept until the offer is sent. */
internal class PickedFile(val name: String, val type: String, val bytes: ByteArray)

internal fun readFile(context: Context, uri: Uri): PickedFile? = runCatching {
    val resolver = context.contentResolver
    val name = resolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use { c -> if (c.moveToFirst()) c.getString(0) else null } ?: "file"
    PickedFile(name, resolver.getType(uri) ?: "application/octet-stream", resolver.openInputStream(uri)?.use { it.readBytes() } ?: return null)
}.getOrNull()

internal fun offerStatusColor(status: String): Color = when (status) {
    "accepted" -> Color(0xFF16A34A)
    "shortlisted" -> Color(0xFF2563EB)
    "viewed" -> Color(0xFFD97706)
    "rejected", "withdrawn" -> Color(0xFFDC2626)
    else -> Color(0xFF6B7280)
}

@Composable
internal fun offerStatusLabel(status: String): String = when (status) {
    "viewed" -> stringResource(R.string.offer_status_viewed)
    "shortlisted" -> stringResource(R.string.offer_status_shortlisted)
    "accepted" -> stringResource(R.string.offer_status_accepted)
    "rejected" -> stringResource(R.string.offer_status_rejected)
    "withdrawn" -> stringResource(R.string.offer_status_withdrawn)
    else -> stringResource(R.string.offer_status_submitted)
}

/** My offer on a project, as a card (on the project page and in my offers). */
@Composable
internal fun MyOfferCard(offer: OfferDto, onClick: (() -> Unit)? = null, onEdit: (() -> Unit)? = null, onChanged: ((OfferDto) -> Unit)? = null, onChat: ((String) -> Unit)? = null, onAgreement: (() -> Unit)? = null) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var confirmWithdraw by remember { mutableStateOf(false) }
    var history by remember { mutableStateOf<List<com.dorr.freelance.net.OfferRevisionDto>?>(null) }
    var showHistory by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    Column(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(offer.project?.title ?: stringResource(R.string.offer_mine), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            Text(
                offerStatusLabel(offer.status), color = offerStatusColor(offer.status), fontSize = 11.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(offerStatusColor(offer.status).copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp),
            )
        }
        Text("‎${formatMinor(offer.priceMinor, null)} ${offer.currency}  ·  " + stringResource(R.string.projects_days, offer.durationDays), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
        Text(stringResource(R.string.offer_net_line, "‎${formatMinor(offer.netMinor, null)} ${offer.currency}"), color = settingsMut(), fontSize = 12.sp)
        Text(offer.details, color = settingsInk(), fontSize = 13.sp, maxLines = 3, overflow = TextOverflow.Ellipsis)
        offer.answers.forEach { a -> Text(a.question + "\n" + a.answer.orEmpty(), color = settingsMut(), fontSize = 12.sp) }
        offer.statusReason?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.offer_reject_reason, it), color = Color(0xFFDC2626), fontSize = 13.sp) }
        // Where it has been: sent, seen, shortlisted … with the day.
        if (offer.timeline.size > 1) Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
            offer.timeline.forEach { e ->
                val label = when (e.type) {
                    "offer.submitted" -> R.string.offer_ev_submitted
                    "offer.viewed" -> R.string.offer_ev_viewed
                    "offer.shortlisted" -> R.string.offer_ev_shortlisted
                    "offer.rejected" -> R.string.offer_ev_rejected
                    "offer.accepted" -> R.string.offer_ev_accepted
                    "offer.edited" -> R.string.offer_ev_edited
                    "offer.withdrawn" -> R.string.offer_ev_withdrawn
                    else -> null
                }
                if (label != null) Text("•  " + stringResource(label) + "  ·  \u200E" + e.at?.take(10).orEmpty(), color = settingsMut(), fontSize = 11.sp)
            }
        }
        if (offer.revisions > 0) Text(
            stringResource(R.string.offer_edited_n, offer.revisions), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable {
                showHistory = !showHistory
                if (history == null) scope.launch { (apiCall { ApiClient.offers.revisions(offer.uuid) } as? ApiResult.Ok)?.data?.let { history = it } }
            }.padding(vertical = 2.dp),
        )
        if (showHistory) history?.forEach { r ->
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsMut().copy(alpha = 0.08f)).padding(10.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(stringResource(R.string.offer_revision, r.number, "\u200E${formatMinor(r.priceMinor, null)} ${offer.currency}", r.durationDays), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                Text(r.details, color = settingsMut(), fontSize = 12.sp, maxLines = 3, overflow = TextOverflow.Ellipsis)
            }
        }
        offer.agreement?.let { ag ->
            if (onAgreement != null) Text(
                stringResource(R.string.ag_title) + "  ·  " + agreementStatusText(ag.status), color = if (ag.status == "pending_approval") Color(0xFFD97706) else settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { onAgreement() }.padding(vertical = 8.dp),
            )
        }
        offer.chat?.let { room ->
            if (onChat != null) Text(
                stringResource(R.string.chat_open) + if (room.unread > 0) " (${room.unread})" else "", color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { onChat(room.uuid) }.padding(vertical = 8.dp),
            )
        }
        if (offer.can.edit && onEdit != null) Text(stringResource(R.string.offer_edit), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable(onClick = onEdit).padding(vertical = 8.dp))
        if (offer.can.withdraw && onChanged != null) Text(stringResource(R.string.offer_withdraw), color = Color(0xFFDC2626), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { confirmWithdraw = true }.padding(vertical = 8.dp))
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
    if (confirmWithdraw) androidx.compose.material3.AlertDialog(
        onDismissRequest = { if (!busy) confirmWithdraw = false },
        text = { Text(stringResource(R.string.offer_withdraw_confirm)) },
        confirmButton = {
            androidx.compose.material3.TextButton(onClick = {
                busy = true
                scope.launch {
                    val result = apiCall { ApiClient.offers.withdraw(offer.uuid) }
                    busy = false
                    confirmWithdraw = false
                    if (result is ApiResult.Ok) onChanged?.invoke(result.data) else error = result.errorText(offlineText) ?: genericError
                }
            }) { Text(stringResource(R.string.offer_withdraw), color = Color(0xFFDC2626)) }
        },
        dismissButton = { androidx.compose.material3.TextButton(onClick = { confirmWithdraw = false }) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
    )
}

/**
 * Sending an offer (FL-301): first whether I may (verified, taking work, the project open, not offered already, not full, quota left —
 * the server says which, and the form is not shown), then the price with what it leaves me after the commission (asked 400 ms after the
 * last key), the time it takes, the details and up to 5 files. Sent once; back on the project page it shows as my offer.
 */
@Composable
fun OfferFormScreen(projectUuid: String, onBack: () -> Unit, onSent: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val badPrice = stringResource(R.string.offer_price_invalid)

    var preview by remember { mutableStateOf<OfferPreviewDto?>(null) }
    var breakdown by remember { mutableStateOf<OfferBreakdownDto?>(null) }
    var price by rememberSaveable { mutableStateOf("") }
    var duration by rememberSaveable { mutableStateOf("") }
    var details by rememberSaveable { mutableStateOf("") }
    val files = remember { mutableStateListOf<PickedFile>() }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    var checks by remember { mutableStateOf<List<com.dorr.freelance.net.OfferCheckDto>>(emptyList()) }
    var templates by remember { mutableStateOf<List<com.dorr.freelance.net.OfferTemplateDto>>(emptyList()) }
    // FL-307: the client's questions, one answer each.
    var questions by remember { mutableStateOf<List<String>>(emptyList()) }
    val answers = remember { mutableStateListOf<String>() }
    var showTemplates by remember { mutableStateOf(false) }
    var savingTemplate by remember { mutableStateOf(false) }
    var templateNotice by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(projectUuid, reconnect) {
        val result = apiCall { ApiClient.offers.preview(projectUuid) }
        if (result is ApiResult.Ok) preview = result.data else error = result.errorText(offlineText) ?: genericError
        (apiCall { ApiClient.offers.templates() } as? ApiResult.Ok)?.data?.let { templates = it }
        (apiCall { ApiClient.projects.show(projectUuid) } as? ApiResult.Ok)?.data?.questions?.let { q ->
            questions = q
            while (answers.size < q.size) answers.add("")
        }
    }
    // FL-304: what looks weak, asked once the typing stops (not on a timer).
    LaunchedEffect(price, duration, details, files.size) {
        if (details.isBlank() && price.isBlank()) { checks = emptyList(); return@LaunchedEffect }
        delay(700)
        val body = com.dorr.freelance.net.OfferCheckRequest(parseMinor(price), duration.toIntOrNull(), details.trim(), files.size)
        (apiCall { ApiClient.offers.check(projectUuid, body) } as? ApiResult.Ok)?.data?.let { checks = it.checks }
    }
    // What the price leaves me, asked once the typing stops.
    LaunchedEffect(price) {
        val minor = parseMinor(price) ?: run { breakdown = null; return@LaunchedEffect }
        delay(400)
        (apiCall { ApiClient.offers.preview(projectUuid, minor) } as? ApiResult.Ok)?.data?.breakdown?.let { breakdown = it }
    }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri != null && files.size < MAX_OFFER_FILES) readFile(context, uri)?.let { files.add(it) }
    }

    fun send() {
        val minor = parseMinor(price) ?: run { error = badPrice; return }
        busy = true
        error = null
        scope.launch {
            val text = "text/plain".toMediaTypeOrNull()
            val fields = mapOf(
                "price_minor" to minor.toString().toRequestBody(text),
                "duration_days" to duration.toRequestBody(text),
                "details" to details.trim().toRequestBody(text),
            ) + answers.mapIndexed { i, a -> "answers[$i]" to a.trim().toRequestBody(text) }
            val parts = files.map { MultipartBody.Part.createFormData("files[]", it.name, it.bytes.toRequestBody(it.type.toMediaTypeOrNull())) }
            val result = apiCall { ApiClient.offers.send(projectUuid, fields, parts) }
            busy = false
            if (result is ApiResult.Ok) onSent() else error = result.errorText(offlineText) ?: genericError
        }
    }

    Page(title = stringResource(R.string.offer_title), onBack = onBack) {
        val p = preview
        if (p == null) {
            if (error == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
            ErrorBanner(error, Modifier.fillMaxWidth())
            return@Page
        }
        p.blocked?.let { blocked ->
            // The server's reason in its own words, and nothing to fill in.
            Card { Text(blocked.message, color = Color(0xFFD97706), fontSize = 14.sp, fontWeight = FontWeight.SemiBold) }
            return@Page
        }

        p.quota.left?.let { left -> Text(stringResource(R.string.offer_quota_left, left, p.quota.limit ?: left), color = settingsMut(), fontSize = 12.sp) }
        Card {
            FieldLabel(stringResource(R.string.offer_price))
            DorrTextField(price, { price = it }, placeholder = "0.00", keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth())
            p.minPriceMinor?.let { Text(stringResource(R.string.offer_price_min, "‎${formatMinor(it, null)}"), color = settingsMut(), fontSize = 12.sp) }
            breakdown?.let { b ->
                Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.08f)).padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(stringResource(R.string.offer_commission, "‎${formatMinor(b.commissionMinor, null)} ${b.currency}"), color = settingsMut(), fontSize = 12.sp)
                    Text(stringResource(R.string.offer_net, "‎${formatMinor(b.netMinor, null)} ${b.currency}"), color = settingsAccent(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                }
            }
            DorrTextField(duration, { duration = it.filter(Char::isDigit).take(3) }, label = stringResource(R.string.offer_duration), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
            DorrTextField(details, { details = it.take(3000) }, label = stringResource(R.string.offer_details), placeholder = stringResource(R.string.offer_details_hint), singleLine = false, minLines = 5, modifier = Modifier.fillMaxWidth())
            Text(stringResource(R.string.offer_details_count, details.trim().length, DETAILS_MIN), color = if (details.trim().length < DETAILS_MIN) settingsMut() else Color(0xFF16A34A), fontSize = 11.sp)
            // FL-304: start from a kept text, or keep this one.
            Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                if (templates.isNotEmpty()) Text(stringResource(R.string.offer_use_template), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable { showTemplates = true }.padding(vertical = 4.dp))
                if (details.trim().length >= DETAILS_MIN) Text(stringResource(R.string.offer_save_template), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable { savingTemplate = true }.padding(vertical = 4.dp))
            }
            templateNotice?.let { Text(it, color = Color(0xFF16A34A), fontSize = 12.sp) }
        }
        if (questions.isNotEmpty()) Card {
            FieldLabel(stringResource(R.string.offer_questions))
            questions.forEachIndexed { i, q ->
                Text("${i + 1}. $q", color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                DorrTextField(answers.getOrElse(i) { "" }, { if (i < answers.size) answers[i] = it.take(1000) }, placeholder = stringResource(R.string.offer_answer), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
            }
        }
        Card {
            FieldLabel(stringResource(R.string.offer_files))
            files.forEachIndexed { index, f ->
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Icon(Icons.Rounded.Description, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                    Text(f.name, color = settingsInk(), fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                    Icon(Icons.Rounded.Close, null, tint = settingsMut(), modifier = Modifier.size(28.dp).clip(RoundedCornerShape(50)).clickable { files.removeAt(index) }.padding(5.dp))
                }
            }
            if (files.size < MAX_OFFER_FILES) Row(
                Modifier.clip(RoundedCornerShape(12.dp)).clickable { picker.launch("*/*") }.padding(vertical = 6.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                Icon(Icons.Rounded.AttachFile, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                Text(stringResource(R.string.offer_add_file), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            }
        }
        if (checks.isNotEmpty()) Card {
            FieldLabel(stringResource(R.string.offer_checks_title))
            checks.forEach { c -> Text("•  " + c.message, color = if (c.level == "error") Color(0xFFDC2626) else Color(0xFFD97706), fontSize = 13.sp) }
        }
        SettingsPrimaryButton(
            text = stringResource(R.string.offer_send), loading = busy,
            enabled = parseMinor(price) != null && (duration.toIntOrNull() ?: 0) > 0 && details.trim().length >= DETAILS_MIN && checks.none { it.level == "error" } && answers.take(questions.size).all { it.trim().length >= 2 },
            onClick = ::send,
        )
        ErrorBanner(error, Modifier.fillMaxWidth())
    }

    if (showTemplates) androidx.compose.material3.AlertDialog(
        onDismissRequest = { showTemplates = false },
        title = { Text(stringResource(R.string.offer_templates)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                templates.forEach { t ->
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f).clip(RoundedCornerShape(10.dp)).clickable { details = t.details; showTemplates = false }.padding(6.dp)) {
                            Text(t.title, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                            Text(t.details, color = settingsMut(), fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
                        }
                        Icon(Icons.Rounded.Close, stringResource(R.string.offer_template_delete), tint = settingsMut(), modifier = Modifier.size(30.dp).clip(RoundedCornerShape(50)).clickable {
                            scope.launch { if (apiCall { ApiClient.offers.deleteTemplate(t.id) } is ApiResult.Ok) templates = templates.filter { it.id != t.id } }
                        }.padding(6.dp))
                    }
                }
            }
        },
        confirmButton = { androidx.compose.material3.TextButton(onClick = { showTemplates = false }) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
    )
    if (savingTemplate) {
        var name by remember { mutableStateOf("") }
        var saveError by remember { mutableStateOf<String?>(null) }
        val savedText = stringResource(R.string.offer_template_saved)
        androidx.compose.material3.AlertDialog(
            onDismissRequest = { savingTemplate = false },
            title = { Text(stringResource(R.string.offer_save_template)) },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    DorrTextField(name, { name = it.take(80) }, placeholder = stringResource(R.string.offer_template_name), modifier = Modifier.fillMaxWidth())
                    saveError?.let { Text(it, color = Color(0xFFDC2626), fontSize = 12.sp) }
                }
            },
            confirmButton = {
                androidx.compose.material3.TextButton(enabled = name.isNotBlank(), onClick = {
                    scope.launch {
                        val result = apiCall { ApiClient.offers.saveTemplate(com.dorr.freelance.net.OfferTemplateRequest(name.trim(), details.trim())) }
                        if (result is ApiResult.Ok) {
                            templates = listOf(result.data) + templates
                            templateNotice = savedText
                            savingTemplate = false
                        } else saveError = result.errorText(offlineText) ?: genericError
                    }
                }) { Text(stringResource(R.string.offer_template_keep), color = settingsAccent()) }
            },
            dismissButton = { androidx.compose.material3.TextButton(onClick = { savingTemplate = false }) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
        )
    }
}

/** All my offers, newest first (read when the page opens and when the connection returns). */
@Composable
fun MyOffersScreen(onBack: () -> Unit, onOpenProject: (String) -> Unit) {
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var offers by remember { mutableStateOf<List<OfferDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var filter by rememberSaveable { mutableStateOf("all") }

    suspend fun load() {
        val result = apiCall { ApiClient.offers.list(perPage = 50) }
        if (result is ApiResult.Ok) offers = result.data else error = result.errorText(offlineText) ?: genericError
    }
    LaunchedEffect(reconnect) { load() }
    // A notice arrived: one reload.
    LaunchedEffect(Unit) { com.dorr.freelance.net.Realtime.changed.collect { load() } }

    Page(title = stringResource(R.string.offer_my_offers), onBack = onBack) {
        val all = offers
        val list = all?.filter { filter == "all" || it.status == filter }
        if (all != null && all.isNotEmpty()) Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf("all", "submitted", "viewed", "shortlisted", "accepted", "rejected", "withdrawn").forEach { s ->
                val selected = filter == s
                Text(
                    if (s == "all") stringResource(R.string.projects_all) else offerStatusLabel(s),
                    color = if (selected) Color.White else settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(if (selected) settingsAccent() else settingsAccent().copy(alpha = 0.08f)).clickable { filter = s }.padding(horizontal = 14.dp, vertical = 7.dp),
                )
            }
        }
        when {
            all == null && error == null -> CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
            all != null && all.isEmpty() -> Text(stringResource(R.string.offer_none), color = settingsMut(), fontSize = 14.sp)
            list != null && list.isEmpty() -> Text(stringResource(R.string.offer_none_filtered), color = settingsMut(), fontSize = 14.sp)
            list != null -> list.forEach { o -> MyOfferCard(o) { o.project?.uuid?.let(onOpenProject) } }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

/** Changing an offer that is still open (FL-302): the price (with what it leaves me), the days and the details. What it said before is kept for the history. */
@Composable
fun EditOfferScreen(projectUuid: String, onBack: () -> Unit, onDone: () -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val badPrice = stringResource(R.string.offer_price_invalid)
    var offer by remember { mutableStateOf<OfferDto?>(null) }
    var price by rememberSaveable { mutableStateOf("") }
    var duration by rememberSaveable { mutableStateOf("") }
    var details by rememberSaveable { mutableStateOf("") }
    var breakdown by remember { mutableStateOf<OfferBreakdownDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(projectUuid) {
        val result = apiCall { ApiClient.offers.mine(projectUuid) }
        val current = (result as? ApiResult.Ok)?.data?.offer
        if (current != null) {
            offer = current
            price = formatMinor(current.priceMinor, null)
            duration = current.durationDays.toString()
            details = current.details
        } else error = (result as? ApiResult.Failed)?.let { result.errorText(offlineText) } ?: genericError
    }
    LaunchedEffect(price) {
        val minor = parseMinor(price) ?: run { breakdown = null; return@LaunchedEffect }
        delay(400)
        (apiCall { ApiClient.offers.preview(projectUuid, minor) } as? ApiResult.Ok)?.data?.breakdown?.let { breakdown = it }
    }

    Page(title = stringResource(R.string.offer_edit), onBack = onBack) {
        val current = offer
        if (current == null) {
            if (error == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
            ErrorBanner(error, Modifier.fillMaxWidth())
            return@Page
        }
        Card {
            FieldLabel(stringResource(R.string.offer_price))
            DorrTextField(price, { price = it }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth())
            breakdown?.let { b ->
                Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.08f)).padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(stringResource(R.string.offer_commission, "\u200E${formatMinor(b.commissionMinor, null)} ${b.currency}"), color = settingsMut(), fontSize = 12.sp)
                    Text(stringResource(R.string.offer_net, "\u200E${formatMinor(b.netMinor, null)} ${b.currency}"), color = settingsAccent(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                }
            }
            DorrTextField(duration, { duration = it.filter(Char::isDigit).take(3) }, label = stringResource(R.string.offer_duration), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
            DorrTextField(details, { details = it.take(3000) }, label = stringResource(R.string.offer_details), singleLine = false, minLines = 5, modifier = Modifier.fillMaxWidth())
            Text(stringResource(R.string.offer_details_count, details.trim().length, DETAILS_MIN), color = if (details.trim().length < DETAILS_MIN) settingsMut() else Color(0xFF16A34A), fontSize = 11.sp)
        }
        Text(stringResource(R.string.offer_edit_note), color = settingsMut(), fontSize = 12.sp)
        SettingsPrimaryButton(
            text = stringResource(R.string.offer_save_changes), loading = busy,
            enabled = parseMinor(price) != null && (duration.toIntOrNull() ?: 0) > 0 && details.trim().length >= DETAILS_MIN,
            onClick = {
                val minor = parseMinor(price) ?: run { error = badPrice; return@SettingsPrimaryButton }
                busy = true
                error = null
                scope.launch {
                    val result = apiCall { ApiClient.offers.update(current.uuid, com.dorr.freelance.net.OfferUpdateRequest(minor, duration.toInt(), details.trim())) }
                    busy = false
                    if (result is ApiResult.Ok) onDone() else error = result.errorText(offlineText) ?: genericError
                }
            },
        )
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}
