package com.dorr.freelance.ui.screens

import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.CloudUpload
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.ExitToApp
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Checklist
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Cancel
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Handshake
import com.dorr.freelance.ui.kit.settingsSurface
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.material3.Icon
import androidx.compose.material.icons.Icons
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.Box
import com.dorr.freelance.ui.components.SkeletonDetail
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
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
    var deliverFor by androidx.compose.runtime.saveable.rememberSaveable { mutableStateOf<String?>(null) }
    // FL-506: stepping back from a part.
    var withdrawFor by remember { mutableStateOf<String?>(null) }
    // FL-607: why I step back (a picked reason and a few words).
    var withdrawCode by remember { mutableStateOf<String?>(null) }
    var withdrawNote by remember { mutableStateOf("") }

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

    fun milestone(uuid: String, action: String, text: String? = null, reasonCode: String? = null) {
        busy = true
        error = null
        scope.launch {
            val result = apiCall { ApiClient.agreements.milestone(projectUuid, uuid, action, com.dorr.freelance.net.MilestoneActionRequest(text, reasonCode)) }
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

    var tab by androidx.compose.runtime.saveable.rememberSaveable { mutableIntStateOf(0) }

    Page(title = stringResource(R.string.ag_title), onBack = onBack) {
        val a = agreement
        if (a == null) {
            if (error == null) SkeletonDetail()
            ErrorBanner(error, Modifier.fillMaxWidth())
            return@Page
        }
        val tint = agreementColor(a.status)
        val live = a.milestones.filter { it.status != "cancelled" }
        val done = live.count { it.status == "approved" }
        val money = "\u200E" + formatMinor(a.money.priceMinor, null) + " " + a.currency

        // What it is and where it stands - with the answer buttons while it waits for me.
        Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(tint.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Handshake, contentDescription = null, tint = tint, modifier = Modifier.size(22.dp))
                }
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(stringResource(R.string.ag_title), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
                    Text(
                        agreementStatusText(a.status), color = tint, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(tint.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp),
                    )
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OfferStat(money, stringResource(R.string.ag_price), Modifier.weight(1f))
                OfferStat("\u200E" + formatMinor(a.money.netMinor, null) + " " + a.currency, stringResource(R.string.ag_net), Modifier.weight(1f))
                OfferStat(stringResource(R.string.projects_days, a.durationDays), stringResource(R.string.ag_days), Modifier.weight(1f))
            }
            if (live.isNotEmpty() && a.status != "pending_approval") {
                val fraction = done.toFloat() / live.size
                Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(stringResource(R.string.work_parts_done, done, live.size), color = settingsMut(), fontSize = 11.5.sp, modifier = Modifier.weight(1f))
                        Text("\u200E${(fraction * 100).toInt()}%", color = tint, fontSize = 11.5.sp, fontWeight = FontWeight.ExtraBold)
                    }
                    Box(Modifier.fillMaxWidth().height(6.dp).clip(RoundedCornerShape(999.dp)).background(settingsMut().copy(alpha = 0.18f))) {
                        Box(Modifier.fillMaxWidth(fraction).height(6.dp).clip(RoundedCornerShape(999.dp)).background(tint))
                    }
                }
            }
            a.expiresAt?.takeIf { a.status == "pending_approval" }?.let { Text(stringResource(R.string.ag_answer_by, "\u200E" + it.take(10)), color = settingsMut(), fontSize = 12.sp) }
            if (a.can.approve) ActionButton(stringResource(R.string.ag_approve), Icons.Rounded.CheckCircle, Tone.Primary, loading = busy && !declining) { act(true) }
            if (a.can.decline) ActionButton(stringResource(R.string.ag_decline), Icons.Rounded.Cancel, Tone.Danger, enabled = !busy) { declining = true }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())

        AgreementTabs(
            listOf(
                AgreementTab(Icons.Rounded.Description, stringResource(R.string.ag_tab_overview)),
                AgreementTab(Icons.Rounded.Checklist, stringResource(R.string.ag_tab_parts), a.milestones.size),
                AgreementTab(Icons.Rounded.History, stringResource(R.string.ag_tab_activity)),
            ),
            tab,
        ) { tab = it }

        val recordsKey = a.status + ":" + a.milestones.joinToString(",") { it.status }
        when (tab) {
            0 -> {
                OfferSection(stringResource(R.string.ag_terms)) {
                    Line(stringResource(R.string.ag_commission), "\u200E${formatMinor(a.money.commissionMinor, null)} ${a.currency}")
                    a.terms.reviewDays?.let { Line(stringResource(R.string.ag_review_days), stringResource(R.string.projects_days, it)) }
                    a.terms.freeRevisions?.let { Line(stringResource(R.string.ag_free_revisions), it.toString()) }
                }
                OfferSection(stringResource(R.string.ag_scope)) {
                    Text(a.scope.orEmpty(), color = settingsInk(), fontSize = 14.sp, lineHeight = 21.sp)
                    a.includes?.takeIf { it.isNotBlank() }?.let { FieldLabel(stringResource(R.string.ag_includes)); Text(it, color = settingsInk(), fontSize = 14.sp, lineHeight = 21.sp) }
                    a.excludes?.takeIf { it.isNotBlank() }?.let { FieldLabel(stringResource(R.string.ag_excludes)); Text(it, color = settingsInk(), fontSize = 14.sp, lineHeight = 21.sp) }
                }
            }
            1 -> {
                if (a.milestones.isEmpty()) Text(stringResource(R.string.ag_no_parts), color = settingsMut(), fontSize = 13.sp)
                // FL-402: the parts it is paid in.
                a.milestones.forEach { m ->
                    MilestoneCard(
                        m = m, currency = a.currency, active = a.status == "active", busy = busy, projectUuid = projectUuid,
                        onAction = { action -> milestone(m.uuid, action) },
                        onDeliver = { deliverFor = m.uuid },
                        onWithdraw = { withdrawCode = null; withdrawNote = ""; withdrawFor = m.uuid },
                        onDispute = { dsp -> onDispute(m.uuid, dsp) },
                    )
                }
            }
            else -> {
                // FL-605: progress reports (optional; the client may ask for one every few days).
                if (a.status == "active" || a.status == "completed") AgreementReportsCard(projectUuid, a.reports, a.can.report, recordsKey + ":" + a.reports.lastAt)
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
                // FL-511 / FL-406: my receipts of this project and its record of approvals.
                ProjectReceiptsCard(projectUuid, recordsKey)
                ApprovalTrailCard(projectUuid, recordsKey)
                // FL-701: rating the client once it is completed.
                a.review?.let { rv ->
                    ReviewClientCard(
                        rv, busy,
                        onObject = { body ->
                            busy = true
                            scope.launch {
                                val r = apiCall { ApiClient.agreements.objectToReview(projectUuid, body) }
                                if (r is ApiResult.Ok) load() else error = r.errorText(offlineText) ?: genericError
                                busy = false
                            }
                        },
                    ) { body ->
                        busy = true
                        scope.launch {
                            val r = apiCall { ApiClient.agreements.review(projectUuid, body) }
                            if (r is ApiResult.Ok) load() else error = r.errorText(offlineText) ?: genericError
                            busy = false
                        }
                    }
                }
                // FL-702: how satisfied, once it is over.
                if (a.status == "completed" || a.status == "cancelled") ProjectSurveyCard(projectUuid, recordsKey)
            }
        }
    }

    withdrawFor?.let { target ->
        FormSheet(Icons.Rounded.ExitToApp, stringResource(R.string.ag_ms_withdraw), stringResource(R.string.ag_ms_withdraw_note), onDismiss = { if (!busy) withdrawFor = null }) {
            CancelReasonPicker(withdrawCode, withdrawNote, { withdrawCode = it }, { withdrawNote = it })
            ActionButton(stringResource(R.string.ag_ms_withdraw), Icons.Rounded.ExitToApp, Tone.Danger, enabled = !busy && cancelReasonReady(withdrawCode, withdrawNote)) {
                milestone(target, "withdraw", withdrawNote.trim().ifEmpty { null }, withdrawCode)
                withdrawFor = null
            }
        }
    }

    deliverFor?.let { target ->
        DeliverDialog(busy = busy, error = error, onDismiss = { deliverFor = null }, onSend = { text, files -> deliver(target, text, files) })
    }

    if (declining) FormSheet(Icons.Rounded.Cancel, stringResource(R.string.ag_decline), stringResource(R.string.ag_decline_hint), onDismiss = { if (!busy) declining = false }) {
        DorrTextField(reason, { reason = it.take(500) }, label = stringResource(R.string.ag_reason), icon = Icons.Rounded.Notes, singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
        ErrorBanner(error, Modifier.fillMaxWidth())
        ActionButton(stringResource(R.string.ag_decline), Icons.Rounded.Cancel, Tone.Danger, enabled = !busy, loading = busy) { act(false) }
    }
}

