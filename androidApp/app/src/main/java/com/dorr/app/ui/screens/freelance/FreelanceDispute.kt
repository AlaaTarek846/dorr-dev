package com.dorr.app.ui.screens.freelance

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
                // Opening one.
                Card {
                    Label(stringResource(R.string.fl_dispute_open_title))
                    Text(stringResource(R.string.fl_dispute_open_note), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        DISPUTE_REASONS.forEach { r -> Chip(disputeReasonLabel(r), selected = reason == r) { reason = r } }
                    }
                    DorrTextField(text, { text = it.take(3000) }, label = stringResource(R.string.fl_dispute_details), placeholder = stringResource(R.string.fl_dispute_details_hint), singleLine = false, minLines = 4, modifier = Modifier.fillMaxWidth())
                }
                error?.let { Text(it, color = Color(0xFFDC2626), fontSize = 13.sp) }
                SettingsPrimaryButton(
                    text = stringResource(R.string.fl_dispute_open), loading = busy, enabled = reason != null && text.trim().length >= 20 && milestoneUuid != null,
                    onClick = {
                        run {
                            val opened = ApiClient.freelance.openDispute(auth(), projectUuid, milestoneUuid!!, FreelanceDisputeOpenRequest(reason!!, text.trim())).data
                            if (opened != null) { text = ""; onOpened(opened.uuid) }
                        }
                    },
                )
            } else if (d == null) {
                Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) {
                    if (error != null) Text(error.orEmpty(), color = settingsMut(), fontSize = 14.sp) else CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
                }
            } else {
                Card {
                    Text(disputeStatusLabel(d.status), color = if (d.status == "resolved") Color(0xFF16A34A) else Color(0xFFD97706), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                    d.milestone?.let { Text("${it.title}  ·  ‎${money(it.amountMinor)} ${d.currency.orEmpty()}", color = settingsInk(), fontSize = 13.sp) }
                    Text(stringResource(if (d.mine) R.string.fl_dispute_opened_by_me else R.string.fl_dispute_opened_by_them) + "  ·  " + d.reasonLabel, color = settingsMut(), fontSize = 12.sp)
                    Text(d.details, color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp)
                    if (d.status == "open") d.respondBy?.let { Text(stringResource(R.string.fl_dispute_respond_by, "‎" + it.take(10)), color = settingsMut(), fontSize = 12.sp) }
                    Text(stringResource(R.string.fl_dispute_frozen), color = settingsMut(), fontSize = 11.sp)
                }
                d.decision?.let { dec ->
                    Card {
                        Label(stringResource(R.string.fl_dispute_decision))
                        Text(
                            when (dec.type) {
                                "refund" -> stringResource(R.string.fl_dd_refund)
                                "release" -> stringResource(R.string.fl_dd_release)
                                else -> stringResource(R.string.fl_dd_split, (dec.freelancerBp ?: 0) / 100)
                            },
                            color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
                        )
                        dec.note?.let { Text(it, color = settingsInk(), fontSize = 13.sp) }
                        if (d.status == "decided") dec.executesAt?.let { Text(stringResource(R.string.fl_dispute_runs_at, "‎" + it.take(10)), color = settingsMut(), fontSize = 12.sp) }
                        if (dec.final) Text(stringResource(R.string.fl_dispute_final), color = settingsMut(), fontSize = 12.sp)
                    }
                }
                d.appeal?.let { Text(stringResource(R.string.fl_dispute_appealed_note), color = Color(0xFFD97706), fontSize = 12.sp) }
                Card {
                    Label(stringResource(R.string.fl_dispute_evidence))
                    if (d.evidence.isEmpty()) Text(stringResource(R.string.fl_dispute_no_evidence), color = settingsMut(), fontSize = 12.sp)
                    d.evidence.forEach { e ->
                        Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(if (e.mine) settingsAccent().copy(alpha = 0.06f) else Color(0x0F6B7280)).padding(10.dp)) {
                            Text(stringResource(if (e.mine) R.string.fl_dispute_you else R.string.fl_dispute_them) + "  ·  ‎" + e.at?.take(10).orEmpty(), color = settingsMut(), fontSize = 11.sp)
                            Text(e.body, color = settingsInk(), fontSize = 13.sp)
                            e.file?.let { Text("📎 " + it.name, color = settingsMut(), fontSize = 12.sp) }
                        }
                    }
                    if (d.can.evidence) {
                        DorrTextField(text, { text = it.take(3000) }, placeholder = stringResource(R.string.fl_dispute_add_hint), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
                        TextAction(stringResource(R.string.fl_dispute_add)) {
                            if (text.trim().length >= 5) run {
                                ApiClient.freelance.disputeEvidence(auth(), projectUuid, d.uuid, FreelanceTextRequest(text.trim())).data?.let { dispute = it }
                                text = ""
                            }
                        }
                    }
                }
                notice?.let { Text(it, color = Color(0xFF16A34A), fontSize = 13.sp, fontWeight = FontWeight.Bold) }
                error?.let { Text(it, color = Color(0xFFDC2626), fontSize = 13.sp) }
                if (d.can.appeal) {
                    if (!appealing) TextAction(stringResource(R.string.fl_dispute_appeal), danger = true) { appealing = true; text = "" }
                    else Card {
                        Text(stringResource(R.string.fl_dispute_appeal_note), color = settingsMut(), fontSize = 12.sp)
                        DorrTextField(text, { text = it.take(2000) }, placeholder = stringResource(R.string.fl_dispute_appeal_hint), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
                        SettingsPrimaryButton(text = stringResource(R.string.fl_dispute_appeal_send), loading = busy, enabled = text.trim().length >= 20, onClick = {
                            run {
                                val r = ApiClient.freelance.appealDispute(auth(), projectUuid, d.uuid, FreelanceTextRequest(reason = text.trim()))
                                r.data?.let { dispute = it }
                                notice = r.message
                                appealing = false
                                text = ""
                            }
                        })
                    }
                }
            }
            Spacer(Modifier.height(20.dp))
        }
    }
}
