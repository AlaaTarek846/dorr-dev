package com.dorr.app.ui.screens.freelance

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelanceAgreementDto
import com.dorr.app.network.FreelanceAgreementRequest
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.ConfirmDialog
import com.dorr.app.ui.screens.profile.SettingsPrimaryButton
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

@Composable
internal fun agreementStatusLabel(status: String): String = stringResource(
    when (status) {
        "pending_approval" -> R.string.fl_ag_st_pending
        "active" -> R.string.fl_ag_st_active
        "declined" -> R.string.fl_ag_st_declined
        "cancelled" -> R.string.fl_ag_st_cancelled
        "expired" -> R.string.fl_ag_st_expired
        "completed" -> R.string.fl_ag_st_completed
        else -> R.string.fl_ag_st_draft
    },
)

internal fun agreementStatusColor(status: String): Color = when (status) {
    "active", "completed" -> Color(0xFF16A34A)
    "pending_approval" -> Color(0xFFD97706)
    "declined", "cancelled", "expired" -> Color(0xFFDC2626)
    else -> Color(0xFF6B7280)
}

/**
 * The agreement with the chosen freelancer (FL-401): the price and the days come from the offer and the terms from the policy as it
 * was; the client writes what the work covers, what it includes and what it doesn't, and sends it — which is their approval. The
 * freelancer approves it (the work starts) or declines it (the client chooses again). Read once on open and on reconnect.
 */
