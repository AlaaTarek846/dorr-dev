package com.dorr.app.ui.screens.rides

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.AddLocationAlt
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.EditLocationAlt
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
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
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.RideChangeDto
import com.dorr.app.network.RideDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import com.google.gson.JsonObject
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/*
 * Changing the trip during the ride (R-91..95, wireflow 6أ / 6ج): pick a new destination (or one more
 * stop), see what it does — "+3.2 km · +8 min · +8 SAR", the original price, the extra and the new
 * one, and that the captain must accept — then send it; the ride card shows the wait (a draining
 * ring) and the answer ("✅ The captain agreed! …", or that nothing changed).
 */

/** "Change destination" · "Add a stop" — under the trip on the ride card. */
@Composable
internal fun ChangeTripLinks(state: RidesState, ride: RideDto) {
    if (ride.openDestination || ride.destination == null || ride.changeRequest?.status == "pending") return
    Row(Modifier.fillMaxWidth().padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        if (state.feature("change_destination")) ChangeChip(Icons.Rounded.EditLocationAlt, stringResource(R.string.rd_change_destination), Modifier.weight(1f)) { state.changing = "destination" }
        if (state.feature("multi_stop") && ride.stops.size < 3) ChangeChip(Icons.Rounded.AddLocationAlt, stringResource(R.string.rd_add_stop), Modifier.weight(1f)) { state.changing = "add_stop" }
    }
}

@Composable
private fun ChangeChip(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, modifier: Modifier, onClick: () -> Unit) {
    Row(
        modifier.clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.08f)).clickable(onClick = onClick).padding(horizontal = 10.dp, vertical = 9.dp),
        horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(6.dp))
        Text(label, color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
    }
}

