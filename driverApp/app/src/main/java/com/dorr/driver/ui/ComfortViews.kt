package com.dorr.driver.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.NextPlan
import androidx.compose.material.icons.rounded.Spa
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.ComfortDto
import com.dorr.driver.net.ComfortItemDto
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.NextTripOfferDto
import com.dorr.driver.net.TripDto
import com.dorr.driver.ui.kit.bounceClick
import com.dorr.driver.ui.theme.DorrBrand
import com.google.gson.JsonObject
import kotlinx.coroutines.launch

/* Block 06 on the captain's request card and trip: the rider's «راحتي» (requests, never above safety) with
 * "can't do this" and why, and the next trip taken while finishing a ride. */

/** On the request card: offered while finishing a ride — it starts after that ride ends. */
@Composable
internal fun NextTripBadge(next: NextTripOfferDto?) {
    next ?: return
    Row(
        Modifier.padding(top = 8.dp).fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(DorrBrand.success.copy(alpha = 0.12f)).padding(10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.NextPlan, null, tint = DorrBrand.success, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(8.dp))
        Column {
            Text(stringResource(R.string.nt_offer_title), fontWeight = FontWeight.Bold, fontSize = 13.sp, color = DorrBrand.success)
            Text(stringResource(R.string.nt_offer_body), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.7f))
        }
    }
}

/** The rider's «راحتي», read-only (the request card). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun ComfortChips(comfort: ComfortDto?, unable: Set<String> = emptySet(), onItem: ((ComfortItemDto) -> Unit)? = null) {
    val c = comfort ?: return
    if (c.items.isEmpty()) return
    Column(Modifier.padding(top = 10.dp).fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(DorrBrand.success.copy(alpha = 0.07f)).padding(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Spa, null, tint = DorrBrand.success, modifier = Modifier.size(16.dp))
            Spacer(Modifier.width(6.dp))
            Text(stringResource(R.string.cf_title), fontWeight = FontWeight.Bold, fontSize = 13.sp)
        }
        Spacer(Modifier.size(6.dp))
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            c.items.forEach { item ->
                val off = item.item in unable
                Text(
                    item.label, fontSize = 12.sp, textDecoration = if (off) TextDecoration.LineThrough else null,
                    color = if (off) MaterialTheme.colorScheme.onSurface.copy(alpha = 0.45f) else MaterialTheme.colorScheme.onSurface,
                    modifier = Modifier.clip(CircleShape).background(MaterialTheme.colorScheme.surface)
                        .then(if (onItem != null && !off) Modifier.bounceClick { onItem(item) } else Modifier)
                        .padding(horizontal = 10.dp, vertical = 5.dp),
                )
            }
        }
        c.disclaimer?.let {
            Row(Modifier.padding(top = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Info, null, tint = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.5f), modifier = Modifier.size(13.dp))
                Spacer(Modifier.width(4.dp))
                Text(it, fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
            }
        }
    }
}

/** On the trip: the same, and a tap on an item says it can't be done (with why) — the rider is told. */
@Composable
internal fun TripComfortCard(trip: TripDto, onUpdated: (TripDto) -> Unit) {
    val c = trip.comfort ?: return
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var asking by remember { mutableStateOf<ComfortItemDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val open = trip.status in setOf("accepted", "arrived", "in_progress")
    ComfortChips(c, c.unable.mapNotNull { it.item }.toSet(), onItem = if (open) ({ asking = it }) else null)
    if (open && c.unable.size < c.items.size) Text(stringResource(R.string.cf_tap_hint), fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.55f), modifier = Modifier.padding(top = 4.dp))
    c.unable.forEach { u ->
        Text(stringResource(R.string.cf_told, u.label.orEmpty(), u.reasonLabel.orEmpty()), fontSize = 12.sp, color = DorrBrand.warning, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 4.dp))
    }
    error?.let { Text(it, color = MaterialTheme.colorScheme.error, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp)) }

    asking?.let { item ->
        AlertDialog(
            onDismissRequest = { asking = null },
            icon = { Icon(Icons.Rounded.Spa, null, tint = DorrBrand.success) },
            title = { Text(stringResource(R.string.cf_unable_title, item.label)) },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text(stringResource(R.string.cf_unable_body), fontSize = 13.sp)
                    c.reasons.forEach { reason ->
                        Text(
                            reason.label, fontWeight = FontWeight.SemiBold,
                            modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(MaterialTheme.colorScheme.onSurface.copy(alpha = 0.06f))
                                .bounceClick {
                                    asking = null
                                    scope.launch {
                                        val body = JsonObject().apply { addProperty("item", item.item); addProperty("reason", reason.value) }
                                        when (val r = apiCall { DriverBackend.api.comfortUnable(trip.uuid, body) }) {
                                            is ApiResult.Ok -> onUpdated(r.data)
                                            is ApiResult.Failed -> error = r.error.displayMessage
                                            is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                                        }
                                    }
                                }.padding(12.dp),
                        )
                    }
                }
            },
            confirmButton = { TextButton(onClick = { asking = null }) { Text(stringResource(R.string.cf_cancel)) } },
        )
    }
}

/** On the ride being driven: the next trip already taken — who, where, what it pays. On that trip: it waits for this one. */
@Composable
internal fun NextTripCard(trip: TripDto) {
    trip.nextTrip?.let { next ->
        Row(
            Modifier.padding(top = 10.dp).fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(DorrBrand.success.copy(alpha = 0.1f)).padding(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.NextPlan, null, tint = DorrBrand.success, modifier = Modifier.size(22.dp))
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.nt_card_title, next.rider.orEmpty()), fontWeight = FontWeight.Bold, fontSize = 14.sp)
                next.pickup?.address?.let { Text(it, fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.65f), maxLines = 1) }
            }
            Text(money(next.earningMinor, trip.currency), color = DorrBrand.success, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp)
        }
    }
    trip.waitingForRide?.let {
        Row(
            Modifier.padding(top = 10.dp).fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(DorrBrand.warning.copy(alpha = 0.12f)).padding(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.NextPlan, null, tint = DorrBrand.warning, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.nt_waiting, it.number.orEmpty()), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
        }
    }
}
