package com.dorr.app.ui.screens.rides

import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.wallet.CheckoutLauncher
import com.dorr.app.ui.screens.wallet.CheckoutRequest
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.delay
import java.time.OffsetDateTime

/**
 * A tapped notification of a booked service (bus, boat, driver, lessons): which one and its booking.
 * The rides screen opens that service, and the service opens that booking.
 */
object ServiceLink {
    var pending by androidx.compose.runtime.mutableStateOf<Pair<String, String?>?>(null)

    /** From a notification's `event` (`bus.boarded`…) and its data — null when it isn't one of these. */
    fun of(event: String?, value: (String) -> String?): Pair<String, String?>? {
        val kind = event?.substringBefore('.')?.takeIf { it in setOf("bus", "marine", "hire", "lesson", "loyalty") } ?: return null
        val uuid = listOf("booking_uuid", "hire_uuid", "enrollment_uuid").firstNotNullOfOrNull { k -> value(k)?.takeIf { it.isNotBlank() && it != "null" } }
        return kind to uuid
    }

    /** The booking waiting for this service, once. */
    internal fun take(kind: String): String? {
        val p = pending ?: return null
        if (p.first != kind) return null
        pending = null
        return p.second
    }
}

/** O-67: the payment screen's purposes for the booked services (server: ServiceBookingPurpose). */
internal object ServicePay {
    const val BUS = "bus_booking"
    const val MARINE = "marine_booking"
    const val HIRE = "driver_hire"
    const val LESSONS = "lesson_pack"

    /** The app's one payment screen for this booking — coupon, wallet, «top up» and back to the same page. */
    fun open(purpose: String, uuid: String, onPaid: () -> Unit) {
        CheckoutLauncher.open(CheckoutRequest(purpose, mapOf("booking_uuid" to uuid)), onPaid)
    }
}

/**
 * O-67: a booking held for its payment — the time left (device clock) and «pay now». A fresh booking
 * opens the payment screen at once (`autoOpen`); paid → [onPaid] reloads the booking.
 */
@Composable
internal fun ServicePayCard(purpose: String, uuid: String, expiresAt: String?, autoOpen: Boolean, onPaid: () -> Unit) {
    LaunchedEffect(uuid) { if (autoOpen) ServicePay.open(purpose, uuid, onPaid) }
    val ends = remember(expiresAt) { expiresAt?.let { runCatching { OffsetDateTime.parse(it).toInstant().toEpochMilli() }.getOrNull() } }
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(ends) { while (ends != null && now < ends) { delay(1000); now = System.currentTimeMillis() } }
    val left = ends?.let { ((it - now) / 1000).coerceAtLeast(0) }
    val pulse by rememberInfiniteTransition(label = "pay").animateFloat(1f, 1.12f, infiniteRepeatable(tween(700), RepeatMode.Reverse), label = "pay-pulse")

    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp)).background(settingsCard())
            .border(1.5.dp, AppColors.warning.copy(alpha = 0.5f), RoundedCornerShape(22.dp)).padding(16.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(44.dp).scale(pulse).clip(CircleShape).background(AppColors.warning.copy(alpha = 0.16f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Payments, null, tint = AppColors.warning, modifier = Modifier.size(24.dp))
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.svc_pay_waiting), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 16.sp)
                Text(
                    if (left != null) stringResource(R.string.svc_pay_left, String.format("%d:%02d", left / 60, left % 60)) else stringResource(R.string.svc_pay_hint),
                    color = settingsMut(), fontSize = 12.5.sp,
                )
            }
        }
        Spacer(Modifier.height(12.dp))
        AccentButton(stringResource(R.string.svc_pay_now), enabled = left == null || left > 0) { ServicePay.open(purpose, uuid, onPaid) }
    }
}
