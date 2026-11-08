package com.dorr.freelance.ui.screens.workroom

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.SwapHoriz
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
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
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.AppLocale
import com.dorr.freelance.net.WorkDecisionDto
import com.dorr.freelance.net.WorkDecisionSummaryDto
import com.dorr.freelance.net.WorkNoteRequest
import com.dorr.freelance.net.WorkPartDto
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.kit.ConfirmDialog
import com.dorr.freelance.ui.kit.Corners
import com.dorr.freelance.ui.kit.FilterChips
import com.dorr.freelance.ui.kit.FilterOption
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.StatusPill
import com.dorr.freelance.ui.kit.Tints
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import kotlinx.coroutines.launch

/** The decisions log: the read, the numbers (and how many wait for me) and what I may do. */
internal class DecisionsState(private val project: String) {
    var filter by mutableStateOf("all")
    var summary by mutableStateOf(WorkDecisionSummaryDto())
    var readOnly by mutableStateOf(false)
    var canCreate by mutableStateOf(false)

    val feed = WorkFeed<WorkDecisionDto>({ it.uuid }) { page ->
        val f = filter
        val r = apiCall {
            ApiClient.workroom.decisions(
                project, status = f.takeIf { it in DECISION_FILTERS }, waiting = if (f == "waiting") 1 else null, perPage = WORK_PAGE, page = page,
            )
        }
        if (r is ApiResult.Ok) {
            summary = r.data.summary
            readOnly = r.data.readOnly
            canCreate = r.data.can.decisionCreate
        }
        r.rows { it.decisions }
    }
}

private val DECISION_FILTERS = listOf("proposed", "agreed", "declined", "superseded")

