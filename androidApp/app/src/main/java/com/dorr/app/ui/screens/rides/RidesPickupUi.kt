package com.dorr.app.ui.screens.rides

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.foundation.Canvas
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
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.RideReasonDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.util.UUID

/*
 * The booking's first card, the map pin and the cancel sheet, redone (docs/rides_tasks.md O-51, O-52).
 */

/**
 * O-51: from → to as one card: a route line on the side (green dot, dashes, orange square), the pickup's
 * address on top (or «finding you…» while it's worked out), and «Where to?» under it as the big target.
 */
@Composable
internal fun RouteCard(state: RidesState) {
    val accent = settingsAccent()
    Column {
        Text(stringResource(R.string.pk_title), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 22.sp)
        Spacer(Modifier.height(12.dp))
        Row(
            Modifier.fillMaxWidth().shadow(10.dp, RoundedCornerShape(22.dp), spotColor = accent.copy(alpha = 0.18f))
                .clip(RoundedCornerShape(22.dp)).background(settingsBackground()).padding(vertical = 6.dp),
        ) {
            // The route line.
            Column(Modifier.padding(start = 16.dp, top = 20.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Box(Modifier.size(14.dp).clip(CircleShape).background(AppColors.success.copy(alpha = 0.22f)), contentAlignment = Alignment.Center) {
                    Box(Modifier.size(8.dp).clip(CircleShape).background(AppColors.success))
                }
                Canvas(Modifier.width(2.dp).height(42.dp)) {
                    drawLine(Color.Gray.copy(alpha = 0.45f), Offset(size.width / 2, 4f), Offset(size.width / 2, size.height - 4f), strokeWidth = 4f, pathEffect = PathEffect.dashPathEffect(floatArrayOf(8f, 8f)))
                }
                Box(Modifier.size(12.dp).clip(RoundedCornerShape(3.dp)).background(AppColors.brandOrange))
            }
            Column(Modifier.weight(1f).padding(start = 12.dp, end = 10.dp)) {
                // Pickup.
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).pressable { state.editing = "pickup"; state.step = RideStep.Search }.padding(vertical = 8.dp, horizontal = 4.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(R.string.pk_pickup), color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
                        val address = state.pickup?.address
                        if (state.pickupLoading || address == null) {
                            val shimmer by rememberInfiniteTransition(label = "pk").animateFloat(0.35f, 0.9f, infiniteRepeatable(tween(700), RepeatMode.Reverse), label = "a")
                            Text(stringResource(R.string.pk_finding), color = settingsMut().copy(alpha = shimmer), fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
                        } else {
                            Text(address, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                    }
                    Box(Modifier.size(30.dp).clip(CircleShape).background(accent.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.Edit, null, tint = accent, modifier = Modifier.size(15.dp))
                    }
                }
                Box(Modifier.fillMaxWidth().height(1.dp).background(settingsInk().copy(alpha = 0.07f)))
                // Where to?
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).pressable { state.editing = "destination"; state.step = RideStep.Search }.padding(vertical = 12.dp, horizontal = 4.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(stringResource(R.string.rd_where_to), color = settingsInk(), fontSize = 19.sp, fontWeight = FontWeight.Black, modifier = Modifier.weight(1f))
                    Box(Modifier.size(38.dp).clip(CircleShape).background(accent), contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.Search, null, tint = Color.White, modifier = Modifier.size(20.dp))
                    }
                }
            }
        }
    }
}

/**
 * O-51: the pin in the middle of the map — a drop with a soft pulse on the ground while it rests, lifted
 * while the map moves, and a «Pickup here» bubble once it lands.
 */
@Composable
internal fun PickupPin(lifted: Boolean, color: Color, label: String?) {
    val lift by animateFloatAsState(if (lifted) -26f else 0f, spring(dampingRatio = 0.5f), label = "pin")
    val pulse by rememberInfiniteTransition(label = "ground").animateFloat(0f, 1f, infiniteRepeatable(tween(1600, easing = LinearEasing)), label = "p")
    Box(Modifier.fillMaxSize().padding(bottom = 260.dp), contentAlignment = Alignment.Center) {
        if (!lifted) {
            Box(Modifier.size(46.dp).graphicsLayer { scaleX = 0.4f + pulse; scaleY = (0.4f + pulse) * 0.4f; alpha = 1f - pulse }.clip(CircleShape).background(color.copy(alpha = 0.35f)))
        }
        Box(Modifier.size(width = 12.dp, height = 5.dp).clip(CircleShape).background(Color.Black.copy(alpha = if (lifted) 0.12f else 0.28f)))
        Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.offset(y = (-30).dp).graphicsLayer { translationY = lift }) {
            AnimatedVisibility(visible = !lifted && label != null, enter = scaleIn(spring(dampingRatio = 0.55f)) + fadeIn(), exit = scaleOut() + fadeOut()) {
                Text(
                    label.orEmpty(), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.padding(bottom = 6.dp).shadow(6.dp, CircleShape).clip(CircleShape).background(Color(0xFF111928)).padding(horizontal = 12.dp, vertical = 6.dp),
                )
            }
            Box(Modifier.size(38.dp).shadow(8.dp, CircleShape).clip(CircleShape).background(color), contentAlignment = Alignment.Center) {
                Box(Modifier.size(14.dp).clip(CircleShape).background(Color.White))
            }
            Box(Modifier.size(width = 4.dp, height = 18.dp).clip(RoundedCornerShape(bottomStart = 2.dp, bottomEnd = 2.dp)).background(color))
        }
    }
}