@Composable
internal fun AgreementPage(projectUuid: String, onBack: () -> Unit, onEnded: () -> Unit, onDispute: (milestone: String, dispute: String?) -> Unit = { _, _ -> }) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var agreement by remember { mutableStateOf<FreelanceAgreementDto?>(null) }
    var scopeText by rememberSaveable { mutableStateOf("") }
    var includes by rememberSaveable { mutableStateOf("") }
    var excludes by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var confirmCancel by remember { mutableStateOf(false) }
    // FL-403: the part the client is sending back, and why.
    var revisionFor by remember { mutableStateOf<String?>(null) }
    var revisionReason by remember { mutableStateOf("") }
    // FL-501: the part being funded (the confirm sheet).
    var fundFor by remember { mutableStateOf<com.dorr.app.network.FreelanceMilestoneDto?>(null) }
    // FL-506: the part being called off (the sheet says what comes back).
    var cancelFor by remember { mutableStateOf<com.dorr.app.network.FreelanceMilestoneDto?>(null) }
    var reloads by remember { mutableStateOf(0) }
    // FL-402: the parts, while the client splits them (title, amount as typed).
    var splitting by remember { mutableStateOf(false) }
    val parts = remember { androidx.compose.runtime.mutableStateListOf<Pair<String, String>>() }

    fun take(a: FreelanceAgreementDto?) {
        agreement = a
        if (a != null) {
            scopeText = a.scope.orEmpty()
            includes = a.includes.orEmpty()
            excludes = a.excludes.orEmpty()
        }
    }

    LaunchedEffect(projectUuid, reconnect, reloads) {
        try {
            take(ApiClient.freelance.agreement(auth(), projectUuid).data)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    fun act(block: suspend () -> FreelanceAgreementDto?, after: (() -> Unit)? = null) {
        busy = true
        error = null
        notice = null
        scope.launch {
            try {
                take(block())
                after?.invoke()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.message(generic)
            }
            busy = false
        }
    }

    val a0 = agreement
    val changed = a0 != null && (scopeText.trim() != a0.scope.orEmpty() || includes.trim() != a0.includes.orEmpty() || excludes.trim() != a0.excludes.orEmpty())
    fun body() = FreelanceAgreementRequest(scopeText.trim(), includes.trim().ifBlank { null }, excludes.trim().ifBlank { null })

    Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.fl_agreement), onBack)
        val a = agreement
        if (a == null) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                if (error != null) Text(error.orEmpty(), color = settingsMut(), fontSize = 14.sp, modifier = Modifier.padding(24.dp))
                else CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
            }
            return@Column
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Card {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(a.freelancer?.name ?: "—", color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                    Text(agreementStatusLabel(a.status), color = agreementStatusColor(a.status), fontSize = 12.sp, fontWeight = FontWeight.Bold)
                }
                Text(stringResource(agreementHint(a.status)), color = settingsMut(), fontSize = 12.sp)
                a.expiresAt?.let { Text(stringResource(R.string.fl_ag_answer_by, "‎" + it.take(10)), color = settingsMut(), fontSize = 12.sp) }
            }
            Card {
                Field(stringResource(R.string.fl_price), "‎${money(a.money.priceMinor)} ${a.currency}")
                if (a.money.clientFeeMinor > 0) Field(stringResource(R.string.fl_client_fee), "‎${money(a.money.clientFeeMinor)} ${a.currency}")
                Field(stringResource(R.string.fl_total), "‎${money(a.money.totalMinor)} ${a.currency}")
                // FL-510: the split made the total differ from the one told before choosing (a minimum fee on each part).
                a.money.quotedTotalMinor?.takeIf { it != a.money.totalMinor }?.let { q ->
                    Text(stringResource(R.string.fl_quoted_total, "‎${money(q)} ${a.currency}"), color = settingsMut(), fontSize = 12.sp)
                }
                Field(stringResource(R.string.fl_duration), stringResource(R.string.fl_days, a.durationDays))
                a.terms.reviewDays?.let { Field(stringResource(R.string.fl_ag_review_days), stringResource(R.string.fl_days, it)) }
                a.terms.freeRevisions?.let { Field(stringResource(R.string.fl_ag_free_revisions), it.toString()) }
            }
            Card {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Label(stringResource(R.string.fl_ms_title))
                    Spacer(Modifier.weight(1f))
                    if (a.can.split && !splitting) TextLink(stringResource(if (a.milestones.size > 1) R.string.fl_ms_edit else R.string.fl_ms_split)) {
                        parts.clear()
                        parts.addAll(a.milestones.map { it.title to money(it.amountMinor) })
                        if (parts.size < 2 && a.milestoneRules.maxCount > 1) {
                            parts.clear()
                            val half = a.priceMinor / 2
                            parts.add("" to money(half))
                            parts.add("" to money(a.priceMinor - half))
                        }
                        splitting = true
                    }
                }
                if (!splitting) a.milestones.forEach { m ->
                    Column(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text("${m.position}. ${m.title}", color = settingsInk(), fontSize = 14.sp, modifier = Modifier.weight(1f))
                            Text("\u200E${money(m.amountMinor)} ${a.currency}", color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                        }
                        if (a.status == "active") Text(milestoneStatusLabel(m.status) + if (m.heldMinor > 0) "  ·  " + stringResource(R.string.fl_held, "\u200E${money(m.heldMinor)} ${a.currency}") else "", color = milestoneStatusColor(m.status), fontSize = 12.sp, fontWeight = FontWeight.Bold)
                        // FL-602 / FL-604: the versions delivered (opened in place) and the checklist for the part.
                        if (a.status == "active" || a.status == "completed") DeliveryReview(projectUuid, m)
                        // FL-407: the free rounds of changes.
                        if (m.status == "delivered" && m.revisionsMax != null && m.revisionsMax > 0) Text(
                            stringResource(if ((m.revisionsLeft ?: 0) > 0) R.string.fl_ms_rounds_left else R.string.fl_ms_rounds_none, m.revisionsLeft ?: 0, m.revisionsMax),
                            color = settingsMut(), fontSize = 11.sp,
                        )
                        // FL-506: a request to end a started part waits for an answer; what went back after it ended.
                        if (m.cancelRequestedBy == "freelancer") Text(stringResource(R.string.fl_ms_cancel_asked_them), color = Color(0xFFD97706), fontSize = 12.sp)
                        if (m.cancelRequestedBy == "client") Text(stringResource(R.string.fl_ms_cancel_asked_you), color = Color(0xFFD97706), fontSize = 12.sp)
                        if (m.status == "cancelled" && (m.refundedMinor ?: 0L) > 0) Text(stringResource(R.string.fl_ms_refunded, "\u200E${money(m.refundedMinor ?: 0L)} ${a.currency}"), color = Color(0xFF16A34A), fontSize = 12.sp)
                        m.autoApproveAt?.let { Text(stringResource(R.string.fl_auto_approve_on, "\u200E" + it.take(10)), color = Color(0xFFD97706), fontSize = 11.sp) }
                        Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                            if (m.can.fund) TextLink(stringResource(R.string.fl_fund)) { fundFor = m }
                            if (m.can.approve) TextLink(stringResource(R.string.fl_ms_approve)) { act({ ApiClient.freelance.milestoneAction(auth(), projectUuid, m.uuid, "approve", com.dorr.app.network.FreelanceDecisionRequest()).data }) }
                            if (m.can.revision) TextLink(stringResource(R.string.fl_ms_revision)) { revisionFor = m.uuid; revisionReason = "" }
                            if (m.can.cancel) TextLink(stringResource(if (m.status == "unfunded") R.string.fl_ms_cancel else R.string.fl_ms_call_off)) { cancelFor = m }
                            if (m.can.cancelAccept) TextLink(stringResource(R.string.fl_ms_cancel_accept)) { act({ ApiClient.freelance.milestoneAction(auth(), projectUuid, m.uuid, "cancel-accept", com.dorr.app.network.FreelanceDecisionRequest()).data }) }
                            // FL-704: open a dispute, or see it.
                            m.dispute?.let { dsp -> TextLink(stringResource(R.string.fl_dispute_see)) { onDispute(m.uuid, dsp.uuid) } }
                            if (m.can.dispute && m.dispute?.status.let { it == null || it == "resolved" }) TextLink(stringResource(R.string.fl_dispute_open)) { onDispute(m.uuid, null) }
                            if (m.can.cancelReject) TextLink(stringResource(R.string.fl_ms_cancel_reject)) { act({ ApiClient.freelance.milestoneAction(auth(), projectUuid, m.uuid, "cancel-reject", com.dorr.app.network.FreelanceDecisionRequest()).data }) }
                        }
                    }
                } else {
                    val sum = parts.sumOf { parseMoney(it.second) ?: 0L }
                    val left = a.priceMinor - sum
                    val minPart = a.milestoneRules.minAmountMinor
                    parts.forEachIndexed { i, p ->
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            DorrTextField(p.first, { parts[i] = it.take(120) to p.second }, placeholder = stringResource(R.string.fl_ms_part, i + 1), modifier = Modifier.weight(1.4f))
                            DorrTextField(p.second, { parts[i] = p.first to it.take(12) }, placeholder = "0.00", keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(keyboardType = androidx.compose.ui.text.input.KeyboardType.Decimal), modifier = Modifier.weight(1f))
                            if (parts.size > 1) androidx.compose.material3.Icon(Icons.Rounded.Close, stringResource(R.string.fl_remove), tint = settingsMut(), modifier = Modifier.size(28.dp).clickable { parts.removeAt(i) }.padding(5.dp))
                        }
                    }
                    if (parts.size < a.milestoneRules.maxCount) TextLink(stringResource(R.string.fl_ms_add)) { parts.add("" to money(left.coerceAtLeast(0))) }
                    Text(
                        if (left == 0L) stringResource(R.string.fl_ms_balanced) else stringResource(R.string.fl_ms_left, "\u200E${money(left)} ${a.currency}"),
                        color = if (left == 0L) Color(0xFF16A34A) else Color(0xFFD97706), fontSize = 12.sp, fontWeight = FontWeight.Bold,
                    )
                    minPart?.let { Text(stringResource(R.string.fl_ms_rules, a.milestoneRules.maxCount, "\u200E${money(it)} ${a.currency}"), color = settingsMut(), fontSize = 11.sp) }
                    Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                        TextLink(stringResource(R.string.fl_ms_save)) {
                            val items = parts.mapIndexed { i, p -> com.dorr.app.network.FreelanceMilestoneInput(p.first.trim().ifBlank { "${i + 1}" }, parseMoney(p.second) ?: 0L) }
                            act({ ApiClient.freelance.setMilestones(auth(), projectUuid, com.dorr.app.network.FreelanceMilestonesRequest(items)).data }, after = { splitting = false })
                        }
                        TextLink(stringResource(R.string.fl_ms_cancel_edit)) { splitting = false }
                    }
                }
            }
            // FL-511 / FL-406: the receipts of this project and its record of approvals.
            val recordsKey = "${a.status}:${a.version}:" + a.milestones.joinToString(",") { it.status }
            ReceiptsCard(projectUuid, recordsKey)
            TrailCard(projectUuid, recordsKey)
            // FL-701: rating the freelancer once it is completed.
            a.review?.let { rv -> ReviewCard(rv, busy) { body -> act({ ApiClient.freelance.review(auth(), projectUuid, body); ApiClient.freelance.agreement(auth(), projectUuid).data }) } }
            // FL-404 / FL-405: changes after the agreement started.
            if (a.status == "active") ChangesCard(
                changes = a.changes, milestones = a.milestones, currency = a.currency, canAsk = true, busy = busy,
                onAsk = { body -> act({ ApiClient.freelance.requestChange(auth(), projectUuid, body); ApiClient.freelance.agreement(auth(), projectUuid).data }) },
                onAnswer = { change, action -> act({ ApiClient.freelance.answerChange(auth(), projectUuid, change, action); ApiClient.freelance.agreement(auth(), projectUuid).data }) },
            )
            Card {
                if (a.can.edit) {
                    DorrTextField(scopeText, { scopeText = it.take(8000) }, label = stringResource(R.string.fl_ag_scope), singleLine = false, minLines = 4, modifier = Modifier.fillMaxWidth())
                    DorrTextField(includes, { includes = it.take(3000) }, label = stringResource(R.string.fl_ag_includes), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
                    DorrTextField(excludes, { excludes = it.take(3000) }, label = stringResource(R.string.fl_ag_excludes), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
                    if (a.status == "pending_approval") Text(stringResource(R.string.fl_ag_edit_resets), color = settingsMut(), fontSize = 11.sp)
                } else {
                    Field(stringResource(R.string.fl_ag_scope), a.scope)
                    Field(stringResource(R.string.fl_ag_includes), a.includes)
                    Field(stringResource(R.string.fl_ag_excludes), a.excludes)
                }
            }
            notice?.let { Text(it, color = Color(0xFF16A34A), fontSize = 13.sp, fontWeight = FontWeight.Bold) }
            error?.let { Text(it, color = Color(0xFFDC2626), fontSize = 13.sp) }
            if (a.can.edit && changed) TextAction(stringResource(R.string.fl_ag_save)) { act({ ApiClient.freelance.updateAgreement(auth(), projectUuid, body()).data }) }
            if (a.can.send || (a.can.edit && changed)) SettingsPrimaryButton(
                text = stringResource(R.string.fl_ag_send), loading = busy, enabled = scopeText.isNotBlank(),
                onClick = {
                    act({
                        if (changed) ApiClient.freelance.updateAgreement(auth(), projectUuid, body())
                        ApiClient.freelance.sendAgreement(auth(), projectUuid).also { notice = it.message }.data
                    })
                },
            )
            if (a.can.cancel) TextAction(stringResource(R.string.fl_ag_cancel), danger = true) { confirmCancel = true }
            Spacer(Modifier.height(20.dp))
        }
    }

    cancelFor?.let { m ->
        androidx.compose.material3.AlertDialog(
            onDismissRequest = { if (!busy) cancelFor = null },
            title = { Text(stringResource(R.string.fl_ms_call_off_title, m.title)) },
            text = { Text(stringResource(when (m.status) { "unfunded" -> R.string.fl_ms_cancel_note_unfunded; "funded" -> R.string.fl_ms_cancel_note_funded; else -> R.string.fl_ms_cancel_note_started }), color = settingsMut(), fontSize = 13.sp) },
            confirmButton = {
                androidx.compose.material3.TextButton(enabled = !busy, onClick = {
                    act({ ApiClient.freelance.milestoneAction(auth(), projectUuid, m.uuid, "cancel", com.dorr.app.network.FreelanceDecisionRequest()).data }, after = { cancelFor = null })
                }) { Text(stringResource(if (m.status == "in_progress" || m.status == "revision") R.string.fl_ms_cancel_ask else R.string.fl_ms_cancel_confirm), color = Color(0xFFDC2626)) }
            },
            dismissButton = { androidx.compose.material3.TextButton(onClick = { cancelFor = null }) { Text(stringResource(R.string.fl_not_now), color = settingsMut()) } },
        )
    }

    fundFor?.let { m ->
        val cur = agreement?.currency.orEmpty()
        val total = m.amountMinor + m.clientFeeMinor
        val available = agreement?.walletAvailableMinor ?: 0L
        val enough = available >= total
        androidx.compose.material3.AlertDialog(
            onDismissRequest = { if (!busy) fundFor = null },
            title = { Text(stringResource(R.string.fl_fund_title, m.title)) },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Field(stringResource(R.string.fl_price), "\u200E${money(m.amountMinor)} $cur")
                    if (m.clientFeeMinor > 0) Field(stringResource(R.string.fl_client_fee), "\u200E${money(m.clientFeeMinor)} $cur")
                    Field(stringResource(R.string.fl_total), "\u200E${money(total)} $cur")
                    Field(stringResource(R.string.fl_wallet_available), "\u200E${money(available)} $cur")
                    Text(stringResource(if (enough) R.string.fl_fund_note else R.string.fl_fund_short), color = if (enough) settingsMut() else Color(0xFFD97706), fontSize = 12.sp)
                }
            },
            confirmButton = {
                if (enough) androidx.compose.material3.TextButton(enabled = !busy, onClick = {
                    act({ ApiClient.freelance.milestoneAction(auth(), projectUuid, m.uuid, "fund", com.dorr.app.network.FreelanceDecisionRequest()).data }, after = { fundFor = null })
                }) { Text(stringResource(R.string.fl_fund_confirm), color = settingsAccent()) }
                else androidx.compose.material3.TextButton(onClick = {
                    fundFor = null
                    // Top up what is missing; coming back reads the wallet once more.
                    com.dorr.app.ui.screens.wallet.TopupLauncher.open(total - available) { reloads++ }
                }) { Text(stringResource(R.string.fl_topup), color = settingsAccent()) }
            },
            dismissButton = { androidx.compose.material3.TextButton(onClick = { fundFor = null }) { Text(stringResource(R.string.fl_not_now), color = settingsMut()) } },
        )
    }

    revisionFor?.let { target ->
        androidx.compose.material3.AlertDialog(
            onDismissRequest = { if (!busy) revisionFor = null },
            title = { Text(stringResource(R.string.fl_ms_revision)) },
            text = { DorrTextField(revisionReason, { revisionReason = it.take(1000) }, placeholder = stringResource(R.string.fl_ms_revision_hint), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth()) },
            confirmButton = {
                androidx.compose.material3.TextButton(enabled = revisionReason.trim().length >= 5 && !busy, onClick = {
                    act({ ApiClient.freelance.milestoneAction(auth(), projectUuid, target, "revision", com.dorr.app.network.FreelanceDecisionRequest(revisionReason.trim())).data }, after = { revisionFor = null })
                }) { Text(stringResource(R.string.fl_ms_revision_send), color = settingsAccent()) }
            },
            dismissButton = { androidx.compose.material3.TextButton(onClick = { revisionFor = null }) { Text(stringResource(R.string.fl_not_now), color = settingsMut()) } },
        )
    }

    if (confirmCancel) ConfirmDialog(
        icon = Icons.Rounded.Close, title = stringResource(R.string.fl_ag_cancel), message = stringResource(R.string.fl_ag_cancel_confirm),
        loading = busy, onDismiss = { confirmCancel = false },
        onConfirm = { act({ ApiClient.freelance.cancelAgreement(auth(), projectUuid).data }, after = { confirmCancel = false; onEnded() }) },
    )
}

