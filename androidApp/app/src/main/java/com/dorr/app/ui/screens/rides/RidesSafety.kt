package com.dorr.app.ui.screens.rides

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
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.Chat
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.IosShare
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
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
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.RideDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import com.google.gson.JsonObject
import kotlinx.coroutines.launch

/*
 * Safety on the ride (R-84..86, R-110, R-111): the wireflow's row of tiles under the captain card —
 * 💬 Message · 📤 Share · 🚨 SOS — and the SOS sheet: a big red button with a breathing ring (hold to
 * alert DORR's safety team with where you are), the country's emergency number one tap away, and
 * the family link.
 */

private val SosRed = Color(0xFFA32D2D)

/** 📞 Call · 💬 Message (with the unread count) · 📤 Share · 🚨 SOS — equal tiles, SOS in red. */
@Composable
internal fun SafetyActions(onChat: () -> Unit, onShare: () -> Unit, onSos: () -> Unit, sharing: Boolean, unread: Int = 0, callPhone: String? = null, onAppCall: (() -> Unit)? = null) {
    val context = LocalContext.current
    var callMenu by remember { mutableStateOf(false) }
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        // O-09: call the captain — inside the app (free, no numbers shown) or the phone's own call.
        if (callPhone != null || onAppCall != null) {
            Box(Modifier.weight(1f)) {
                Row {
                    ActionTile(Icons.Rounded.Call, stringResource(R.string.rd_call), AppColors.success, {
                        when {
                            onAppCall != null && callPhone != null -> callMenu = true
                            onAppCall != null -> onAppCall()
                            else -> runCatching { context.startActivity(Intent(Intent.ACTION_DIAL, android.net.Uri.parse("tel:$callPhone"))) }
                        }
                    })
                }
                androidx.compose.material3.DropdownMenu(expanded = callMenu, onDismissRequest = { callMenu = false }) {
                    androidx.compose.material3.DropdownMenuItem(
                        text = { Text(stringResource(R.string.rc_in_app)) },
                        leadingIcon = { Icon(Icons.Rounded.Call, null, tint = AppColors.success) },
                        onClick = { callMenu = false; onAppCall?.invoke() },
                    )
                    androidx.compose.material3.DropdownMenuItem(
                        text = { Text(stringResource(R.string.rc_phone)) },
                        leadingIcon = { Icon(Icons.Rounded.Call, null, tint = settingsMut()) },
                        onClick = { callMenu = false; runCatching { context.startActivity(Intent(Intent.ACTION_DIAL, android.net.Uri.parse("tel:$callPhone"))) } },
                    )
                }
            }
        }
        ActionTile(Icons.Rounded.Chat, stringResource(R.string.rd_chat), settingsAccent(), onChat, badge = unread)
        ActionTile(Icons.Rounded.IosShare, stringResource(R.string.rd_share), settingsAccent(), onShare, busy = sharing)
        ActionTile(Icons.Rounded.Shield, stringResource(R.string.rd_sos), SosRed, onSos, danger = true)
    }
}

