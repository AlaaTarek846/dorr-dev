package com.dorr.driver.ui

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.DirectionsCar
import androidx.compose.material.icons.filled.EditNote
import androidx.compose.material.icons.filled.Favorite
import androidx.compose.material.icons.filled.Group
import androidx.compose.material.icons.filled.HourglassBottom
import androidx.compose.material.icons.filled.PersonPinCircle
import androidx.compose.material.icons.filled.PinDrop
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.driver.ui.theme.DorrBrand
import com.dorr.driver.R
import com.dorr.driver.net.PickupPointDto
import com.dorr.driver.net.ProgressDto

/*
 * What the captain needs around the ride (rides_tasks batch 3): how many ride and the rider's pickup
 * note (D-60, D-61), the time / distance left (D-129), and the waiting clock that warns before the
 * free minutes end (D-122).
 */

/**
 * D-60 / D-61: "3 riders" and the note, on the request card and on the ride; R-13: "Pick up Mona" when
 * the ride was booked for someone else (first name only — the app chat still reaches the rider who booked).
 */
@Composable
internal fun RiderDetails(passengers: Int?, note: String?, beneficiary: String? = null, pickupPoint: PickupPointDto? = null, dropoffPoint: PickupPointDto? = null, fromCircle: Boolean = false) {
    if ((passengers ?: 1) <= 1 && note.isNullOrBlank() && beneficiary.isNullOrBlank() && pickupPoint == null && dropoffPoint == null && !fromCircle) return
    Column(Modifier.fillMaxWidth().padding(top = 10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        // D-INN-07 DORR Circle: the rider picked this captain from their favourites.
        if (fromCircle) {
            Row(
                Modifier.clip(CircleShape).background(DorrBrand.orange.copy(alpha = 0.12f)).padding(horizontal = 12.dp, vertical = 5.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Filled.Favorite, null, tint = DorrBrand.orange, modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(6.dp))
                Text(stringResource(R.string.trip_from_circle), fontWeight = FontWeight.SemiBold, fontSize = 13.sp, color = DorrBrand.orange)
            }
        }
        pickupPoint?.let { PointRow(stringResource(R.string.trip_pickup_point), it) }
        dropoffPoint?.let { PointRow(stringResource(R.string.trip_dropoff_point), it) }
        beneficiary?.takeIf { it.isNotBlank() }?.let { name ->
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(DorrBrand.navy.copy(alpha = 0.08f)).padding(10.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Filled.PersonPinCircle, null, tint = DorrBrand.navy, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Column {
                    Text(stringResource(R.string.trip_beneficiary, name), fontWeight = FontWeight.Bold, fontSize = 14.sp, color = DorrBrand.navy)
                    Text(stringResource(R.string.trip_beneficiary_hint), fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
                }
            }
        }
        passengers?.takeIf { it > 1 }?.let { n ->
            Row(
                Modifier.clip(CircleShape).background(DorrBrand.navy.copy(alpha = 0.08f)).padding(horizontal = 12.dp, vertical = 5.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Filled.Group, null, tint = DorrBrand.navy, modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(6.dp))
                Text(stringResource(R.string.trip_passengers, n), fontWeight = FontWeight.SemiBold, fontSize = 13.sp, color = DorrBrand.navy)
            }
        }
        note?.takeIf { it.isNotBlank() }?.let {
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(DorrBrand.orange.copy(alpha = 0.08f)).padding(10.dp),
                verticalAlignment = Alignment.Top,
            ) {
                Icon(Icons.Filled.EditNote, null, tint = DorrBrand.orange, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Column {
                    Text(stringResource(R.string.trip_pickup_note), fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
                    Text("“$it”", fontSize = 14.sp, fontStyle = FontStyle.Italic, fontWeight = FontWeight.Medium)
                }
            }
        }
    }
}

/** D-111: the approved point to meet at (a gate, a terminal…) with how to find it — where the navigation goes. */
@Composable
private fun PointRow(title: String, point: PickupPointDto) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(DorrBrand.navy.copy(alpha = 0.08f)).padding(10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Filled.PinDrop, null, tint = DorrBrand.navy, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(8.dp))
        Column {
            Text(title, fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
            Text("${point.name.orEmpty()} · ${point.kindLabel.orEmpty()}", fontWeight = FontWeight.Bold, fontSize = 14.sp, color = DorrBrand.navy)
            point.instructions?.takeIf { it.isNotBlank() }?.let { Text(it, fontSize = 12.sp, fontStyle = FontStyle.Italic) }
        }
    }
}

/** D-129: "6 min · 2.4 km to the pickup", a breathing dot while the numbers update. */
@Composable
internal fun ProgressChip(progress: ProgressDto?) {
    progress ?: return
    val breathe by rememberInfiniteTransition(label = "eta").animateFloat(0.35f, 1f, infiniteRepeatable(tween(900), RepeatMode.Reverse), label = "dot")
    val target = stringResource(
        when (progress.to) {
            "pickup" -> R.string.trip_to_pickup_short
            "stop" -> R.string.trip_to_stop_short
            else -> R.string.trip_to_destination_short
        },
    )
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(DorrBrand.navy.copy(alpha = 0.07f)).padding(horizontal = 14.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(9.dp).alpha(breathe).clip(CircleShape).background(DorrBrand.success))
        Spacer(Modifier.width(10.dp))
        Icon(Icons.Filled.DirectionsCar, null, tint = DorrBrand.navy, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(8.dp))
        AnimatedContent(targetState = progress, transitionSpec = { fadeIn(tween(300)) togetherWith fadeOut(tween(200)) }, label = "progress") { p ->
            Text(
                stringResource(R.string.trip_progress, ((p.etaS + 59) / 60).coerceAtLeast(1), String.format("%.1f", p.distanceM / 1000.0), target),
                fontWeight = FontWeight.Bold, fontSize = 15.sp, color = DorrBrand.navy,
            )
        }
    }
}

/** D-122: the free waiting on the status band — white, then an amber pulsing pill near the end, then paid. */
@Composable
internal fun WaitClock(left: Int, warnBeforeS: Int) {
    val warning = left in 1..warnBeforeS
    val pill by animateColorAsState(
        when {
            left <= 0 -> DorrBrand.danger
            warning -> DorrBrand.orange
            else -> Color.White.copy(alpha = 0.18f)
        },
        tween(400), label = "wait",
    )
    val pulse = if (warning) rememberInfiniteTransition(label = "warn").animateFloat(1f, 1.07f, infiniteRepeatable(tween(500), RepeatMode.Reverse), label = "pulse").value else 1f
    Row(
        Modifier.padding(top = 8.dp).scale(pulse).clip(CircleShape).background(pill).padding(horizontal = 12.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Filled.HourglassBottom, null, tint = Color.White, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(6.dp))
        Text(
            when {
                left <= 0 -> stringResource(R.string.trip_waiting_paid)
                warning -> stringResource(R.string.trip_waiting_ending, clock(left))
                else -> stringResource(R.string.trip_waiting_free, clock(left))
            },
            color = Color.White, fontWeight = FontWeight.Bold,
        )
    }
}