/** The tab of the decisions: what was agreed between the two sides, in writing, with the answer of each. */
@Composable
internal fun DecisionsTab(project: String, state: DecisionsState, parts: List<WorkPartDto>, onToast: (String) -> Unit, onChanged: () -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var proposing by remember { mutableStateOf<ProposeSeed?>(null) }
    var editing by remember { mutableStateOf<WorkDecisionDto?>(null) }
    var answering by remember { mutableStateOf<Pair<WorkDecisionDto, Boolean>?>(null) }
    var withdrawing by remember { mutableStateOf<WorkDecisionDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    val s = state.summary

    fun reload() { scope.launch { state.feed.reload() }; onChanged() }

    val filters = listOf(
        FilterOption("all", stringResource(R.string.wr_f_all)),
        FilterOption("waiting", stringResource(R.string.wr_f_waiting), dot = s.waitingForMe > 0),
        FilterOption("proposed", stringResource(R.string.wr_dec_proposed)),
        FilterOption("agreed", stringResource(R.string.wr_dec_agreed)),
        FilterOption("declined", stringResource(R.string.wr_dec_declined)),
        FilterOption("superseded", stringResource(R.string.wr_dec_superseded)),
    )

    FeedList(
        feed = state.feed, keyOf = { it.uuid },
        empty = Triple(Icons.Rounded.Gavel, stringResource(R.string.wr_dec_empty_title), stringResource(R.string.wr_dec_empty_body)),
        fabLabel = if (state.canCreate && !state.readOnly) stringResource(R.string.wr_dec_propose) else null,
        onFab = if (state.canCreate && !state.readOnly) ({ proposing = ProposeSeed() }) else null,
        header = {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                if (state.readOnly) ReadOnlyBanner()
                if (s.waitingForMe > 0) Row(
                    Modifier.fillMaxWidth().clip(Corners.card).background(Tints.amber.copy(alpha = 0.13f)).clickable { state.filter = "waiting"; scope.launch { state.feed.reload() } }.padding(14.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    CountDot(s.waitingForMe, Tints.amber)
                    Text(stringResource(R.string.wr_dec_waiting_me), color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                }
                FilterChips(filters, state.filter, { key ->
                    if (key != state.filter) {
                        state.filter = key
                        scope.launch { state.feed.reload() }
                    }
                })
            }
        },
    ) { d ->
        DecisionCard(
            d, readOnly = state.readOnly,
            onAgree = { answering = d to true }, onDecline = { answering = d to false },
            onWithdraw = { withdrawing = d }, onEdit = { editing = d },
            onSupersede = { proposing = ProposeSeed(supersedes = d) },
        )
    }

    proposing?.let { seed ->
        DecisionSheet(project, null, seed.supersedes, parts, onDismiss = { proposing = null }, onSaved = { proposing = null; reload() })
    }
    editing?.let { d -> DecisionSheet(project, d, null, parts, onDismiss = { editing = null }, onSaved = { editing = null; reload() }) }
    answering?.let { (d, agree) ->
        AnswerSheet(project, d, agree, onDismiss = { answering = null }, onDone = { answering = null; reload() })
    }
    withdrawing?.let { d ->
        ConfirmDialog(
            icon = Icons.Rounded.Undo, title = stringResource(R.string.wr_dec_withdraw), message = stringResource(R.string.wr_dec_withdraw_ask), confirmLabel = stringResource(R.string.wr_dec_withdraw), loading = busy,
            onConfirm = {
                busy = true
                scope.launch {
                    val r = apiCall { ApiClient.workroom.withdrawDecision(project, d.uuid) }
                    busy = false
                    withdrawing = null
                    if (r is ApiResult.Ok) reload() else onToast(r.errorText(offlineText) ?: genericError)
                }
            },
            onDismiss = { withdrawing = null },
        )
    }
}

/** What a new proposal starts from: nothing, or the decision it replaces. */
private class ProposeSeed(val supersedes: WorkDecisionDto? = null)

/** One decision: its state, the words, both answers, the link to what it replaces, and what I may do (agree · decline · edit · withdraw · replace). */
@Composable
private fun DecisionCard(
    d: WorkDecisionDto, readOnly: Boolean,
    onAgree: () -> Unit, onDecline: () -> Unit, onWithdraw: () -> Unit, onEdit: () -> Unit, onSupersede: () -> Unit,
) {
    val locale = AppLocale.java()
    var expanded by remember { mutableStateOf(false) }
    val tint = decisionTint(d.status)
    val waitingMe = d.status == "proposed" && d.can.decisionAgree
    WorkCard(onClick = { expanded = !expanded }, accent = if (waitingMe) Tints.amber else null) {
        Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(Modifier.size(36.dp).clip(CircleShape).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(decisionIcon(d.status), contentDescription = decisionLabel(d.status), tint = tint, modifier = Modifier.size(20.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(d.title, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = if (expanded) 6 else 2, overflow = TextOverflow.Ellipsis)
                Text(d.body, color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp, maxLines = if (expanded) Int.MAX_VALUE else 3, overflow = TextOverflow.Ellipsis)
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth()) {
            StatusPill(decisionLabel(d.status), tint, decisionIcon(d.status))
            StatusPill(stringResource(R.string.wr_dec_by, sideName(d.proposedBySide)), if (d.proposedBySide == MY_SIDE) Tints.teal else Tints.violet)
            workWhen(d.answeredAt ?: d.createdAt, locale)?.let { Text(it, color = settingsMut(), fontSize = 11.sp) }
        }
        d.milestone?.let { PartTag(it) }
        // Each side's answer: a decision stands when both agreed.
        Row(horizontalArrangement = Arrangement.spacedBy(14.dp), verticalAlignment = Alignment.CenterVertically) {
            Answer(stringResource(R.string.wr_side_me), d.freelancerAgreed)
            Answer(stringResource(R.string.wr_side_client), d.clientAgreed)
        }
        d.answerNote?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.wr_dec_note, it), color = settingsInk(), fontSize = 12.5.sp, lineHeight = 18.sp) }
        d.supersedes?.let { Text(stringResource(R.string.wr_dec_replaces, it.title), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp) }
        d.supersededBy?.let { Text(stringResource(R.string.wr_dec_replaced_by, it.title), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp) }
        if (!readOnly && (d.can.decisionAgree || d.can.decisionDecline || d.can.decisionEdit || d.can.decisionWithdraw || d.can.decisionSupersede)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (d.can.decisionAgree) DecisionAction(Icons.Rounded.Check, stringResource(R.string.wr_dec_agree), Tints.green, Modifier.weight(1f), onAgree)
                if (d.can.decisionDecline) DecisionAction(Icons.Rounded.Close, stringResource(R.string.wr_dec_decline), WorkRed, Modifier.weight(1f), onDecline)
                if (d.can.decisionEdit) DecisionAction(Icons.Rounded.Edit, stringResource(R.string.wr_edit), settingsAccent(), Modifier.weight(1f), onEdit)
                if (d.can.decisionWithdraw) DecisionAction(Icons.Rounded.Undo, stringResource(R.string.wr_dec_withdraw), settingsMut(), Modifier.weight(1f), onWithdraw)
                if (d.can.decisionSupersede) DecisionAction(Icons.Rounded.SwapHoriz, stringResource(R.string.wr_dec_replace), settingsAccent(), Modifier.weight(1f), onSupersede)
            }
        }
    }
}

