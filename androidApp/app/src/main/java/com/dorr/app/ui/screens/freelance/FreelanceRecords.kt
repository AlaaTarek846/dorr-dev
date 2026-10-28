package com.dorr.app.ui.screens.freelance

import androidx.compose.material.icons.Icons
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.material3.Icon
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.foundation.background
import androidx.compose.foundation.shape.RoundedCornerShape
import com.dorr.app.ui.screens.profile.settingsSurface
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material.icons.rounded.*
import android.content.Intent
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Text
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
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelanceReceiptDto
import com.dorr.app.network.FreelanceTrailDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.screens.profile.SettingsPrimaryButton
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

/**
 * The project's receipts (FL-511): one per money movement that concerns the client, newest first. Read when shown, when the agreement
 * changes (`refreshKey`) and when the connection comes back; a receipt opens with its details and can be shared as text.
 */
@Composable
internal fun ReceiptsCard(projectUuid: String, refreshKey: String) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var list by remember { mutableStateOf<List<FreelanceReceiptDto>>(emptyList()) }
    var open by remember { mutableStateOf<FreelanceReceiptDto?>(null) }
    LaunchedEffect(refreshKey, reconnect) {
        try {
            list = ApiClient.freelance.receipts(auth(), project = projectUuid).data.orEmpty()
        } catch (e: CancellationException) {
            throw e
        } catch (_: Throwable) {
        }
    }
    if (list.isEmpty()) return
    Card(Icons.Rounded.Receipt, stringResource(R.string.fl_rcp_title)) {
        list.forEach { r ->
            Row(Modifier.fillMaxWidth().clickable {
                scope.launch {
                    open = try { ApiClient.freelance.receipt(auth(), r.uuid).data ?: r } catch (e: CancellationException) { throw e } catch (_: Throwable) { r }
                }
            }.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(r.title.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                    Text("‎${r.number} · ${r.milestone?.title.orEmpty()} · ${r.issuedAt?.take(10).orEmpty()}", color = settingsMut(), fontSize = 11.sp)
                }
                Text("‎${money(if (r.totalMinor > 0) r.totalMinor else r.refundedMinor)} ${r.currency}", color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            }
        }
    }
    open?.let { r ->
        BottomPanel({ open = null }) {
            Text(r.title.orEmpty(), color = settingsAccent(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Field(stringResource(R.string.fl_rcp_number), "‎${r.number}")
            Field(stringResource(R.string.fl_rcp_date), "‎" + r.issuedAt?.take(16)?.replace('T', ' ').orEmpty())
            Field(stringResource(R.string.fl_rcp_part), r.milestone?.title)
            if (r.amountMinor > 0) Field(stringResource(R.string.fl_price), "‎${money(r.amountMinor)} ${r.currency}")
            if (r.feeMinor > 0) Field(stringResource(R.string.fl_client_fee), "‎${money(r.feeMinor)} ${r.currency}")
            if (r.totalMinor > 0) Field(stringResource(R.string.fl_total), "‎${money(r.totalMinor)} ${r.currency}")
            if (r.refundedMinor > 0) Field(stringResource(R.string.fl_rcp_refunded), "‎${money(r.refundedMinor)} ${r.currency}")
            r.reasonText?.let { Field(stringResource(R.string.fl_rcp_reason), it) }
            SettingsPrimaryButton(text = stringResource(R.string.fl_rcp_share), onClick = {
                context.startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, r.text ?: r.number), null))
            })
        }
    }
}

/** The project's record of approvals (FL-406): every step both sides agreed to, oldest first; folded until opened. */
@Composable
internal fun TrailCard(projectUuid: String, refreshKey: String) {
    val reconnect = collectReconnectTick()
    var list by remember { mutableStateOf<List<FreelanceTrailDto>>(emptyList()) }
    var shown by remember { mutableStateOf(false) }
    LaunchedEffect(refreshKey, reconnect) {
        try {
            list = ApiClient.freelance.trail(auth(), projectUuid).data.orEmpty()
        } catch (e: CancellationException) {
            throw e
        } catch (_: Throwable) {
        }
    }
    if (list.isEmpty()) return
    Card {
        Row(Modifier.fillMaxWidth().clickable { shown = !shown }, verticalAlignment = Alignment.CenterVertically) {
            FlHeading(Icons.Rounded.History, stringResource(R.string.fl_trail_title, list.size))
            Text(stringResource(if (shown) R.string.fl_trail_hide else R.string.fl_trail_show), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.weight(1f).padding(start = 8.dp))
        }
        if (shown) list.forEach { s ->
            Column(Modifier.padding(vertical = 4.dp)) {
                Text(s.label, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                Text(listOfNotNull(
                    stringResource(if (s.mine) R.string.fl_trail_by_you else when (s.actor) { "freelancer" -> R.string.fl_trail_by_freelancer; "admin" -> R.string.fl_trail_by_admin; "system" -> R.string.fl_trail_by_system; else -> R.string.fl_trail_by_client }),
                    "‎" + s.at?.take(16)?.replace('T', ' ').orEmpty(),
                ).joinToString(" · "), color = settingsMut(), fontSize = 11.sp)
            }
        }
    }
}
