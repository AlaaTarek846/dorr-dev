package com.dorr.freelance.ui.screens

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.AgreementDto
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.DeclineRequest
import com.dorr.freelance.net.Realtime
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
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody

@Composable
internal fun agreementStatusText(status: String): String = stringResource(
    when (status) {
        "pending_approval" -> R.string.ag_st_pending
        "active" -> R.string.ag_st_active
        "declined" -> R.string.ag_st_declined
        "cancelled" -> R.string.ag_st_cancelled
        "expired" -> R.string.ag_st_expired
        "completed" -> R.string.ag_st_completed
        else -> R.string.ag_st_pending
    },
)

/**
 * The agreement the client sent on my chosen offer (FL-401): what the work covers, the price and what it leaves me after the
 * commission (as it was when the agreement was made), the review days and the free revisions. I approve it — the work starts — or
 * decline it with a reason. Read on open, when a notice arrives and when the connection comes back.
 */
@Composable
fun AgreementScreen(projectUuid: String, onBack: () -> Unit, onDispute: (milestone: String, dispute: String?) -> Unit = { _, _ -> }) {
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var agreement by remember { mutableStateOf<AgreementDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var declining by remember { mutableStateOf(false) }
    var reason by remember { mutableStateOf("") }
    // FL-403: the part being delivered, and its note.
    var deliverFor by remember { mutableStateOf<String?>(null) }
    // FL-506: stepping back from a part.
    var withdrawFor by remember { mutableStateOf<String?>(null) }

    // FL-601: a delivery carries files; it goes as one multipart request.
    fun deliver(uuid: String, text: String, files: List<PickedFile>) {
        busy = true
        error = null
        scope.launch {
            val plain = "text/plain".toMediaTypeOrNull()
            val parts = files.map { MultipartBody.Part.createFormData("files[]", it.name, it.bytes.toRequestBody(it.type.toMediaTypeOrNull())) }
            val result = apiCall { ApiClient.agreements.deliver(projectUuid, uuid, mapOf("note" to text.toRequestBody(plain)), parts) }
            if (result is ApiResult.Ok) { agreement = result.data; deliverFor = null } else error = result.errorText(offlineText) ?: genericError
            busy = false
        }
    }

    fun milestone(uuid: String, action: String, text: String? = null) {
        busy = true
        error = null
        scope.launch {
            val result = apiCall { ApiClient.agreements.milestone(projectUuid, uuid, action, com.dorr.freelance.net.MilestoneActionRequest(text)) }
            if (result is ApiResult.Ok) { agreement = result.data; deliverFor = null } else error = result.errorText(offlineText) ?: genericError
            busy = false
        }
    }

    suspend fun load() {
        val result = apiCall { ApiClient.agreements.show(projectUuid) }
        if (result is ApiResult.Ok) agreement = result.data else if (agreement == null) error = result.errorText(offlineText) ?: genericError
    }
    LaunchedEffect(projectUuid, reconnect) { load() }
    LaunchedEffect(projectUuid) { Realtime.changed.collect { load() } }

    fun act(approve: Boolean) {
        busy = true
        error = null
        scope.launch {
            val result = if (approve) apiCall { ApiClient.agreements.approve(projectUuid) } else apiCall { ApiClient.agreements.decline(projectUuid, DeclineRequest(reason.trim().ifBlank { null })) }
            if (result is ApiResult.Ok) {
                agreement = result.data
                notice = if (approve) null else null
                declining = false
            } else error = result.errorText(offlineText) ?: genericError
            busy = false
        }
    }

    Page(title = stringResource(R.string.ag_title), onBack = onBack) {
        val a = agreement
        if (a == null) {
            if (error == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
            ErrorBanner(error, Modifier.fillMaxWidth())
            return@Page
        }
        Card {
            Text(agreementStatusText(a.status), color = if (a.status == "active") Color(0xFF16A34A) else if (a.status == "pending_approval") Color(0xFFD97706) else Color(0xFFDC2626), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
            a.expiresAt?.let { Text(stringResource(R.string.ag_answer_by, "‎" + it.take(10)), color = settingsMut(), fontSize = 12.sp) }
        }
        Card {
            Line(stringResource(R.string.ag_price), "‎${formatMinor(a.money.priceMinor, null)} ${a.currency}")
            Line(stringResource(R.string.ag_commission), "‎${formatMinor(a.money.commissionMinor, null)} ${a.currency}")
            Line(stringResource(R.string.ag_net), "‎${formatMinor(a.money.netMinor, null)} ${a.currency}", strong = true)
            Line(stringResource(R.string.ag_days), stringResource(R.string.projects_days, a.durationDays))
            a.terms.reviewDays?.let { Line(stringResource(R.string.ag_review_days), stringResource(R.string.projects_days, it)) }
            a.terms.freeRevisions?.let { Line(stringResource(R.string.ag_free_revisions), it.toString()) }
        }
        // FL-402: the parts it is paid in.
        if (a.milestones.isNotEmpty()) Card {
            FieldLabel(stringResource(R.string.ag_parts))
            a.milestones.forEach { m ->
                Line("${m.position}. ${m.title}", "\u200E${formatMinor(m.amountMinor, null)} ${a.currency}")
                Text(stringResource(R.string.ag_part_net, "\u200E${formatMinor(m.netMinor, null)} ${a.currency}"), color = settingsMut(), fontSize = 11.sp)
                if (a.status == "active") Text(milestoneStatusText(m.status), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
                m.paidMinor?.let { Text(stringResource(R.string.ag_ms_paid, "\u200E${formatMinor(it, null)} ${a.currency}"), color = Color(0xFF16A34A), fontSize = 12.sp, fontWeight = FontWeight.Bold) }
                m.autoApproveAt?.let { Text(stringResource(R.string.ag_ms_auto, "\u200E" + it.take(10)), color = settingsMut(), fontSize = 11.sp) }
                m.revisionReason?.let { Text(stringResource(R.string.ag_ms_revision_reason, it), color = Color(0xFFD97706), fontSize = 12.sp) }
                ChecklistView(m)
                DeliveriesBlock(projectUuid, m)
                if (m.cancelRequestedBy == "client") Text(stringResource(R.string.ag_ms_cancel_asked), color = Color(0xFFD97706), fontSize = 12.sp)
                if (m.cancelRequestedBy == "freelancer") Text(stringResource(R.string.ag_ms_cancel_waiting), color = Color(0xFFD97706), fontSize = 12.sp)
                if (m.can.cancelAccept) Text(stringResource(R.string.ag_ms_cancel_accept), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(enabled = !busy) { milestone(m.uuid, "cancel-accept") }.padding(vertical = 6.dp))
                if (m.can.cancelReject) Text(stringResource(R.string.ag_ms_cancel_reject), color = Color(0xFFDC2626), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(enabled = !busy) { milestone(m.uuid, "cancel-reject") }.padding(vertical = 6.dp))
                // FL-704: open a dispute, or follow it.
                m.dispute?.let { dsp -> Text(stringResource(R.string.dp_see), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable { onDispute(m.uuid, dsp.uuid) }.padding(vertical = 6.dp)) }
                if (m.can.dispute && m.dispute?.status.let { it == null || it == "resolved" }) Text(stringResource(R.string.dp_open), color = Color(0xFFD97706), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable { onDispute(m.uuid, null) }.padding(vertical = 6.dp))
                if (m.can.withdraw) Text(stringResource(R.string.ag_ms_withdraw), color = Color(0xFFDC2626), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(enabled = !busy) { withdrawFor = m.uuid }.padding(vertical = 6.dp))
                if (m.can.start) Text(stringResource(R.string.ag_ms_start), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(enabled = !busy) { milestone(m.uuid, "start") }.padding(vertical = 6.dp))
                if (m.can.deliver) Text(stringResource(R.string.ag_ms_deliver), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(enabled = !busy) { deliverFor = m.uuid }.padding(vertical = 6.dp))
            }
        }
        // FL-511 / FL-406: my receipts of this project and its record of approvals.
        val recordsKey = a.status + ":" + a.milestones.joinToString(",") { it.status }
        ProjectReceiptsCard(projectUuid, recordsKey)
        ApprovalTrailCard(projectUuid, recordsKey)
        // FL-701: rating the client once it is completed.
        a.review?.let { rv ->
            ReviewClientCard(rv, busy) { body ->
                busy = true
                scope.launch {
                    val r = apiCall { ApiClient.agreements.review(projectUuid, body) }
                    if (r is ApiResult.Ok) load() else error = r.errorText(offlineText) ?: genericError
                    busy = false
                }
            }
        }
        // FL-404 / FL-405: changes after the agreement started.
        if (a.status == "active") AgreementChangesCard(
            changes = a.changes, milestones = a.milestones, currency = a.currency, busy = busy,
            onAsk = { body ->
                busy = true
                scope.launch {
                    val r = apiCall { ApiClient.agreements.requestChange(projectUuid, body) }
                    if (r is ApiResult.Ok) load() else error = r.errorText(offlineText) ?: genericError
                    busy = false
                }
            },
            onAnswer = { change, action ->
                busy = true
                scope.launch {
                    val r = apiCall { ApiClient.agreements.answerChange(projectUuid, change, action) }
                    if (r is ApiResult.Ok) load() else error = r.errorText(offlineText) ?: genericError
                    busy = false
                }
            },
        )
        Card {
            FieldLabel(stringResource(R.string.ag_scope))
            Text(a.scope.orEmpty(), color = settingsInk(), fontSize = 14.sp, lineHeight = 21.sp)
            a.includes?.let { FieldLabel(stringResource(R.string.ag_includes)); Text(it, color = settingsInk(), fontSize = 14.sp) }
            a.excludes?.let { FieldLabel(stringResource(R.string.ag_excludes)); Text(it, color = settingsInk(), fontSize = 14.sp) }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
        if (a.can.approve) SettingsPrimaryButton(text = stringResource(R.string.ag_approve), loading = busy && !declining, onClick = { act(true) })
        if (a.can.decline) Text(
            stringResource(R.string.ag_decline), color = Color(0xFFDC2626), fontSize = 14.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { declining = true }.padding(vertical = 10.dp),
        )
    }

    withdrawFor?.let { target ->
        AlertDialog(
            onDismissRequest = { if (!busy) withdrawFor = null },
            title = { Text(stringResource(R.string.ag_ms_withdraw)) },
            text = { Text(stringResource(R.string.ag_ms_withdraw_note), color = settingsMut(), fontSize = 13.sp) },
            confirmButton = { TextButton(enabled = !busy, onClick = { milestone(target, "withdraw"); withdrawFor = null }) { Text(stringResource(R.string.ag_ms_withdraw), color = Color(0xFFDC2626)) } },
            dismissButton = { TextButton(onClick = { withdrawFor = null }) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
        )
    }

    deliverFor?.let { target ->
        DeliverDialog(busy = busy, onDismiss = { deliverFor = null }, onSend = { text, files -> deliver(target, text, files) })
    }

    if (declining) AlertDialog(
        onDismissRequest = { if (!busy) declining = false },
        title = { Text(stringResource(R.string.ag_decline)) },
        text = {
            Column {
                Text(stringResource(R.string.ag_decline_hint), color = settingsMut(), fontSize = 13.sp)
                DorrTextField(reason, { reason = it.take(500) }, placeholder = stringResource(R.string.ag_reason), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth().padding(top = 8.dp))
            }
        },
        confirmButton = { TextButton(enabled = !busy, onClick = { act(false) }) { Text(stringResource(R.string.ag_decline), color = Color(0xFFDC2626)) } },
        dismissButton = { TextButton(onClick = { declining = false }) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
    )
}

@Composable
private fun Line(label: String, value: String, strong: Boolean = false) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = settingsMut(), fontSize = 13.sp, modifier = Modifier.weight(1f))
        Text(value, color = if (strong) settingsAccent() else settingsInk(), fontSize = if (strong) 16.sp else 14.sp, fontWeight = if (strong) FontWeight.ExtraBold else FontWeight.SemiBold)
    }
}

@Composable
internal fun milestoneStatusText(status: String): String = stringResource(
    when (status) {
        "funded" -> R.string.ag_mst_funded
        "in_progress" -> R.string.ag_mst_in_progress
        "delivered" -> R.string.ag_mst_delivered
        "revision" -> R.string.ag_mst_revision
        "approved" -> R.string.ag_mst_approved
        "disputed" -> R.string.ag_mst_disputed
        "cancelled" -> R.string.ag_mst_cancelled
        else -> R.string.ag_mst_unfunded
    },
)
