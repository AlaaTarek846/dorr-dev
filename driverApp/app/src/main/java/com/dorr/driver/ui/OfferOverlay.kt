package com.dorr.driver.ui

import android.media.RingtoneManager
import android.os.Build
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.foundation.Canvas
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
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Circle
import androidx.compose.material.icons.filled.Place
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.foundation.horizontalScroll
import androidx.compose.material.icons.filled.Inbox
import androidx.compose.ui.draw.scale
import androidx.compose.animation.core.animateFloat
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.app.NotificationManagerCompat
import com.dorr.core.network.ApiResult
import com.dorr.core.network.CoreHttp
import com.dorr.core.network.apiCall
import com.dorr.driver.ui.theme.DorrBrand
import com.dorr.driver.R
import com.dorr.driver.duty.Duty
import com.dorr.driver.duty.DriverDutyService
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.OfferDto
import com.google.gson.JsonObject
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * A request, over everything while the app is open: what you earn in big, the pickup distance, the
 * trip, and a ring that empties with the time left. Rings and vibrates; times out by itself.
 */
@Composable
fun OfferOverlay(onAccepted: () -> Unit) {
    val offer by Duty.offer.collectAsState()
    val minimized by Duty.minimized.collectAsState()
    AnimatedVisibility(visible = offer != null && !minimized, enter = slideInVertically { it } + fadeIn(), exit = fadeOut()) {
        offer?.let { OfferCard(it, onAccepted) }
    }
}

/**
 * O-36: the requests inbox — an icon with how many are open; tapping it brings the request cards back.
 * Shown while the card is put aside (or anywhere a screen wants it).
 */
@Composable
fun OffersInboxButton(modifier: Modifier = Modifier) {
    val offers by Duty.offers.collectAsState()
    val minimized by Duty.minimized.collectAsState()
    if (offers.isEmpty() || !minimized) return
    val pulse = androidx.compose.animation.core.rememberInfiniteTransition(label = "inbox")
    val scale by pulse.animateFloat(1f, 1.08f, androidx.compose.animation.core.infiniteRepeatable(tween(700), androidx.compose.animation.core.RepeatMode.Reverse), label = "inbox-scale")
    Box(modifier.size(64.dp).scale(scale), contentAlignment = Alignment.Center) {
        Box(
            Modifier.size(58.dp).clip(CircleShape).background(DorrBrand.orange).clickable { Duty.minimize(false) },
            contentAlignment = Alignment.Center,
        ) { Icon(Icons.Filled.Inbox, stringResource(R.string.offer_inbox), tint = Color.White, modifier = Modifier.size(28.dp)) }
        Box(
            Modifier.align(Alignment.TopEnd).size(24.dp).clip(CircleShape).background(DorrBrand.danger),
            contentAlignment = Alignment.Center,
        ) { Text(offers.size.toString(), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 12.sp) }
    }
}