@Composable
private fun Answer(who: String, agreed: Boolean) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
        Icon(if (agreed) Icons.Rounded.Check else Icons.Rounded.Gavel, contentDescription = null, tint = if (agreed) Tints.green else settingsMut().copy(alpha = 0.6f), modifier = Modifier.size(14.dp))
        Text(who, color = if (agreed) settingsInk() else settingsMut(), fontSize = 12.sp, fontWeight = if (agreed) FontWeight.Bold else FontWeight.Normal)
    }
}

@Composable
private fun DecisionAction(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, tint: Color, modifier: Modifier, onClick: () -> Unit) {
    Column(
        modifier.clip(Corners.small).background(tint.copy(alpha = 0.10f)).clickable(onClick = onClick).padding(vertical = 9.dp),
        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(3.dp),
    ) {
        Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(19.dp))
        Text(label, color = tint, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/** A new proposal (or the replacement of an older decision), or editing my own proposal while it waits. */
@Composable
private fun DecisionSheet(project: String, decision: WorkDecisionDto?, supersedes: WorkDecisionDto?, parts: List<WorkPartDto>, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var title by rememberSaveable { mutableStateOf(decision?.title ?: supersedes?.title.orEmpty()) }
    var body by rememberSaveable { mutableStateOf(decision?.body ?: supersedes?.body.orEmpty()) }
    var part by rememberSaveable { mutableStateOf(decision?.milestone?.uuid ?: supersedes?.milestone?.uuid) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    WorkSheet(
        title = stringResource(if (decision != null) R.string.wr_dec_edit else if (supersedes != null) R.string.wr_dec_replace else R.string.wr_dec_propose),
        onDismiss = onDismiss, dismissible = !busy,
    ) {
        supersedes?.let { Text(stringResource(R.string.wr_dec_replaces, it.title), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp) }
        Text(stringResource(R.string.wr_dec_hint), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
        DorrTextField(value = title, onValueChange = { if (it.length <= 160) title = it }, label = stringResource(R.string.wr_dec_title), modifier = Modifier.fillMaxWidth())
        DorrTextField(value = body, onValueChange = { if (it.length <= 2000) body = it }, label = stringResource(R.string.wr_dec_body), singleLine = false, minLines = 4, modifier = Modifier.fillMaxWidth())
        if (parts.isNotEmpty()) {
            Text(stringResource(R.string.wr_part), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
            PartPicker(parts, part) { part = it }
        }
        error?.let { Text(it, color = WorkRed, fontSize = 12.5.sp) }
        SettingsPrimaryButton(text = stringResource(R.string.wr_send), loading = busy, enabled = title.isNotBlank() && body.isNotBlank(), onClick = {
            busy = true
            error = null
            scope.launch {
                val r = if (decision == null) {
                    val fields = buildMap<String, Any?> {
                        put("title", title.trim())
                        put("body", body.trim())
                        part?.let { put("milestone", it) }
                        supersedes?.let { put("supersedes", it.uuid) }
                    }
                    apiCall { ApiClient.workroom.proposeDecision(project, fields) }
                } else {
                    val fields = mapOf<String, Any?>("title" to title.trim(), "body" to body.trim(), "milestone" to (part ?: ""))
                    apiCall { ApiClient.workroom.updateDecision(project, decision.uuid, fields) }
                }
                busy = false
                if (r is ApiResult.Ok) onSaved() else error = r.errorText(offlineText) ?: genericError
            }
        })
    }
}

/** Agreeing to, or declining, the other side's proposal — with a short note when I want to say why. */
@Composable
private fun AnswerSheet(project: String, d: WorkDecisionDto, agree: Boolean, onDismiss: () -> Unit, onDone: () -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var note by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    WorkSheet(title = stringResource(if (agree) R.string.wr_dec_agree else R.string.wr_dec_decline), onDismiss = onDismiss, dismissible = !busy) {
        Text(d.title, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
        Text(d.body, color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp)
        DorrTextField(
            value = note, onValueChange = { if (it.length <= 300) note = it }, label = stringResource(R.string.wr_dec_note_label),
            placeholder = stringResource(if (agree) R.string.wr_dec_note_agree_hint else R.string.wr_dec_note_decline_hint), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth(),
        )
        error?.let { Text(it, color = WorkRed, fontSize = 12.5.sp) }
        SettingsPrimaryButton(text = stringResource(if (agree) R.string.wr_dec_agree else R.string.wr_dec_decline), loading = busy, onClick = {
            busy = true
            error = null
            scope.launch {
                val body = WorkNoteRequest(note.trim().ifEmpty { null })
                val r = if (agree) apiCall { ApiClient.workroom.agreeDecision(project, d.uuid, body) } else apiCall { ApiClient.workroom.declineDecision(project, d.uuid, body) }
                busy = false
                if (r is ApiResult.Ok) onDone() else error = r.errorText(offlineText) ?: genericError
            }
        })
    }
}
