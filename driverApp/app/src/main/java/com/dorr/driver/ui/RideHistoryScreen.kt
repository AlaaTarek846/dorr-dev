package com.dorr.driver.ui

import androidx.compose.foundation.background
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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.TripOrigin
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
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
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.DriverRideItemDto
import com.dorr.driver.net.TripDto
import com.dorr.driver.ui.kit.HeroBadge
import com.dorr.driver.ui.kit.ScreenScaffold
import com.dorr.driver.ui.kit.surface
import com.dorr.driver.ui.theme.Pal
import kotlinx.coroutines.launch

/** O-78: my past rides — finished, cancelled or no-show — page by page, and each one's details. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RideHistoryScreen(onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    var items by remember { mutableStateOf<List<DriverRideItemDto>?>(null) }
    var page by remember { mutableIntStateOf(1) }
    var lastPage by remember { mutableIntStateOf(1) }
    var loadingMore by remember { mutableStateOf(false) }
    var open by remember { mutableStateOf<String?>(null) }

    suspend fun load(p: Int) {
        val data = (apiCall { DriverBackend.api.rideHistory(p) } as? ApiResult.Ok)?.data ?: return
        items = if (p == 1) data.items else items.orEmpty() + data.items
        page = p
        lastPage = data.meta?.lastPage ?: p
    }
    LaunchedEffect(Unit) { load(1); if (items == null) items = emptyList() }

    ScreenScaffold(stringResource(R.string.rh_title), onBack) {
        val list = items
        when {
            list == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Pal.accent) }
            list.isEmpty() -> Column(Modifier.fillMaxSize().padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                HeroBadge(Icons.Rounded.History, size = 76.dp)
                Spacer(Modifier.height(10.dp))
                Text(stringResource(R.string.rh_empty), color = Pal.mut)
            }
            else -> LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                item { Spacer(Modifier.height(6.dp)) }
                items(list, key = { it.uuid }) { r -> HistoryRow(r) { open = r.uuid } }
                if (page < lastPage) item {
                    Text(
                        stringResource(if (loadingMore) R.string.rh_loading else R.string.rh_more), color = Pal.accent, fontWeight = FontWeight.Bold,
                        modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable(enabled = !loadingMore) {
                            loadingMore = true
                            scope.launch { load(page + 1); loadingMore = false }
                        }.padding(14.dp),
                        textAlign = androidx.compose.ui.text.style.TextAlign.Center,
                    )
                }
                item { Spacer(Modifier.height(16.dp)) }
            }
        }
    }

    open?.let { uuid ->
        ModalBottomSheet(onDismissRequest = { open = null }, containerColor = Pal.card) { RideDetails(uuid) }
    }
}

@Composable
private fun HistoryRow(r: DriverRideItemDto, onClick: () -> Unit) {
    Column(Modifier.fillMaxWidth().surface(RoundedCornerShape(18.dp), 4.dp).clickable(onClick = onClick).padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(r.number ?: "—", color = Pal.ink, fontWeight = FontWeight.Black, fontSize = 14.sp)
            Spacer(Modifier.width(8.dp))
            StatusChip(r.status)
            Spacer(Modifier.weight(1f))
            Text(r.earningMinor?.let { moneyText(it, r.currency) } ?: "—", color = if (r.status == "completed") Pal.success else Pal.mut, fontWeight = FontWeight.Bold)
        }
        Text(listOfNotNull(r.category, whenText(r.completedAt).ifBlank { null }).joinToString(" · "), color = Pal.mut, fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp))
        Spacer(Modifier.height(8.dp))
        PlaceLine(Icons.Rounded.TripOrigin, r.originAddress, Pal.success)
        PlaceLine(Icons.Rounded.Place, r.destinationAddress, Pal.danger)
    }
}

@Composable
private fun PlaceLine(icon: androidx.compose.ui.graphics.vector.ImageVector, text: String?, tint: Color) {
    Row(Modifier.padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = tint, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(6.dp))
        Text(text ?: "—", color = Pal.ink, fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

@Composable
private fun StatusChip(status: String) {
    val (label, tint) = when (status) {
        "completed" -> stringResource(R.string.rh_completed) to Pal.success
        "no_show" -> stringResource(R.string.rh_no_show) to Pal.warning
        else -> stringResource(R.string.rh_canceled) to Pal.danger
    }
    Text(label, color = tint, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).background(tint.copy(alpha = 0.12f)).padding(horizontal = 8.dp, vertical = 3.dp))
}

/** One past ride: where, when, how far, what it cost, what I earned, the rider — and blocking them. */
@Composable
private fun RideDetails(uuid: String) {
    var t by remember { mutableStateOf<TripDto?>(null) }
    LaunchedEffect(uuid) { t = (apiCall { DriverBackend.api.ride(uuid) } as? ApiResult.Ok)?.data }
    val trip = t ?: run {
        Box(Modifier.fillMaxWidth().height(220.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Pal.accent) }
        return
    }
    Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 28.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(trip.number ?: "—", color = Pal.ink, fontWeight = FontWeight.Black, fontSize = 18.sp)
            Spacer(Modifier.width(8.dp))
            StatusChip(trip.status)
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(listOfNotNull(trip.category?.name, trip.rider?.name).joinToString(" · "), color = Pal.mut, fontSize = 13.sp, modifier = Modifier.weight(1f, fill = false))
            RiderTick(trip.rider?.isVerified == true, 13.dp)
        }
        Column(Modifier.fillMaxWidth().surface(RoundedCornerShape(16.dp), 2.dp).padding(12.dp)) {
            PlaceLine(Icons.Rounded.TripOrigin, trip.origin?.address, Pal.success)
            trip.stops.forEach { s -> PlaceLine(Icons.Rounded.Place, s.address, Pal.warning) }
            PlaceLine(Icons.Rounded.Place, trip.destination?.address, Pal.danger)
        }
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Fact(stringResource(R.string.rh_distance), trip.route?.distanceM?.let { String.format(java.util.Locale.getDefault(), "%.1f km", it / 1000.0) } ?: "—", Modifier.weight(1f))
            Fact(stringResource(R.string.rh_duration), trip.route?.durationS?.let { "${it / 60} min" } ?: "—", Modifier.weight(1f))
            Fact(stringResource(R.string.rh_payment), when (trip.paymentMethod) { "cash" -> stringResource(R.string.offer_cash); null -> "—"; else -> stringResource(R.string.offer_paid) }, Modifier.weight(1f))
        }
        trip.times?.let { times ->
            Column(Modifier.fillMaxWidth().surface(RoundedCornerShape(16.dp), 2.dp).padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                times.arrivedAt?.let { Line(stringResource(R.string.rh_arrived_at), whenText(it)) }
                times.startedAt?.let { Line(stringResource(R.string.rh_started_at), whenText(it)) }
            }
        }
        if (trip.lines.isNotEmpty()) {
            Column(Modifier.fillMaxWidth().surface(RoundedCornerShape(16.dp), 2.dp).padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                trip.lines.forEach { l -> Line(l.label ?: l.code, money(l.amountMinor, trip.currency)) }
            }
        }
        Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Pal.success.copy(alpha = 0.08f)).padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Line(stringResource(R.string.rh_total), money(trip.finalTotalMinor, trip.currency))
            trip.tipMinor?.takeIf { it > 0 }?.let { Line(stringResource(R.string.rh_tip), money(it, trip.currency)) }
            Row {
                Text(stringResource(R.string.rh_earned), color = Pal.ink, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                Text(money(trip.earningMinor, trip.currency), color = Pal.success, fontWeight = FontWeight.Black)
            }
        }
        // O-80: block this rider — they never get matched with me again.
        if (trip.status == "completed" || trip.status == "no_show" || trip.status == "canceled") BlockRiderButton(trip)
    }
}

@Composable
private fun Fact(label: String, value: String, modifier: Modifier) {
    Column(modifier.clip(RoundedCornerShape(14.dp)).background(Pal.accent.copy(alpha = 0.07f)).padding(vertical = 10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Text(value, color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
        Text(label, color = Pal.mut, fontSize = 11.sp)
    }
}

@Composable
private fun Line(label: String, value: String) {
    Row(Modifier.fillMaxWidth()) {
        Text(label, color = Pal.mut, fontSize = 13.sp, modifier = Modifier.weight(1f))
        Text(value, color = Pal.ink, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
    }
}

/** The list sends the currency as its symbol only. */
private fun moneyText(minor: Long, symbol: String?): String =
    "${String.format(java.util.Locale.getDefault(), "%.2f", minor / 100.0)} ${symbol.orEmpty()}".trim()