@Composable
private fun TextLink(text: String, onClick: () -> Unit) {
    Text(text, color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(onClick = onClick).padding(vertical = 4.dp))
}

private fun agreementHint(status: String): Int = when (status) {
    "pending_approval" -> R.string.fl_ag_hint_pending
    "active" -> R.string.fl_ag_hint_active
    "declined", "cancelled", "expired" -> R.string.fl_ag_hint_ended
    else -> R.string.fl_ag_hint_draft
}

@Composable
internal fun milestoneStatusLabel(status: String): String = stringResource(
    when (status) {
        "funded" -> R.string.fl_mst_funded
        "in_progress" -> R.string.fl_mst_in_progress
        "delivered" -> R.string.fl_mst_delivered
        "revision" -> R.string.fl_mst_revision
        "approved" -> R.string.fl_mst_approved
        "disputed" -> R.string.fl_mst_disputed
        "cancelled" -> R.string.fl_mst_cancelled
        else -> R.string.fl_mst_unfunded
    },
)

internal fun milestoneStatusColor(status: String): Color = when (status) {
    "approved" -> Color(0xFF16A34A)
    "delivered" -> Color(0xFF2563EB)
    "revision", "disputed" -> Color(0xFFD97706)
    "cancelled" -> Color(0xFFDC2626)
    "funded", "in_progress" -> Color(0xFF0EA5E9)
    else -> Color(0xFF6B7280)
}
