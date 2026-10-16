package com.dorr.app.ui.screens.rides

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.CreditCard
import androidx.compose.material.icons.rounded.Diamond
import androidx.compose.material.icons.rounded.DirectionsCar
import androidx.compose.material.icons.rounded.EditNote
import androidx.compose.material.icons.rounded.Group
import androidx.compose.material.icons.rounded.HourglassBottom
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.MilitaryTech
import androidx.compose.material.icons.rounded.NewReleases
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.PersonAdd
import androidx.compose.material.icons.rounded.Remove
import androidx.compose.material.icons.rounded.SwapHoriz
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.RideDriverDto
import com.dorr.app.network.RideDto
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
 * The trip's details around the ride (rides_tasks batch 3):
 *  - how many ride and a short note for the captain, on the booking sheet (D-60, D-61);
 *  - the captain's tier badge (R-81);
 *  - the time and distance left (D-129);
 *  - changing the way of paying before the trip starts (R-61);
 *  - the waiting countdown turning to a warning before the free minutes end (D-122);
 *  - booking for someone else (R-13).
 */

/** D-60 / D-61 on the booking sheet: a − n + stepper up to the category's seats, and the pickup note. */
@Composable
internal fun TripDetailsRow(state: RidesState) {
    val quote = state.selected ?: return
    val seats = (quote.category.capacity ?: 4).coerceAtLeast(1)
    if (state.passengers > seats) state.passengers = seats
    var noteOpen by remember { mutableStateOf(state.pickupNote.isNotBlank()) }

    Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(30.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Group, null, tint = settingsAccent(), modifier = Modifier.size(17.dp))
        }
        Spacer(Modifier.width(10.dp))
        Text(stringResource(R.string.rd_passengers), color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 14.sp, modifier = Modifier.weight(1f))
        StepButton(Icons.Rounded.Remove, state.passengers > 1) { state.passengers-- }
        AnimatedContent(
            targetState = state.passengers,
            transitionSpec = {
                val up = targetState > initialState
                (slideInVertically { if (up) it else -it } + fadeIn()) togetherWith (slideOutVertically { if (up) -it else it } + fadeOut())
            },
            label = "passengers",
        ) { n -> Text("$n", color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 17.sp, modifier = Modifier.width(34.dp), textAlign = androidx.compose.ui.text.style.TextAlign.Center) }
        StepButton(Icons.Rounded.Add, state.passengers < seats) { state.passengers++ }
    }
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { noteOpen = !noteOpen }.padding(vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(30.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.EditNote, null, tint = settingsAccent(), modifier = Modifier.size(17.dp))
        }
        Spacer(Modifier.width(10.dp))
        Text(
            state.pickupNote.ifBlank { stringResource(R.string.rd_pickup_note_title) },
            color = if (state.pickupNote.isBlank()) settingsInk() else settingsAccent(), fontWeight = FontWeight.SemiBold, fontSize = 14.sp, maxLines = 1,
            modifier = Modifier.weight(1f),
        )
    }
    AnimatedVisibility(noteOpen, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
        Column {
            Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsBackground()).padding(12.dp)) {
                if (state.pickupNote.isEmpty()) Text(stringResource(R.string.rd_pickup_note_hint), color = settingsMut(), fontSize = 13.sp)
                BasicTextField(
                    state.pickupNote, { state.pickupNote = it.replace('\n', ' ').take(120) },
                    textStyle = TextStyle(color = settingsInk(), fontSize = 14.sp), cursorBrush = SolidColor(settingsAccent()),
                    modifier = Modifier.fillMaxWidth(),
                )
            }
            Row(Modifier.fillMaxWidth().padding(top = 4.dp, bottom = 4.dp)) {
                Text(stringResource(R.string.rd_pickup_note_privacy), color = settingsMut(), fontSize = 11.sp, modifier = Modifier.weight(1f))
                Text("${state.pickupNote.length}/120", color = settingsMut(), fontSize = 11.sp)
            }
        }
    }
}

/**
 * R-13 on the booking sheet: "The ride is for someone else" — their name and number, who pays and
 * answers for the fees (the rider), what the captain sees (a first name only), and the rider's own
 * consent tick, never pre-ticked. Confirm stays off until all three are filled.
 */
