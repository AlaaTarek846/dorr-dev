package com.dorr.app.ui.screens.freelance.workroom

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.DeleteForever
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Replay
import androidx.compose.material.icons.rounded.SwapHoriz
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.WorkDecisionDto
import com.dorr.app.network.WorkDecisionSummaryDto
import com.dorr.app.network.WorkDecisionsDto
import com.dorr.app.network.WorkNoteRequest
import com.dorr.app.network.WorkPartDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.components.PullToRefreshHost
import com.dorr.app.ui.screens.ConfirmDialog
import com.dorr.app.ui.screens.freelance.Chip
import com.dorr.app.ui.screens.freelance.FlAction
import com.dorr.app.ui.screens.freelance.FlCorners
import com.dorr.app.ui.screens.freelance.FlDotPill
import com.dorr.app.ui.screens.freelance.FlNotice
import com.dorr.app.ui.screens.freelance.FlRetry
import com.dorr.app.ui.screens.freelance.FlSheet
import com.dorr.app.ui.screens.freelance.FlSkeletonList
import com.dorr.app.ui.screens.freelance.FlStat
import com.dorr.app.ui.screens.freelance.FlTone
import com.dorr.app.ui.screens.freelance.NearEnd
import com.dorr.app.ui.screens.freelance.PagedSpinner
import com.dorr.app.ui.screens.freelance.auth
import com.dorr.app.ui.screens.freelance.message
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

private val FILTERS = listOf("all", "waiting", "agreed", "declined")

/**
 * The Workroom's decision log: a detail the two sides settle («the logo is blue») instead of losing it in the chat. One side proposes (and is
 * agreed by that act), the other agrees or declines with an optional note; an agreed decision is the record and is never edited — a change is
 * a new decision that replaces it. Read once when it opens, on a live event, on a pull and when the connection returns.
 */
