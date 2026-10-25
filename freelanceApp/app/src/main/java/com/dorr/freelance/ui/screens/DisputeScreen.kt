package com.dorr.freelance.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
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
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.DisputeDto
import com.dorr.freelance.net.DisputeOpenRequest
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.TextRequest
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

private val REASONS = listOf("not_as_agreed", "not_delivered", "late", "quality", "out_of_scope", "no_response", "other")

@Composable
private fun reasonLabel(reason: String): String = stringResource(
    when (reason) {
        "not_as_agreed" -> R.string.dp_r_not_as_agreed
        "not_delivered" -> R.string.dp_r_not_delivered
        "late" -> R.string.dp_r_late
        "quality" -> R.string.dp_r_quality
        "out_of_scope" -> R.string.dp_r_out_of_scope
        "no_response" -> R.string.dp_r_no_response
        else -> R.string.dp_r_other
    },
)

/**
 * A dispute on a part of my work (FL-704): opening one (`disputeUuid` null), or following it — what each side added, DORR's decision and
 * when it takes effect, one appeal. Read on open, when a notice arrives and when the connection comes back.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun DisputeScreen(projectUuid: String, milestoneUuid: String?, disputeUuid: String?, onBack: () -> Unit, onOpened: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var dispute by remember { mutableStateOf<DisputeDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var reason by rememberSaveable { mutableStateOf<String?>(null) }
    var text by rememberSaveable { mutableStateOf("") }
    var appealing by remember { mutableStateOf(false) }

    suspend fun load() {
        if (disputeUuid == null) return
        val result = apiCall { ApiClient.agreements.dispute(projectUuid, disputeUuid) }
        if (result is ApiResult.Ok) dispute = result.data else if (dispute == null) error = result.errorText(offlineText) ?: genericError
    }
    LaunchedEffect(projectUuid, disputeUuid, reconnect) { load() }
    LaunchedEffect(projectUuid, disputeUuid) { Realtime.changed.collect { load() } }

    fun call(block: suspend () -> ApiResult<DisputeDto>, after: (DisputeDto) -> Unit = { dispute = it }) {
        busy = true
        error = null
        scope.launch {
            val result = block()
            if (result is ApiResult.Ok) after(result.data) else error = result.errorText(offlineText) ?: genericError
            busy = false
        }
    }

    Page(title = stringResource(R.string.dp_title), onBack = onBack) {
        val d = dispute
        if (disputeUuid == null) {
            Card {
                FieldLabel(stringResource(R.string.dp_open_title))
                Text(stringResource(R.string.dp_open_note), color = settingsMut(), fontSize = 12.sp)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    REASONS.forEach { r ->
                        val on = reason == r
                        Text(
                            reasonLabel(r), color = if (on) Color.White else settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(if (on) settingsAccent() else settingsAccent().copy(alpha = 0.08f)).clickable { reason = r }.padding(horizontal = 12.dp, vertical = 6.dp),
                        )
                    }
                }
                DorrTextField(text, { text = it.take(3000) }, placeholder = stringResource(R.string.dp_details_hint), singleLine = false, minLines = 4, modifier = Modifier.fillMaxWidth())
            }
            ErrorBanner(error, Modifier.fillMaxWidth())
            SettingsPrimaryButton(text = stringResource(R.string.dp_open), loading = busy, enabled = reason != null && text.trim().length >= 20 && milestoneUuid != null, onClick = {
                call({ apiCall { ApiClient.agreements.openDispute(projectUuid, milestoneUuid!!, DisputeOpenRequest(reason!!, text.trim())) } }) { onOpened(it.uuid) }
            })
            return@Page
        }
        if (d == null) {
            if (error == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
            ErrorBanner(error, Modifier.fillMaxWidth())
            return@Page
        }
        Card {
            Text(stringResource(when (d.status) { "decided" -> R.string.dp_s_decided; "appealed" -> R.string.dp_s_appealed; "resolved" -> R.string.dp_s_resolved; else -> R.string.dp_s_open }),
                color = if (d.status == "resolved") Color(0xFF16A34A) else Color(0xFFD97706), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
            d.milestone?.let { Text("${it.title}  ·  ‎${formatMinor(it.amountMinor, null)} ${d.currency.orEmpty()}", color = settingsInk(), fontSize = 13.sp) }
            Text(stringResource(if (d.mine) R.string.dp_by_me else R.string.dp_by_them) + "  ·  " + d.reasonLabel, color = settingsMut(), fontSize = 12.sp)
            Text(d.details, color = settingsInk(), fontSize = 13.sp)
            Text(stringResource(R.string.dp_frozen), color = settingsMut(), fontSize = 11.sp)
        }
        d.decision?.let { dec ->
            Card {
                FieldLabel(stringResource(R.string.dp_decision))
                Text(
                    when (dec.type) {
                        "refund" -> stringResource(R.string.dp_d_refund)
                        "release" -> stringResource(R.string.dp_d_release)
                        else -> stringResource(R.string.dp_d_split, (dec.freelancerBp ?: 0) / 100)
                    },
                    color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
                )
                dec.note?.let { Text(it, color = settingsInk(), fontSize = 13.sp) }
                if (d.status == "decided") dec.executesAt?.let { Text(stringResource(R.string.dp_runs_at, "‎" + it.take(10)), color = settingsMut(), fontSize = 12.sp) }
            }
        }
        Card {
            FieldLabel(stringResource(R.string.dp_evidence))
            d.evidence.forEach { e ->
                Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(if (e.mine) settingsAccent().copy(alpha = 0.06f) else Color(0x0F6B7280)).padding(10.dp)) {
                    Text(stringResource(if (e.mine) R.string.dp_you else R.string.dp_them) + "  ·  ‎" + e.at?.take(10).orEmpty(), color = settingsMut(), fontSize = 11.sp)
                    Text(e.body, color = settingsInk(), fontSize = 13.sp)
                }
            }
            if (d.can.evidence) {
                DorrTextField(text, { text = it.take(3000) }, placeholder = stringResource(R.string.dp_add_hint), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
                Text(stringResource(R.string.dp_add), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(enabled = !busy && text.trim().length >= 5) {
                    call({ apiCall { ApiClient.agreements.disputeEvidence(projectUuid, d.uuid, TextRequest(body = text.trim())) } }) { dispute = it; text = "" }
                }.padding(vertical = 6.dp))
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
        if (d.can.appeal) {
            if (!appealing) Text(stringResource(R.string.dp_appeal), color = Color(0xFFDC2626), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable { appealing = true; text = "" }.padding(vertical = 8.dp))
            else Card {
                Text(stringResource(R.string.dp_appeal_note), color = settingsMut(), fontSize = 12.sp)
                DorrTextField(text, { text = it.take(2000) }, placeholder = stringResource(R.string.dp_appeal_hint), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
                SettingsPrimaryButton(text = stringResource(R.string.dp_appeal_send), loading = busy, enabled = text.trim().length >= 20, onClick = {
                    call({ apiCall { ApiClient.agreements.appealDispute(projectUuid, d.uuid, TextRequest(reason = text.trim())) } }) { dispute = it; appealing = false; text = "" }
                })
            }
        }
    }
}
