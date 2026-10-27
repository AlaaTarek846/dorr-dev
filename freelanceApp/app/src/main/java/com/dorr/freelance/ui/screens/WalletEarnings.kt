package com.dorr.freelance.ui.screens

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.EarningsDto
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.ui.wallet.Tone
import com.dorr.freelance.ui.wallet.Wa
import com.dorr.freelance.ui.wallet.WaCard
import com.dorr.freelance.ui.wallet.WaEmpty
import com.dorr.freelance.ui.wallet.WaPage
import com.dorr.freelance.ui.wallet.WaSectionTitle

/** My earnings from projects (FL-509 / FL-505): read once when shown, when a notice arrives and when the connection comes back. */
@Composable
private fun rememberEarnings(): EarningsDto? {
    var data by remember { mutableStateOf<EarningsDto?>(null) }
    val reconnect = collectReconnectTick()
    suspend fun load() {
        (apiCall { ApiClient.agreements.earnings() } as? ApiResult.Ok)?.data?.let { data = it }
    }
    LaunchedEffect(reconnect) { load() }
    LaunchedEffect(Unit) { Realtime.changed.collect { load() } }

    return data
}

/** On the wallet home: what waits its days and what is on its way, with a way to the list. */
@Composable
internal fun ProjectEarningsCard(hidden: Boolean, onOpen: () -> Unit) {
    val e = rememberEarnings() ?: return
    if (e.pendingMinor == 0L && e.expectedMinor == 0L && e.recent.isEmpty()) return
    val money = { minor: Long -> if (hidden) "••••" else "‎${formatMinor(minor, null)} ${e.currency.orEmpty()}" }
    WaCard(Modifier.fillMaxWidth().padding(top = 12.dp).clickable(onClick = onOpen), padding = 16.dp) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Work, null, tint = Wa.Red, modifier = Modifier.size(20.dp))
            Text(stringResource(R.string.er_title), color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f).padding(start = 8.dp))
            Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = Wa.Red, modifier = Modifier.size(18.dp))
        }
        Row(Modifier.fillMaxWidth().padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Part(stringResource(R.string.er_pending), money(e.pendingMinor), Modifier.weight(1f))
            Part(stringResource(R.string.er_expected), money(e.expectedMinor), Modifier.weight(1f))
        }
        e.nextReleaseAt?.let { Text(stringResource(R.string.er_next, "‎" + it.take(10)), color = Wa.Mut, fontSize = 11.sp, modifier = Modifier.padding(top = 6.dp)) }
    }
}

@Composable
private fun Part(label: String, value: String, modifier: Modifier) {
    Column(modifier) {
        Text(label, color = Wa.Mut, fontSize = 12.sp)
        Text(value, color = Wa.Ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
    }
}

/** The list: every earning from a part, released or waiting its day. */
@Composable
internal fun ProjectEarningsPage(onBack: () -> Unit) {
    val e = rememberEarnings()
    WaPage(title = stringResource(R.string.er_title), onBack = onBack) {
        if (e == null) return@WaPage
        val cur = e.currency.orEmpty()
        WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                Part(stringResource(R.string.er_available), "‎${formatMinor(e.availableMinor, null)} $cur", Modifier.weight(1f))
                Part(stringResource(R.string.er_pending), "‎${formatMinor(e.pendingMinor, null)} $cur", Modifier.weight(1f))
            }
            Row(Modifier.fillMaxWidth().padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                Part(stringResource(R.string.er_expected), "‎${formatMinor(e.expectedMinor, null)} $cur", Modifier.weight(1f))
                Part(stringResource(R.string.er_earned), "‎${formatMinor(e.earnedMinor, null)} $cur", Modifier.weight(1f))
            }
            Text(stringResource(R.string.er_note), color = Wa.Mut, fontSize = 11.sp, modifier = Modifier.padding(top = 8.dp))
        }
        // FL-703: my numbers and what clients said.
        MyRatingCard()
        WaSectionTitle(stringResource(R.string.er_list))
        if (e.recent.isEmpty()) WaEmpty(Icons.Rounded.Work, Tone.Gray, stringResource(R.string.er_empty_title), stringResource(R.string.er_empty_text))
        e.recent.forEach { r ->
            WaCard(Modifier.fillMaxWidth().padding(bottom = 8.dp), padding = 14.dp) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(r.project?.title.orEmpty(), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold)
                        Text(r.milestone.orEmpty(), color = Wa.Mut, fontSize = 12.sp)
                    }
                    Text("‎${formatMinor(r.amountMinor, null)} ${r.currency}", color = if (r.status == "released") Wa.Green else Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                }
                Text(
                    when (r.status) {
                        "pending" -> stringResource(R.string.er_s_pending, "‎" + r.releaseAt?.take(10).orEmpty())
                        "released" -> stringResource(R.string.er_s_released, "‎" + r.releasedAt?.take(10).orEmpty())
                        else -> stringResource(R.string.er_s_cancelled)
                    },
                    color = Wa.Mut, fontSize = 11.sp, modifier = Modifier.padding(top = 4.dp),
                )
            }
        }
    }
}