/** One part of the work: its number, name and state, the money, what to do next, and its deliveries and checklist. */
@Composable
private fun MilestoneCard(
    m: com.dorr.freelance.net.MilestoneDto, currency: String, active: Boolean, busy: Boolean, projectUuid: String,
    onAction: (String) -> Unit, onDeliver: () -> Unit, onWithdraw: () -> Unit, onDispute: (String?) -> Unit,
) {
    val tint = milestoneColor(m.status)
    val amber = Color(0xFFD97706)
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(36.dp).clip(RoundedCornerShape(50)).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Text(m.position.toString(), color = tint, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(m.title, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2)
                Text("\u200E${formatMinor(m.amountMinor, null)} $currency  ·  " + stringResource(R.string.ag_part_net, "\u200E${formatMinor(m.netMinor, null)} $currency"), color = settingsMut(), fontSize = 11.5.sp)
            }
            if (active) Text(
                milestoneStatusText(m.status), color = tint, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(tint.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp),
            )
        }
        m.paidMinor?.let { Text(stringResource(R.string.ag_ms_paid, "\u200E${formatMinor(it, null)} $currency"), color = Color(0xFF16A34A), fontSize = 12.sp, fontWeight = FontWeight.Bold) }
        m.autoApproveAt?.let { Text(stringResource(R.string.ag_ms_auto, "\u200E" + it.take(10)), color = settingsMut(), fontSize = 11.sp) }
        m.revisionReason?.let {
            Text(stringResource(R.string.ag_ms_revision_reason, it), color = amber, fontSize = 12.sp, lineHeight = 18.sp, modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(amber.copy(alpha = 0.08f)).padding(10.dp))
        }
        ChecklistView(m)
        DeliveriesBlock(projectUuid, m)
        if (m.cancelRequestedBy == "client") Text(stringResource(R.string.ag_ms_cancel_asked), color = amber, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        if (m.cancelRequestedBy == "freelancer") Text(stringResource(R.string.ag_ms_cancel_waiting), color = amber, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        // What I can do with it now: the main step filled, the rest tinted.
        if (m.can.start) ActionButton(stringResource(R.string.ag_ms_start), Icons.Rounded.PlayArrow, Tone.Primary, enabled = !busy) { onAction("start") }
        if (m.can.deliver) ActionButton(stringResource(R.string.ag_ms_deliver), Icons.Rounded.CloudUpload, Tone.Primary, enabled = !busy) { onDeliver() }
        if (m.can.cancelAccept) ActionButton(stringResource(R.string.ag_ms_cancel_accept), Icons.Rounded.CheckCircle, Tone.Soft, enabled = !busy) { onAction("cancel-accept") }
        if (m.can.cancelReject) ActionButton(stringResource(R.string.ag_ms_cancel_reject), Icons.Rounded.Cancel, Tone.Danger, enabled = !busy) { onAction("cancel-reject") }
        // FL-704: open a dispute, or follow it.
        m.dispute?.let { dsp -> ActionButton(stringResource(R.string.dp_see), Icons.Rounded.Gavel, Tone.Soft) { onDispute(dsp.uuid) } }
        if (m.can.dispute && m.dispute?.status.let { it == null || it == "resolved" }) ActionButton(stringResource(R.string.dp_open), Icons.Rounded.Gavel, Tone.Warn) { onDispute(null) }
        if (m.can.withdraw) ActionButton(stringResource(R.string.ag_ms_withdraw), Icons.Rounded.ExitToApp, Tone.Danger, enabled = !busy) { onWithdraw() }
    }
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
