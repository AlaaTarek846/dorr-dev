package com.dorr.driver.ui

import androidx.compose.animation.animateContentSize
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Remove
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.CoreHttp
import com.dorr.core.network.apiCall
import com.dorr.driver.ui.theme.DorrBrand
import com.dorr.driver.R
import com.dorr.driver.duty.Duty
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.OfferDto
import com.google.gson.JsonObject
import kotlinx.coroutines.launch

/**
 * A bidding request (docs/rides-plan.md §9.2): "Accept for <rider's price>" gives the ride at once;
 * or the driver offers more — +5 / +10 / +15 or their own number, never over the cap — and sees
 * what they'd earn as the price moves. While the rider decides, the card says so; declined or
 * timed out, they can offer again (even lower, still above the rider).
 */
@Composable
internal fun BidSection(offer: OfferDto, onAccepted: () -> Unit, onDecline: () -> Unit) {
    val bid = offer.bid ?: return
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val rider = bid.riderPriceMinor
    var price by remember(offer.uuid, rider) { mutableLongStateOf(rider + (bid.stepsMinor.firstOrNull() ?: 500)) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val mine = bid.myBid

    fun send(amount: Long) {
        busy = true
        error = null
        scope.launch {
            when (val r = apiCall { DriverBackend.api.bid(CoreHttp.newIdempotencyKey(), offer.uuid, JsonObject().apply { addProperty("price_minor", amount) }) }) {
                is ApiResult.Ok -> {
                    val ride = r.data.takeIf { it.isJsonObject }?.asJsonObject?.get("ride")
                    if (ride != null && ride.isJsonObject) {
                        Duty.clearOffer(offer.uuid)
                        Duty.setOnTrip(true)
                        onAccepted()
                    }
                }
                is ApiResult.Failed -> error = r.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }

    Column(Modifier.fillMaxWidth().animateContentSize()) {
        Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(DorrBrand.navy.copy(alpha = 0.06f)).padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.bid_rider_price), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
                Text(money(rider, offer.currency), fontWeight = FontWeight.ExtraBold, fontSize = 24.sp)
                Text(stringResource(R.string.bid_net, money(bid.netAt(rider), offer.currency)), color = DorrBrand.success, fontSize = 12.sp)
            }
        }
        Spacer(Modifier.height(10.dp))

        when (mine?.status) {
            "pending", "queued" -> {
                Text(stringResource(R.string.bid_waiting, money(mine.priceMinor, offer.currency)), fontWeight = FontWeight.Bold, color = DorrBrand.orange, modifier = Modifier.padding(vertical = 8.dp))
                return@Column
            }
            "declined" -> Text(stringResource(R.string.bid_declined), color = DorrBrand.danger, fontSize = 13.sp)
            "expired" -> Text(stringResource(R.string.bid_expired), color = DorrBrand.warning, fontSize = 13.sp)
        }

        BigButton(stringResource(R.string.bid_accept_at, money(rider, offer.currency)), color = DorrBrand.success, busy = busy) { send(rider) }
        Spacer(Modifier.height(12.dp))
        Text(stringResource(R.string.bid_or_offer), fontWeight = FontWeight.SemiBold)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.padding(vertical = 8.dp)) {
            bid.stepsMinor.forEach { step ->
                val target = (rider + step).coerceAtMost(bid.capMinor)
                Text(
                    "+" + money(step, offer.currency?.copy(symbol = "")),
                    fontWeight = FontWeight.Bold, color = if (price == target) Color.White else DorrBrand.navy,
                    modifier = Modifier.clip(CircleShape).background(if (price == target) DorrBrand.navy else Color.Transparent)
                        .border(1.dp, DorrBrand.navy, CircleShape).clickable { price = target }.padding(horizontal = 14.dp, vertical = 8.dp),
                )
            }
        }
        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth()) {
            Stepper(Icons.Filled.Remove, enabled = price - 100 > rider) { price = (price - 100).coerceAtLeast(rider + 100) }
            Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                Text(money(price, offer.currency), fontWeight = FontWeight.ExtraBold, fontSize = 22.sp)
                Text(stringResource(R.string.bid_net, money(bid.netAt(price), offer.currency)), color = DorrBrand.success, fontSize = 12.sp)
                Text(stringResource(R.string.bid_max, money(bid.capMinor, offer.currency)), fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.5f))
            }
            Stepper(Icons.Filled.Add, enabled = price + 100 <= bid.capMinor) { price = (price + 100).coerceAtMost(bid.capMinor) }
        }
        bid.attemptsLeft?.let { Text(stringResource(R.string.bid_attempts, it), fontSize = 11.sp, modifier = Modifier.padding(top = 4.dp)) }
        error?.let { Text(it, color = MaterialTheme.colorScheme.error, fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp)) }
        Spacer(Modifier.height(10.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(
                Modifier.weight(1f).height(58.dp).clip(RoundedCornerShape(18.dp)).background(MaterialTheme.colorScheme.onSurface.copy(alpha = 0.08f)).clickable(enabled = !busy, onClick = onDecline),
                contentAlignment = Alignment.Center,
            ) { Text(stringResource(R.string.offer_decline), fontWeight = FontWeight.Bold) }
            Box(Modifier.weight(2f)) {
                BigButton(stringResource(R.string.bid_send, money(price, offer.currency)), color = DorrBrand.orange, busy = busy, enabled = price > rider && price <= bid.capMinor) { send(price) }
            }
        }
    }
}

@Composable
private fun Stepper(icon: androidx.compose.ui.graphics.vector.ImageVector, enabled: Boolean, onClick: () -> Unit) {
    Box(
        Modifier.size(46.dp).clip(CircleShape).background(if (enabled) DorrBrand.navy else Color.Gray.copy(alpha = 0.3f)).clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, null, tint = Color.White) }
}