/**
 * O-52: cancelling — the question asked plainly, what it costs (free / the fee) as a badge, the reasons as
 * cards to tap, and «keep my ride» as the main button with cancelling as the quieter one.
 */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
internal fun CancelRideSheet(state: RidesState, onClose: () -> Unit) {
    val ride = state.ride ?: return
    val scope = rememberCoroutineScope()
    var fee by remember { mutableStateOf<Long?>(null) }
    var reasons by remember { mutableStateOf<List<RideReasonDto>>(emptyList()) }
    var reason by remember { mutableStateOf<Int?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) {
        fee = runCatching { ApiClient.rides.cancelPreview(ridesAuth(), ride.uuid).data?.feeMinor }.getOrNull() ?: 0
        reasons = runCatching { ApiClient.rides.cancelReasons(ridesAuth()).data.orEmpty() }.getOrDefault(emptyList())
    }
    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), dragHandle = null) {
        Column(Modifier.navigationBarsPadding().padding(horizontal = 20.dp).padding(top = 22.dp, bottom = 18.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Box(Modifier.size(64.dp).clip(CircleShape).background(AppColors.danger.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.WarningAmber, null, tint = AppColors.danger, modifier = Modifier.size(34.dp))
            }
            Spacer(Modifier.height(12.dp))
            Text(stringResource(R.string.cx_title), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 20.sp, textAlign = TextAlign.Center)
            Text(
                stringResource(if (ride.driver != null) R.string.cx_body_driver else R.string.cx_body_search),
                color = settingsMut(), fontSize = 13.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 4.dp),
            )
            fee?.let {
                val free = it <= 0
                Text(
                    if (free) stringResource(R.string.rd_cancel_free) else stringResource(R.string.rd_cancel_fee, money(it, ride.currency)),
                    color = if (free) AppColors.success else AppColors.danger, fontWeight = FontWeight.Bold, fontSize = 13.sp,
                    modifier = Modifier.padding(top = 10.dp).clip(CircleShape).background((if (free) AppColors.success else AppColors.danger).copy(alpha = 0.1f)).padding(horizontal = 14.dp, vertical = 6.dp),
                )
            }
            if (reasons.isNotEmpty()) {
                Text(stringResource(R.string.rd_cancel_reason), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp, modifier = Modifier.fillMaxWidth().padding(top = 18.dp, bottom = 8.dp))
                FlowRow(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    reasons.forEach { r ->
                        val on = reason == r.id
                        val bg by animateColorAsState(if (on) settingsAccent().copy(alpha = 0.12f) else settingsBackground(), tween(180), label = "r")
                        Row(
                            Modifier.clip(RoundedCornerShape(14.dp)).background(bg)
                                .border(1.5.dp, if (on) settingsAccent() else Color.Transparent, RoundedCornerShape(14.dp))
                                .clickable { reason = if (on) null else r.id }.padding(horizontal = 12.dp, vertical = 10.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            if (on) { Icon(Icons.Rounded.CheckCircle, null, tint = settingsAccent(), modifier = Modifier.size(16.dp)); Spacer(Modifier.width(6.dp)) }
                            Text(r.label.orEmpty(), color = settingsInk(), fontSize = 13.sp, fontWeight = if (on) FontWeight.Bold else FontWeight.Medium)
                        }
                    }
                }
            }
            error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp, modifier = Modifier.padding(top = 10.dp)) }
            Spacer(Modifier.height(18.dp))
            AccentButton(stringResource(R.string.cx_keep)) { onClose() }
            Text(
                stringResource(R.string.rd_cancel_confirm), color = AppColors.danger, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                modifier = Modifier.padding(top = 8.dp).fillMaxWidth().clip(RoundedCornerShape(16.dp)).border(1.5.dp, AppColors.danger.copy(alpha = 0.35f), RoundedCornerShape(16.dp))
                    .clickable(enabled = !busy) {
                        busy = true
                        error = null
                        scope.launch {
                            val body = JsonObject().apply { reason?.let { addProperty("reason_id", it) } }
                            runCatching { ApiClient.rides.cancel(ridesAuth(), UUID.randomUUID().toString(), ride.uuid, body).data }
                                .onSuccess { state.ride = it; onClose() }
                                .onFailure { error = it.apiFailure().message }
                            busy = false
                        }
                    }.padding(vertical = 14.dp),
            )
        }
    }
}
