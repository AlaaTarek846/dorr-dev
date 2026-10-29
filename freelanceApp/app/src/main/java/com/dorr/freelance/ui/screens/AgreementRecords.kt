package com.dorr.freelance.ui.screens

import android.content.Intent
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.ReceiptDto
import com.dorr.freelance.net.TrailStepDto
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.components.FieldLabel
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import kotlinx.coroutines.launch

/**
 * My receipts of this project (FL-511): one per money that came to me from a part, newest first. Read when shown, when the agreement
 * changes (`refreshKey`) and when the connection comes back; a receipt opens with its lines and can be shared as text.
 */
@Composable
internal fun ProjectReceiptsCard(projectUuid: String, refreshKey: String) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var list by remember { mutableStateOf<List<ReceiptDto>>(emptyList()) }
    var open by remember { mutableStateOf<ReceiptDto?>(null) }
    LaunchedEffect(refreshKey, reconnect) {
        (apiCall { ApiClient.agreements.receipts(project = projectUuid) } as? ApiResult.Ok)?.data?.let { list = it }
    }
    if (list.isEmpty()) return
    Card {
        FieldLabel(stringResource(R.string.rcp_title))
        list.forEach { r ->
            Row(Modifier.fillMaxWidth().clickable {
                scope.launch { open = (apiCall { ApiClient.agreements.receipt(r.uuid) } as? ApiResult.Ok)?.data ?: r }
            }.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(r.title.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                    Text("‎${r.number} · ${r.milestone?.title.orEmpty()} · ${r.issuedAt?.take(10).orEmpty()}", color = settingsMut(), fontSize = 11.sp)
                }
                Text("‎${formatMinor(r.totalMinor, null)} ${r.currency}", color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            }
        }
    }
    open?.let { r ->
        AlertDialog(
            onDismissRequest = { open = null },
            title = { Text(r.title.orEmpty(), fontWeight = FontWeight.ExtraBold) },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    ReceiptLine(stringResource(R.string.rcp_number), "‎${r.number}")
                    ReceiptLine(stringResource(R.string.rcp_date), "‎" + r.issuedAt?.take(16)?.replace('T', ' ').orEmpty())
                    ReceiptLine(stringResource(R.string.rcp_part), r.milestone?.title.orEmpty())
                    if (r.amountMinor > 0) ReceiptLine(stringResource(R.string.ag_price), "‎${formatMinor(r.amountMinor, null)} ${r.currency}")
                    if (r.feeMinor > 0) ReceiptLine(stringResource(R.string.ag_commission), "‎${formatMinor(r.feeMinor, null)} ${r.currency}")
                    ReceiptLine(stringResource(R.string.ag_net), "‎${formatMinor(r.totalMinor, null)} ${r.currency}")
                    r.reasonText?.let { ReceiptLine(stringResource(R.string.rcp_reason), it) }
                }
            },
            confirmButton = {
                TextButton(onClick = {
                    context.startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, r.text ?: r.number), null))
                }) { Text(stringResource(R.string.rcp_share), color = settingsAccent()) }
            },
            dismissButton = { TextButton(onClick = { open = null }) { Text(stringResource(R.string.common_ok), color = settingsMut()) } },
        )
    }
}

@Composable
private fun ReceiptLine(label: String, value: String) {
    Row(Modifier.fillMaxWidth()) {
        Text(label, color = settingsMut(), fontSize = 13.sp, modifier = Modifier.weight(1f))
        Text(value, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
    }
}

/** The project's record of approvals (FL-406): every step both sides agreed to, oldest first; folded until opened. */
@Composable
internal fun ApprovalTrailCard(projectUuid: String, refreshKey: String) {
    val reconnect = collectReconnectTick()
    var list by remember { mutableStateOf<List<TrailStepDto>>(emptyList()) }
    var shown by remember { mutableStateOf(false) }
    LaunchedEffect(refreshKey, reconnect) {
        (apiCall { ApiClient.agreements.trail(projectUuid) } as? ApiResult.Ok)?.data?.let { list = it }
    }
    if (list.isEmpty()) return
    Card {
        Row(Modifier.fillMaxWidth().clickable { shown = !shown }, verticalAlignment = Alignment.CenterVertically) {
            FieldLabel(stringResource(R.string.trail_title, list.size))
            Text(stringResource(if (shown) R.string.trail_hide else R.string.trail_show), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.weight(1f).padding(start = 8.dp))
        }
        if (shown) list.forEach { s ->
            Column(Modifier.padding(vertical = 4.dp)) {
                Text(s.label, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                Text(listOf(
                    stringResource(if (s.mine) R.string.trail_by_you else when (s.actor) { "client" -> R.string.trail_by_client; "admin" -> R.string.trail_by_admin; "system" -> R.string.trail_by_system; else -> R.string.trail_by_freelancer }),
                    "‎" + s.at?.take(16)?.replace('T', ' ').orEmpty(),
                ).joinToString(" · "), color = settingsMut(), fontSize = 11.sp)
            }
        }
    }
}
