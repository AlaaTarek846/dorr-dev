package com.dorr.app.ui.screens.aichat

import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowForward
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.DirectionsCar
import androidx.compose.material.icons.rounded.Group
import androidx.compose.material.icons.rounded.LocalTaxi
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.StickyNote2
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AiServiceActionDto
import com.dorr.app.network.RideAiPlaceDto
import com.dorr.app.ui.screens.rides.RidesLink
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale

private val Navy = Color(0xFF001B53)
private val Orange = Color(0xFFFA7552)

/** The DORR service cards under an answer (Modules/AI AiServiceActions). */
@Composable
internal fun AiServiceActionCards(actions: List<AiServiceActionDto>, night: Boolean, ink: Color, mut: Color) {
    actions.forEach { action ->
        when (action.type) {
            "ride_booking" -> RideBookingCard(action, night, ink, mut)
        }
    }
}

/**
 * «احجز مشوارك مع دُرر»: the place DORR AI found on the map, and what else it understood (category, time,
 * riders, payment, a note) — the rider can pick another of the places found, then one tap opens rides with it
 * all filled in and the place on the map to confirm. Nothing is booked from the chat.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun RideBookingCard(action: AiServiceActionDto, night: Boolean, ink: Color, mut: Color) {
    var chosen by remember(action) { mutableStateOf(action.destination) }
    val surface = if (night) Ai.surfaceDark else Ai.surfaceLight
    val line = if (night) Ai.lineDark else Ai.lineLight
    val drive = rememberInfiniteTransition(label = "ride-card")
    val bob by drive.animateFloat(0.94f, 1.06f, infiniteRepeatable(tween(900, easing = FastOutSlowInEasing), RepeatMode.Reverse), label = "bob")
    val shine by drive.animateFloat(0f, 1f, infiniteRepeatable(tween(2600, easing = LinearEasing), RepeatMode.Restart), label = "shine")

    Column(
        Modifier.padding(top = 10.dp).widthIn(max = 340.dp).fillMaxWidth()
            .clip(RoundedCornerShape(20.dp)).background(surface)
            .border(BorderStroke(1.dp, line), RoundedCornerShape(20.dp)),
    ) {
        // The header: DORR's colours, a car that breathes, the title.
        Row(
            Modifier.fillMaxWidth()
                .background(Brush.linearGradient(listOf(Navy, Navy.copy(alpha = 0.92f), Orange), start = androidx.compose.ui.geometry.Offset(600f * shine - 300f, 0f)))
                .padding(horizontal = 14.dp, vertical = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(Modifier.size(38.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.16f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.LocalTaxi, null, tint = Color.White, modifier = Modifier.size(22.dp).scale(bob))
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(action.title ?: stringResource(R.string.ai_ride_card_title), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
                Text(stringResource(R.string.ai_ride_card_sub), color = Color.White.copy(alpha = 0.85f), fontSize = 11.5.sp)
            }
            Icon(Icons.Rounded.AutoAwesome, null, tint = Color.White.copy(alpha = 0.9f), modifier = Modifier.size(16.dp))
        }

        Column(Modifier.padding(14.dp)) {
            // Where to.
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(34.dp).clip(RoundedCornerShape(12.dp)).background(Orange.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.LocationOn, null, tint = Orange, modifier = Modifier.size(19.dp))
                }
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.ai_ride_card_to), color = mut, fontSize = 11.sp)
                    Text(
                        chosen?.let { it.name ?: it.address } ?: action.subtitle.orEmpty(),
                        color = ink, fontWeight = FontWeight.Bold, fontSize = 14.sp, maxLines = 2, overflow = TextOverflow.Ellipsis,
                    )
                    chosen?.address?.takeIf { it.isNotBlank() && it != chosen?.name }?.let {
                        Text(it, color = mut, fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }

            // The other places found: one tap to switch.
            val others = action.places.filter { it != chosen }
            if (others.isNotEmpty()) {
                Spacer(Modifier.height(8.dp))
                Text(stringResource(R.string.ai_ride_card_or), color = mut, fontSize = 11.sp)
                Spacer(Modifier.height(4.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    others.forEach { place -> PlaceChip(place, ink, line) { chosen = place } }
                }
            }

            // What else was understood.
            val u = action.understood
            val chips = buildList {
                u?.categoryName?.let { add(Icons.Rounded.DirectionsCar to it) }
                u?.`when`?.let { iso -> runCatching { ZonedDateTime.parse(iso) }.getOrNull()?.let { add(Icons.Rounded.Schedule to it.format(DateTimeFormatter.ofPattern("EEE d MMM · h:mm a", Locale.getDefault()))) } }
                u?.passengers?.let { add(Icons.Rounded.Group to it.toString()) }
                u?.payment?.let { add(Icons.Rounded.Payments to it) }
                u?.note?.let { add(Icons.Rounded.StickyNote2 to it) }
            }
            if (chips.isNotEmpty()) {
                Spacer(Modifier.height(10.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    chips.forEach { (icon, label) -> InfoChip(icon, paymentLabel(label), ink, line) }
                }
            }
            action.clarify?.let { Text(it, color = Orange, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 8.dp)) }

            Spacer(Modifier.height(12.dp))
            Row(
                Modifier.fillMaxWidth().height(46.dp).clip(RoundedCornerShape(14.dp))
                    .background(Brush.horizontalGradient(listOf(Orange, Color(0xFFFF8E6E))))
                    .clickable { RidesLink.book(action.copy(destination = chosen)) },
                horizontalArrangement = Arrangement.Center,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(stringResource(if (chosen != null) R.string.ai_ride_card_book else R.string.ai_ride_card_pick), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 14.5.sp)
                Spacer(Modifier.width(8.dp))
                Icon(Icons.AutoMirrored.Rounded.ArrowForward, null, tint = Color.White, modifier = Modifier.size(18.dp))
            }
            Text(stringResource(R.string.ai_ride_card_hint), color = mut, fontSize = 10.5.sp, modifier = Modifier.padding(top = 6.dp))
        }
    }
}

@Composable
private fun paymentLabel(value: String): String = when (value) {
    "cash" -> stringResource(R.string.ai_ride_pay_cash)
    "wallet" -> stringResource(R.string.ai_ride_pay_wallet)
    "card" -> stringResource(R.string.ai_ride_pay_card)
    else -> value
}

@Composable
private fun InfoChip(icon: ImageVector, label: String, ink: Color, line: Color) {
    Row(
        Modifier.clip(RoundedCornerShape(50)).border(BorderStroke(1.dp, line), RoundedCornerShape(50)).padding(horizontal = 10.dp, vertical = 5.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = Navy.copy(alpha = 0.8f), modifier = Modifier.size(13.dp))
        Spacer(Modifier.width(4.dp))
        Text(label, color = ink, fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

@Composable
private fun PlaceChip(place: RideAiPlaceDto, ink: Color, line: Color, onClick: () -> Unit) {
    Text(
        place.name ?: place.address, color = ink, fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
        modifier = Modifier.clip(RoundedCornerShape(50)).border(BorderStroke(1.dp, line), RoundedCornerShape(50)).clickable(onClick = onClick).padding(horizontal = 10.dp, vertical = 5.dp),
    )
}