@Composable
private fun RowScope.ActionTile(icon: ImageVector, label: String, tint: Color, onClick: () -> Unit, busy: Boolean = false, danger: Boolean = false, badge: Int = 0) {
    Column(
        Modifier.weight(1f).clip(RoundedCornerShape(14.dp))
            .border(BorderStroke(1.dp, if (danger) SosRed.copy(alpha = 0.25f) else settingsInk().copy(alpha = 0.1f)), RoundedCornerShape(14.dp))
            .background(if (danger) SosRed.copy(alpha = 0.06f) else Color.Transparent)
            .clickable(enabled = !busy, onClick = onClick).padding(vertical = 10.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        if (busy) androidx.compose.material3.CircularProgressIndicator(strokeWidth = 2.dp, color = tint, modifier = Modifier.size(22.dp))
        else Box {
            Icon(icon, null, tint = tint, modifier = Modifier.size(22.dp))
            // O-10: messages waiting unread.
            if (badge > 0) {
                Box(
                    Modifier.align(Alignment.TopEnd).offset(x = 10.dp, y = (-6).dp).size(18.dp).clip(CircleShape).background(AppColors.danger),
                    contentAlignment = Alignment.Center,
                ) { Text(if (badge > 9) "9+" else badge.toString(), color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.Bold) }
            }
        }
        Spacer(Modifier.height(4.dp))
        Text(label, color = if (danger) SosRed else settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
    }
}

/** Gets (or makes) the ride's family link and opens the phone's share sheet with it. */
internal suspend fun shareRide(context: Context, ride: RideDto): String? {
    val url = runCatching { ApiClient.rides.share(ridesAuth(), ride.uuid).data?.url }.getOrElse { return it.apiFailure().message }
        ?: return null
    val text = context.getString(R.string.rd_share_text, url)
    val send = Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, text)
    context.startActivity(Intent.createChooser(send, context.getString(R.string.rd_share_trip)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    return null
}

/** SOS: hold the red button to alert the safety team (with where you are); call the emergency number. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun SosSheet(ride: RideDto, onClose: () -> Unit, onReport: (() -> Unit)? = null) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val haptics = LocalHapticFeedback.current
    var sent by remember { mutableStateOf(false) }
    var sending by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var number by remember { mutableStateOf(ride.safety?.emergencyNumber ?: "911") }
    var sharing by remember { mutableStateOf(false) }

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
            val here = currentLocation(context)
            val body = JsonObject().apply {
                here?.let { addProperty("lat", it.latitude); addProperty("lng", it.longitude) }
            }
            runCatching { ApiClient.rides.sos(ridesAuth(), ride.uuid, body).data }
                .onSuccess { result -> sent = true; result?.emergencyNumber?.let { number = it } }
                .onFailure { error = it.apiFailure().message }
            sending = false
        }
    }

    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 22.dp).padding(bottom = 28.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Shield, null, tint = SosRed)
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.rd_sos_title), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            }
            Spacer(Modifier.height(18.dp))

            // The big button: two rings breathing out of it; hold it to send.
            Box(Modifier.size(190.dp), contentAlignment = Alignment.Center) {
                if (!sent) listOf(0f, 0.5f).forEach { offset ->
                    val p = (ring + offset) % 1f
                    Box(Modifier.size(190.dp).scale(0.62f + p * 0.38f).clip(CircleShape).background(SosRed.copy(alpha = (1f - p) * 0.22f)))
                }
                AnimatedContent(
                    targetState = sent,
                    transitionSpec = { (scaleIn(spring(dampingRatio = Spring.DampingRatioMediumBouncy)) + fadeIn()) togetherWith fadeOut(tween(120)) },
                    label = "sos-state",
                ) { done ->
                    Box(
                        Modifier.size(128.dp).scale(press).clip(CircleShape).background(if (done) AppColors.success else SosRed)
                            .pointerInput(done) {
                                if (!done) detectTapGestures(
                                    onPress = { pressed = true; tryAwaitRelease(); pressed = false },
                                    onLongPress = { alert() },
                                )
                            },
                        contentAlignment = Alignment.Center,
                    ) {
                        when {
                            done -> Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(64.dp))
                            sending -> androidx.compose.material3.CircularProgressIndicator(color = Color.White, strokeWidth = 3.dp, modifier = Modifier.size(40.dp))
                            else -> Text("SOS", color = Color.White, fontWeight = FontWeight.Black, fontSize = 34.sp)
                        }
                    }
                }
            }
            Text(
                if (sent) stringResource(R.string.rd_sos_sent) else stringResource(R.string.rd_sos_hold),
                color = if (sent) AppColors.success else settingsMut(), fontWeight = if (sent) FontWeight.Bold else FontWeight.Normal,
                fontSize = 14.sp, textAlign = TextAlign.Center,
            )
            error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 6.dp)) }
            Spacer(Modifier.height(18.dp))

            // The emergency number: the phone dials it (no permission needed).
            Row(
                Modifier.fillMaxWidth().height(54.dp).clip(RoundedCornerShape(16.dp)).background(SosRed)
                    .clickable { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$number"))) },
                horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.Call, null, tint = Color.White)
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.rd_sos_call, number), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 16.sp)
            }
            Spacer(Modifier.height(10.dp))
            Row(
                Modifier.fillMaxWidth().height(50.dp).clip(RoundedCornerShape(16.dp)).border(BorderStroke(1.dp, settingsInk().copy(alpha = 0.15f)), RoundedCornerShape(16.dp))
                    .clickable(enabled = !sharing) {
                        sharing = true
                        scope.launch { shareRide(context, ride)?.let { error = it }; sharing = false }
                    },
                horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.IosShare, null, tint = settingsInk())
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.rd_share_trip), color = settingsInk(), fontWeight = FontWeight.SemiBold)
            }
            // 🚩 Not an emergency: report a problem on the ride (R-117).
            onReport?.let {
                Text(
                    stringResource(R.string.rd_report_title), color = SosRed, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().padding(top = 10.dp).clip(RoundedCornerShape(10.dp)).clickable(onClick = it).padding(10.dp),
                )
            }
        }
    }
}