@Composable
internal fun DecisionsTab(
    project: String,
    parts: List<WorkPartDto>,
    freelancer: String,
    live: WorkroomLive,
    onSummary: (WorkDecisionSummaryDto, Boolean) -> Unit,
) {
    val generic = stringResource(R.string.fl_error_generic)
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    var filter by rememberSaveable { mutableStateOf("all") }
    var version by remember { mutableIntStateOf(0) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var proposing by remember { mutableStateOf(false) }
    // A new decision that replaces an agreed one, or the one of mine that is being changed.
    var replacing by remember { mutableStateOf<WorkDecisionDto?>(null) }
    var editing by remember { mutableStateOf<WorkDecisionDto?>(null) }
    var answering by remember { mutableStateOf<Pair<WorkDecisionDto, Boolean>?>(null) }
    var withdrawing by remember { mutableStateOf<WorkDecisionDto?>(null) }

    val meta = remember(project) { mutableStateOf<WorkDecisionsDto?>(null) }
    val pager = remember(project, filter) {
        val status = filter.takeIf { it == "agreed" || it == "declined" }
        val waiting = if (filter == "waiting") 1 else null
        workPager(meta, { it.decisions }, { it.uuid }) { page ->
            ApiClient.freelanceWorkroom.decisions(auth(), project, status = status, waiting = waiting, perPage = WORK_PAGE, page = page)
        }
    }
    LaunchedEffect(pager, reconnect, live.decisions, version) { pager.reload(generic) }
    val answer = meta.value
    LaunchedEffect(answer) { answer?.let { onSummary(it.summary, it.readOnly) } }
    val listState = rememberLazyListState()
    listState.NearEnd(pager.rows.size) { scope.launch { pager.loadMore(generic) } }

    fun act(block: suspend () -> Unit) {
        busy = true
        error = null
        scope.launch {
            try {
                block()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.message(generic)
            }
            busy = false
        }
    }

    val summary = answer?.summary
    val canPropose = answer != null && answer.can.decisionCreate && !answer.readOnly

    PullToRefreshHost(Modifier.fillMaxSize()) {
        LazyColumn(
            Modifier.fillMaxSize(), state = listState,
            contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 4.dp, bottom = 28.dp), verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            if (summary != null) item(key = "summary") { DecisionsSummary(summary) }
            if (summary != null && summary.waitingForMe > 0) item(key = "waiting") {
                FlNotice(stringResource(R.string.wr_dec_waiting_notice, summary.waitingForMe), Icons.Rounded.HourglassTop, WrAmber)
            }
            if (canPropose) item(key = "add") { FlAction(stringResource(R.string.wr_dec_propose), Icons.Rounded.Add, FlTone.Primary) { proposing = true } }
            if (summary != null && summary.total > 0) item(key = "filters") {
                WrChipRow {
                    FILTERS.forEach { f ->
                        val label = when (f) {
                            "waiting" -> stringResource(R.string.wr_dec_filter_waiting) + if (summary.waitingForMe > 0) "  ${summary.waitingForMe}" else ""
                            "agreed" -> decisionLabel("agreed") + "  ${summary.agreed}"
                            "declined" -> decisionLabel("declined") + "  ${summary.declined}"
                            else -> stringResource(R.string.wr_all)
                        }
                        Chip(label, filter == f, when (f) { "waiting" -> Icons.Rounded.HourglassTop; "agreed" -> Icons.Rounded.CheckCircle; "declined" -> Icons.Rounded.Close; else -> Icons.Rounded.Gavel }) { filter = f }
                    }
                }
            }
            error?.let { item(key = "error") { FlNotice(it, Icons.Rounded.ErrorOutline, WrRed) } }
            if (answer?.readOnly == true) item(key = "readonly") { FlNotice(stringResource(R.string.wr_read_only), Icons.Rounded.Lock, WrAmber) }
            when {
                !pager.loaded && pager.error == null -> item(key = "loading") { FlSkeletonList(3, 120.dp) }
                pager.rows.isEmpty() && pager.error != null -> item(key = "failed") { FlRetry(pager.error ?: generic) }
                pager.rows.isEmpty() -> item(key = "empty") {
                    val filtered = filter != "all"
                    WrEmpty(
                        Icons.Rounded.Gavel,
                        stringResource(if (filtered) R.string.wr_dec_none_filtered else R.string.wr_dec_none),
                        stringResource(if (filtered) R.string.wr_dec_none_filtered_body else R.string.wr_dec_none_body),
                    )
                }
            }
            items(pager.rows, key = { it.uuid }) { d ->
                DecisionCard(
                    d, freelancer, busy,
                    onAgree = { answering = d to true },
                    onDecline = { answering = d to false },
                    onReplace = { replacing = d },
                    onEdit = { editing = d },
                    onWithdraw = { withdrawing = d },
                )
            }
            item(key = "more") { PagedSpinner(pager) }
        }
    }

    if (proposing || replacing != null) DecisionSheet(
        initial = null, replaces = replacing, parts = parts, busy = busy,
        onDismiss = { if (!busy) { proposing = false; replacing = null } },
    ) { body ->
        act {
            ApiClient.freelanceWorkroom.proposeDecision(auth(), project, body)
            proposing = false
            replacing = null
            filter = "all"
            version++
        }
    }
    editing?.let { d ->
        DecisionSheet(initial = d, replaces = null, parts = parts, busy = busy, onDismiss = { if (!busy) editing = null }) { body ->
            act {
                ApiClient.freelanceWorkroom.updateDecision(auth(), project, d.uuid, body)
                editing = null
                version++
            }
        }
    }
    answering?.let { (d, agree) ->
        AnswerSheet(d, agree, busy, onDismiss = { if (!busy) answering = null }) { note ->
            act {
                val request = WorkNoteRequest(note.ifBlank { null })
                if (agree) ApiClient.freelanceWorkroom.agreeDecision(auth(), project, d.uuid, request)
                else ApiClient.freelanceWorkroom.declineDecision(auth(), project, d.uuid, request)
                answering = null
                version++
            }
        }
    }
    withdrawing?.let { d ->
        ConfirmDialog(
            Icons.Rounded.Replay, stringResource(R.string.wr_dec_withdraw_title), stringResource(R.string.wr_dec_withdraw_body, d.title),
            onConfirm = { act { ApiClient.freelanceWorkroom.withdrawDecision(auth(), project, d.uuid); withdrawing = null; version++ } },
            onDismiss = { if (!busy) withdrawing = null }, loading = busy, confirmLabel = stringResource(R.string.wr_dec_withdraw),
        )
    }
}

