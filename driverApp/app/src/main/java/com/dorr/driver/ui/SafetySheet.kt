package com.dorr.driver.ui

import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
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
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.Share
import androidx.compose.material.icons.filled.Shield
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
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
import androidx.compose.ui.input.pointer.pointerInput
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
import com.google.gson.JsonObject
import kotlinx.coroutines.launch

/*
 * The captain's safety on a ride (D-141, D-142, D-156): share the trip with someone they trust, and
 * SOS — hold the red button to alert DORR's safety team with where they are; the emergency number is
 * one tap away. The rider is never told.
 */

private val SosRed = Color(0xFFA32D2D)

/** Gets (or makes) the trip's link and opens the phone's share sheet; an error message, or null. */
internal suspend fun shareTrip(context: Context, uuid: String): String? {
    return when (val r = apiCall { DriverBackend.api.shareRide(uuid) }) {
        is ApiResult.Ok -> {
            val text = context.getString(R.string.trip_share_text, r.data.url)
            val send = Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, text)
            context.startActivity(Intent.createChooser(send, context.getString(R.string.trip_share)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            null
        }
        is ApiResult.Failed -> r.error.displayMessage
        is ApiResult.Offline -> context.getString(R.string.error_offline)
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun SosSheet(t: TripDto, onClose: () -> Unit, onReport: () -> Unit = {}) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val haptics = LocalHapticFeedback.current
    var sent by remember { mutableStateOf(false) }
    var sending by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var number by remember { mutableStateOf(t.safety?.emergencyNumber ?: "911") }
    var sharing by remember { mutableStateOf(false) }
    var info by remember { mutableStateOf<String?>(null) }
    val breathe = rememberInfiniteTransition(label = "sos")
    val ring by breathe.animateFloat(0f, 1f, infiniteRepeatable(tween(1600, easing = LinearEasing), RepeatMode.Restart), label = "ring")
    var pressed by remember { mutableStateOf(false) }
    val press by animateFloatAsState(if (pressed) 0.92f else 1f, spring(dampingRatio = Spring.DampingRatioMediumBouncy), label = "press")

    fun alert() {
        if (sent || sending) return
        sending = true
        error = null
        haptics.performHapticFeedback(HapticFeedbackType.LongPress)
        scope.launch {
            val here = lastPosition(context)
            val body = JsonObject().apply { here?.let { addProperty("lat", it.first); addProperty("lng", it.second) } }
            when (val r = apiCall { DriverBackend.api.sos(t.uuid, body) }) {
                is ApiResult.Ok -> { sent = true; r.data.emergencyNumber?.let { number = it } }
                is ApiResult.Failed -> error = r.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            sending = false
        }
    }

    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 22.dp).padding(bottom = 28.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.Shield, null, tint = SosRed)
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.trip_sos_title), fontWeight = FontWeight.ExtraBold, fontSize = 20.sp)
            }
            Spacer(Modifier.height(18.dp))
            Box(Modifier.size(200.dp), contentAlignment = Alignment.Center) {
                if (!sent) listOf(0f, 0.5f).forEach { offset ->
                    val p = (ring + offset) % 1f
                    Box(Modifier.size(200.dp).scale(0.62f + p * 0.38f).clip(CircleShape).background(SosRed.copy(alpha = (1f - p) * 0.22f)))
                }
                AnimatedContent(
                    targetState = sent,
                    transitionSpec = { (scaleIn(spring(dampingRatio = Spring.DampingRatioMediumBouncy)) + fadeIn()) togetherWith fadeOut(tween(120)) },
                    label = "sos-state",
                ) { done ->
                    Box(
                        Modifier.size(136.dp).scale(press).clip(CircleShape).background(if (done) DorrBrand.success else SosRed)
                            .pointerInput(done) {
                                if (!done) detectTapGestures(onPress = { pressed = true; tryAwaitRelease(); pressed = false }, onLongPress = { alert() })
                            },
                        contentAlignment = Alignment.Center,
                    ) {
                        when {
                            done -> Icon(Icons.Filled.Check, null, tint = Color.White, modifier = Modifier.size(66.dp))
                            sending -> CircularProgressIndicator(color = Color.White, strokeWidth = 3.dp, modifier = Modifier.size(42.dp))
                            else -> Text("SOS", color = Color.White, fontWeight = FontWeight.Black, fontSize = 36.sp)
                        }
                    }
                }
            }
            Text(
                stringResource(if (sent) R.string.trip_sos_sent else R.string.trip_sos_hold),
                color = if (sent) DorrBrand.success else MaterialTheme.colorScheme.onSurface.copy(alpha = 0.65f),
                fontWeight = if (sent) FontWeight.Bold else FontWeight.Normal, fontSize = 14.sp, textAlign = TextAlign.Center,
            )
            error?.let { Text(it, color = MaterialTheme.colorScheme.error, fontSize = 13.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 6.dp)) }
            Spacer(Modifier.height(18.dp))
            Row(
                Modifier.fillMaxWidth().height(58.dp).clip(RoundedCornerShape(18.dp)).background(SosRed)
                    .clickable { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$number"))) },
                horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Filled.Call, null, tint = Color.White)
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.trip_sos_call, number), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 17.sp)
            }
            Spacer(Modifier.height(10.dp))
            Row(
                Modifier.fillMaxWidth().height(52.dp).clip(RoundedCornerShape(18.dp)).border(1.dp, MaterialTheme.colorScheme.onSurface.copy(alpha = 0.15f), RoundedCornerShape(18.dp))
                    .clickable(enabled = !sharing) { sharing = true; scope.launch { error = shareTrip(context, t.uuid); sharing = false } },
                horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Filled.Share, null)
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.trip_share), fontWeight = FontWeight.SemiBold)
            }
            Spacer(Modifier.height(10.dp))
            // Straight to my trusted contacts (D-142, D-143), and a report that isn't an emergency.
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Box(
                    Modifier.weight(1f).height(50.dp).clip(RoundedCornerShape(18.dp)).background(DorrBrand.navy.copy(alpha = 0.08f))
                        .clickable { scope.launch { shareTripWithContacts(t.uuid)?.let { error = it } ?: run { info = context.getString(R.string.sc_sent) } } },
                    contentAlignment = Alignment.Center,
                ) { Text(stringResource(R.string.sc_send_contacts), fontWeight = FontWeight.SemiBold, fontSize = 13.sp, textAlign = TextAlign.Center) }
                Box(
                    Modifier.weight(1f).height(50.dp).clip(RoundedCornerShape(18.dp)).background(SosRed.copy(alpha = 0.08f)).clickable(onClick = onReport),
                    contentAlignment = Alignment.Center,
                ) { Text(stringResource(R.string.rp_title), color = SosRed, fontWeight = FontWeight.SemiBold, fontSize = 13.sp) }
            }
            info?.let { Text(it, color = DorrBrand.success, fontSize = 13.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(top = 8.dp)) }
        }
    }
}
