package com.dorr.app.ui.screens.rides

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Flight
import androidx.compose.material.icons.rounded.LocalParking
import androidx.compose.material.icons.rounded.Login
import androidx.compose.material.icons.rounded.MeetingRoom
import androidx.compose.material.icons.rounded.PinDrop
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.RideDto
import com.dorr.app.network.RidePickupPointDto
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut

/*
 * Approved pickup / drop-off points (R-16, R-17, R-18): in an airport or other sensitive place the rider
 * picks a gate, terminal, entrance or parking from the admin's list — a must in some zones — and the
 * captain is sent to exactly it. The server is the judge (`rides_pickup_point_required`); this only asks first.
 */

private fun RidePoint.key() = "%.5f,%.5f".format(java.util.Locale.US, lat, lng)

/**
 * Before pricing: is the pickup (or the drop-off) in a place with approved points, not decided yet?
 * Opens the chooser and returns true; the chooser prices the trip again once the rider has decided.
 */
internal suspend fun askForPoint(state: RidesState, pickup: RidePoint, destination: RidePoint?): Boolean {
    if (pickup.key() != state.decidedPickup) {
        state.pickupPointId = null
        val around = runCatching { ApiClient.rides.pickupPoints(ridesAuth(), pickup.lat, pickup.lng, "pickup").data }.getOrNull()
        if (around != null && around.points.isNotEmpty()) {
            state.pointChoices = around
            state.pointsChooser = "pickup"
            return true
        }
        state.decidedPickup = pickup.key()
    }
    if (destination != null && destination.key() != state.decidedDropoff) {
        state.dropoffPointId = null
        val around = runCatching { ApiClient.rides.pickupPoints(ridesAuth(), destination.lat, destination.lng, "dropoff").data }.getOrNull()
        if (around != null && around.points.isNotEmpty()) {
            state.pointChoices = around
            state.pointsChooser = "dropoff"
            return true
        }
        state.decidedDropoff = destination.key()
    }
    return false
}

/** The rider chose a point (or, where allowed, kept their own spot): the trip is priced from it. */
private fun decide(state: RidesState, purpose: String, point: RidePickupPointDto?) {
    if (purpose == "pickup") {
        state.pickupPointId = point?.id
        if (point != null) state.pickup = RidePoint(point.lat, point.lng, point.name ?: state.pickup?.address.orEmpty())
        state.decidedPickup = state.pickup?.key()
    } else {
        state.dropoffPointId = point?.id
        if (point != null) state.destination = RidePoint(point.lat, point.lng, point.name ?: state.destination?.address.orEmpty())
        state.decidedDropoff = state.destination?.key()
    }
    state.pointsChooser = null
    state.pointChoices = null
    loadQuotes(state)
}

/** "Choose your pickup point" — the zone's approved gates, terminals, entrances and parkings, nearest first. */
@Composable
internal fun PickupPointsPanel(state: RidesState) {
    val purpose = state.pointsChooser ?: return
    val choices = state.pointChoices ?: return
    PanelCard {
        Column(Modifier.heightIn(max = 520.dp).verticalScroll(rememberScrollState())) {
            Text(
                stringResource(if (purpose == "pickup") R.string.rd_points_title_pickup else R.string.rd_points_title_dropoff),
                color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp,
            )
            choices.zone?.name?.let { Text(it, color = settingsMut(), fontSize = 13.sp) }
            Text(
                stringResource(if (choices.required) R.string.rd_points_required else R.string.rd_points_optional),
                color = settingsMut(), fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp, bottom = 10.dp),
            )
            choices.points.sortedBy { it.distanceM ?: Int.MAX_VALUE }.forEach { point ->
                Row(
                    Modifier.fillMaxWidth().padding(bottom = 8.dp).clip(RoundedCornerShape(14.dp)).background(settingsBackground())
                        .clickable { decide(state, purpose, point) }.padding(12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Box(Modifier.size(38.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                        Icon(pointIcon(point.kind), null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
                    }
                    Spacer(Modifier.width(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(point.name.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 15.sp)
                        Text(point.kindLabel.orEmpty(), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                        point.instructions?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
                    }
                    point.distanceM?.let { Text(stringResource(R.string.rd_point_away, it), color = settingsMut(), fontSize = 12.sp) }
                }
            }
            if (!choices.required) {
                Text(
                    stringResource(R.string.rd_points_keep), color = settingsAccent(), fontWeight = FontWeight.Bold,
                    modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { decide(state, purpose, null) }.padding(vertical = 12.dp),
                )
            }
        }
    }
}

/** On the ride: the pickup point (and the drop-off one) with how to find it. */
@Composable
internal fun PickupPointCard(ride: RideDto) {
    ride.pickupPoint?.let { PointLine(stringResource(R.string.rd_pickup_point), it) }
    ride.dropoffPoint?.let { PointLine(stringResource(R.string.rd_dropoff_point), it) }
}

@Composable
private fun PointLine(title: String, point: RidePickupPointDto) {
    Row(
        Modifier.fillMaxWidth().padding(top = 8.dp).clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.08f)).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(pointIcon(point.kind), null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(title, color = settingsMut(), fontSize = 11.sp)
            Text("${point.name.orEmpty()} · ${point.kindLabel.orEmpty()}", color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp)
            point.instructions?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
        }
    }
}

private fun pointIcon(kind: String?): ImageVector = when (kind) {
    "gate" -> Icons.Rounded.MeetingRoom
    "terminal" -> Icons.Rounded.Flight
    "entrance" -> Icons.Rounded.Login
    "parking" -> Icons.Rounded.LocalParking
    else -> Icons.Rounded.PinDrop
}