/** O-36: the other open requests, as chips over the card — tap one to see it. */
@Composable
private fun OfferSwitcher(current: OfferDto) {
    val offers by Duty.offers.collectAsState()
    if (offers.size < 2) return
    Row(
        Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(bottom = 10.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Text(stringResource(R.string.offer_count, offers.size), fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.align(Alignment.CenterVertically))
        offers.forEach { o ->
            val on = o.uuid == current.uuid
            Text(
                "${money(o.earningMinor, o.currency)} · ${String.format("%.1f", o.pickupDistanceM / 1000.0)} km",
                fontSize = 12.sp, fontWeight = FontWeight.Bold, color = if (on) Color.White else DorrBrand.navy,
                modifier = Modifier.clip(CircleShape).background(if (on) DorrBrand.navy else DorrBrand.navy.copy(alpha = 0.08f))
                    .clickable { Duty.focus(o.uuid) }.padding(horizontal = 12.dp, vertical = 7.dp),
            )
        }
    }
}

@Composable
private fun OfferCard(offer: OfferDto, onAccepted: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    // Follows the server on every refresh (a bidding request's time moves with each offer).
    var left by remember(offer.uuid, offer.secondsLeft) { mutableIntStateOf(offer.secondsLeft.coerceAtLeast(1)) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val total = offer.ttlS.coerceAtLeast(1)
    val progress by animateFloatAsState(left.toFloat() / total, tween(900), label = "timer")

    // Sound and vibration while it waits (the notification already rang when the app was closed).
    DisposableEffect(offer.uuid) {
        val tone = runCatching { RingtoneManager.getRingtone(context, RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE)) }.getOrNull()
        tone?.play()
        val vibrator = if (Build.VERSION.SDK_INT >= 31) (context.getSystemService(VibratorManager::class.java))?.defaultVibrator
        else @Suppress("DEPRECATION") context.getSystemService(Vibrator::class.java)
        if (Build.VERSION.SDK_INT >= 26) vibrator?.vibrate(VibrationEffect.createWaveform(longArrayOf(0, 500, 400, 500, 400, 500), -1))
        NotificationManagerCompat.from(context).cancel(DriverDutyService.ID_OFFER)
        onDispose { tone?.stop(); vibrator?.cancel() }
    }
    LaunchedEffect(offer.uuid, offer.secondsLeft) {
        while (left > 0) { delay(1000); left -= 1 }
        Duty.clearOffer(offer.uuid)
    }

    Box(Modifier.fillMaxSize().background(Color.Black.copy(alpha = 0.55f)).clickable(enabled = false) {}, contentAlignment = Alignment.BottomCenter) {
        Column(
            // Scrolls when it is taller than the screen (a bidding request with the keyboard up), so the
            // buttons at its foot — "send my offer" — are always reachable (O-42).
            Modifier.fillMaxWidth().statusBarsPadding().clip(RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)).background(MaterialTheme.colorScheme.surface)
                .imePadding().verticalScroll(rememberScrollState()).padding(22.dp).navigationBarsPadding(),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            // The grabber of a sheet, as in the DORR app's panels.
            Box(Modifier.size(width = 38.dp, height = 4.dp).clip(CircleShape).background(MaterialTheme.colorScheme.onSurface.copy(alpha = 0.15f)))
            Spacer(Modifier.height(10.dp))
            // O-36: more than one request open — switch between them, or put them aside behind the inbox icon.
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.weight(1f)) { OfferSwitcher(offer) }
                Text(
                    stringResource(R.string.offer_later), color = DorrBrand.navy, fontWeight = FontWeight.SemiBold, fontSize = 13.sp,
                    modifier = Modifier.clip(CircleShape).clickable { Duty.minimize(true) }.padding(horizontal = 10.dp, vertical = 6.dp),
                )
            }
            Spacer(Modifier.height(4.dp))
            Box(Modifier.size(120.dp), contentAlignment = Alignment.Center) {
                Canvas(Modifier.size(120.dp)) {
                    drawArc(Color.LightGray.copy(alpha = 0.3f), 0f, 360f, false, style = Stroke(10f), topLeft = Offset(6f, 6f), size = Size(size.width - 12f, size.height - 12f))
                    drawArc(if (left <= 5) DorrBrand.danger else DorrBrand.orange, -90f, 360f * progress, false, style = Stroke(10f, cap = StrokeCap.Round), topLeft = Offset(6f, 6f), size = Size(size.width - 12f, size.height - 12f))
                }
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Text(money(offer.earningMinor, offer.currency), fontWeight = FontWeight.ExtraBold, fontSize = 20.sp)
                    Text("${left}s", color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f), fontSize = 13.sp)
                }
            }
            Spacer(Modifier.height(8.dp))
            Text(stringResource(R.string.offer_title), fontWeight = FontWeight.Bold, fontSize = 18.sp)
            // O-28: the ride's category, and how the earning is made — before accepting.
            offer.category?.name?.let { name ->
                Text(
                    name, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 12.sp,
                    modifier = Modifier.padding(top = 6.dp).clip(CircleShape).background(DorrBrand.navy).padding(horizontal = 12.dp, vertical = 4.dp),
                )
            }
            offer.money?.let { m ->
                Column(
                    Modifier.fillMaxWidth().padding(top = 10.dp).clip(RoundedCornerShape(14.dp))
                        .background(MaterialTheme.colorScheme.onSurface.copy(alpha = 0.05f)).padding(horizontal = 14.dp, vertical = 8.dp),
                ) {
                    MoneyLine(stringResource(R.string.offer_fare), money(m.fareMinor, offer.currency))
                    if (m.serviceFeeMinor > 0) MoneyLine(stringResource(R.string.offer_service_fee), "−" + money(m.serviceFeeMinor, offer.currency))
                    if (m.taxMinor > 0 && m.taxOnDriver) MoneyLine(stringResource(R.string.offer_tax), "−" + money(m.taxMinor, offer.currency))
                    MoneyLine(stringResource(R.string.offer_net), money(m.netMinor, offer.currency), strong = true)
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.padding(top = 6.dp)) {
                Chip(stringResource(R.string.offer_pickup_km, String.format("%.1f", offer.pickupDistanceM / 1000.0)))
                Chip(stringResource(R.string.offer_away, ((offer.pickupEtaS + 59) / 60).toString()))
                Chip(if (offer.paymentMethod == "cash") stringResource(R.string.offer_cash) else stringResource(R.string.offer_paid))
                Chip(offer.riderRating?.let { String.format("%.1f ★", it) } ?: stringResource(R.string.offer_new_rider))
            }
            Spacer(Modifier.height(14.dp))
            Point(DorrBrand.navy, stringResource(R.string.offer_pickup), offer.origin?.address)
            offer.stops.forEach { Point(DorrBrand.warning, "•", it.address) }
            Point(DorrBrand.orange, stringResource(R.string.offer_dropoff), offer.destination?.address ?: stringResource(R.string.offer_open_destination))
            // D-60, D-61: how many ride and the pickup note, before accepting.
            RiderDetails(offer.passengers, offer.pickupNote, offer.beneficiary?.name, offer.pickupPoint, offer.dropoffPoint, offer.fromCircle)
            error?.let { Text(it, color = MaterialTheme.colorScheme.error, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp)) }
            Spacer(Modifier.height(16.dp))
            // Bidding: the rider's price, "accept for", or my own offer.
            if (offer.bid != null) {
                BidSection(offer, onAccepted = onAccepted, onDecline = {
                    scope.launch { apiCall { DriverBackend.api.decline(offer.uuid, JsonObject()) } }
                    Duty.clearOffer(offer.uuid)
                })
                return@Column
            }
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                Box(
                    Modifier.weight(1f).height(58.dp).clip(RoundedCornerShape(18.dp)).background(MaterialTheme.colorScheme.onSurface.copy(alpha = 0.08f))
                        .clickable(enabled = !busy) {
                            scope.launch { apiCall { DriverBackend.api.decline(offer.uuid, JsonObject()) } }
                            Duty.clearOffer(offer.uuid)
                        },
                    contentAlignment = Alignment.Center,
                ) { Text(stringResource(R.string.offer_decline), fontWeight = FontWeight.Bold) }
                Box(Modifier.weight(2f)) {
                    BigButton(stringResource(R.string.offer_accept), color = DorrBrand.success, busy = busy) {
                        busy = true
                        scope.launch {
                            when (val result = apiCall { DriverBackend.api.accept(CoreHttp.newIdempotencyKey(), offer.uuid) }) {
                                is ApiResult.Ok -> {
                                    Duty.clearOffer(offer.uuid)
                                    Duty.setOnTrip(true)
                                    onAccepted()
                                }
                                is ApiResult.Failed -> { error = result.error.displayMessage; delay(1500); Duty.clearOffer(offer.uuid) }
                                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                            }
                            busy = false
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun MoneyLine(label: String, value: String, strong: Boolean = false) {
    Row(Modifier.fillMaxWidth().padding(vertical = 2.dp)) {
        Text(label, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = if (strong) 1f else 0.65f), fontWeight = if (strong) FontWeight.Bold else FontWeight.Normal, modifier = Modifier.weight(1f))
        Text(value, fontSize = if (strong) 15.sp else 13.sp, fontWeight = if (strong) FontWeight.ExtraBold else FontWeight.SemiBold, color = if (strong) DorrBrand.success else MaterialTheme.colorScheme.onSurface)
    }
}

@Composable
private fun Chip(text: String) {
    Text(text, fontSize = 12.sp, modifier = Modifier.clip(CircleShape).background(MaterialTheme.colorScheme.onSurface.copy(alpha = 0.07f)).padding(horizontal = 10.dp, vertical = 5.dp))
}

@Composable
private fun Point(color: Color, label: String, address: String?) {
    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.Top) {
        Icon(if (color == DorrBrand.orange) Icons.Filled.Place else Icons.Filled.Circle, null, tint = color, modifier = Modifier.size(if (color == DorrBrand.orange) 20.dp else 14.dp).padding(top = 2.dp))
        Spacer(Modifier.width(10.dp))
        Column {
            Text(label, fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.55f))
            Text(address ?: "—", fontWeight = FontWeight.SemiBold, maxLines = 2)
        }
    }
}