@Composable
internal fun BookForOtherRow(state: RidesState) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { state.forOther = !state.forOther }.padding(vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(30.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.PersonAdd, null, tint = settingsAccent(), modifier = Modifier.size(17.dp))
        }
        Spacer(Modifier.width(10.dp))
        Text(stringResource(R.string.rd_for_other_title), color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 14.sp, modifier = Modifier.weight(1f))
        androidx.compose.material3.Switch(state.forOther, { state.forOther = it })
    }
    AnimatedVisibility(state.forOther, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            BeneficiaryField(state.beneficiaryName, stringResource(R.string.rd_for_other_name), KeyboardType.Text) { state.beneficiaryName = it.take(60) }
            BeneficiaryField(state.beneficiaryPhone, stringResource(R.string.rd_for_other_phone), KeyboardType.Phone) { v ->
                state.beneficiaryPhone = v.filter { it.isDigit() || it == '+' || it == ' ' }.take(18)
            }
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(AppColors.warning.copy(alpha = 0.1f)).padding(10.dp)) {
                Icon(Icons.Rounded.Info, null, tint = AppColors.warning, modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.rd_for_other_responsibility), color = settingsInk(), fontSize = 12.sp)
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Lock, null, tint = settingsMut(), modifier = Modifier.size(14.dp))
                Spacer(Modifier.width(6.dp))
                Text(stringResource(R.string.rd_for_other_privacy), color = settingsMut(), fontSize = 11.sp)
            }
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { state.beneficiaryConsent = !state.beneficiaryConsent },
                verticalAlignment = Alignment.CenterVertically,
            ) {
                androidx.compose.material3.Checkbox(state.beneficiaryConsent, { state.beneficiaryConsent = it })
                Text(stringResource(R.string.rd_for_other_consent), color = settingsInk(), fontSize = 13.sp, modifier = Modifier.weight(1f))
            }
        }
    }
}

@Composable
private fun BeneficiaryField(value: String, hint: String, type: KeyboardType, onChange: (String) -> Unit) {
    Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsBackground()).padding(12.dp)) {
        if (value.isEmpty()) Text(hint, color = settingsMut(), fontSize = 13.sp)
        BasicTextField(
            value, onChange, singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = type),
            textStyle = TextStyle(color = settingsInk(), fontSize = 14.sp), cursorBrush = SolidColor(settingsAccent()),
            modifier = Modifier.fillMaxWidth(),
        )
    }
}

/** R-13 on the ride: "For Mona" — the rider booked it for someone else. */
@Composable
internal fun BeneficiaryChip(ride: RideDto) {
    val name = ride.beneficiary?.name ?: return
    Row(
        Modifier.padding(top = 6.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.1f)).padding(horizontal = 12.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.PersonAdd, null, tint = settingsAccent(), modifier = Modifier.size(15.dp))
        Spacer(Modifier.width(6.dp))
        Text(stringResource(R.string.rd_for_other_ride, name), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun StepButton(icon: ImageVector, enabled: Boolean, onClick: () -> Unit) {
    Box(
        Modifier.size(32.dp).clip(CircleShape)
            .border(BorderStroke(1.dp, if (enabled) settingsAccent() else settingsInk().copy(alpha = 0.12f)), CircleShape)
            .clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, null, tint = if (enabled) settingsAccent() else settingsMut().copy(alpha = 0.5f), modifier = Modifier.size(18.dp)) }
}

/** R-81: the captain's tier — a coloured pill; gold and diamond shine softly. */
@Composable
internal fun TierBadge(driver: RideDriverDto?) {
    val tier = driver?.tier ?: return
    val (color, icon) = when (tier) {
        "bronze" -> Color(0xFFB0793F) to Icons.Rounded.MilitaryTech
        "silver" -> Color(0xFF8A94A6) to Icons.Rounded.MilitaryTech
        "gold" -> Color(0xFFD4A017) to Icons.Rounded.MilitaryTech
        "diamond" -> Color(0xFF2BA8E0) to Icons.Rounded.Diamond
        else -> AppColors.success to Icons.Rounded.NewReleases
    }
    val shine = if (tier == "gold" || tier == "diamond") {
        val pulse = rememberInfiniteTransition(label = "tier")
        pulse.animateFloat(0.10f, 0.22f, infiniteRepeatable(tween(1400, easing = LinearEasing), RepeatMode.Reverse), label = "shine").value
    } else 0.12f
    Row(
        Modifier.clip(CircleShape).background(color.copy(alpha = shine)).padding(horizontal = 8.dp, vertical = 3.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = color, modifier = Modifier.size(13.dp))
        Spacer(Modifier.width(4.dp))
        Text(driver.tierLabel ?: tier, color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold)
    }
}

/** D-129: "Arrives in 4 min · 1.2 km" with a breathing dot; the numbers roll as they change. */
@Composable
internal fun EtaChip(ride: RideDto) {
    val progress = ride.progress ?: return
    val minute = stringResource(R.string.rd_minutes)
    val hour = stringResource(R.string.rd_hours)
    val label = when (progress.to) {
        "pickup" -> R.string.rd_eta_pickup
        "stop" -> R.string.rd_eta_stop
        else -> R.string.rd_eta_destination
    }
    val breathe = rememberInfiniteTransition(label = "eta").animateFloat(0.35f, 1f, infiniteRepeatable(tween(900), RepeatMode.Reverse), label = "dot").value
    Row(
        Modifier.padding(top = 6.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.1f)).padding(horizontal = 12.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(8.dp).alpha(breathe).clip(CircleShape).background(AppColors.success))
        Spacer(Modifier.width(8.dp))
        Icon(Icons.Rounded.DirectionsCar, null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(6.dp))
        AnimatedContent(targetState = progress.etaS to progress.distanceM, transitionSpec = { fadeIn(tween(300)) togetherWith fadeOut(tween(200)) }, label = "eta") { (eta, metres) ->
            Text(
                stringResource(label, minutesText(eta, minute, hour), stringResource(R.string.rd_km, String.format("%.1f", metres / 1000.0))),
                color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold,
            )
        }
    }
}

