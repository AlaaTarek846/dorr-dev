package com.dorr.driver.ui

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.widget.Toast
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.dorr.driver.R
import com.dorr.driver.net.LastWithdrawalDto
import com.dorr.driver.net.ServiceStatusDto
import com.dorr.driver.ui.kit.Banner
import com.dorr.driver.ui.kit.BannerKind

/**
 * D-329, D-330, D-335, D-336, D-344: what is out of the ordinary where the captain works, on the home sheet.
 * Loaded on open / reconnect and on a real-time change — never on a timer.
 */
@Composable
internal fun ServiceStateBanners(s: ServiceStatusDto?) {
    if (s == null) return
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        if (s.maintenance) Banner(s.maintenanceMessage ?: stringResource(R.string.ss_maintenance), kind = BannerKind.Warning)
        if (!s.serviceOn) Banner(stringResource(R.string.ss_service_off), kind = BannerKind.Warning)
        s.emergencyReview?.let { r ->
            Banner(stringResource(R.string.ss_emergency_review, r.rideNumber.orEmpty(), r.until?.let { formatClock(it) }.orEmpty()), kind = BannerKind.Error)
        }
        if (s.pausedCategories.isNotEmpty()) Banner(stringResource(R.string.ss_categories_paused, s.pausedCategories.joinToString("، ") { it.name }), kind = BannerKind.Info)
        if (s.safeMode) Banner(stringResource(R.string.ss_safe_mode), kind = BannerKind.Info)
    }
}

/** D-330, D-338..D-340: the wallet's side — the safe mode, and how the last withdrawal stands. */
@Composable
internal fun WithdrawalStateBanners(s: ServiceStatusDto?, money: (Long) -> String) {
    if (s == null) return
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        if (s.safeMode) Banner(stringResource(R.string.ss_safe_mode), kind = BannerKind.Warning)
        s.lastWithdrawal?.let { w -> WithdrawalLine(w, money(w.amountMinor)) }
    }
}

@Composable
private fun WithdrawalLine(w: LastWithdrawalDto, amount: String) {
    when (w.state) {
        "pending" -> Banner(stringResource(R.string.ss_wd_pending, amount), kind = BannerKind.Info)
        "unknown" -> Banner(stringResource(R.string.ss_wd_unknown, amount), kind = BannerKind.Warning)
        "failed" -> Banner(stringResource(R.string.ss_wd_failed, amount, w.reason ?: "—"), kind = BannerKind.Error)
    }
}

/** "2026-10-21T18:00:00+03:00" → "21/10 18:00" in the phone's time zone. */
private fun formatClock(iso: String): String = instantOf(iso)?.let {
    java.time.format.DateTimeFormatter.ofPattern("dd/MM HH:mm").withZone(java.time.ZoneId.systemDefault()).format(it)
} ?: ""

/**
 * D-341: to a point by navigation — Google Maps, else any maps app, else the browser; with none of those,
 * the coordinates go to the clipboard so the captain can paste them anywhere.
 */
internal fun openNavigation(context: Context, lat: Double, lng: Double, webUrl: String? = null) {
    val tries = listOf(
        Intent(Intent.ACTION_VIEW, Uri.parse("google.navigation:q=$lat,$lng&mode=d")).setPackage("com.google.android.apps.maps"),
        Intent(Intent.ACTION_VIEW, Uri.parse("geo:$lat,$lng?q=$lat,$lng")),
        Intent(Intent.ACTION_VIEW, Uri.parse(webUrl ?: "https://www.google.com/maps/dir/?api=1&destination=$lat,$lng")),
    )
    if (tries.any { runCatching { context.startActivity(it.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }.isSuccess }) return
    context.getSystemService(ClipboardManager::class.java)?.setPrimaryClip(ClipData.newPlainText("DORR", "$lat,$lng"))
    Toast.makeText(context, context.getString(R.string.ss_nav_failed), Toast.LENGTH_LONG).show()
}