/** The state of a change on the ride card: waiting for the captain, or the answer. */
@Composable
internal fun ChangeBanner(ride: RideDto) {
    val change = ride.changeRequest
    var dismissed by remember(change?.uuid, change?.status) { mutableStateOf(false) }
    AnimatedVisibility(visible = change != null && !dismissed, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
        val c = change ?: return@AnimatedVisibility
        val minute = stringResource(R.string.rd_minutes)
        val hour = stringResource(R.string.rd_hours)
        when (c.status) {
            "pending" -> {
                var left by remember(c.uuid) { mutableIntStateOf(c.secondsLeft) }
                LaunchedEffect(c.uuid) { while (left > 0) { delay(1000); left-- } }
                val progress by animateFloatAsState(left / 30f, tween(900), label = "wait")
                Row(
                    Modifier.fillMaxWidth().padding(top = 10.dp).clip(RoundedCornerShape(14.dp)).background(AppColors.warning.copy(alpha = 0.1f)).padding(12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Box(Modifier.size(40.dp), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(progress = { progress }, color = AppColors.warning, strokeWidth = 3.dp, modifier = Modifier.size(40.dp))
                        Text(left.toString(), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 13.sp)
                    }
                    Spacer(Modifier.width(10.dp))
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(R.string.rd_change_waiting), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp)
                        Text(c.place?.address.orEmpty(), color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
            "applied" -> {
                val pop by animateFloatAsState(1f, spring(dampingRatio = Spring.DampingRatioMediumBouncy), label = "ok")
                Column(Modifier.fillMaxWidth().padding(top = 10.dp).scale(pop).clip(RoundedCornerShape(14.dp)).background(AppColors.success.copy(alpha = 0.1f)).clickable { dismissed = true }.padding(12.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.CheckCircle, null, tint = AppColors.success)
                        Spacer(Modifier.width(8.dp))
                        Text(stringResource(R.string.rd_change_applied), color = AppColors.success, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
                    }
                    Spacer(Modifier.height(6.dp))
                    ImpactRow(stringResource(if (c.type == "add_stop") R.string.rd_change_new_stop else R.string.rd_change_new_destination), c.place?.address.orEmpty())
                    c.route?.distanceM?.let { ImpactRow(stringResource(R.string.rd_change_total_distance), stringResource(R.string.rd_km, String.format("%.1f", it / 1000.0))) }
                    c.route?.durationS?.let { ImpactRow(stringResource(R.string.rd_change_time_left), "~" + minutesText(it, minute, hour)) }
                    c.newTotalMinor?.let { ImpactRow(stringResource(R.string.rd_change_new_price), money(it, ride.currency), bold = true) }
                    if ((c.priceDeltaMinor ?: 0) != 0L) Text(stringResource(R.string.rd_change_on_invoice, signed(c.priceDeltaMinor ?: 0, ride)), color = settingsMut(), fontSize = 11.sp, modifier = Modifier.padding(top = 4.dp))
                }
            }
            else -> Row(
                Modifier.fillMaxWidth().padding(top = 10.dp).clip(RoundedCornerShape(14.dp)).background(settingsBackground()).clickable { dismissed = true }.padding(12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.WarningAmber, null, tint = settingsMut())
                Spacer(Modifier.width(8.dp))
                Text(stringResource(if (c.status == "rejected") R.string.rd_change_rejected else R.string.rd_change_expired), color = settingsInk(), fontSize = 13.sp)
            }
        }
    }
}

private fun signed(minor: Long, ride: RideDto): String = (if (minor > 0) "+" else "−") + money(kotlin.math.abs(minor), ride.currency)

@Composable
private fun ImpactRow(label: String, value: String, bold: Boolean = false, color: Color = settingsInk()) {
    Row(Modifier.fillMaxWidth().padding(vertical = 2.dp)) {
        Text(label, color = settingsMut(), fontSize = 13.sp, modifier = Modifier.weight(1f))
        Text(value, color = color, fontSize = 13.sp, fontWeight = if (bold) FontWeight.ExtraBold else FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/** Wireflow 6أ: the current destination, the search, then the effect and "Send the change". */
@Composable
internal fun ChangeTripPage(state: RidesState, onClose: () -> Unit) {
    val ride = state.ride ?: return
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val adding = state.changing == "add_stop"
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<RidePoint>>(emptyList()) }
    var searching by remember { mutableStateOf(false) }
    var preview by remember { mutableStateOf<RideChangeDto?>(null) }
    var picked by remember { mutableStateOf<RidePoint?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val minute = stringResource(R.string.rd_minutes)
    val hour = stringResource(R.string.rd_hours)

    LaunchedEffect(query) {
        if (query.trim().length < 3) { results = emptyList(); return@LaunchedEffect }
        delay(350)
        searching = true
        results = searchPlaces(context, query, ride.destination?.let { com.google.android.gms.maps.model.LatLng(it.lat, it.lng) })
        searching = false
    }

    fun pick(point: RidePoint) {
        picked = point
        busy = true
        error = null
        scope.launch {
            val body = JsonObject().apply {
                addProperty("type", if (adding) "add_stop" else "destination")
                addProperty("lat", point.lat)
                addProperty("lng", point.lng)
                addProperty("address", point.address.take(255))
            }
            runCatching { ApiClient.rides.changePreview(ridesAuth(), ride.uuid, body).data }
                .onSuccess { preview = it }
                .onFailure { error = it.apiFailure().message }
            busy = false
        }
    }

    fun send() {
        val p = preview ?: return
        busy = true
        error = null
        scope.launch {
            runCatching { ApiClient.rides.changeSend(ridesAuth(), ride.uuid, p.uuid).data }
                .onSuccess {
                    runCatching { ApiClient.rides.ride(ridesAuth(), ride.uuid).data }.getOrNull()?.let { state.ride = it }
                    onClose()
                }
                .onFailure { error = it.apiFailure().message }
            busy = false
        }
    }

    Column(Modifier.fillMaxSize().background(settingsBackground()).statusBarsPadding().imePadding()) {
        Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            RoundButton(Icons.AutoMirrored.Rounded.ArrowBack, onClick = { if (preview != null) { preview = null; picked = null } else onClose() })
            Spacer(Modifier.width(10.dp))
            Column {
                Text(stringResource(if (adding) R.string.rd_add_stop else R.string.rd_change_destination), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 20.sp)
                Text(stringResource(R.string.rd_change_running), color = AppColors.success, fontSize = 12.sp)
            }
        }
        // The current destination.
        Row(
            Modifier.padding(horizontal = 16.dp).fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsCard()).padding(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.LocationOn, null, tint = AppColors.brandOrange)
            Spacer(Modifier.width(8.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.rd_change_current), color = settingsMut(), fontSize = 11.sp)
                Text(ride.destination?.address.orEmpty(), color = settingsInk(), fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
        Spacer(Modifier.height(10.dp))

        val p = preview
        if (p == null) {
            Row(
                Modifier.padding(horizontal = 16.dp).fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsCard()).padding(horizontal = 14.dp, vertical = 12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.Search, null, tint = settingsMut())
                Spacer(Modifier.width(8.dp))
                Box(Modifier.weight(1f)) {
                    if (query.isEmpty()) Text(stringResource(if (adding) R.string.rd_change_search_stop else R.string.rd_change_search), color = settingsMut())
                    BasicTextField(value = query, onValueChange = { query = it }, singleLine = true, textStyle = TextStyle(color = settingsInk(), fontSize = 16.sp), cursorBrush = SolidColor(settingsAccent()), modifier = Modifier.fillMaxWidth())
                }
                if (searching || busy) CircularProgressIndicator(strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
            }
            error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp, modifier = Modifier.padding(16.dp)) }
            LazyColumn(Modifier.weight(1f).padding(horizontal = 16.dp, vertical = 8.dp)) {
                items(results) { place ->
                    Row(Modifier.fillMaxWidth().clickable(enabled = !busy) { pick(place) }.padding(vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(34.dp).clip(CircleShape).background(settingsCard()), contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.LocationOn, null, tint = settingsMut(), modifier = Modifier.size(18.dp))
                        }
                        Spacer(Modifier.width(10.dp))
                        Text(place.address, color = settingsInk(), maxLines = 2, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
        } else {
            // The effect, before anything changes (R-92, R-94).
            Column(Modifier.padding(horizontal = 16.dp).fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(settingsCard()).padding(16.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(if (adding) Icons.Rounded.AddLocationAlt else Icons.Rounded.EditLocationAlt, null, tint = settingsAccent())
                    Spacer(Modifier.width(8.dp))
                    Text(picked?.address.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                }
                Text(
                    listOf(
                        (if (p.distanceDeltaM >= 0) "+" else "−") + stringResource(R.string.rd_km, String.format("%.1f", kotlin.math.abs(p.distanceDeltaM) / 1000.0)),
                        (if (p.durationDeltaS >= 0) "+" else "−") + minutesText(kotlin.math.abs(p.durationDeltaS), minute, hour),
                        signed(p.priceDeltaMinor ?: 0, ride),
                    ).joinToString(" • "),
                    color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp),
                )
                Spacer(Modifier.height(12.dp))
                Text(stringResource(R.string.rd_change_effect), color = settingsInk(), fontWeight = FontWeight.Bold)
                val newTotal = p.newTotalMinor ?: 0
                val delta = p.priceDeltaMinor ?: 0
                ImpactRow(stringResource(R.string.rd_change_original), money(newTotal - delta, ride.currency))
                ImpactRow(stringResource(R.string.rd_change_extra), signed(delta, ride), color = if (delta > 0) AppColors.warning else AppColors.success)
                ImpactRow(stringResource(R.string.rd_change_new_price), money(newTotal, ride.currency), bold = true)
                if (ride.pricingMode == "metered") Text(stringResource(R.string.rd_change_metered), color = settingsMut(), fontSize = 11.sp)
            }
            Spacer(Modifier.height(10.dp))
            Row(
                Modifier.padding(horizontal = 16.dp).fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(AppColors.warning.copy(alpha = 0.1f)).padding(12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.HourglassTop, null, tint = AppColors.warning, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.rd_change_driver_must_accept), color = settingsInk(), fontSize = 13.sp)
            }
            error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp, modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp)) }
            Spacer(Modifier.weight(1f))
            Box(Modifier.padding(16.dp)) { AccentButton(stringResource(R.string.rd_change_send), busy = busy) { send() } }
        }
    }
}
