package com.dorr.driver.ui

import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
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
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Bolt
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.ui.theme.DorrBrand
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.TripDto
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Wireflow 6ب: "⚡ Change of destination — 30 seconds to answer". The old and the new destination,
 * the extra distance and what the captain earns more; "✅ Agree +X" or "❌ Decline, keep going". No
 * answer in time = declined by itself (the server's clock decides).
 */
@Composable
internal fun ChangeRequestCard(t: TripDto, onAnswered: () -> Unit) {
    val change = t.changeRequest ?: return
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val haptics = LocalHapticFeedback.current
    var left by remember(change.uuid) { mutableIntStateOf(change.secondsLeft) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(change.uuid) {
        haptics.performHapticFeedback(HapticFeedbackType.LongPress)
        while (left > 0) { delay(1000); left-- }
    }
    var shown by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { shown = true }
    val pop by animateFloatAsState(if (shown) 1f else 0.85f, spring(dampingRatio = Spring.DampingRatioMediumBouncy), label = "card")
    val ring by animateFloatAsState(left / 30f, tween(900), label = "ring")
    val adding = change.type == "add_stop"

    fun answer(accept: Boolean) {
        busy = true
        error = null
        scope.launch {
            val r = apiCall { if (accept) DriverBackend.api.acceptChange(t.uuid, change.uuid) else DriverBackend.api.rejectChange(t.uuid, change.uuid) }
            when (r) {
                is ApiResult.Ok -> onAnswered()
                is ApiResult.Failed -> { error = r.error.displayMessage; onAnswered() }
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }

    Box(Modifier.fillMaxSize().background(Color.Black.copy(alpha = 0.55f)).clickable(enabled = false) {}, contentAlignment = Alignment.BottomCenter) {
        Column(
            Modifier.fillMaxWidth().scale(pop).clip(RoundedCornerShape(topStart = 28.dp, topEnd = 28.dp)).background(MaterialTheme.colorScheme.surface)
                .padding(20.dp).navigationBarsPadding(),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.Bolt, null, tint = DorrBrand.orange, modifier = Modifier.size(26.dp))
                Spacer(Modifier.width(6.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(if (adding) R.string.chg_title_stop else R.string.chg_title), fontWeight = FontWeight.ExtraBold, fontSize = 20.sp)
                    Text(stringResource(R.string.chg_seconds, 30), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
                }
                Box(Modifier.size(56.dp), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(progress = { ring }, color = if (left > 10) DorrBrand.orange else DorrBrand.danger, strokeWidth = 4.dp, modifier = Modifier.size(56.dp))
                    Text(left.toString(), fontWeight = FontWeight.ExtraBold, fontSize = 20.sp)
                }
            }
            Spacer(Modifier.height(10.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(stringResource(R.string.chg_rider_wants, t.rider?.name.orEmpty()), fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f, fill = false))
                RiderTick(t.rider?.isVerified == true, 14.dp)
            }
            Spacer(Modifier.height(10.dp))
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(DorrBrand.navy.copy(alpha = 0.05f)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                if (!adding) Line(stringResource(R.string.chg_old), t.destination?.address ?: "—")
                Line(stringResource(if (adding) R.string.chg_new_stop else R.string.chg_new), change.place?.address ?: "—", bold = true)
                Line(stringResource(R.string.chg_extra_distance), (if (change.distanceDeltaM >= 0) "+" else "−") + stringResource(R.string.sch_trip_km, String.format("%.1f", kotlin.math.abs(change.distanceDeltaM) / 1000.0)))
                Line(stringResource(R.string.chg_extra_earning), (if ((change.earningDeltaMinor ?: 0) >= 0) "+" else "−") + money(kotlin.math.abs(change.earningDeltaMinor ?: 0), t.currency), color = DorrBrand.success, bold = true)
            }
            error?.let { Text(it, color = MaterialTheme.colorScheme.error, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp)) }
            Spacer(Modifier.height(14.dp))
            BigButton(stringResource(R.string.chg_accept, money(kotlin.math.max(0, change.earningDeltaMinor ?: 0), t.currency)), color = DorrBrand.success, busy = busy, enabled = left > 0) { answer(true) }
            Spacer(Modifier.height(10.dp))
            Box(
                Modifier.fillMaxWidth().height(54.dp).clip(RoundedCornerShape(18.dp)).border(1.dp, DorrBrand.danger.copy(alpha = 0.4f), RoundedCornerShape(18.dp))
                    .clickable(enabled = !busy) { answer(false) },
                contentAlignment = Alignment.Center,
            ) {
                Text(stringResource(R.string.chg_reject, t.destination?.address?.take(28) ?: ""), color = DorrBrand.danger, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
            }
        }
    }
}

@Composable
private fun Line(label: String, value: String, bold: Boolean = false, color: Color = MaterialTheme.colorScheme.onSurface) {
    Row(Modifier.fillMaxWidth()) {
        Text(label, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f), modifier = Modifier.weight(1f))
        Text(value, fontSize = 14.sp, fontWeight = if (bold) FontWeight.ExtraBold else FontWeight.SemiBold, color = color, maxLines = 1)
    }
}
