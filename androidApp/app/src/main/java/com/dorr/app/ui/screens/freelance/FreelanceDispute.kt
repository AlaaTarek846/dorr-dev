package com.dorr.app.ui.screens.freelance

import androidx.compose.material.icons.Icons
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.width
import androidx.compose.material3.Icon
import androidx.compose.foundation.clickable
import com.dorr.app.ui.screens.profile.settingsSurface
import androidx.compose.material.icons.rounded.*
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelanceDisputeDto
import com.dorr.app.network.FreelanceDisputeOpenRequest
import com.dorr.app.network.FreelanceTextRequest
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.profile.SettingsPrimaryButton
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

/** The reasons a dispute may give (as the server names them). */
internal val DISPUTE_REASONS = listOf("not_as_agreed", "not_delivered", "late", "quality", "out_of_scope", "no_response", "other")

@Composable
internal fun disputeReasonLabel(reason: String): String = stringResource(
    when (reason) {
        "not_as_agreed" -> R.string.fl_dr_not_as_agreed
        "not_delivered" -> R.string.fl_dr_not_delivered
        "late" -> R.string.fl_dr_late
        "quality" -> R.string.fl_dr_quality
        "out_of_scope" -> R.string.fl_dr_out_of_scope
        "no_response" -> R.string.fl_dr_no_response
        else -> R.string.fl_dr_other
    },
)

@Composable
private fun disputeStatusLabel(status: String): String = stringResource(
    when (status) {
        "decided" -> R.string.fl_ds_decided
        "appealed" -> R.string.fl_ds_appealed
        "resolved" -> R.string.fl_ds_resolved
        else -> R.string.fl_ds_open
    },
)