@Composable
private fun DecisionsSummary(s: WorkDecisionSummaryDto) {
    Row(Modifier.fillMaxWidth().settingsSurface(FlCorners.card).padding(16.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        FlStat(Icons.Rounded.HourglassTop, stringResource(R.string.wr_dec_for_me), s.waitingForMe.toString(), Modifier.weight(1f))
        FlStat(Icons.Rounded.CheckCircle, decisionLabel("agreed"), s.agreed.toString(), Modifier.weight(1f))
        FlStat(Icons.Rounded.Close, decisionLabel("declined"), s.declined.toString(), Modifier.weight(1f))
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun DecisionCard(
    d: WorkDecisionDto, freelancer: String, busy: Boolean,
    onAgree: () -> Unit, onDecline: () -> Unit, onReplace: () -> Unit, onEdit: () -> Unit, onWithdraw: () -> Unit,
) {
    val tone = decisionTone(d.status)
    // A decision that waits for my answer stands out.
    val mineToAnswer = d.can.decisionAgree || d.can.decisionDecline
    Column(
        Modifier.fillMaxWidth().settingsSurface(FlCorners.card).then(if (mineToAnswer) Modifier.border(1.5.dp, WrAmber.copy(alpha = 0.7f), FlCorners.card) else Modifier).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(9.dp),
    ) {
        Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(d.title, color = settingsInk(), fontSize = 15.5.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 22.sp, modifier = Modifier.weight(1f))
            FlDotPill(decisionLabel(d.status), tone)
        }
        Text(d.body, color = settingsInk(), fontSize = 13.5.sp, lineHeight = 20.sp, maxLines = 8, overflow = TextOverflow.Ellipsis)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            d.milestone?.let { WrTag("${it.position}. ${it.title}", Icons.Rounded.Flag, settingsAccent()) }
            WrTag(stringResource(R.string.wr_dec_by, sideName(d.proposedBySide, freelancer)), Icons.Rounded.Person)
            if (mineToAnswer) WrTag(stringResource(R.string.wr_dec_your_answer), Icons.Rounded.HourglassTop, WrAmber)
        }
        // Who has agreed so far (a proposal is agreed by whoever made it).
        if (d.status == "proposed" || d.status == "agreed") Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            AgreedChip(stringResource(R.string.wr_you), d.clientAgreed)
            AgreedChip(freelancer.ifBlank { stringResource(R.string.wr_freelancer) }, d.freelancerAgreed)
        }
        d.answerNote?.takeIf { it.isNotBlank() }?.let { note ->
            Text(
                "“$note”", color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp, fontStyle = FontStyle.Italic,
                modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsMut().copy(alpha = 0.08f)).padding(10.dp),
            )
        }
        d.supersedes?.let { Text(stringResource(R.string.wr_dec_replaces, it.title), color = settingsMut(), fontSize = 12.sp) }
        d.supersededBy?.let { Text(stringResource(R.string.wr_dec_replaced_by, it.title), color = WrAmber, fontSize = 12.sp, fontWeight = FontWeight.SemiBold) }
        Text(wrLocalDateTime(d.answeredAt ?: d.createdAt), color = settingsMut(), fontSize = 11.5.sp)
        if (d.can.decisionAgree || d.can.decisionDecline) Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            if (d.can.decisionAgree) FlAction(stringResource(R.string.wr_dec_agree), Icons.Rounded.Check, FlTone.Primary, enabled = !busy, modifier = Modifier.weight(1f), onClick = onAgree)
            if (d.can.decisionDecline) FlAction(stringResource(R.string.wr_dec_decline), Icons.Rounded.Close, FlTone.Danger, enabled = !busy, modifier = Modifier.weight(1f), onClick = onDecline)
        }
        if (d.can.decisionSupersede || d.can.decisionEdit || d.can.decisionWithdraw) Row(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
            if (d.can.decisionSupersede) WrLink(stringResource(R.string.wr_dec_replace), Icons.Rounded.SwapHoriz, settingsAccent(), onReplace)
            if (d.can.decisionEdit) WrLink(stringResource(R.string.wr_edit), Icons.Rounded.Edit, settingsAccent(), onEdit)
            if (d.can.decisionWithdraw) WrLink(stringResource(R.string.wr_dec_withdraw), Icons.Rounded.DeleteForever, WrRed, onWithdraw)
        }
    }
}