/** R-61: how the ride is paid, and — before the trip starts — switching wallet ↔ cash. */
@Composable
internal fun PaymentSwitch(state: RidesState, ride: RideDto) {
    val scope = rememberCoroutineScope()
    var open by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val method = ride.paymentMethod ?: return
    val canChange = ride.paymentChange.isNotEmpty()

    Column(Modifier.fillMaxWidth().padding(top = 10.dp)) {
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsBackground())
                .clickable(enabled = canChange) { open = !open; error = null }.padding(horizontal = 12.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            AnimatedContent(targetState = method, transitionSpec = { fadeIn(tween(250)) togetherWith fadeOut(tween(150)) }, label = "method") { m ->
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(methodIcon(m), null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
                    Spacer(Modifier.width(8.dp))
                    Text(stringResource(R.string.rd_paying_with, methodLabel(m)), color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
                }
            }
            Spacer(Modifier.weight(1f))
            if (canChange) {
                Icon(Icons.Rounded.SwapHoriz, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                Text(stringResource(R.string.rd_change), color = settingsAccent(), fontWeight = FontWeight.Bold, fontSize = 13.sp)
            }
        }
        AnimatedVisibility(open && canChange, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            Row(Modifier.fillMaxWidth().padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                ride.paymentChange.forEach { option ->
                    Row(
                        Modifier.weight(1f).clip(RoundedCornerShape(12.dp))
                            .border(BorderStroke(1.dp, settingsAccent().copy(alpha = 0.4f)), RoundedCornerShape(12.dp))
                            .clickable(enabled = busy == null) {
                                busy = option
                                scope.launch {
                                    runCatching { ApiClient.rides.changePayment(ridesAuth(), UUID.randomUUID().toString(), ride.uuid, JsonObject().apply { addProperty("payment_method", option) }).data }
                                        .onSuccess { fresh -> fresh?.let { state.ride = it }; open = false }
                                        .onFailure { error = it.apiFailure().message }
                                    busy = null
                                }
                            }.padding(vertical = 10.dp),
                        horizontalArrangement = Arrangement.Center,
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        if (busy == option) androidx.compose.material3.CircularProgressIndicator(strokeWidth = 2.dp, color = settingsAccent(), modifier = Modifier.size(16.dp))
                        else Icon(methodIcon(option), null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                        Spacer(Modifier.width(6.dp))
                        Text(methodLabel(option), color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
                    }
                }
            }
        }
        error?.let { Text(it, color = AppColors.danger, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp)) }
    }
}

private fun methodIcon(method: String): ImageVector = when (method) {
    "cash" -> Icons.Rounded.Payments
    "card" -> Icons.Rounded.CreditCard
    else -> Icons.Rounded.AccountBalanceWallet
}

@Composable
private fun methodLabel(method: String): String = stringResource(
    when (method) {
        "cash" -> R.string.rd_pay_cash
        "card" -> R.string.rd_pay_card
        else -> R.string.rd_pay_wallet
    },
)

/**
 * D-122: the free waiting at the pickup — green while there's time, amber and pulsing in the last
 * `warn_before_s`, then the paid minutes.
 */
@Composable
internal fun WaitingCountdown(left: Int, warnBeforeS: Int) {
    val warning = left in 1..warnBeforeS
    val color by animateColorAsState(
        when {
            left <= 0 -> AppColors.danger
            warning -> AppColors.warning
            else -> AppColors.success
        },
        tween(400), label = "wait",
    )
    val pulse = if (warning) {
        rememberInfiniteTransition(label = "warn").animateFloat(1f, 1.06f, infiniteRepeatable(tween(500), RepeatMode.Reverse), label = "pulse").value
    } else 1f
    Row(
        Modifier.padding(top = 4.dp).scale(pulse).clip(CircleShape).background(color.copy(alpha = 0.12f)).padding(horizontal = 12.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.HourglassBottom, null, tint = color, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(6.dp))
        Text(
            when {
                left <= 0 -> stringResource(R.string.rd_arrived_paid_wait)
                warning -> stringResource(R.string.rd_wait_ending, "${left / 60}:${(left % 60).toString().padStart(2, '0')}")
                else -> stringResource(R.string.rd_arrived_wait, "${left / 60}:${(left % 60).toString().padStart(2, '0')}")
            },
            color = color, fontSize = 13.sp, fontWeight = FontWeight.Bold,
        )
    }
}
