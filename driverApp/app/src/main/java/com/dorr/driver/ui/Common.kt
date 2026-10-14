package com.dorr.driver.ui

import android.annotation.SuppressLint
import android.content.Context
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.ui.DorrBrand
import com.dorr.driver.R
import com.dorr.driver.net.CurrencyDto
import com.dorr.driver.net.TripDto
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import kotlinx.coroutines.suspendCancellableCoroutine
import java.util.Locale
import kotlin.coroutines.resume

/** "25.50 ر.س" from minor units. */
internal fun money(minor: Long?, currency: CurrencyDto?): String {
    if (minor == null) return "—"
    val places = currency?.decimals ?: 2
    var divisor = 1.0
    repeat(places) { divisor *= 10 }
    return "${String.format(Locale.getDefault(), "%.${places}f", minor / divisor)} ${currency?.symbol.orEmpty()}".trim()
}

/** The reason code the server gives for "can't work now", in words (D-9). */
internal fun problemText(context: Context, code: String?): String {
    val id = when (code) {
        "account_pending_documents" -> R.string.problem_account_pending_documents
        "account_under_review" -> R.string.problem_account_under_review
        "account_suspended" -> R.string.problem_account_suspended
        "account_restricted" -> R.string.problem_account_restricted
        "account_blocked" -> R.string.problem_account_blocked
        "no_active_vehicle" -> R.string.problem_no_active_vehicle
        "documents_missing" -> R.string.problem_documents_missing
        "documents_expired" -> R.string.problem_documents_expired
        "regulator_pending" -> R.string.problem_regulator_pending
        "regulator_invalid" -> R.string.problem_regulator_invalid
        "regulator_rejected" -> R.string.problem_regulator_rejected
        "regulator_expired" -> R.string.problem_regulator_expired
        "regulator_vehicle_changed" -> R.string.problem_regulator_vehicle_changed
        else -> R.string.problem_generic
    }
    return context.getString(id)
}

@SuppressLint("MissingPermission")
internal suspend fun lastPosition(context: Context): Pair<Double, Double>? = runCatching {
    suspendCancellableCoroutine { cont ->
        LocationServices.getFusedLocationProviderClient(context)
            .getCurrentLocation(Priority.PRIORITY_HIGH_ACCURACY, null)
            .addOnSuccessListener { loc -> cont.resume(loc?.let { it.latitude to it.longitude }) }
            .addOnFailureListener { cont.resume(null) }
    }
}.getOrNull()

internal fun clock(seconds: Int): String = "${seconds / 60}:${(seconds % 60).toString().padStart(2, '0')}"

/** A server time ("2026-10-14T09:30:00+00:00") → an instant; null if missing or unreadable. */
internal fun instantOf(iso: String?): java.time.Instant? =
    iso?.let { runCatching { java.time.OffsetDateTime.parse(it).toInstant() }.getOrNull() }

/** When the waiting at the pickup counts from: the booked time if the captain came early. */
internal fun waitingFrom(t: TripDto): java.time.Instant? = instantOf(t.times?.waitingFrom ?: t.times?.arrivedAt)

/** "9:30 AM" in the phone's language and zone. */
internal fun timeOfDay(at: java.time.Instant): String =
    at.atZone(java.time.ZoneId.systemDefault()).format(java.time.format.DateTimeFormatter.ofLocalizedTime(java.time.format.FormatStyle.SHORT).withLocale(java.util.Locale.getDefault()))

@Composable
internal fun Card(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Column(
        modifier.fillMaxWidth().shadow(4.dp, RoundedCornerShape(22.dp)).clip(RoundedCornerShape(22.dp))
            .background(MaterialTheme.colorScheme.surface).padding(18.dp),
        content = content,
    )
}

@Composable
internal fun BigButton(text: String, color: Color = DorrBrand.orange, enabled: Boolean = true, busy: Boolean = false, onClick: () -> Unit) {
    Box(
        Modifier.fillMaxWidth().height(58.dp).clip(RoundedCornerShape(18.dp))
            .background(if (enabled) color else color.copy(alpha = 0.4f))
            .clickable(enabled = enabled && !busy, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        if (busy) CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(24.dp))
        else Text(text, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 17.sp)
    }
}