/**
 * A dispute on a part of the work (FL-704). Opening one (`disputeUuid` null: the reason and what happened) freezes the part and its money
 * until DORR decides. Then: the status, what each side added (and adding more while it is open), the decision with when it takes effect,
 * and one appeal while it waits. Read on open and when the connection comes back.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun DisputePage(projectUuid: String, milestoneUuid: String?, disputeUuid: String?, onBack: () -> Unit, onOpened: (String) -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var dispute by remember { mutableStateOf<FreelanceDisputeDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var reason by rememberSaveable { mutableStateOf<String?>(null) }
    var text by rememberSaveable { mutableStateOf("") }
    var appealing by remember { mutableStateOf(false) }

    LaunchedEffect(projectUuid, disputeUuid, reconnect) {
        if (disputeUuid == null) return@LaunchedEffect
        try {
            dispute = ApiClient.freelance.dispute(auth(), projectUuid, disputeUuid).data
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    fun run(block: suspend () -> Unit) {
        busy = true
        error = null
        notice = null
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

    Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.fl_dispute), onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            val d = dispute
            if (disputeUuid == null) {
                // Opening one: the same look as the organizer form.
                FlIntro(Icons.Rounded.Gavel, stringResource(R.string.fl_dispute_open_title), stringResource(R.string.fl_dispute_open_note))
                FlHeading(Icons.Rounded.Flag, stringResource(R.string.fl_dispute_reason))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    DISPUTE_REASONS.forEach { r -> Chip(disputeReasonLabel(r), selected = reason == r, icon = Icons.Rounded.Flag) { reason = r } }
                }
                DorrTextField(text, { text = it.take(3000) }, label = stringResource(R.string.fl_dispute_details), placeholder = stringResource(R.string.fl_dispute_details_hint), icon = Icons.Rounded.Notes, singleLine = false, minLines = 4, modifier = Modifier.fillMaxWidth())
                error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Color(0xFFDC2626)) }
                FlAction(
                    stringResource(R.string.fl_dispute_open), Icons.Rounded.Gavel, FlTone.Warn, loading = busy, enabled = reason != null && text.trim().length >= 20 && milestoneUuid != null,
                    onClick = {
                        run {
                            val opened = ApiClient.freelance.openDispute(auth(), projectUuid, milestoneUuid!!, FreelanceDisputeOpenRequest(reason!!, text.trim())).data
                            if (opened != null) { text = ""; onOpened(opened.uuid) }
                        }
                    },
                )
            } else if (d == null) {
                FlLoading(error)
            } else {
                val sc = if (d.status == "resolved") Color(0xFF16A34A) else Color(0xFFD97706)
                Card {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        Box(Modifier.size(46.dp).clip(RoundedCornerShape(15.dp)).background(sc.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.Gavel, null, tint = sc, modifier = Modifier.size(24.dp))
                        }
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                            Text(disputeStatusLabel(d.status), color = sc, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
                            Text(stringResource(if (d.mine) R.string.fl_dispute_opened_by_me else R.string.fl_dispute_opened_by_them) + "  ·  " + d.reasonLabel, color = settingsMut(), fontSize = 12.sp)
                        }
                    }
                    d.milestone?.let { Field(stringResource(R.string.fl_ms_title), "${it.title}  ·  ‎${money(it.amountMinor)} ${d.currency.orEmpty()}") }
                    Text(d.details, color = settingsInk(), fontSize = 13.sp, lineHeight = 20.sp)
                    if (d.status == "open") d.respondBy?.let { FlNotice(stringResource(R.string.fl_dispute_respond_by, "‎" + it.take(10)), Icons.Rounded.Schedule, Color(0xFFD97706)) }
                    FlNotice(stringResource(R.string.fl_dispute_frozen), Icons.Rounded.Lock, Color(0xFF0EA5E9))
                }
                d.decision?.let { dec ->
                    Card(Icons.Rounded.Balance, stringResource(R.string.fl_dispute_decision)) {
                        Text(
                            when (dec.type) {
                                "refund" -> stringResource(R.string.fl_dd_refund)
                                "release" -> stringResource(R.string.fl_dd_release)
                                else -> stringResource(R.string.fl_dd_split, (dec.freelancerBp ?: 0) / 100)
                            },
                            color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold,
                        )
                        dec.note?.let { Text(it, color = settingsInk(), fontSize = 13.sp, lineHeight = 20.sp) }
                        if (d.status == "decided") dec.executesAt?.let { FlNotice(stringResource(R.string.fl_dispute_runs_at, "‎" + it.take(10)), Icons.Rounded.Schedule, Color(0xFFD97706)) }
                        if (dec.final) Text(stringResource(R.string.fl_dispute_final), color = settingsMut(), fontSize = 12.sp)
                    }
                }
                d.appeal?.let { FlNotice(stringResource(R.string.fl_dispute_appealed_note), Icons.Rounded.HourglassTop, Color(0xFFD97706)) }
                Card(Icons.Rounded.Forum, stringResource(R.string.fl_dispute_evidence)) {
                    if (d.evidence.isEmpty()) Text(stringResource(R.string.fl_dispute_no_evidence), color = settingsMut(), fontSize = 12.sp)
                    d.evidence.forEach { e ->
                        Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(if (e.mine) settingsAccent().copy(alpha = 0.08f) else Color(0x146B7280)).padding(12.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                            Text(stringResource(if (e.mine) R.string.fl_dispute_you else R.string.fl_dispute_them) + "  ·  ‎" + e.at?.take(10).orEmpty(), color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
                            Text(e.body, color = settingsInk(), fontSize = 13.sp, lineHeight = 20.sp)
                            e.file?.let { Text("📎 " + it.name, color = settingsMut(), fontSize = 12.sp) }
                        }
                    }
                    if (d.can.evidence) {
                        DorrTextField(text, { text = it.take(3000) }, placeholder = stringResource(R.string.fl_dispute_add_hint), icon = Icons.Rounded.Notes, singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
                        FlAction(stringResource(R.string.fl_dispute_add), Icons.Rounded.Send, FlTone.Primary) {
                            if (text.trim().length >= 5) run {
                                ApiClient.freelance.disputeEvidence(auth(), projectUuid, d.uuid, FreelanceTextRequest(text.trim())).data?.let { dispute = it }
                                text = ""
                            }
                        }
                    }
                }
                notice?.let { FlNotice(it, Icons.Rounded.CheckCircle, Color(0xFF16A34A)) }
                error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, Color(0xFFDC2626)) }
                if (d.can.appeal) {
                    if (!appealing) FlAction(stringResource(R.string.fl_dispute_appeal), Icons.Rounded.Gavel, FlTone.Danger) { appealing = true; text = "" }
                    else {
                        FlIntro(Icons.Rounded.Gavel, stringResource(R.string.fl_dispute_appeal), stringResource(R.string.fl_dispute_appeal_note))
                        DorrTextField(text, { text = it.take(2000) }, placeholder = stringResource(R.string.fl_dispute_appeal_hint), icon = Icons.Rounded.Notes, singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
                        FlAction(stringResource(R.string.fl_dispute_appeal_send), Icons.Rounded.Send, FlTone.Primary, loading = busy, enabled = text.trim().length >= 20) {
                            run {
                                val r = ApiClient.freelance.appealDispute(auth(), projectUuid, d.uuid, FreelanceTextRequest(reason = text.trim()))
                                r.data?.let { dispute = it }
                                notice = r.message
                                appealing = false
                                text = ""
                            }
                        }
                    }
                }
            }
            Spacer(Modifier.height(20.dp))
        }
    }
}
