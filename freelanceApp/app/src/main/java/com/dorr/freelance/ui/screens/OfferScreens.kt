package com.dorr.freelance.ui.screens

import androidx.compose.material.icons.rounded.Apps
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material.icons.rounded.Cancel
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material.icons.rounded.QuestionAnswer
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.HourglassEmpty
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.VerifiedUser
import com.dorr.freelance.ui.components.SkeletonDetail
import com.dorr.freelance.ui.components.SkeletonList
import androidx.compose.material.icons.rounded.Chat
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.foundation.layout.Box
import androidx.compose.material.icons.rounded.LocalOffer
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
import com.dorr.freelance.net.PagedRows
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.MessageToast
import com.dorr.freelance.ui.components.FieldLabel
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.components.PagedFooter
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
fun OfferFormScreen(projectUuid: String, onBack: () -> Unit, onSent: () -> Unit, onOpenProfile: () -> Unit = {}) {
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
            if (error == null) SkeletonDetail()
            ErrorBanner(error, Modifier.fillMaxWidth())
            return@Page
        }
        p.blocked?.let { blocked ->
            // The server's reason in its own words, and nothing to fill in.
            val amber = Color(0xFFD97706)
            val icon = when {
                blocked.code.contains("verif") || blocked.code.contains("kyc") || blocked.code.contains("approved") -> androidx.compose.material.icons.Icons.Rounded.VerifiedUser
                blocked.code == "offer_already_sent" -> androidx.compose.material.icons.Icons.Rounded.CheckCircle
                blocked.code == "offer_quota_exhausted" -> androidx.compose.material.icons.Icons.Rounded.HourglassEmpty
                blocked.code == "project_invite_only" -> androidx.compose.material.icons.Icons.Rounded.Lock
                else -> androidx.compose.material.icons.Icons.Rounded.Block
            }
            Column(Modifier.fillMaxWidth().padding(top = 60.dp, start = 12.dp, end = 12.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Box(Modifier.size(104.dp).clip(RoundedCornerShape(50)).background(amber.copy(alpha = 0.10f)), contentAlignment = Alignment.Center) {
                    Box(Modifier.size(76.dp).clip(RoundedCornerShape(50)).background(amber.copy(alpha = 0.16f)), contentAlignment = Alignment.Center) {
                        Icon(icon, contentDescription = null, tint = amber, modifier = Modifier.size(38.dp))
                    }
                }
                androidx.compose.foundation.layout.Spacer(Modifier.size(22.dp))
                Text(stringResource(R.string.offer_blocked_title), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, textAlign = androidx.compose.ui.text.style.TextAlign.Center)
                androidx.compose.foundation.layout.Spacer(Modifier.size(10.dp))
                Text(blocked.message, color = settingsMut(), fontSize = 14.sp, lineHeight = 22.sp, textAlign = androidx.compose.ui.text.style.TextAlign.Center)
                androidx.compose.foundation.layout.Spacer(Modifier.size(24.dp))
                if (icon == androidx.compose.material.icons.Icons.Rounded.VerifiedUser) SettingsPrimaryButton(text = stringResource(R.string.offer_go_profile), onClick = onOpenProfile)
                else SettingsPrimaryButton(text = stringResource(R.string.common_back), onClick = onBack)
            }
            return@Page
        }

        // The same look as the user app's forms: a short intro, then fields with an icon each.
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(52.dp).clip(RoundedCornerShape(16.dp)).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.LocalOffer, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(26.dp))
            }
            androidx.compose.foundation.layout.Spacer(Modifier.size(12.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(stringResource(R.string.offer_form_intro_title), color = settingsInk(), fontSize = 19.sp, fontWeight = FontWeight.ExtraBold)
                Text(stringResource(R.string.offer_form_intro), color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp)
            }
        }
        p.quota.left?.let { left ->
            Text(
                stringResource(R.string.offer_quota_left, left, p.quota.limit ?: left), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.10f)).padding(horizontal = 12.dp, vertical = 5.dp),
            )
        }
        DorrTextField(price, { price = it }, label = stringResource(R.string.offer_price), placeholder = "0.00", icon = Icons.Rounded.Payments, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth())
        p.minPriceMinor?.let { Text(stringResource(R.string.offer_price_min, "\u200E${formatMinor(it, null)}"), color = settingsMut(), fontSize = 12.sp) }
        breakdown?.let { b ->
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(settingsAccent().copy(alpha = 0.08f)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(stringResource(R.string.offer_commission, "\u200E${formatMinor(b.commissionMinor, null)} ${b.currency}"), color = settingsMut(), fontSize = 12.sp)
                Text(stringResource(R.string.offer_net, "\u200E${formatMinor(b.netMinor, null)} ${b.currency}"), color = settingsAccent(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
            }
        }
        DorrTextField(duration, { duration = it.filter(Char::isDigit).take(3) }, label = stringResource(R.string.offer_duration), icon = Icons.Rounded.Schedule, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
        DorrTextField(details, { details = it.take(3000) }, label = stringResource(R.string.offer_details), placeholder = stringResource(R.string.offer_details_hint), icon = Icons.Rounded.Notes, singleLine = false, minLines = 5, modifier = Modifier.fillMaxWidth())
        Text(stringResource(R.string.offer_details_count, details.trim().length, DETAILS_MIN), color = if (details.trim().length < DETAILS_MIN) settingsMut() else Color(0xFF16A34A), fontSize = 11.5.sp)
        // FL-304: start from a kept text, or keep this one.
        if (templates.isNotEmpty() || details.trim().length >= DETAILS_MIN) Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            if (templates.isNotEmpty()) Text(stringResource(R.string.offer_use_template), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.10f)).clickable { showTemplates = true }.padding(horizontal = 14.dp, vertical = 7.dp))
            if (details.trim().length >= DETAILS_MIN) Text(stringResource(R.string.offer_save_template), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.10f)).clickable { savingTemplate = true }.padding(horizontal = 14.dp, vertical = 7.dp))
        }
        MessageToast(templateNotice)
        if (questions.isNotEmpty()) {
            FieldLabel(stringResource(R.string.offer_questions))
            questions.forEachIndexed { i, q ->
                DorrTextField(answers.getOrElse(i) { "" }, { if (i < answers.size) answers[i] = it.take(1000) }, label = "${i + 1}. $q", placeholder = stringResource(R.string.offer_answer), icon = Icons.Rounded.QuestionAnswer, singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
            }
        }
        FieldLabel(stringResource(R.string.offer_files))
        Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            files.forEachIndexed { index, f ->
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Icon(Icons.Rounded.Description, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                    Text(f.name, color = settingsInk(), fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                    Icon(Icons.Rounded.Close, null, tint = settingsMut(), modifier = Modifier.size(28.dp).clip(RoundedCornerShape(50)).clickable { files.removeAt(index) }.padding(5.dp))
                }
            }
            if (files.size < MAX_OFFER_FILES) Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.08f)).clickable { picker.launch("*/*") }.padding(vertical = 12.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp, Alignment.CenterHorizontally),
            ) {
                Icon(Icons.Rounded.AttachFile, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                Text(stringResource(R.string.offer_add_file), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            }
        }
        if (checks.isNotEmpty()) Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Color(0xFFD97706).copy(alpha = 0.08f)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(stringResource(R.string.offer_checks_title), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
            checks.forEach { c -> Text("•  " + c.message, color = if (c.level == "error") Color(0xFFDC2626) else Color(0xFFD97706), fontSize = 13.sp, lineHeight = 19.sp) }
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

/** All my offers, newest first, fifteen at a time while I scroll (read when the page opens, when a notice arrives and when the connection returns). */
@Composable
fun MyOffersScreen(onBack: (() -> Unit)?, onOpenProject: (String) -> Unit, asTab: Boolean = false) {
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val paged = remember { PagedRows<OfferDto>(15, { it.uuid }, { page -> apiCall { ApiClient.offers.list(page = page, perPage = 15) } }) }
    val scope = rememberCoroutineScope()
    var filter by rememberSaveable { mutableStateOf("all") }

    LaunchedEffect(reconnect) { paged.reload() }
    // A notice arrived: one reload of the first page.
    LaunchedEffect(Unit) { com.dorr.freelance.net.Realtime.changed.collect { paged.reload() } }

    val nearEnd = { scope.launch { paged.loadMore() }; Unit }
    val body: @Composable () -> Unit = {
        val all = if (paged.loaded) paged.rows.toList() else null
        val list = all?.filter { filter == "all" || it.status == filter }
        if (all != null && all.isNotEmpty()) Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf("all", "submitted", "viewed", "shortlisted", "accepted", "rejected", "withdrawn").forEach { s ->
                val selected = filter == s
                val ink = if (selected) Color.White else settingsInk()
                Row(
                    Modifier.clip(RoundedCornerShape(999.dp)).background(if (selected) settingsAccent() else settingsAccent().copy(alpha = 0.08f)).clickable { filter = s }.padding(horizontal = 14.dp, vertical = 8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(
                        when (s) {
                            "submitted" -> Icons.Rounded.Send
                            "viewed" -> Icons.Rounded.Visibility
                            "shortlisted" -> Icons.Rounded.Star
                            "accepted" -> Icons.Rounded.CheckCircle
                            "rejected" -> Icons.Rounded.Cancel
                            "withdrawn" -> Icons.Rounded.Undo
                            else -> Icons.Rounded.Apps
                        },
                        contentDescription = null, tint = if (selected) ink else settingsAccent(), modifier = Modifier.size(15.dp),
                    )
                    androidx.compose.foundation.layout.Spacer(Modifier.size(6.dp))
                    Text(if (s == "all") stringResource(R.string.projects_all) else offerStatusLabel(s), color = ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1, softWrap = false)
                }
            }
        }
        when {
            all == null && paged.failure == null -> SkeletonList()
            all != null && all.isEmpty() && paged.failure == null -> com.dorr.freelance.ui.components.EmptyState(
                icon = androidx.compose.material.icons.Icons.Rounded.LocalOffer,
                title = stringResource(R.string.offer_empty_title),
                body = stringResource(R.string.offer_empty_body),
                modifier = Modifier.padding(top = 90.dp),
            )
            list != null && list.isEmpty() && !paged.hasMore -> Text(stringResource(R.string.offer_none_filtered), color = settingsMut(), fontSize = 14.sp)
            list != null -> list.forEach { o -> OfferListCard(o) { o.project?.uuid?.let(onOpenProject) } }
        }
        PagedFooter(paged) { scope.launch { paged.loadMore() } }
        ErrorBanner(paged.failure?.let { it.errorText(offlineText) ?: genericError }, Modifier.fillMaxWidth())
    }
    if (asTab) com.dorr.freelance.ui.components.TabPage(title = stringResource(R.string.offer_my_offers), onBack = onBack, onNearEnd = nearEnd, content = body)
    else Page(title = stringResource(R.string.offer_my_offers), onBack = onBack ?: {}, onNearEnd = nearEnd, content = body)
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
            if (error == null) SkeletonDetail()
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

/** An offer in my offers: small and easy to scan - what it is on, its state, the price and the time; the whole card opens the offer's page. */
@Composable
internal fun OfferListCard(offer: OfferDto, onClick: () -> Unit) {
    val tint = offerStatusColor(offer.status)
    Row(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(16.dp)).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(40.dp).clip(RoundedCornerShape(12.dp)).background(tint.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(androidx.compose.material.icons.Icons.Rounded.LocalOffer, contentDescription = null, tint = tint, modifier = Modifier.size(20.dp))
        }
        androidx.compose.foundation.layout.Spacer(Modifier.size(10.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(offer.project?.title ?: stringResource(R.string.offer_mine), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text("\u200E${formatMinor(offer.priceMinor, null)} ${offer.currency}  ·  " + stringResource(R.string.projects_days, offer.durationDays), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 1)
            offer.createdAt?.take(10)?.let { Text("\u200E$it", color = settingsMut(), fontSize = 11.sp) }
        }
        androidx.compose.foundation.layout.Spacer(Modifier.size(8.dp))
        Text(
            offerStatusLabel(offer.status), color = tint, fontSize = 11.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(tint.copy(alpha = 0.12f)).padding(horizontal = 9.dp, vertical = 3.dp),
        )
        Icon(androidx.compose.material.icons.Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(20.dp))
    }
}

/**
 * One offer of mine with everything, in sections: what it is on and where it stands, the money and the time, the details, my answers, the
 * files, where it has been, the agreement and the chat when there are, changing or withdrawing it while that is allowed - and a button to the project.
 */
@Composable
fun OfferDetailScreen(
    projectUuid: String,
    onBack: () -> Unit,
    onOpenProject: () -> Unit,
    onEdit: () -> Unit,
    onChat: (String) -> Unit,
    onAgreement: () -> Unit,
) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var offer by remember { mutableStateOf<OfferDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var confirmWithdraw by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var history by remember { mutableStateOf<List<com.dorr.freelance.net.OfferRevisionDto>?>(null) }
    var showHistory by remember { mutableStateOf(false) }

    suspend fun load() {
        val result = apiCall { ApiClient.offers.mine(projectUuid) }
        val current = (result as? ApiResult.Ok)?.data?.offer
        if (current != null) { offer = current; error = null } else error = result.errorText(offlineText) ?: genericError
    }
    LaunchedEffect(reconnect, projectUuid) { load() }
    LaunchedEffect(projectUuid) { com.dorr.freelance.net.Realtime.changed.collect { load() } }

    Page(title = stringResource(R.string.offer_detail_title), onBack = onBack) {
        val o = offer
        if (o == null) {
            if (error == null) SkeletonDetail()
        } else {
            val tint = offerStatusColor(o.status)
            val money = "\u200E" + formatMinor(o.priceMinor, null) + " " + o.currency

            // The offer and where it stands.
            Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(tint.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                        Icon(androidx.compose.material.icons.Icons.Rounded.LocalOffer, contentDescription = null, tint = tint, modifier = Modifier.size(22.dp))
                    }
                    androidx.compose.foundation.layout.Spacer(Modifier.size(12.dp))
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(o.project?.title ?: stringResource(R.string.offer_mine), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                        Text(
                            offerStatusLabel(o.status), color = tint, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(tint.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp),
                        )
                    }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OfferStat(money, stringResource(R.string.offer_stat_price), Modifier.weight(1f))
                    OfferStat("\u200E" + formatMinor(o.netMinor, null) + " " + o.currency, stringResource(R.string.offer_stat_net), Modifier.weight(1f))
                    OfferStat(stringResource(R.string.projects_days, o.durationDays), stringResource(R.string.offer_stat_time), Modifier.weight(1f))
                }
                o.createdAt?.take(10)?.let { Text(stringResource(R.string.offer_sent_on, "\u200E$it"), color = settingsMut(), fontSize = 11.5.sp) }
            }

            o.statusReason?.takeIf { it.isNotBlank() }?.let { reason ->
                Text(
                    stringResource(R.string.offer_reject_reason, reason), color = Color(0xFFDC2626), fontSize = 13.sp, lineHeight = 19.sp,
                    modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Color(0xFFDC2626).copy(alpha = 0.08f)).padding(14.dp),
                )
            }

            OfferSection(stringResource(R.string.offer_section_details)) {
                Text(o.details, color = settingsInk(), fontSize = 13.5.sp, lineHeight = 21.sp)
            }

            if (o.answers.isNotEmpty()) OfferSection(stringResource(R.string.offer_section_answers)) {
                o.answers.forEach { a ->
                    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text(a.question, color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                        Text(a.answer?.takeIf { it.isNotBlank() } ?: "—", color = settingsInk(), fontSize = 13.5.sp, lineHeight = 20.sp)
                    }
                }
            }

            if (o.attachments.isNotEmpty()) OfferSection(stringResource(R.string.offer_attached_files)) {
                o.attachments.forEach { f ->
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(androidx.compose.material.icons.Icons.Rounded.AttachFile, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                        androidx.compose.foundation.layout.Spacer(Modifier.size(8.dp))
                        Text(f.name, color = settingsInk(), fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }

            // Where it has been: sent, seen, shortlisted … with the day, on a line.
            val steps = o.timeline.mapNotNull { e ->
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
                label?.let { it to e.at?.take(10).orEmpty() }
            }
            if (steps.isNotEmpty()) OfferSection(stringResource(R.string.offer_section_timeline)) {
                steps.forEachIndexed { index, (label, day) ->
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(10.dp).clip(RoundedCornerShape(999.dp)).background(if (index == steps.lastIndex) tint else settingsMut().copy(alpha = 0.35f)))
                        androidx.compose.foundation.layout.Spacer(Modifier.size(12.dp))
                        Text(stringResource(label), color = settingsInk(), fontSize = 13.sp, fontWeight = if (index == steps.lastIndex) FontWeight.Bold else FontWeight.Medium, modifier = Modifier.weight(1f))
                        Text("\u200E$day", color = settingsMut(), fontSize = 11.5.sp)
                    }
                }
                if (o.revisions > 0) Text(
                    stringResource(R.string.offer_edited_n, o.revisions), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable {
                        showHistory = !showHistory
                        if (history == null) scope.launch { (apiCall { ApiClient.offers.revisions(o.uuid) } as? ApiResult.Ok)?.data?.let { history = it } }
                    }.padding(vertical = 4.dp),
                )
                if (showHistory) history?.forEach { r ->
                    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsMut().copy(alpha = 0.08f)).padding(10.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text(stringResource(R.string.offer_revision, r.number, "\u200E${formatMinor(r.priceMinor, null)} ${o.currency}", r.durationDays), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                        Text(r.details, color = settingsMut(), fontSize = 12.sp, maxLines = 3, overflow = TextOverflow.Ellipsis)
                    }
                }
            }

            // The agreement and the chat, as rows that open them.
            o.agreement?.let { ag ->
                OfferLinkRow(
                    androidx.compose.material.icons.Icons.Rounded.Description,
                    stringResource(R.string.ag_title), agreementStatusText(ag.status),
                    if (ag.status == "pending_approval") Color(0xFFD97706) else settingsAccent(), onAgreement,
                )
            }
            o.chat?.let { room ->
                OfferLinkRow(
                    androidx.compose.material.icons.Icons.Rounded.Chat,
                    stringResource(R.string.chat_open), if (room.unread > 0) room.unread.toString() else "", settingsAccent(),
                ) { onChat(room.uuid) }
            }

            SettingsPrimaryButton(text = stringResource(R.string.offer_view_project), onClick = onOpenProject)
            if (o.can.edit) Text(
                stringResource(R.string.offer_edit), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.10f)).clickable(onClick = onEdit).padding(vertical = 13.dp),
                textAlign = androidx.compose.ui.text.style.TextAlign.Center,
            )
            if (o.can.withdraw) Text(
                stringResource(R.string.offer_withdraw), color = Color(0xFFDC2626), fontSize = 14.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Color(0xFFDC2626).copy(alpha = 0.08f)).clickable { confirmWithdraw = true }.padding(vertical = 13.dp),
                textAlign = androidx.compose.ui.text.style.TextAlign.Center,
            )
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }

    if (confirmWithdraw) androidx.compose.material3.AlertDialog(
        onDismissRequest = { if (!busy) confirmWithdraw = false },
        text = { Text(stringResource(R.string.offer_withdraw_confirm)) },
        confirmButton = {
            androidx.compose.material3.TextButton(onClick = {
                val current = offer ?: return@TextButton
                busy = true
                scope.launch {
                    val result = apiCall { ApiClient.offers.withdraw(current.uuid) }
                    busy = false
                    confirmWithdraw = false
                    if (result is ApiResult.Ok) offer = result.data else error = result.errorText(offlineText) ?: genericError
                }
            }) { Text(stringResource(R.string.offer_withdraw), color = Color(0xFFDC2626)) }
        },
        dismissButton = { androidx.compose.material3.TextButton(onClick = { confirmWithdraw = false }) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
    )
}

/** A titled card of the offer page. */
@Composable
internal fun OfferSection(title: String, content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Text(title, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
        content()
    }
}

/** One number of the offer (the price, what it leaves me, the time) on a soft tile. */
@Composable
internal fun OfferStat(value: String, label: String, modifier: Modifier = Modifier) {
    Column(
        modifier.clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.08f)).padding(horizontal = 6.dp, vertical = 10.dp),
        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(3.dp),
    ) {
        Text(value, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, textAlign = androidx.compose.ui.text.style.TextAlign.Center)
        Text(label, color = settingsMut(), fontSize = 10.5.sp, maxLines = 1)
    }
}

/** A row that opens the agreement or the chat of the offer. */
@Composable
internal fun OfferLinkRow(icon: androidx.compose.ui.graphics.vector.ImageVector, title: String, trailing: String, tint: Color, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(16.dp)).clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(36.dp).clip(RoundedCornerShape(11.dp)).background(tint.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(19.dp))
        }
        androidx.compose.foundation.layout.Spacer(Modifier.size(12.dp))
        Text(title, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
        if (trailing.isNotEmpty()) Text(trailing, color = tint, fontSize = 12.sp, fontWeight = FontWeight.Bold)
        Icon(androidx.compose.material.icons.Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(20.dp))
    }
}
