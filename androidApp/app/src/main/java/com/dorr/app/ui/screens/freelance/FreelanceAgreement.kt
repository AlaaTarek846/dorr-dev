package com.dorr.app.ui.screens.freelance

import androidx.compose.foundation.layout.width
import com.dorr.app.ui.screens.profile.settingsSurface
import androidx.compose.material3.Icon
import androidx.compose.foundation.background
import androidx.compose.ui.draw.clip
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.layout.wrapContentSize
import androidx.compose.material.icons.rounded.*
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
import androidx.compose.foundation.horizontalScroll
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
    // FL-605: how often I ask for a progress report (0 = none).
    var reportDays by rememberSaveable { mutableStateOf(0) }
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
    // FL-607: why (a picked reason and a few words).
    var cancelCode by remember { mutableStateOf<String?>(null) }
    var cancelNote by remember { mutableStateOf("") }
    var reloads by remember { mutableStateOf(0) }
    var tab by rememberSaveable { mutableStateOf(0) }
    // FL-402: the parts, while the client splits them (title, amount as typed).
    var splitting by remember { mutableStateOf(false) }
    val parts = remember { androidx.compose.runtime.mutableStateListOf<Pair<String, String>>() }

    fun take(a: FreelanceAgreementDto?) {
        agreement = a
        if (a != null) {
            scopeText = a.scope.orEmpty()
            includes = a.includes.orEmpty()
            excludes = a.excludes.orEmpty()
            reportDays = a.reports.everyDays ?: 0
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
    val changed = a0 != null && (scopeText.trim() != a0.scope.orEmpty() || includes.trim() != a0.includes.orEmpty() || excludes.trim() != a0.excludes.orEmpty() || reportDays != (a0.reports.everyDays ?: 0))
    fun body() = FreelanceAgreementRequest(scopeText.trim(), includes.trim().ifBlank { null }, excludes.trim().ifBlank { null }, reportDays)

    Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.fl_agreement), onBack)
        val a = agreement
        if (a == null) {
            FlLoading(error)
            return@Column
        }
        val recordsKey = "${a.status}:${a.version}:" + a.milestones.joinToString(",") { it.status }
        val activity = a.status == "active" || a.status == "completed"
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            // The head: who, where it stands and the figures.
            val sc = agreementStatusColor(a.status)
            Card {
                Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    Box(Modifier.size(48.dp).clip(RoundedCornerShape(15.dp)).background(sc.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.Handshake, null, tint = sc, modifier = Modifier.size(24.dp))
                    }
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                        Text(a.freelancer?.name ?: "—", color = settingsInk(), fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
                        Text(
                            agreementStatusLabel(a.status), color = sc, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(sc.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp),
                        )
                    }
                }
                Text(stringResource(agreementHint(a.status)), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 19.sp)
                a.expiresAt?.let { FlNotice(stringResource(R.string.fl_ag_answer_by, "‎" + it.take(10)), Icons.Rounded.HourglassTop, Color(0xFFD97706)) }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FlStat(Icons.Rounded.Payments, stringResource(R.string.fl_total), "‎${money(a.money.totalMinor)} ${a.currency}", Modifier.weight(1.5f))
                    FlStat(Icons.Rounded.Schedule, stringResource(R.string.fl_duration), stringResource(R.string.fl_days, a.durationDays), Modifier.weight(1f))
                }
                val done = a.milestones.count { it.status == "approved" }
                if (a.milestones.isNotEmpty() && activity) {
                    Box(Modifier.fillMaxWidth().height(6.dp).clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.15f))) {
                        if (done > 0) Box(Modifier.fillMaxWidth((done.toFloat() / a.milestones.size).coerceAtMost(1f)).height(6.dp).clip(RoundedCornerShape(999.dp)).background(settingsAccent()))
                    }
                    Text(stringResource(R.string.fl_progress_parts, done, a.milestones.size), color = settingsMut(), fontSize = 12.sp)
                }
            }
            FlTabs(
                listOf(
                    FlTab(Icons.Rounded.Description, stringResource(R.string.fl_tab_overview)),
                    FlTab(Icons.Rounded.Flag, stringResource(R.string.fl_tab_parts), a.milestones.size),
                    FlTab(Icons.Rounded.History, stringResource(R.string.fl_tab_activity)),
                ),
                tab,
            ) { tab = it }
            when (tab) {
                0 -> {
                    Card(Icons.Rounded.Payments, stringResource(R.string.fl_ag_money)) {
                        Field(stringResource(R.string.fl_price), "‎${money(a.money.priceMinor)} ${a.currency}")
                        if (a.money.clientFeeMinor > 0) Field(stringResource(R.string.fl_client_fee), "‎${money(a.money.clientFeeMinor)} ${a.currency}")
                        Field(stringResource(R.string.fl_total), "‎${money(a.money.totalMinor)} ${a.currency}")
                        // FL-510: the split made the total differ from the one told before choosing (a minimum fee on each part).
                        a.money.quotedTotalMinor?.takeIf { it != a.money.totalMinor }?.let { q ->
                            Text(stringResource(R.string.fl_quoted_total, "‎${money(q)} ${a.currency}"), color = settingsMut(), fontSize = 12.sp)
                        }
                        a.terms.reviewDays?.let { Field(stringResource(R.string.fl_ag_review_days), stringResource(R.string.fl_days, it)) }
                        a.terms.freeRevisions?.let { Field(stringResource(R.string.fl_ag_free_revisions), it.toString()) }
                    }
                    if (a.can.edit) {
                        // The terms are a form: the same look as the organizer form.
                        FlIntro(Icons.Rounded.EditNote, stringResource(R.string.fl_ag_terms), stringResource(R.string.fl_ag_form_intro))
                        Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
                            DorrTextField(scopeText, { scopeText = it.take(8000) }, label = stringResource(R.string.fl_ag_scope), icon = Icons.Rounded.Notes, singleLine = false, minLines = 4, modifier = Modifier.fillMaxWidth())
                            DorrTextField(includes, { includes = it.take(3000) }, label = stringResource(R.string.fl_ag_includes), icon = Icons.Rounded.CheckCircle, singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
                            DorrTextField(excludes, { excludes = it.take(3000) }, label = stringResource(R.string.fl_ag_excludes), icon = Icons.Rounded.Block, singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
                        }
                        // FL-605: optional progress reports.
                        FlHeading(Icons.Rounded.EventRepeat, stringResource(R.string.fl_rp_ask), stringResource(R.string.fl_rp_hint))
                        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Chip(stringResource(R.string.fl_rp_none), selected = reportDays == 0, icon = Icons.Rounded.Block) { reportDays = 0 }
                            a.reports.allowedDays.forEach { d -> Chip(stringResource(R.string.fl_rp_every, d), selected = reportDays == d, icon = Icons.Rounded.EventRepeat) { reportDays = d } }
                        }
                        if (a.status == "pending_approval") FlNotice(stringResource(R.string.fl_ag_edit_resets), Icons.Rounded.Info, settingsAccent())
                    } else {
                        Card(Icons.Rounded.Description, stringResource(R.string.fl_ag_terms)) {
                            Field(stringResource(R.string.fl_ag_scope), a.scope)
                            Field(stringResource(R.string.fl_ag_includes), a.includes)
                            Field(stringResource(R.string.fl_ag_excludes), a.excludes)
                        }
                    }
                    notice?.let { FlNotice(it, Icons.Rounded.CheckCircle, Color(0xFF16A34A)) }
                    error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Color(0xFFDC2626)) }
                    if (a.can.edit && changed) FlAction(stringResource(R.string.fl_ag_save), Icons.Rounded.Save) { act({ ApiClient.freelance.updateAgreement(auth(), projectUuid, body()).data }) }
                    if (a.can.send || (a.can.edit && changed)) FlAction(
                        stringResource(R.string.fl_ag_send), Icons.Rounded.Send, FlTone.Primary, loading = busy, enabled = scopeText.isNotBlank(),
                        onClick = {
                            act({
                                if (changed) ApiClient.freelance.updateAgreement(auth(), projectUuid, body())
                                ApiClient.freelance.sendAgreement(auth(), projectUuid).also { notice = it.message }.data
                            })
                        },
                    )
                    if (a.can.cancel) FlAction(stringResource(R.string.fl_ag_cancel), Icons.Rounded.Cancel, FlTone.Danger) { confirmCancel = true }
                }
                1 -> {
                    if (a.can.split && !splitting) FlAction(stringResource(if (a.milestones.size > 1) R.string.fl_ms_edit else R.string.fl_ms_split), Icons.Rounded.CallSplit) {
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
                    if (!splitting) a.milestones.forEach { m ->
                        val mc = milestoneStatusColor(m.status)
                        Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                Text(m.position.toString(), color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.size(28.dp).clip(RoundedCornerShape(50)).background(mc).wrapContentSize(Alignment.Center))
                                Column(Modifier.weight(1f)) {
                                    Text(m.title, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                                    Text("\u200E${money(m.amountMinor)} ${a.currency}", color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                                }
                                if (a.status == "active") Text(
                                    milestoneStatusLabel(m.status), color = mc, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                                    modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(mc.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp),
                                )
                            }
                            if (a.status == "active" && m.heldMinor > 0) FlNotice(stringResource(R.string.fl_held, "\u200E${money(m.heldMinor)} ${a.currency}"), Icons.Rounded.Lock, Color(0xFF0EA5E9))
                            // FL-602 / FL-604: the versions delivered (opened in place) and the checklist for the part.
                            if (a.status == "active" || a.status == "completed") DeliveryReview(projectUuid, m)
                            // FL-407: the free rounds of changes.
                            if (m.status == "delivered" && m.revisionsMax != null && m.revisionsMax > 0) Text(
                                stringResource(if ((m.revisionsLeft ?: 0) > 0) R.string.fl_ms_rounds_left else R.string.fl_ms_rounds_none, m.revisionsLeft ?: 0, m.revisionsMax),
                                color = settingsMut(), fontSize = 11.5.sp,
                            )
                            // FL-506: a request to end a started part waits for an answer; what went back after it ended.
                            if (m.cancelRequestedBy == "freelancer") FlNotice(stringResource(R.string.fl_ms_cancel_asked_them), Icons.Rounded.HourglassTop, Color(0xFFD97706))
                            if (m.cancelRequestedBy == "client") FlNotice(stringResource(R.string.fl_ms_cancel_asked_you), Icons.Rounded.HourglassTop, Color(0xFFD97706))
                            if (m.status == "cancelled" && (m.refundedMinor ?: 0L) > 0) FlNotice(stringResource(R.string.fl_ms_refunded, "\u200E${money(m.refundedMinor ?: 0L)} ${a.currency}"), Icons.Rounded.CheckCircle, Color(0xFF16A34A))
                            m.autoApproveAt?.let { FlNotice(stringResource(R.string.fl_auto_approve_on, "\u200E" + it.take(10)), Icons.Rounded.Schedule, Color(0xFFD97706)) }
                            if (m.can.fund) FlAction(stringResource(R.string.fl_fund), Icons.Rounded.AccountBalanceWallet, FlTone.Primary) { fundFor = m }
                            if (m.can.approve) FlAction(stringResource(R.string.fl_ms_approve), Icons.Rounded.CheckCircle, FlTone.Primary) { act({ ApiClient.freelance.milestoneAction(auth(), projectUuid, m.uuid, "approve", com.dorr.app.network.FreelanceDecisionRequest()).data }) }
                            if (m.can.revision) FlAction(stringResource(R.string.fl_ms_revision), Icons.Rounded.Replay) { revisionFor = m.uuid; revisionReason = "" }
                            if (m.can.cancelAccept) FlAction(stringResource(R.string.fl_ms_cancel_accept), Icons.Rounded.CheckCircle) { act({ ApiClient.freelance.milestoneAction(auth(), projectUuid, m.uuid, "cancel-accept", com.dorr.app.network.FreelanceDecisionRequest()).data }) }
                            if (m.can.cancelReject) FlAction(stringResource(R.string.fl_ms_cancel_reject), Icons.Rounded.Close) { act({ ApiClient.freelance.milestoneAction(auth(), projectUuid, m.uuid, "cancel-reject", com.dorr.app.network.FreelanceDecisionRequest()).data }) }
                            // FL-704: open a dispute, or see it.
                            m.dispute?.let { dsp -> FlAction(stringResource(R.string.fl_dispute_see), Icons.Rounded.Gavel, FlTone.Warn) { onDispute(m.uuid, dsp.uuid) } }
                            if (m.can.dispute && m.dispute?.status.let { it == null || it == "resolved" }) FlAction(stringResource(R.string.fl_dispute_open), Icons.Rounded.Gavel, FlTone.Warn) { onDispute(m.uuid, null) }
                            if (m.can.cancel) FlAction(stringResource(if (m.status == "unfunded") R.string.fl_ms_cancel else R.string.fl_ms_call_off), Icons.Rounded.Cancel, FlTone.Danger) { cancelCode = null; cancelNote = ""; cancelFor = m }
                        }
                    } else {
                        FlIntro(Icons.Rounded.CallSplit, stringResource(R.string.fl_ms_title), stringResource(R.string.fl_ms_split_intro))
                        val sum = parts.sumOf { parseMoney(it.second) ?: 0L }
                        val left = a.priceMinor - sum
                        val minPart = a.milestoneRules.minAmountMinor
                        parts.forEachIndexed { i, p ->
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                DorrTextField(p.first, { parts[i] = it.take(120) to p.second }, placeholder = stringResource(R.string.fl_ms_part, i + 1), icon = Icons.Rounded.Flag, modifier = Modifier.weight(1.4f))
                                DorrTextField(p.second, { parts[i] = p.first to it.take(12) }, placeholder = "0.00", icon = Icons.Rounded.Payments, keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(keyboardType = androidx.compose.ui.text.input.KeyboardType.Decimal), modifier = Modifier.weight(1f))
                                if (parts.size > 1) androidx.compose.material3.Icon(Icons.Rounded.Close, stringResource(R.string.fl_remove), tint = settingsMut(), modifier = Modifier.size(32.dp).clip(RoundedCornerShape(50)).clickable { parts.removeAt(i) }.padding(6.dp))
                            }
                        }
                        if (parts.size < a.milestoneRules.maxCount) FlAction(stringResource(R.string.fl_ms_add), Icons.Rounded.Add) { parts.add("" to money(left.coerceAtLeast(0))) }
                        FlNotice(
                            if (left == 0L) stringResource(R.string.fl_ms_balanced) else stringResource(R.string.fl_ms_left, "\u200E${money(left)} ${a.currency}"),
                            if (left == 0L) Icons.Rounded.CheckCircle else Icons.Rounded.Warning, if (left == 0L) Color(0xFF16A34A) else Color(0xFFD97706),
                        )
                        minPart?.let { Text(stringResource(R.string.fl_ms_rules, a.milestoneRules.maxCount, "\u200E${money(it)} ${a.currency}"), color = settingsMut(), fontSize = 11.5.sp) }
                        FlAction(stringResource(R.string.fl_ms_save), Icons.Rounded.Save, FlTone.Primary, loading = busy) {
                            val items = parts.mapIndexed { i, p -> com.dorr.app.network.FreelanceMilestoneInput(p.first.trim().ifBlank { "${i + 1}" }, parseMoney(p.second) ?: 0L) }
                            act({ ApiClient.freelance.setMilestones(auth(), projectUuid, com.dorr.app.network.FreelanceMilestonesRequest(items)).data }, after = { splitting = false })
                        }
                        FlAction(stringResource(R.string.fl_ms_cancel_edit), null) { splitting = false }
                    }
                    if (!splitting) {
                        notice?.let { FlNotice(it, Icons.Rounded.CheckCircle, Color(0xFF16A34A)) }
                        error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Color(0xFFDC2626)) }
                    }
                }
                else -> {
                    // FL-511 / FL-406: the receipts of this project and its record of approvals.
                    ReceiptsCard(projectUuid, recordsKey)
                    TrailCard(projectUuid, recordsKey)
                    // FL-701: rating the freelancer once it is completed.
                    a.review?.let { rv ->
                        ReviewCard(rv, busy, onObject = { body -> act({ ApiClient.freelance.objectToReview(auth(), projectUuid, body); ApiClient.freelance.agreement(auth(), projectUuid).data }) }) { body -> act({ ApiClient.freelance.review(auth(), projectUuid, body); ApiClient.freelance.agreement(auth(), projectUuid).data }) }
                    }
                    // FL-702: how satisfied, once it is over.
                    if (a.status == "completed" || a.status == "cancelled") SurveyCard(projectUuid, recordsKey)
                    // FL-605: the progress reports the freelancer sent.
                    if (activity) ProgressReportsCard(projectUuid, a.reports, recordsKey)
                    // FL-404 / FL-405: changes after the agreement started.
                    if (a.status == "active") ChangesCard(
                        changes = a.changes, milestones = a.milestones, currency = a.currency, canAsk = true, busy = busy,
                        onAsk = { body -> act({ ApiClient.freelance.requestChange(auth(), projectUuid, body); ApiClient.freelance.agreement(auth(), projectUuid).data }) },
                        onAnswer = { change, action -> act({ ApiClient.freelance.answerChange(auth(), projectUuid, change, action); ApiClient.freelance.agreement(auth(), projectUuid).data }) },
                    )
                    error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Color(0xFFDC2626)) }
                }
            }
            Spacer(Modifier.height(20.dp))
        }
    }

    cancelFor?.let { m ->
        FlSheet(
            Icons.Rounded.Cancel, stringResource(R.string.fl_ms_call_off_title, m.title),
            stringResource(when (m.status) { "unfunded" -> R.string.fl_ms_cancel_note_unfunded; "funded" -> R.string.fl_ms_cancel_note_funded; else -> R.string.fl_ms_cancel_note_started }),
            onDismiss = { if (!busy) cancelFor = null },
        ) {
            CancelReasonPicker(cancelCode, cancelNote, { cancelCode = it }, { cancelNote = it })
            FlAction(
                stringResource(if (m.status == "in_progress" || m.status == "revision") R.string.fl_ms_cancel_ask else R.string.fl_ms_cancel_confirm), Icons.Rounded.Cancel, FlTone.Danger,
                enabled = cancelReasonReady(cancelCode, cancelNote), loading = busy,
            ) {
                act({ ApiClient.freelance.milestoneAction(auth(), projectUuid, m.uuid, "cancel", com.dorr.app.network.FreelanceDecisionRequest(cancelNote.trim().ifEmpty { null }, cancelCode)).data }, after = { cancelFor = null })
            }
            FlAction(stringResource(R.string.fl_not_now), null) { cancelFor = null }
        }
    }

    fundFor?.let { m ->
        val cur = agreement?.currency.orEmpty()
        val total = m.amountMinor + m.clientFeeMinor
        val available = agreement?.walletAvailableMinor ?: 0L
        val enough = available >= total
        FlSheet(Icons.Rounded.AccountBalanceWallet, stringResource(R.string.fl_fund_title, m.title), stringResource(if (enough) R.string.fl_fund_note else R.string.fl_fund_short), onDismiss = { if (!busy) fundFor = null }) {
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(settingsAccent().copy(alpha = 0.07f)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Field(stringResource(R.string.fl_price), "\u200E${money(m.amountMinor)} $cur")
                if (m.clientFeeMinor > 0) Field(stringResource(R.string.fl_client_fee), "\u200E${money(m.clientFeeMinor)} $cur")
                Field(stringResource(R.string.fl_total), "\u200E${money(total)} $cur")
                Field(stringResource(R.string.fl_wallet_available), "\u200E${money(available)} $cur")
            }
            if (enough) FlAction(stringResource(R.string.fl_fund_confirm), Icons.Rounded.AccountBalanceWallet, FlTone.Primary, loading = busy) {
                act({ ApiClient.freelance.milestoneAction(auth(), projectUuid, m.uuid, "fund", com.dorr.app.network.FreelanceDecisionRequest()).data }, after = { fundFor = null })
            } else FlAction(stringResource(R.string.fl_topup), Icons.Rounded.AddCard, FlTone.Primary) {
                fundFor = null
                // Top up what is missing; coming back reads the wallet once more.
                com.dorr.app.ui.screens.wallet.TopupLauncher.open(total - available) { reloads++ }
            }
            FlAction(stringResource(R.string.fl_not_now), null) { fundFor = null }
        }
    }

    revisionFor?.let { target ->
        FlSheet(Icons.Rounded.Replay, stringResource(R.string.fl_ms_revision), stringResource(R.string.fl_ms_revision_intro), onDismiss = { if (!busy) revisionFor = null }) {
            DorrTextField(revisionReason, { revisionReason = it.take(1000) }, placeholder = stringResource(R.string.fl_ms_revision_hint), icon = Icons.Rounded.Notes, singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
            FlAction(stringResource(R.string.fl_ms_revision_send), Icons.Rounded.Send, FlTone.Primary, enabled = revisionReason.trim().length >= 5, loading = busy) {
                act({ ApiClient.freelance.milestoneAction(auth(), projectUuid, target, "revision", com.dorr.app.network.FreelanceDecisionRequest(revisionReason.trim())).data }, after = { revisionFor = null })
            }
            FlAction(stringResource(R.string.fl_not_now), null) { revisionFor = null }
        }
    }

    if (confirmCancel) ConfirmDialog(
        icon = Icons.Rounded.Close, title = stringResource(R.string.fl_ag_cancel), message = stringResource(R.string.fl_ag_cancel_confirm),
        loading = busy, onDismiss = { confirmCancel = false },
        onConfirm = { act({ ApiClient.freelance.cancelAgreement(auth(), projectUuid).data }, after = { confirmCancel = false; onEnded() }) },
    )
}

@Composable
internal fun TextLink(text: String, onClick: () -> Unit) {
    Text(
        text, color = settingsAccent(), fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.10f)).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 7.dp),
    )
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