/** One side's part in a decision: a check when it agreed, an hourglass while it has not answered. */
@Composable
private fun AgreedChip(name: String, agreed: Boolean) {
    val color = if (agreed) WrGreen else WrGray
    WrTag(name, if (agreed) Icons.Rounded.Verified else Icons.Rounded.HourglassTop, color)
}

/** The form of a decision: a new one, one that replaces an agreed decision, or the one being changed (while it still waits). */
@Composable
private fun DecisionSheet(
    initial: WorkDecisionDto?, replaces: WorkDecisionDto?, parts: List<WorkPartDto>, busy: Boolean,
    onDismiss: () -> Unit, onSave: (Map<String, Any?>) -> Unit,
) {
    var title by rememberSaveable { mutableStateOf(initial?.title ?: replaces?.title.orEmpty()) }
    var body by rememberSaveable { mutableStateOf(initial?.body ?: replaces?.body.orEmpty()) }
    var part by rememberSaveable { mutableStateOf(initial?.milestone?.uuid ?: replaces?.milestone?.uuid) }
    FlSheet(
        Icons.Rounded.Gavel,
        stringResource(if (initial != null) R.string.wr_dec_edit else if (replaces != null) R.string.wr_dec_replace else R.string.wr_dec_new),
        stringResource(R.string.wr_dec_form_body), onDismiss = onDismiss,
        footer = {
            FlAction(
                stringResource(if (initial != null) R.string.common_save else R.string.wr_dec_send), Icons.Rounded.Check, FlTone.Primary,
                enabled = title.trim().length >= 3 && body.isNotBlank(), loading = busy,
            ) {
                onSave(
                    buildMap {
                        put("title", title.trim())
                        put("body", body.trim())
                        put("milestone", part ?: "")
                        if (replaces != null) put("supersedes", replaces.uuid)
                    },
                )
            }
            FlAction(stringResource(R.string.common_cancel), null, enabled = !busy, onClick = onDismiss)
        },
    ) {
        replaces?.let { FlNotice(stringResource(R.string.wr_dec_replacing, it.title), Icons.Rounded.SwapHoriz, settingsAccent()) }
        DorrTextField(title, { title = it.take(160) }, label = stringResource(R.string.wr_dec_title), icon = Icons.Rounded.Gavel, modifier = Modifier.fillMaxWidth())
        DorrTextField(body, { body = it.take(2000) }, label = stringResource(R.string.wr_dec_text), singleLine = false, minLines = 4, modifier = Modifier.fillMaxWidth())
        if (parts.isNotEmpty()) Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(stringResource(R.string.wr_part), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            PartPicker(parts, part) { part = it }
        }
    }
}

/** Agreeing or declining: the decision again, and a few words (optional) that stay with the answer. */
@Composable
private fun AnswerSheet(d: WorkDecisionDto, agree: Boolean, busy: Boolean, onDismiss: () -> Unit, onSend: (String) -> Unit) {
    var note by rememberSaveable { mutableStateOf("") }
    FlSheet(
        if (agree) Icons.Rounded.Check else Icons.Rounded.Close,
        stringResource(if (agree) R.string.wr_dec_agree_title else R.string.wr_dec_decline_title), d.title, onDismiss = onDismiss,
        footer = {
            FlAction(stringResource(if (agree) R.string.wr_dec_agree else R.string.wr_dec_decline), if (agree) Icons.Rounded.Check else Icons.Rounded.Close, if (agree) FlTone.Primary else FlTone.Danger, loading = busy) { onSend(note.trim()) }
            FlAction(stringResource(R.string.common_cancel), null, enabled = !busy, onClick = onDismiss)
        },
    ) {
        Text(d.body, color = settingsMut(), fontSize = 13.sp, lineHeight = 20.sp, maxLines = 6, overflow = TextOverflow.Ellipsis)
        DorrTextField(
            note, { note = it.take(300) }, label = stringResource(R.string.wr_dec_note), placeholder = stringResource(if (agree) R.string.wr_dec_note_hint_agree else R.string.wr_dec_note_hint_decline),
            singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth(),
        )
    }
}
